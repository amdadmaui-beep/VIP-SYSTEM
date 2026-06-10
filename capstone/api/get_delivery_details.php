<?php
header('Content-Type: application/json');
ini_set('display_errors', '0');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth.php';

require_once '../includes/db.php';
try {

$delivery_id = intval($_GET['delivery_id'] ?? 0);
$order_id = intval($_GET['order_id'] ?? 0);

if (!$delivery_id && !$order_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Delivery ID or Order ID is required']);
    exit();
}

// Check if orders has delivery_address and total_amount
$o_cols = array_column($conn->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_ASSOC), 'Field');
$o_extra = [];
if (in_array('total_amount', $o_cols)) $o_extra[] = 'o.total_amount';
else $o_extra[] = '0 as total_amount';
if (in_array('is_ar', $o_cols)) $o_extra[] = 'o.is_ar';
else $o_extra[] = '0 as is_ar';
if (in_array('delivery_address', $o_cols)) $o_extra[] = 'o.delivery_address as order_delivery_address';
$o_extra_sql = implode(', ', $o_extra);

// Check if customers has email (optional)
$cust_cols = array_column($conn->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_ASSOC), 'Field');
$email_sql = in_array('email', $cust_cols) ? 'c.email as email' : "'' as email";

// Get delivery information (include total_amount for COD display)
if ($delivery_id) {
    // Check if delivery has proof_of_delivery_path column to avoid query errors
    $d_cols = array_column($conn->query("SHOW COLUMNS FROM delivery")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $d_proof = in_array('proof_of_delivery_path', $d_cols) ? 'd.proof_of_delivery_path' : 'NULL';

    $delivery_stmt = $conn->prepare("SELECT d.*, o.Order_ID, o.Customer_ID, c.Customer_ID as cust_id, {$o_extra_sql}, COALESCE(o.customer_name_snapshot, c.customer_name) as customer_name, c.phone_number, COALESCE(o.customer_address_snapshot, c.address) as customer_address, {$email_sql},
                                            COALESCE(dp_latest.file_path, {$d_proof}) as proof_of_delivery_path
                                     FROM delivery d 
                                     LEFT JOIN orders o ON d.Order_ID = o.Order_ID 
                                     LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
                                     LEFT JOIN (
                                        SELECT p1.delivery_id, p1.file_path
                                        FROM delivery_proofs p1
                                        INNER JOIN (
                                            SELECT delivery_id, MAX(proof_id) AS max_proof_id
                                            FROM delivery_proofs
                                            GROUP BY delivery_id
                                        ) p2 ON p2.max_proof_id = p1.proof_id
                                     ) dp_latest ON dp_latest.delivery_id = d.Delivery_ID
                                     WHERE d.Delivery_ID = ?");
    $delivery_stmt->execute([$delivery_id]);
    $delivery = $delivery_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$delivery) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Delivery not found']);
        exit();
    }
    // Riders may only view deliveries assigned to them (by ID or by name)
    // Owner (1), Manager (2), and Cashier (3) can view all deliveries (for POS/Dashboard)
    $user_role = (int)($_SESSION['user_role'] ?? 0);
    $is_admin = ($user_role === 1 || $user_role === 2 || $user_role === 3);
    
    if (!$is_admin) {
        require_once __DIR__ . '/../includes/rider_availability_helper.php';
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $assigned_id = (int)riderGetUserIdByDeliveryId($conn, $delivery_id);
        if ($assigned_id !== $uid) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied - delivery not assigned to you']);
            exit();
        }
    }
} else {
    // Just order info
    $order_stmt = $conn->prepare("SELECT Order_ID, Customer_ID FROM orders WHERE Order_ID = ?");
    $order_stmt->execute([$order_id]);
    $delivery = $order_stmt->fetch();
    
    if (!$delivery) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit();
    }
}

/**
 * Sum damaged qty from delivery_damage_report for a line (pending + approved, not rejected).
 */
function getReportedDamageQtyForDeliveryLine(PDO $conn, int $delivery_id, int $order_detail_id): float {
    if ($delivery_id <= 0 || $order_detail_id <= 0) {
        return 0.0;
    }
    try {
        $t = $conn->query("SHOW TABLES LIKE 'delivery_damage_report'");
        if (!$t || $t->rowCount() === 0) {
            return 0.0;
        }
        $stmt = $conn->prepare(
            "SELECT COALESCE(SUM(r.damaged_qty), 0)
             FROM delivery_damage_report r
             LEFT JOIN damage_report_reviews rev ON rev.report_id = r.report_id
             WHERE r.Delivery_ID = ?
               AND r.Order_detail_ID = ?
               AND COALESCE(rev.status, 'pending_review') IN ('pending_review', 'approved')"
        );
        $stmt->execute([$delivery_id, $order_detail_id]);
        return (float) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

/**
 * Attach reported_damage_qty and suggested_received_qty to each checklist item.
 */
function enrichDeliveryItemsWithDamageReports(PDO $conn, int $delivery_id, array $items): array {
    if ($delivery_id <= 0 || empty($items)) {
        return $items;
    }
    foreach ($items as &$item) {
        $ordered = (float) ($item['ordered_qty'] ?? 0);
        $order_detail_id = (int) ($item['order_detail_id'] ?? 0);
        $stored_damage = (float) ($item['damage_qty'] ?? 0);
        $reported = getReportedDamageQtyForDeliveryLine($conn, $delivery_id, $order_detail_id);
        $reported = max($stored_damage, $reported);
        $reported = min($reported, $ordered);
        $suggested = max(0.0, $ordered - $reported);
        $item['reported_damage_qty'] = $reported;
        $item['suggested_received_qty'] = $suggested;
        $item['line_collectible_amount'] = round($suggested * (float) ($item['unit_price'] ?? 0), 2);
    }
    unset($item);
    return $items;
}

/**
 * Create missing delivery_detail rows for a given delivery using order_details.
 * This is idempotent (won't duplicate existing (Delivery_ID, Order_detail_ID) pairs).
 */
function ensureDeliveryDetailRows($conn, $delivery_id, $order_id) {
    $delivery_id = intval($delivery_id);
    $order_id = intval($order_id);
    if ($delivery_id <= 0 || $order_id <= 0) return;

    // Detect delivery_detail columns to avoid "Unknown column" issues
    $dd_cols = $conn->query("SHOW COLUMNS FROM delivery_detail")->fetchAll(PDO::FETCH_COLUMN);

    // Required columns
    if (!in_array('Delivery_ID', $dd_cols) || !in_array('Order_detail_ID', $dd_cols)) return;

    $fields = ['Delivery_ID', 'Order_detail_ID'];
    $selects = ['?', 'od.Order_detail_ID'];

    if (in_array('received_qty', $dd_cols)) {
        $fields[] = 'received_qty';
        $selects[] = 'od.ordered_qty';
    }
    if (in_array('damage_qty', $dd_cols)) {
        $fields[] = 'damage_qty';
        $selects[] = '0';
    }
    if (in_array('status', $dd_cols)) {
        $fields[] = 'status';
        $selects[] = "'Pending'";
    }
    if (in_array('created_at', $dd_cols)) {
        $fields[] = 'created_at';
        $selects[] = 'NOW()';
    }
    if (in_array('updated_at', $dd_cols)) {
        $fields[] = 'updated_at';
        $selects[] = 'NOW()';
    }

    $sql = "
        INSERT INTO delivery_detail (" . implode(', ', $fields) . ")
        SELECT " . implode(', ', $selects) . "
        FROM order_details od
        LEFT JOIN delivery_detail dd
          ON dd.Delivery_ID = ? AND dd.Order_detail_ID = od.Order_detail_ID
        WHERE od.Order_ID = ? AND dd.Delivery_Detail_ID IS NULL
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return;
    $stmt->execute([$delivery_id, $delivery_id, $order_id]);
}

// Get delivery details with product information
// Try to join with order_details first. If none found, fall back to order_details only
// Check if products has unit_id (for units join) or unit
$p_cols = $conn->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
$has_unit_id = in_array('unit_id', $p_cols);
$unit_join = $has_unit_id ? "LEFT JOIN units u ON p.unit_id = u.unit_id" : "";
$unit_select_raw = $has_unit_id ? "u.unit_name" : "p.unit";
$unit_select = $has_unit_id ? "u.unit_name" : "p.unit as unit_name";

// proof_delivery is stored per delivery_detail item (optional column).
$dd_cols = $conn->query("SHOW COLUMNS FROM delivery_detail")->fetchAll(PDO::FETCH_COLUMN);
$proof_select = in_array('proof_delivery', $dd_cols) ? 'dd.proof_delivery' : 'NULL as proof_delivery';
$remarks_select = in_array('remarks', $dd_cols) ? 'dd.remarks' : "'' as remarks";

$details_query = "SELECT dd.Delivery_Detail_ID, dd.Order_detail_ID, 
                 COALESCE(dd.received_qty, 0) as received_qty, 
                 COALESCE(dd.damage_qty, 0) as damage_qty,
                 {$remarks_select} as remarks,
                 {$proof_select},
                 od.Product_ID, od.ordered_qty, od.unit_price,
                 COALESCE(od.product_name_snapshot, p.product_name) as product_name, COALESCE(od.unit_name_snapshot, {$unit_select_raw}) as unit_name
                 FROM delivery_detail dd
                 LEFT JOIN order_details od ON dd.Order_detail_ID = od.Order_detail_ID
                 LEFT JOIN products p ON od.Product_ID = p.Product_ID
                 {$unit_join}
                 WHERE dd.Delivery_ID = ?";

$items = [];
if ($delivery_id > 0) {
    $details_stmt = $conn->prepare($details_query);
    $details_stmt->execute([$delivery_id]);
    $details_result = $details_stmt->fetchAll();

    foreach ($details_result as $detail) {
        if (!empty($detail['Product_ID'])) {
            $items[] = [
                'delivery_detail_id' => intval($detail['Delivery_Detail_ID']),
                'order_detail_id' => intval($detail['Order_detail_ID'] ?? 0),
                'product_id' => intval($detail['Product_ID']),
                'product_name' => $detail['product_name'] ?? 'Unknown Product',
                'unit' => $detail['unit_name'] ?? '',
                'ordered_qty' => floatval($detail['ordered_qty'] ?? 0),
                'received_qty' => floatval($detail['received_qty'] ?? 0),
                'damage_qty' => floatval($detail['damage_qty'] ?? 0),
                'unit_price' => floatval($detail['unit_price'] ?? 0),
                'remarks' => (string)($detail['remarks'] ?? ''),
                'proof_delivery' => (string)($detail['proof_delivery'] ?? '')
            ];
        }
    }
}

// If delivery_detail is missing, CREATE it now (so workflow has stored rows)
if (empty($items)) {
    $order_id = intval($delivery['Order_ID'] ?? 0);
    if ($order_id > 0) {
        ensureDeliveryDetailRows($conn, $delivery_id, $order_id);

        $details_stmt = $conn->prepare($details_query);
        $details_stmt->execute([$delivery_id]);
        $details_result = $details_stmt->fetchAll();
        foreach ($details_result as $detail) {
            if (!empty($detail['Product_ID'])) {
                $items[] = [
                    'delivery_detail_id' => intval($detail['Delivery_Detail_ID']),
                    'order_detail_id' => intval($detail['Order_detail_ID'] ?? 0),
                    'product_id' => intval($detail['Product_ID']),
                    'product_name' => $detail['product_name'] ?? 'Unknown Product',
                    'unit' => $detail['unit_name'] ?? '',
                    'ordered_qty' => floatval($detail['ordered_qty'] ?? 0),
                    'received_qty' => floatval($detail['received_qty'] ?? 0),
                    'damage_qty' => floatval($detail['damage_qty'] ?? 0),
                    'unit_price' => floatval($detail['unit_price'] ?? 0),
                    'remarks' => (string)($detail['remarks'] ?? ''),
                    'proof_delivery' => (string)($detail['proof_delivery'] ?? '')
                ];
            }
        }
    }
}

$proofs = [];
if ($delivery_id > 0) {
    try {
        $proof_stmt = $conn->prepare("
            SELECT proof_id, file_path, captured_at
            FROM delivery_proofs
            WHERE delivery_id = ?
            ORDER BY captured_at DESC, proof_id DESC
        ");
        $proof_stmt->execute([$delivery_id]);
        $proof_rows = $proof_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($proof_rows as $proof_row) {
            $file_path = trim((string)($proof_row['file_path'] ?? ''));
            if ($file_path === '') continue;
            $proofs[] = [
                'proof_id' => (int)($proof_row['proof_id'] ?? 0),
                'file_path' => $file_path,
                'captured_at' => (string)($proof_row['captured_at'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        // delivery_proofs might not exist in older installs
        $proofs = [];
    }
}

// Fallback: no delivery_detail rows yet, derive from order_details for this order
$fallback_sql = "SELECT od.Order_detail_ID, od.Product_ID, od.ordered_qty, od.unit_price, COALESCE(od.product_name_snapshot, p.product_name) as product_name, " .
    "COALESCE(od.unit_name_snapshot, " . ($has_unit_id ? "u.unit_name" : "p.unit") . ") as unit_name" .
    " FROM order_details od
     LEFT JOIN products p ON od.Product_ID = p.Product_ID
     " . ($has_unit_id ? "LEFT JOIN units u ON p.unit_id = u.unit_id" : "") . "
     WHERE od.Order_ID = ?";
if (empty($items)) {
    $order_id = intval($delivery['Order_ID'] ?? 0);
    if ($order_id > 0) {
        $fb = $conn->prepare($fallback_sql);
        if ($fb) {
            $fb->execute([$order_id]);
            $fb_res = $fb->fetchAll();
            foreach ($fb_res as $row) {
                $items[] = [
                    'delivery_detail_id' => 0,
                    'order_detail_id' => intval($row['Order_detail_ID']),
                    'product_id' => intval($row['Product_ID']),
                    'product_name' => $row['product_name'] ?? 'Unknown Product',
                    'unit' => $row['unit_name'] ?? '',
                    'ordered_qty' => floatval($row['ordered_qty'] ?? 0),
                    'received_qty' => floatval($row['ordered_qty'] ?? 0),
                    'damage_qty' => 0,
                    'unit_price' => floatval($row['unit_price'] ?? 0)
                ];
            }
        }
    }
}

if (empty($items)) {
    echo json_encode([
        'success' => false,
        'message' => 'No delivery details found. Please ensure the order has items in order_details.',
        'delivery' => $delivery,
        'items' => []
    ]);
} else {
    if ($delivery_id > 0) {
        $items = enrichDeliveryItemsWithDamageReports($conn, $delivery_id, $items);
    }

    // Compute total from items (Shopee-style) if order total is missing/zero
    $computed_total = 0;
    $collectible_total = 0;
    foreach ($items as $it) {
        $computed_total += (float)($it['ordered_qty'] ?? 0) * (float)($it['unit_price'] ?? 0);
        $collectible_total += (float)($it['line_collectible_amount'] ?? ((float)($it['suggested_received_qty'] ?? $it['ordered_qty'] ?? 0) * (float)($it['unit_price'] ?? 0)));
    }
    $ord_total = (float)($delivery['total_amount'] ?? 0);
    if ($ord_total <= 0 && $computed_total > 0) {
        $delivery['total_amount'] = $computed_total;
    }
    $delivery['collectible_amount'] = round($collectible_total, 2);
    $delivery['has_reported_damage'] = $collectible_total < $computed_total - 0.009;
    // Keep backward compatibility with older frontend consumers:
    // when proofs list exists, expose first image as proof_of_delivery_path.
    if (!empty($proofs)) {
        $delivery['proof_of_delivery_path'] = (string)$proofs[0]['file_path'];
    }

    $damage_reports_enabled = false;
    if ($delivery_id > 0) {
        try {
            $ddr_chk = $conn->query("SHOW TABLES LIKE 'delivery_damage_report'");
            $damage_reports_enabled = $ddr_chk && $ddr_chk->rowCount() > 0;
        } catch (Throwable $e) {
            $damage_reports_enabled = false;
        }
    }

    echo json_encode([
        'success' => true,
        'delivery' => $delivery,
        'items' => $items,
        'proofs' => $proofs,
        'damage_reports_enabled' => $damage_reports_enabled,
    ]);
}
} catch (Throwable $e) {
    http_response_code(500);
    error_log('get_delivery_details server error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An internal error occurred.']);
}

?>
