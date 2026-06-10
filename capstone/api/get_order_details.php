<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

ob_start();

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ob_start();
try {
    require_once '../includes/db.php';
    require_once '../includes/roles_helper.php';
    $management_ids = getManagementRoleIds($conn);
    requireRole(empty($management_ids) ? [1] : $management_ids);
    ob_end_clean();
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    error_log('get_order_details db error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit();
} catch (Error $e) {
    ob_end_clean();
    http_response_code(500);
    error_log('get_order_details db error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit();
}

if (!isset($conn) || !$conn) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

try {
    $order_id = intval($_GET['order_id'] ?? 0);

    if (!$order_id) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Order ID is required']);
        exit();
    }

    $order_status_col = 'order_status';
    $cols_res = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
    if ($cols_res && $cols_res->rowCount() > 0) {
        $row = $cols_res->fetch(PDO::FETCH_ASSOC);
        $order_status_col = $row['Field'];
    }

    $orders_col = [];
    $ocr = $conn->query('SHOW COLUMNS FROM orders');
    if ($ocr) {
        while ($r = $ocr->fetch(PDO::FETCH_ASSOC)) {
            $orders_col[$r['Field']] = true;
        }
    }

    $disc = !empty($orders_col['discount_amount']) ? 'o.discount_amount' : '0 AS discount_amount';
    $del_col_sel = !empty($orders_col['delivery_address']) ? 'o.delivery_address' : "'' AS delivery_address";
    $delivery_date_sel = !empty($orders_col['delivery_date']) ? 'o.delivery_date' : "NULL AS delivery_date";
    $cancel_reason_sel = !empty($orders_col['cancellation_reason']) ? 'o.cancellation_reason' : "NULL AS cancellation_reason";
    $cancel_remarks_sel = !empty($orders_col['cancellation_remarks']) ? 'o.cancellation_remarks' : "NULL AS cancellation_remarks";

    $order_query = "SELECT o.Order_ID, o.Customer_ID, o.order_date, {$delivery_date_sel}, o.{$order_status_col} AS order_status, o.total_amount,
                {$del_col_sel},
                {$disc},
                {$cancel_reason_sel},
                {$cancel_remarks_sel},
                c.customer_name, c.phone_number, c.address AS customer_address
                FROM orders o
                LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
                WHERE o.Order_ID = ? AND o.{$order_status_col} IS NOT NULL";
    $stmt = $conn->prepare($order_query);
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        ob_end_clean();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found or has been cancelled']);
        exit();
    }

    $del_addr = '';
    $delivery_rider = '';
    require_once __DIR__ . '/../includes/rider_availability_helper.php';
    $deliverySelect = ['delivery_address', 'delivered_by'];
    if (riderWorkflowHasColumn($conn, 'delivery', 'assigned_rider_id')) {
        $deliverySelect[] = 'assigned_rider_id';
    } elseif (riderWorkflowHasColumn($conn, 'delivery', 'delivered_by_user_id')) {
        $deliverySelect[] = 'delivered_by_user_id';
    }
    $dq = $conn->prepare(
        'SELECT ' . implode(', ', $deliverySelect) . ' FROM delivery WHERE Order_ID = ? ORDER BY Delivery_ID DESC LIMIT 1'
    );
    $dq->execute([$order_id]);
    $drow = $dq->fetch(PDO::FETCH_ASSOC);
    if ($drow) {
        if (!empty(trim((string)($drow['delivery_address'] ?? '')))) {
            $del_addr = trim($drow['delivery_address']);
        }
        if (!empty($drow['delivered_by'])) {
            $delivery_rider = trim((string)$drow['delivered_by']);
        } elseif (!empty($drow['assigned_rider_id'])) {
            $rider_stmt = $conn->prepare('SELECT full_name, user_name FROM user WHERE User_ID = ?');
            $rider_stmt->execute([(int)$drow['assigned_rider_id']]);
            $rider_row = $rider_stmt->fetch(PDO::FETCH_ASSOC);
            if ($rider_row) {
                $delivery_rider = $rider_row['full_name'] ?? $rider_row['user_name'] ?? '';
            }
        } elseif (!empty($drow['delivered_by_user_id'])) {
            $rider_stmt = $conn->prepare('SELECT full_name, user_name FROM user WHERE User_ID = ?');
            $rider_stmt->execute([(int)$drow['delivered_by_user_id']]);
            $rider_row = $rider_stmt->fetch(PDO::FETCH_ASSOC);
            if ($rider_row) {
                $delivery_rider = $rider_row['full_name'] ?? $rider_row['user_name'] ?? '';
            }
        }
    }
    $oaddr = trim((string)($order['delivery_address'] ?? ''));
    $caddr = trim((string)($order['customer_address'] ?? ''));
    $delivery_address_display = $oaddr !== '' ? $oaddr : ($del_addr !== '' ? $del_addr : $caddr);

    $order_info = 'Order #' . $order['Order_ID'];
    if (!empty($order['customer_name'])) {
        $order_info .= ' – ' . htmlspecialchars($order['customer_name']);
    }
    $order_info .= ' – ' . date('M d, Y', strtotime($order['order_date']));

    $products_col = [];
    $pcr = $conn->query('SHOW COLUMNS FROM products');
    if ($pcr) {
        while ($r = $pcr->fetch(PDO::FETCH_ASSOC)) {
            $products_col[$r['Field']] = true;
        }
    }
    $has_units_table = false;
    $units_table_check = $conn->query("SHOW TABLES LIKE 'units'");
    if ($units_table_check && $units_table_check->rowCount() > 0) {
        $has_units_table = true;
    }
    $unit_select = "'-' AS unit_name";
    $unit_join = '';
    if ($has_units_table && !empty($products_col['unit_id'])) {
        $unit_select = "COALESCE(u.unit_name, '-') AS unit_name";
        $unit_join = "LEFT JOIN units u ON p.unit_id = u.unit_id";
    } elseif (!empty($products_col['unit'])) {
        $unit_select = "COALESCE(p.unit, '-') AS unit_name";
    }

    $items_query = "SELECT od.ordered_qty, od.unit_price, od.Product_ID, p.product_name, {$unit_select}
               FROM order_details od
               LEFT JOIN products p ON od.Product_ID = p.Product_ID
               {$unit_join}
               WHERE od.Order_ID = ?";
    $stmt = $conn->prepare($items_query);
    $stmt->execute([$order_id]);
    $items_result = $stmt->fetchAll();

    $total_quantity = 0;
    $line_subtotal_sum = 0;
    $order_items = [];
    foreach ($items_result as $item) {
        $qty = floatval($item['ordered_qty']);
        $up = floatval($item['unit_price']);
        $line_sub = $qty * $up;
        $line_subtotal_sum += $line_sub;
        $total_quantity += $qty;
        $order_items[] = [
            'product_id' => intval($item['Product_ID']),
            'product_name' => $item['product_name'] ?? 'Unknown Product',
            'unit' => $item['unit_name'] ?? '-',
            'quantity' => $qty,
            'unit_price' => $up,
            'line_subtotal' => $line_sub,
        ];
    }

    $produced_bags = 0;
    $prod_tbl = $conn->query("SHOW TABLES LIKE 'productions'");
    if ($prod_tbl && $prod_tbl->rowCount() > 0) {
        $produced_query = "SELECT COALESCE(SUM(number_of_bags), 0) as total_bags
                      FROM productions
                      WHERE Order_ID = ? AND production_type = 'orders'";
        $stmt = $conn->prepare($produced_query);
        $stmt->execute([$order_id]);
        $produced_bags = intval($stmt->fetchColumn());
    }

    $required_bags = intval($total_quantity);
    $remaining_bags = max(0, $required_bags - $produced_bags);

    $discount_amount = floatval($order['discount_amount'] ?? 0);
    $grand_total = floatval($order['total_amount']);

    $output = ob_get_clean();
    if (!empty($output) && !json_decode($output)) {
        error_log('Unexpected output in get_order_details.php: ' . substr($output, 0, 200));
    }

    echo json_encode([
        'success' => true,
        'order_info' => $order_info,
        'order_id' => (int) $order['Order_ID'],
        'customer_id' => (int)($order['Customer_ID'] ?? 0),
        'order_date' => (string)($order['order_date'] ?? ''),
        'delivery_date' => (string)($order['delivery_date'] ?? ''),
        'order_status' => $order['order_status'],
        'cancellation_reason' => (string)($order['cancellation_reason'] ?? ''),
        'cancellation_remarks' => (string)($order['cancellation_remarks'] ?? ''),
        'delivery_rider' => $delivery_rider,
        'customer_name' => $order['customer_name'] ?? '',
        'phone_number' => $order['phone_number'] ?? '',
        'delivery_address' => $delivery_address_display,
        'subtotal' => round($line_subtotal_sum, 2),
        'discount_amount' => round($discount_amount, 2),
        'grand_total' => round($grand_total, 2),
        'required_bags' => $required_bags,
        'produced_bags' => $produced_bags,
        'remaining_bags' => $remaining_bags,
        'items' => $order_items,
    ]);
} catch (Throwable $e) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    error_log('get_order_details server error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An internal error occurred.']);
}
