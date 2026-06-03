<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/rider_availability_helper.php';
require_once __DIR__ . '/../includes/cash_session_helper.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../realtime/publish_event.php';
require_once __DIR__ . '/../includes/mailer.php';

$user_role = (int)($_SESSION['user_role'] ?? 0);
if (!in_array($user_role, [1, 2, 3, 4], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($user_role === 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Your account (Owner) is restricted to view-only access.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

if (!validateCsrfToken(false)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired security token. Please refresh the page and try again.']);
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$rider_id = intval($_POST['rider_id'] ?? 0);
$delivery_id = intval($_POST['delivery_id'] ?? 0);
$order_id = intval($_POST['order_id'] ?? 0);
$cash_received = max(0, floatval($_POST['cash_received'] ?? 0));
$deliveries_json = $_POST['deliveries'] ?? '[]';
$deliveries = json_decode($deliveries_json, true);

if ($delivery_id <= 0 && (!empty($deliveries) && is_array($deliveries))) {
    $delivery_id = intval($deliveries[0]['delivery_id'] ?? 0);
    $order_id = intval($deliveries[0]['order_id'] ?? 0);
}

if ($delivery_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Delivery ID is required']);
    exit();
}

$openShift = getOpenCashShiftForUser($conn, $user_id);
if (!$openShift) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Open a cashier shift before posting AR deliveries.']);
    exit();
}

$conn->beginTransaction();
try {
    // Verify delivery exists and is in valid status
    $del_check = $conn->prepare("SELECT d.Delivery_ID, d.Order_ID, d.delivery_status, o.Customer_ID, o.total_amount, o.is_ar
                                  FROM delivery d 
                                  LEFT JOIN orders o ON d.Order_ID = o.Order_ID 
                                  WHERE d.Delivery_ID = ?");
    $del_check->execute([$delivery_id]);
    $delivery_info = $del_check->fetch(PDO::FETCH_ASSOC);

    if (!$delivery_info) {
        throw new Exception("Delivery #{$delivery_id} not found");
    }

    $allowed_statuses = ['Delivered', 'Remitted', 'Completed', 'Delivered (Pending Cash Turnover)'];
    if (!in_array($delivery_info['delivery_status'], $allowed_statuses, true)) {
        throw new Exception("Delivery #{$delivery_id} is not yet delivered. Status: {$delivery_info['delivery_status']}");
    }

    // Check if already sold
    $sold_check = $conn->prepare("SELECT COUNT(*) FROM sale_source WHERE Delivery_ID = ? AND Sale_ID IS NOT NULL");
    $sold_check->execute([$delivery_id]);
    if ((int)$sold_check->fetchColumn() > 0) {
        throw new Exception("Delivery #{$delivery_id} has already been recorded as a sale");
    }

    $customer_id = intval($delivery_info['Customer_ID'] ?? 0);
    if ($customer_id <= 0) {
        throw new Exception("Order has no valid customer");
    }

    // Fetch customer info
    $cust_stmt = $conn->prepare("SELECT customer_name, email, aging_days FROM customers WHERE Customer_ID = ?");
    $cust_stmt->execute([$customer_id]);
    $customer_data = $cust_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer_data) {
        throw new Exception("Customer not found");
    }
    $customer_name = $customer_data['customer_name'] ?? 'Customer';
    $customer_email = $customer_data['email'] ?? '';
    $aging_days = intval($customer_data['aging_days'] ?? 0);
    if ($aging_days <= 0) $aging_days = 30;

    // If deliveries param is empty, build from delivery_detail
    if (empty($deliveries) || !is_array($deliveries)) {
        $detail_stmt = $conn->prepare("
            SELECT dd.Delivery_Detail_ID, dd.Order_detail_ID, od.Product_ID, od.ordered_qty, od.unit_price,
                   COALESCE(dd.damage_qty, 0) as damage_qty
            FROM delivery_detail dd
            INNER JOIN order_details od ON dd.Order_detail_ID = od.Order_detail_ID
            WHERE dd.Delivery_ID = ?
        ");
        $detail_stmt->execute([$delivery_id]);
        $detail_items = $detail_stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($detail_items)) {
            $detail_stmt = $conn->prepare("
                SELECT 0 as Delivery_Detail_ID, od.Order_detail_ID, od.Product_ID, od.ordered_qty, od.unit_price, 0 as damage_qty
                FROM order_details od
                WHERE od.Order_ID = ?
            ");
            $detail_stmt->execute([$order_id]);
            $detail_items = $detail_stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $deliveries = [[
            'delivery_id' => $delivery_id,
            'order_id' => $order_id,
            'items' => array_map(function($item) {
                return [
                    'delivery_detail_id' => (int)$item['Delivery_Detail_ID'],
                    'order_detail_id' => (int)$item['Order_detail_ID'],
                    'product_id' => (int)$item['Product_ID'],
                    'ordered_qty' => (float)$item['ordered_qty'],
                    'damage_qty' => (float)($item['damage_qty'] ?? 0),
                ];
            }, $detail_items)
        ]];
    }

    $total_expected_remittance = 0;
    $total_damaged_value = 0;
    $total_amount_to_collect = 0;
    $all_sale_details = [];
    $processed_delivery_ids = [];

    foreach ($deliveries as $delivery_data) {
        $pd_delivery_id = intval($delivery_data['delivery_id'] ?? 0);
        $pd_order_id = intval($delivery_data['order_id'] ?? 0);
        $items = $delivery_data['items'] ?? [];

        if ($pd_delivery_id <= 0) throw new Exception('Invalid delivery ID');

        // Re-fetch delivery info for this specific delivery
        $del_check2 = $conn->prepare("SELECT d.Delivery_ID, d.Order_ID, d.delivery_status, o.Customer_ID 
                                       FROM delivery d 
                                       LEFT JOIN orders o ON d.Order_ID = o.Order_ID 
                                       WHERE d.Delivery_ID = ?");
        $del_check2->execute([$pd_delivery_id]);
        $delivery_info2 = $del_check2->fetch(PDO::FETCH_ASSOC);
        if (!$delivery_info2) throw new Exception("Delivery #{$pd_delivery_id} not found");

        // Check if already sold
        $sold_check2 = $conn->prepare("SELECT COUNT(*) FROM sale_source WHERE Delivery_ID = ? AND Sale_ID IS NOT NULL");
        $sold_check2->execute([$pd_delivery_id]);
        if ((int)$sold_check2->fetchColumn() > 0) {
            throw new Exception("Delivery #{$pd_delivery_id} has already been recorded as a sale");
        }

        $delivery_expected = 0;
        $delivery_damaged = 0;

        foreach ($items as $item) {
            $product_id = intval($item['product_id'] ?? 0);
            $ordered_qty = floatval($item['ordered_qty'] ?? 0);
            $delivery_detail_id = intval($item['delivery_detail_id'] ?? 0);
            $order_detail_id = intval($item['order_detail_id'] ?? 0);

            // Get reported damage
            $dmg_stmt = $conn->prepare("
                SELECT COALESCE(SUM(r.damaged_qty), 0) 
                FROM delivery_damage_report r
                LEFT JOIN damage_report_reviews rev ON rev.report_id = r.report_id
                WHERE r.Delivery_ID = ? AND r.Order_detail_ID = ? 
                  AND COALESCE(rev.status, 'pending_review') IN ('pending_review', 'approved')
            ");
            $dmg_stmt->execute([$pd_delivery_id, $order_detail_id]);
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
                    'delivery_id' => $pd_delivery_id,
                    'order_detail_id' => $order_detail_id
                ];
            }
        }

        $total_expected_remittance += $delivery_expected;
        $total_damaged_value += $delivery_damaged;
        $total_amount_to_collect += ($delivery_expected - $delivery_damaged);
        $processed_delivery_ids[] = $pd_delivery_id;
    }

    $ar_balance = max(0, $total_amount_to_collect - $cash_received);

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
    if (in_array('remarks', $existing_columns)) { $insert_fields[] = 'remarks'; $values_placeholders[] = '?'; $params[] = 'AR delivery'; }
    if (in_array('expected_remittance', $existing_columns)) { $insert_fields[] = 'expected_remittance'; $values_placeholders[] = '?'; $params[] = $total_expected_remittance; }
    if (in_array('damaged_value', $existing_columns)) { $insert_fields[] = 'damaged_value'; $values_placeholders[] = '?'; $params[] = $total_damaged_value; }
    if (in_array('amount_to_collect', $existing_columns)) { $insert_fields[] = 'amount_to_collect'; $values_placeholders[] = '?'; $params[] = $total_amount_to_collect; }
    if (in_array('cash_received', $existing_columns)) { $insert_fields[] = 'cash_received'; $values_placeholders[] = '?'; $params[] = $cash_received; }
    if (in_array('variance', $existing_columns)) { $insert_fields[] = 'variance'; $values_placeholders[] = '?'; $params[] = 0; }
    if (in_array('rider_user_id', $existing_columns)) { $insert_fields[] = 'rider_user_id'; $values_placeholders[] = '?'; $params[] = $rider_id; }
    if (in_array('remittance_status', $existing_columns)) { $insert_fields[] = 'remittance_status'; $values_placeholders[] = "'AR'"; }
    if (in_array('created_at', $existing_columns)) { $insert_fields[] = 'created_at'; $values_placeholders[] = 'NOW()'; }

    if (empty($insert_fields)) {
        $conn->exec("INSERT INTO sales () VALUES ()");
    } else {
        $sql = "INSERT INTO sales (" . implode(', ', $insert_fields) . ") VALUES (" . implode(', ', $values_placeholders) . ")";
        $sale_stmt = $conn->prepare($sql);
        $sale_stmt->execute($params);
    }

    $sale_id = (int)$conn->lastInsertId();

    // Create sale_details and deduct inventory
    foreach ($all_sale_details as $detail) {
        $sale_detail_stmt = $conn->prepare("INSERT INTO sale_details (Sale_ID, Product_ID, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
        $sale_detail_stmt->execute([$sale_id, $detail['product_id'], $detail['quantity'], $detail['unit_price'], $detail['subtotal']]);
        deductInventoryForSale($conn, $detail['product_id'], $detail['quantity']);
    }

    // Create sale_source links and update deliveries
    foreach ($processed_delivery_ids as $pd_id) {
        $order_stmt = $conn->prepare("SELECT Order_ID FROM delivery WHERE Delivery_ID = ?");
        $order_stmt->execute([$pd_id]);
        $oid = (int)($order_stmt->fetchColumn() ?: 0);

        $source_stmt = $conn->prepare("INSERT INTO sale_source (Delivery_ID, Order_ID, Sale_ID) VALUES (?, ?, ?)");
        $source_stmt->execute([$pd_id, $oid > 0 ? $oid : null, $sale_id]);

        $upd_dd = $conn->prepare("UPDATE delivery_detail SET status = 'delivered', updated_at = NOW() WHERE Delivery_ID = ?");
        $upd_dd->execute([$pd_id]);

        $upd_del = $conn->prepare("UPDATE delivery SET delivery_status = 'Completed', updated_at = NOW() WHERE Delivery_ID = ?");
        $upd_del->execute([$pd_id]);

        $assignedRiderId = riderGetUserIdByDeliveryId($conn, $pd_id);
        if ($assignedRiderId > 0) {
            syncRiderAvailabilityForUser($conn, $assignedRiderId);
        }

        if ($oid > 0) updateOrderAfterSaleForRemittance($conn, $oid, $sale_id);
    }

    // Create the AR record for the remaining balance
    if ($ar_balance > 0) {
        $due_date = date('Y-m-d', strtotime('+' . $aging_days . ' days'));
        $ar_status = $cash_received > 0 ? 'Partial' : 'Open';

        $ar_stmt = $conn->prepare("
            INSERT INTO account_receivable (Sale_ID, Customer_ID, invoice_amount, opening_balance, amount_due, due_date, status, invoice_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE())
        ");
        $ar_stmt->execute([$sale_id, $customer_id, $total_amount_to_collect, $ar_balance, $ar_balance, $due_date, $ar_status]);
        $ar_id = (int)$conn->lastInsertId();

        // If customer paid something, record the first AR payment
        if ($cash_received > 0) {
            $pay_stmt = $conn->prepare("INSERT INTO ar_payment (payment_date, amount_paid, remaining_balance, collected_by) VALUES (CURDATE(), ?, ?, ?)");
            $pay_stmt->execute([$cash_received, 0, $user_id]);
            $payment_id = (int)$conn->lastInsertId();

            // Link payment to AR
            $singil_stmt = $conn->prepare("INSERT INTO singil (AR_ID, Payment_ID) VALUES (?, ?)");
            $singil_stmt->execute([$ar_id, $payment_id]);
        }
    }

    // Record cash session entry for collected amount
    if ($cash_received > 0 && function_exists('recordCashSessionEntry')) {
        try {
            recordCashSessionEntry($conn, $openShift['shift_id'] ?? 0, 'sale', $sale_id, $cash_received, "AR delivery collection - Order #{$order_id}");
        } catch (Throwable $e) {
            error_log("Failed to record cash session for AR post: " . $e->getMessage());
        }
    }

    // Send receipt email
    $email_sent = false;
    if (!empty($customer_email) && filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
        try {
            $email_items = [];
            foreach ($all_sale_details as $d) {
                $prod_name_stmt = $conn->prepare("SELECT product_name FROM products WHERE Product_ID = ?");
                $prod_name_stmt->execute([$d['product_id']]);
                $pname = (string)($prod_name_stmt->fetchColumn() ?: 'Product');
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
                'payment_type' => $cash_received > 0 ? 'Cash + AR' : 'AR',
                'gross_total' => $total_expected_remittance,
                'discount' => 0,
                'total_amount' => $total_amount_to_collect,
                'cash_received' => $cash_received,
                'change_given' => 0,
                'ar_balance' => $ar_balance,
            ];

            $mailResult = sendDeliverySaleReceiptEmail($customer_email, $customer_name, $saleDetails, $email_items);
            $email_sent = $mailResult['ok'] ?? false;
        } catch (Throwable $e) {
            error_log("Failed to send AR receipt email: " . $e->getMessage());
        }
    }

    $conn->commit();

    if (function_exists('publishRealtimeEvent')) {
        publishRealtimeEvent([
            'event' => 'delivery.ar_posted',
            'data' => [
                'delivery_id' => $delivery_id,
                'order_id' => $order_id,
                'sale_id' => $sale_id,
                'ar_balance' => $ar_balance,
            ]
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Delivery posted to AR successfully',
        'sale_id' => $sale_id,
        'ar_balance' => $ar_balance,
        'email_sent' => $email_sent,
        'expected_remittance' => $total_expected_remittance,
        'damaged_value' => $total_damaged_value,
        'cash_received' => $cash_received,
        'variance' => 0,
        'remittance_status' => 'AR'
    ]);
    exit();

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    http_response_code(500);
    error_log('delivery_ar_post error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An internal error occurred.']);
    exit();
}

// Helper functions
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
    $ledger_stmt = $conn->prepare("INSERT INTO inventory_ledger (product_id, transaction_type, quantity_change, balance_after, handled_by, notes) VALUES (?, 'SALES', ?, ?, ?, 'AR Sale Transaction')");
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
