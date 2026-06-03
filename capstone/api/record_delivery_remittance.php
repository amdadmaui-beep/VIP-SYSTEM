<?php
/**
 * Record Delivery Remittance API
 * 
 * Records sale from delivery with automatic damage deduction and variance tracking.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$user_role = (int)($_SESSION['user_role'] ?? 0);

// Role check: Owner(1), Cashier(2), Rider(3), Manager(4)
if (!in_array($user_role, [1, 2, 3, 4], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

// Owner is view-only for delivery remittance operations
if ($user_role === 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Your account (Owner) is restricted to view-only access. Delivery remittance operations are not allowed.']);
    exit();
}

require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/rider_availability_helper.php';
require_once __DIR__ . '/../includes/cash_session_helper.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../realtime/publish_event.php';
require_once __DIR__ . '/../includes/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// CSRF Validation
if (!validateCsrfToken(false)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired security token. Please refresh the page and try again.']);
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

// Parse input
$rider_id = intval($_POST['rider_id'] ?? 0);
$deliveries_json = $_POST['deliveries'] ?? '[]';
$deliveries = json_decode($deliveries_json, true);
$cash_received = max(0, floatval($_POST['cash_received'] ?? 0));
$remarks = trim($_POST['remarks'] ?? '');

// Validation
$errors = [];
if ($rider_id <= 0) $errors[] = 'Rider ID is required';
if (empty($deliveries) || !is_array($deliveries)) $errors[] = 'At least one delivery is required';
if ($cash_received <= 0) $errors[] = 'Cash received must be greater than zero';
if (!empty($remarks) && strlen($remarks) > 500) $errors[] = 'Remarks must not exceed 500 characters';

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => implode(' | ', $errors)]);
    exit();
}

// Verify rider exists
$rider_stmt = $conn->prepare("SELECT u.User_ID, COALESCE(u.full_name, u.user_name) as rider_name FROM user u WHERE u.User_ID = ? AND u.is_active = 1");
$rider_stmt->execute([$rider_id]);
$rider = $rider_stmt->fetch(PDO::FETCH_ASSOC);

if (!$rider) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Rider not found or inactive']);
    exit();
}

// Check for open cash shift
$openShift = getOpenCashShiftForUser($conn, $user_id);
if (!$openShift) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Open a cashier shift before recording delivery remittances.']);
    exit();
}

$conn->beginTransaction();
try {
    $total_expected_remittance = 0;
    $total_damaged_value = 0;
    $total_amount_to_collect = 0;
    $all_sale_details = [];
    $processed_delivery_ids = [];
    
    foreach ($deliveries as $delivery_data) {
        $delivery_id = intval($delivery_data['delivery_id'] ?? 0);
        $order_id = intval($delivery_data['order_id'] ?? 0);
        $items = $delivery_data['items'] ?? [];
        
        if ($delivery_id <= 0) throw new Exception('Invalid delivery ID');
        
        // Verify delivery exists and is in valid status
        $del_check = $conn->prepare("SELECT d.Delivery_ID, d.Order_ID, d.delivery_status, o.Customer_ID 
                                      FROM delivery d 
                                      LEFT JOIN orders o ON d.Order_ID = o.Order_ID 
                                      WHERE d.Delivery_ID = ?");
        $del_check->execute([$delivery_id]);
        $delivery_info = $del_check->fetch(PDO::FETCH_ASSOC);
        
        if (!$delivery_info) throw new Exception("Delivery #{$delivery_id} not found");
        
        $allowed_statuses = ['Delivered', 'Remitted', 'Completed', 'Delivered (Pending Cash Turnover)'];
        if (!in_array($delivery_info['delivery_status'], $allowed_statuses, true)) {
            throw new Exception("Delivery #{$delivery_id} is not yet remitted. Status: {$delivery_info['delivery_status']}");
        }
        
        // Check if already sold
        $sold_check = $conn->prepare("SELECT COUNT(*) FROM sale_source WHERE Delivery_ID = ? AND Sale_ID IS NOT NULL");
        $sold_check->execute([$delivery_id]);
        if ((int)$sold_check->fetchColumn() > 0) {
            throw new Exception("Delivery #{$delivery_id} has already been recorded as a sale");
        }
        
        $customer_id = intval($delivery_info['Customer_ID'] ?? 0);
        if ($customer_id <= 0) {
            $customer_id = getOrCreateWalkinCustomerId($conn);
        }
        
        $delivery_expected = 0;
        $delivery_damaged = 0;
        
        foreach ($items as $item) {
            $product_id = intval($item['product_id'] ?? 0);
            $ordered_qty = floatval($item['ordered_qty'] ?? 0);
            $delivery_detail_id = intval($item['delivery_detail_id'] ?? 0);
            $order_detail_id = intval($item['order_detail_id'] ?? 0);
            
            // Get reported damage from delivery_damage_report table where review status is not rejected
            $dmg_stmt = $conn->prepare("
                SELECT COALESCE(SUM(r.damaged_qty), 0) 
                FROM delivery_damage_report r
                LEFT JOIN damage_report_reviews rev ON rev.report_id = r.report_id
                WHERE r.Delivery_ID = ? AND r.Order_detail_ID = ? 
                  AND COALESCE(rev.status, 'pending_review') IN ('pending_review', 'approved')
            ");
            $dmg_stmt->execute([$delivery_id, $order_detail_id]);
            $reported_dmg = floatval($dmg_stmt->fetchColumn() ?: 0);
            
            $damage_qty = max(floatval($item['damage_qty'] ?? 0), $reported_dmg);
            
            if ($product_id <= 0) throw new Exception('Invalid product ID');
            
            $price_stmt = $conn->prepare("SELECT unit_price FROM order_details WHERE Order_detail_ID = ?");
            $price_stmt->execute([$order_detail_id]);
            $price_row = $price_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$price_row) throw new Exception("Order detail not found");
            
            $unit_price = floatval($price_row['unit_price']);
            
            $prod_check = $conn->prepare("SELECT Product_ID, product_name, is_discontinued FROM products WHERE Product_ID = ?");
            $prod_check->execute([$product_id]);
            $prod_info = $prod_check->fetch(PDO::FETCH_ASSOC);
            if (!$prod_info) throw new Exception("Product #{$product_id} not found");
            
            if ($damage_qty > $ordered_qty) throw new Exception("Damage qty exceeds ordered qty for {$prod_info['product_name']}");
            
            $line_expected = $ordered_qty * $unit_price;
            $line_damaged = $damage_qty * $unit_price;
            $sale_qty = $ordered_qty - $damage_qty;
            
            $delivery_expected += $line_expected;
            $delivery_damaged += $line_damaged;
            
            if ($sale_qty > 0) {
                $all_sale_details[] = [
                    'product_id' => $product_id,
                    'quantity' => $sale_qty,
                    'unit_price' => $unit_price,
                    'subtotal' => $sale_qty * $unit_price,
                    'delivery_detail_id' => $delivery_detail_id,
                    'delivery_id' => $delivery_id,
                    'order_detail_id' => $order_detail_id
                ];
            }
        }
        
        $total_expected_remittance += $delivery_expected;
        $total_damaged_value += $delivery_damaged;
        $total_amount_to_collect += ($delivery_expected - $delivery_damaged);
        $processed_delivery_ids[] = $delivery_id;
    }
    
    $variance = $cash_received - $total_amount_to_collect;
    $remittance_status = 'Exact';
    if ($variance < -0.01) $remittance_status = 'Shortage';
    elseif ($variance > 0.01) $remittance_status = 'Surplus';
    
    if ($cash_received + 0.01 < $total_amount_to_collect) {
        throw new Exception(sprintf('Insufficient cash. Expected: ₱%.2f, Received: ₱%.2f', $total_amount_to_collect, $cash_received));
    }
    
    // Create sale record
    $columns_result = $conn->query("SHOW COLUMNS FROM sales");
    $existing_columns = [];
    while ($row = $columns_result->fetch()) $existing_columns[] = $row['Field'];
    
    $insert_fields = [];
    $values_placeholders = [];
    $params = [];
    
    if (in_array('User_ID', $existing_columns)) { $insert_fields[] = 'User_ID'; $values_placeholders[] = '?'; $params[] = $user_id; }
    elseif (in_array('user_id', $existing_columns)) { $insert_fields[] = 'user_id'; $values_placeholders[] = '?'; $params[] = $user_id; }
    
    if (in_array('Customer_ID', $existing_columns)) { $insert_fields[] = 'Customer_ID'; $values_placeholders[] = '?'; $params[] = $customer_id; }
    elseif (in_array('customer_id', $existing_columns)) { $insert_fields[] = 'customer_id'; $values_placeholders[] = '?'; $params[] = $customer_id; }
    
    if (in_array('status', $existing_columns)) { $insert_fields[] = 'status'; $values_placeholders[] = "'Completed'"; }
    if (in_array('remarks', $existing_columns)) { $insert_fields[] = 'remarks'; $values_placeholders[] = '?'; $params[] = $remarks; }
    if (in_array('expected_remittance', $existing_columns)) { $insert_fields[] = 'expected_remittance'; $values_placeholders[] = '?'; $params[] = $total_expected_remittance; }
    if (in_array('damaged_value', $existing_columns)) { $insert_fields[] = 'damaged_value'; $values_placeholders[] = '?'; $params[] = $total_damaged_value; }
    if (in_array('amount_to_collect', $existing_columns)) { $insert_fields[] = 'amount_to_collect'; $values_placeholders[] = '?'; $params[] = $total_amount_to_collect; }
    if (in_array('cash_received', $existing_columns)) { $insert_fields[] = 'cash_received'; $values_placeholders[] = '?'; $params[] = $cash_received; }
    if (in_array('variance', $existing_columns)) { $insert_fields[] = 'variance'; $values_placeholders[] = '?'; $params[] = $variance; }
    if (in_array('rider_user_id', $existing_columns)) { $insert_fields[] = 'rider_user_id'; $values_placeholders[] = '?'; $params[] = $rider_id; }
    if (in_array('remittance_status', $existing_columns)) { $insert_fields[] = 'remittance_status'; $values_placeholders[] = '?'; $params[] = $remittance_status; }
    if (in_array('created_at', $existing_columns)) { $insert_fields[] = 'created_at'; $values_placeholders[] = 'NOW()'; }
    
    if (empty($insert_fields)) {
        $conn->exec("INSERT INTO sales () VALUES ()");
    } else {
        $sql = "INSERT INTO sales (" . implode(', ', $insert_fields) . ") VALUES (" . implode(', ', $values_placeholders) . ")";
        $sale_stmt = $conn->prepare($sql);
        $sale_stmt->execute($params);
    }
    
    $sale_id = (int)$conn->lastInsertId();
    
    // Create sale_details
    foreach ($all_sale_details as $detail) {
        $sale_detail_stmt = $conn->prepare("INSERT INTO sale_details (Sale_ID, Product_ID, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
        $sale_detail_stmt->execute([$sale_id, $detail['product_id'], $detail['quantity'], $detail['unit_price'], $detail['subtotal']]);
        deductInventoryForSale($conn, $detail['product_id'], $detail['quantity']);
    }
    
    // Create sale_source links and update deliveries
    foreach ($processed_delivery_ids as $del_id) {
        $order_stmt = $conn->prepare("SELECT Order_ID FROM delivery WHERE Delivery_ID = ?");
        $order_stmt->execute([$del_id]);
        $order_id_val = (int)($order_stmt->fetchColumn() ?: 0);
        
        $source_stmt = $conn->prepare("INSERT INTO sale_source (Delivery_ID, Order_ID, Sale_ID) VALUES (?, ?, ?)");
        $source_stmt->execute([$del_id, $order_id_val > 0 ? $order_id_val : null, $sale_id]);
        
        $upd_dd = $conn->prepare("UPDATE delivery_detail SET status = 'delivered', updated_at = NOW() WHERE Delivery_ID = ?");
        $upd_dd->execute([$del_id]);
        
        $upd_del = $conn->prepare("UPDATE delivery SET delivery_status = 'Completed', updated_at = NOW() WHERE Delivery_ID = ?");
        $upd_del->execute([$del_id]);
        
        $assignedRiderId = riderGetUserIdByDeliveryId($conn, $del_id);
        if ($assignedRiderId > 0) {
            syncRiderAvailabilityForUser($conn, $assignedRiderId);
        }
        
        if ($order_id_val > 0) updateOrderAfterSaleForRemittance($conn, $order_id_val, $sale_id);
    }
    
    // Create rider_remittance_tracking record
    $table_check = $conn->query("SHOW TABLES LIKE 'rider_remittance_tracking'");
    if ($table_check && $table_check->rowCount() > 0) {
        foreach ($processed_delivery_ids as $del_id) {
            $order_stmt = $conn->prepare("SELECT Order_ID FROM delivery WHERE Delivery_ID = ?");
            $order_stmt->execute([$del_id]);
            $order_id_val = (int)($order_stmt->fetchColumn() ?: 0);
            
            $del_data = null;
            foreach ($deliveries as $d) { if ((int)$d['delivery_id'] === $del_id) { $del_data = $d; break; } }
            
            if ($del_data) {
                $del_expected = 0; $del_damaged = 0;
                foreach ($del_data['items'] ?? [] as $item) {
                    $oq = floatval($item['ordered_qty'] ?? 0);
                    $dq = floatval($item['damage_qty'] ?? 0);
                    $ps = $conn->prepare("SELECT unit_price FROM order_details WHERE Order_detail_ID = ?");
                    $ps->execute([(int)$item['order_detail_id']]);
                    $up = floatval($ps->fetchColumn() ?: 0);
                    $del_expected += $oq * $up;
                    $del_damaged += $dq * $up;
                }
                
                $ratio = $total_amount_to_collect > 0 ? ($del_expected - $del_damaged) / $total_amount_to_collect : 0;
                
                $track_stmt = $conn->prepare("INSERT INTO rider_remittance_tracking (rider_user_id, Sale_ID, Delivery_ID, expected_remittance, damaged_value, amount_to_collect, cash_received, variance, remittance_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $track_stmt->execute([$rider_id, $sale_id, $del_id, round($del_expected, 2), round($del_damaged, 2), round($del_expected - $del_damaged, 2), round($cash_received * $ratio, 2), round($variance * $ratio, 2), $remittance_status]);
            }
        }
    }
    
    // Record cash session entry
    $change_given = max(0, $cash_received - $total_amount_to_collect);
    $net_cash = max(0, $cash_received - $change_given);
    
    recordCashSessionEntry($conn, [
        'shift_id' => (int)$openShift['shift_id'],
        'entry_type' => 'delivery_remittance',
        'source_label' => 'Delivery Remittance - Rider: ' . $rider['rider_name'],
        'sale_id' => $sale_id,
        'delivery_id' => $processed_delivery_ids[0] > 0 ? (int)$processed_delivery_ids[0] : null,
        'order_id' => null,
        'gross_amount' => $total_amount_to_collect,
        'cash_received' => $cash_received,
        'change_given' => $change_given,
        'net_cash' => $net_cash,
        'User_ID' => $user_id,
    ]);
    
    $conn->commit();
    
    logActivity('SALE', sprintf("Recorded delivery remittance for Rider: %s | Sale #%d | Expected: ₱%.2f | Damaged: ₱%.2f | Collected: ₱%.2f | Variance: ₱%.2f (%s)", $rider['rider_name'], $sale_id, $total_expected_remittance, $total_damaged_value, $cash_received, $variance, $remittance_status), $sale_id);
    
    foreach ($processed_delivery_ids as $del_id) {
        publishRealtimeEvent(['event' => 'delivery.remittance_recorded', 'data' => ['delivery_id' => $del_id, 'sale_id' => $sale_id, 'status' => 'Completed', 'rider_user_id' => $rider_id]]);
    }

    // Send receipt email to customer
    try {
        $first_order_id = $processed_delivery_ids[0] ?? 0;
        if ($first_order_id > 0) {
            $cust_email_stmt = $conn->prepare("
                SELECT c.email, COALESCE(o.customer_name_snapshot, c.customer_name) as customer_name
                FROM customers c
                JOIN orders o ON o.Customer_ID = c.Customer_ID
                WHERE o.Order_ID = (
                    SELECT Order_ID FROM delivery WHERE Delivery_ID = ?
                )
            ");
            $cust_email_stmt->execute([$first_order_id]);
            $cust_info = $cust_email_stmt->fetch(PDO::FETCH_ASSOC);
            $customerEmail = $cust_info['email'] ?? '';
            $customerName = $cust_info['customer_name'] ?? 'Customer';

            if (!empty($customerEmail) && filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                $email_items = [];
                foreach ($all_sale_details as $d) {
                    $pn = $conn->prepare("SELECT product_name FROM products WHERE Product_ID = ?");
                    $pn->execute([$d['product_id']]);
                    $pname = (string)($pn->fetchColumn() ?: 'Product');
                    $email_items[] = [
                        'product_name' => $pname,
                        'quantity' => $d['quantity'],
                        'unit_price' => $d['unit_price'],
                        'subtotal' => $d['subtotal'],
                    ];
                }
                $saleDetails = [
                    'sale_id' => $sale_id,
                    'created_at' => date('Y-m-d H:i:s'),
                    'payment_type' => 'Cash',
                    'gross_total' => $total_expected_remittance,
                    'discount' => 0,
                    'total_amount' => $total_amount_to_collect,
                    'cash_received' => $cash_received,
                    'change_given' => max(0, $cash_received - $total_amount_to_collect),
                    'ar_balance' => 0,
                ];
                sendDeliverySaleReceiptEmail($customerEmail, $customerName, $saleDetails, $email_items);
            }
        }
    } catch (Throwable $e) {
        error_log("Failed to send receipt email: " . $e->getMessage());
    }
    
    echo json_encode(['success' => true, 'message' => 'Remittance recorded successfully.', 'sale_id' => $sale_id, 'expected_remittance' => round($total_expected_remittance, 2), 'damaged_value' => round($total_damaged_value, 2), 'amount_to_collect' => round($total_amount_to_collect, 2), 'cash_received' => round($cash_received, 2), 'variance' => round($variance, 2), 'remittance_status' => $remittance_status]);
    
} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    http_response_code(500);
    error_log('record_delivery_remittance error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An internal error occurred.']);
}

function getOrCreateWalkinCustomerId($conn) {
    $t = $conn->query("SHOW TABLES LIKE 'customers'");
    if (!$t || $t->rowCount() === 0) return 0;
    $stmt = $conn->prepare("SELECT Customer_ID FROM customers WHERE customer_name = 'Walk-in Customer' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row) return intval($row['Customer_ID']);
    $cols = []; $cr = $conn->query("SHOW COLUMNS FROM customers");
    if ($cr) while ($r = $cr->fetch()) $cols[] = $r['Field'];
    $fields = []; $params = [];
    if (in_array('customer_name', $cols)) { $fields[] = 'customer_name'; $params[] = 'Walk-in Customer'; }
    if (in_array('phone_number', $cols)) { $fields[] = 'phone_number'; $params[] = 'N/A'; }
    if (in_array('type', $cols)) { $fields[] = 'type'; $params[] = 'Regular'; }
    if (empty($fields)) return 0;
    $sql = "INSERT INTO customers (" . implode(', ', $fields) . ") VALUES (" . implode(', ', array_fill(0, count($fields), '?')) . ")";
    $ins = $conn->prepare($sql);
    if (!$ins || !$ins->execute($params)) return 0;
    return intval($conn->lastInsertId());
}

function deductInventoryForSale($conn, $product_id, $quantity) {
    $product_id = (int)$product_id; $quantity = (float)$quantity;
    if ($product_id <= 0 || $quantity <= 0) return;
    $physical_before = getPhysicalStock($conn, $product_id);
    $new_quantity = max(0, $physical_before - $quantity);
    $product_cols_stmt = $conn->query("SHOW COLUMNS FROM products");
    $product_cols = $product_cols_stmt ? $product_cols_stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    if (in_array('quantity', $product_cols, true)) {
        $update_stmt = $conn->prepare("UPDATE products SET quantity = ?, updated_at = NOW() WHERE Product_ID = ?");
        $update_stmt->execute([$new_quantity, $product_id]);
    }
    $user_id = $_SESSION['user_id'] ?? null;
    $ledger_stmt = $conn->prepare("INSERT INTO inventory_ledger (product_id, transaction_type, quantity_change, balance_after, handled_by, notes) VALUES (?, 'SALES', ?, ?, ?, 'Sale Transaction')");
    $ledger_stmt->execute([$product_id, -$quantity, $new_quantity, $user_id]);
}

function updateOrderAfterSaleForRemittance($conn, $order_id, $sale_id) {
    if ($order_id <= 0) return;
    $cols = []; $cr = $conn->query("SHOW COLUMNS FROM orders");
    if (!$cr) return;
    while ($r = $cr->fetch()) $cols[] = $r['Field'];
    $setParts = []; $params = [];
    if (in_array('order_status', $cols)) { $setParts[] = "order_status = ?"; $params[] = 'Completed'; }
    elseif (in_array('status', $cols)) { $setParts[] = "status = ?"; $params[] = 'Completed'; }
    if (in_array('Sale_ID', $cols)) { $setParts[] = "Sale_ID = ?"; $params[] = $sale_id; }
    if (in_array('completed_at', $cols)) $setParts[] = "completed_at = NOW()";
    if (in_array('updated_at', $cols)) $setParts[] = "updated_at = NOW()";
    if (empty($setParts)) return;
    $sql = "UPDATE orders SET " . implode(', ', $setParts) . " WHERE Order_ID = ?";
    $params[] = $order_id;
    $stmt = $conn->prepare($sql);
    if ($stmt) $stmt->execute($params);
}

function getPhysicalStock($conn, $product_id) {
    $product_id = (int)$product_id;
    $product_cols_stmt = $conn->query("SHOW COLUMNS FROM products");
    $product_cols = $product_cols_stmt ? $product_cols_stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    if (in_array('quantity', $product_cols, true)) {
        $stmt = $conn->prepare("SELECT COALESCE(quantity, 0) FROM products WHERE Product_ID = ?");
        $stmt->execute([$product_id]);
        return (float)$stmt->fetchColumn();
    }
    $inv_stmt = $conn->prepare("SELECT COALESCE(SUM(quantity), 0) FROM stockin_inventory WHERE Product_ID = ?");
    $inv_stmt->execute([$product_id]);
    return (float)$inv_stmt->fetchColumn();
}
