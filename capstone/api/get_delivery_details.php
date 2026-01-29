<?php
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

require_once '../includes/db.php';

$delivery_id = intval($_GET['delivery_id'] ?? 0);

if (!$delivery_id) {
    echo json_encode(['success' => false, 'message' => 'Delivery ID is required']);
    exit();
}

// Get delivery information
$delivery_stmt = $conn->prepare("SELECT d.*, o.Order_ID, o.Customer_ID 
                                 FROM delivery d 
                                 LEFT JOIN orders o ON d.Order_ID = o.Order_ID 
                                 WHERE d.Delivery_ID = ?");
$delivery_stmt->bind_param("i", $delivery_id);
$delivery_stmt->execute();
$delivery_result = $delivery_stmt->get_result();

if ($delivery_result->num_rows === 0) {
    $delivery_stmt->close();
    echo json_encode(['success' => false, 'message' => 'Delivery not found']);
    exit();
}

$delivery = $delivery_result->fetch_assoc();
$delivery_stmt->close();

/**
 * Create missing delivery_detail rows for a given delivery using order_details.
 * This is idempotent (won't duplicate existing (Delivery_ID, Order_detail_ID) pairs).
 */
function ensureDeliveryDetailRows($conn, $delivery_id, $order_id) {
    $delivery_id = intval($delivery_id);
    $order_id = intval($order_id);
    if ($delivery_id <= 0 || $order_id <= 0) return;

    // Detect delivery_detail columns to avoid "Unknown column" issues
    $dd_cols = [];
    $cr = $conn->query("SHOW COLUMNS FROM delivery_detail");
    if (!$cr) return;
    while ($r = $cr->fetch_assoc()) {
        $dd_cols[] = $r['Field'];
    }
    $cr->close();

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
    // bind: Delivery_ID for insert SELECT + Delivery_ID for LEFT JOIN + Order_ID filter
    $stmt->bind_param("iii", $delivery_id, $delivery_id, $order_id);
    $stmt->execute();
    $stmt->close();
}

// Get delivery details with product information
// Try to join with order_details first. If none found, fall back to order_details only
$details_query = "SELECT dd.Delivery_Detail_ID, dd.Order_detail_ID, 
                 COALESCE(dd.received_qty, 0) as received_qty, 
                 COALESCE(dd.damage_qty, 0) as damage_qty,
                 od.Product_ID, od.ordered_qty, od.unit_price,
                 p.product_name, p.form, p.unit
                 FROM delivery_detail dd
                 LEFT JOIN order_details od ON dd.Order_detail_ID = od.Order_detail_ID
                 LEFT JOIN products p ON od.Product_ID = p.Product_ID
                 WHERE dd.Delivery_ID = ?";
$details_stmt = $conn->prepare($details_query);
$details_stmt->bind_param("i", $delivery_id);
$details_stmt->execute();
$details_result = $details_stmt->get_result();

$items = [];
if ($details_result->num_rows > 0) {
    while ($detail = $details_result->fetch_assoc()) {
        if (!empty($detail['Product_ID'])) {
            $items[] = [
                'delivery_detail_id' => intval($detail['Delivery_Detail_ID']),
                'order_detail_id' => intval($detail['Order_detail_ID'] ?? 0),
                'product_id' => intval($detail['Product_ID']),
                'product_name' => $detail['product_name'] ?? 'Unknown Product',
                'form' => $detail['form'] ?? '',
                'unit' => $detail['unit'] ?? '',
                'ordered_qty' => floatval($detail['ordered_qty'] ?? 0),
                'received_qty' => floatval($detail['received_qty'] ?? 0),
                'damage_qty' => floatval($detail['damage_qty'] ?? 0),
                'unit_price' => floatval($detail['unit_price'] ?? 0)
            ];
        }
    }
}

$details_stmt->close();

// If delivery_detail is missing, CREATE it now (so workflow has stored rows)
if (empty($items)) {
    $order_id = intval($delivery['Order_ID'] ?? 0);
    if ($order_id > 0) {
        ensureDeliveryDetailRows($conn, $delivery_id, $order_id);

        // Re-query after backfill
        $details_stmt = $conn->prepare($details_query);
        $details_stmt->bind_param("i", $delivery_id);
        $details_stmt->execute();
        $details_result = $details_stmt->get_result();
        while ($detail = $details_result->fetch_assoc()) {
            if (!empty($detail['Product_ID'])) {
                $items[] = [
                    'delivery_detail_id' => intval($detail['Delivery_Detail_ID']),
                    'order_detail_id' => intval($detail['Order_detail_ID'] ?? 0),
                    'product_id' => intval($detail['Product_ID']),
                    'product_name' => $detail['product_name'] ?? 'Unknown Product',
                    'form' => $detail['form'] ?? '',
                    'unit' => $detail['unit'] ?? '',
                    'ordered_qty' => floatval($detail['ordered_qty'] ?? 0),
                    'received_qty' => floatval($detail['received_qty'] ?? 0),
                    'damage_qty' => floatval($detail['damage_qty'] ?? 0),
                    'unit_price' => floatval($detail['unit_price'] ?? 0)
                ];
            }
        }
        $details_stmt->close();
    }
}

// Fallback: no delivery_detail rows yet, derive from order_details for this order
if (empty($items)) {
    $order_id = intval($delivery['Order_ID'] ?? 0);
    if ($order_id > 0) {
        $fallback_sql = "SELECT od.Order_detail_ID, od.Product_ID, od.ordered_qty, od.unit_price,
                                p.product_name, p.form, p.unit
                         FROM order_details od
                         INNER JOIN products p ON od.Product_ID = p.Product_ID
                         WHERE od.Order_ID = ?";
        $fb = $conn->prepare($fallback_sql);
        if ($fb) {
            $fb->bind_param("i", $order_id);
            $fb->execute();
            $fb_res = $fb->get_result();
            while ($row = $fb_res->fetch_assoc()) {
                $items[] = [
                    'delivery_detail_id' => 0, // no delivery_detail row yet
                    'order_detail_id' => intval($row['Order_detail_ID']),
                    'product_id' => intval($row['Product_ID']),
                    'product_name' => $row['product_name'] ?? 'Unknown Product',
                    'form' => $row['form'] ?? '',
                    'unit' => $row['unit'] ?? '',
                    'ordered_qty' => floatval($row['ordered_qty'] ?? 0),
                    'received_qty' => floatval($row['ordered_qty'] ?? 0),
                    'damage_qty' => 0,
                    'unit_price' => floatval($row['unit_price'] ?? 0)
                ];
            }
            $fb->close();
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
    echo json_encode([
        'success' => true,
        'delivery' => $delivery,
        'items' => $items
    ]);
}

$conn->close();
?>
