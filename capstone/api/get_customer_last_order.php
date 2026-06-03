<?php
declare(strict_types=1);

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles_helper.php';

$management_ids = getManagementRoleIds($conn);
requireRole(empty($management_ids) ? [1] : $management_ids);

try {
    $customer_id = (int)($_GET['customer_id'] ?? 0);
    $exclude_order_id = (int)($_GET['exclude_order_id'] ?? 0);

    if ($customer_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Customer is required.']);
        exit();
    }

    $order_status_col = 'order_status';
    $cols_res = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
    if ($cols_res && $cols_res->rowCount() > 0) {
        $row = $cols_res->fetch(PDO::FETCH_ASSOC);
        $order_status_col = (string)$row['Field'];
    }

    $orders_col = [];
    $ocr = $conn->query('SHOW COLUMNS FROM orders');
    if ($ocr) {
        while ($r = $ocr->fetch(PDO::FETCH_ASSOC)) {
            $orders_col[(string)$r['Field']] = true;
        }
    }

    $delivery_address_select = !empty($orders_col['delivery_address'])
        ? 'o.delivery_address'
        : "'' AS delivery_address";
    $delivery_date_select = !empty($orders_col['delivery_date'])
        ? 'o.delivery_date'
        : 'NULL AS delivery_date';
    $order_sort_expr = !empty($orders_col['created_at'])
        ? 'COALESCE(o.created_at, o.order_date)'
        : 'o.order_date';

    $where = ["o.Customer_ID = ?", "LOWER(o.{$order_status_col}) <> 'cancelled'"];
    $params = [$customer_id];
    if ($exclude_order_id > 0) {
        $where[] = 'o.Order_ID <> ?';
        $params[] = $exclude_order_id;
    }

    $order_sql = "
        SELECT
            o.Order_ID,
            o.order_date,
            {$delivery_date_select},
            {$delivery_address_select},
            o.total_amount,
            o.{$order_status_col} AS order_status,
            c.customer_name,
            c.phone_number,
            c.address AS customer_address
        FROM orders o
        INNER JOIN customers c ON c.Customer_ID = o.Customer_ID
        WHERE " . implode(' AND ', $where) . "
        AND EXISTS (
            SELECT 1
            FROM order_details od
            WHERE od.Order_ID = o.Order_ID
        )
        ORDER BY
            {$order_sort_expr} DESC,
            o.Order_ID DESC
        LIMIT 1
    ";

    $stmt = $conn->prepare($order_sql);
    $stmt->execute($params);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No previous order found for this customer.']);
        exit();
    }

    $products_col = [];
    $pcr = $conn->query('SHOW COLUMNS FROM products');
    if ($pcr) {
        while ($r = $pcr->fetch(PDO::FETCH_ASSOC)) {
            $products_col[(string)$r['Field']] = true;
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
        $unit_join = "LEFT JOIN units u ON u.unit_id = p.unit_id";
    } elseif (!empty($products_col['unit'])) {
        $unit_select = "COALESCE(p.unit, '-') AS unit_name";
    }

    $items_sql = "
        SELECT
            od.Product_ID,
            od.ordered_qty,
            od.unit_price,
            p.product_name,
            {$unit_select}
        FROM order_details od
        LEFT JOIN products p ON p.Product_ID = od.Product_ID
        {$unit_join}
        WHERE od.Order_ID = ?
        ORDER BY od.Order_detail_ID ASC
    ";
    $items_stmt = $conn->prepare($items_sql);
    $items_stmt->execute([(int)$order['Order_ID']]);
    $items = [];
    while ($row = $items_stmt->fetch(PDO::FETCH_ASSOC)) {
        $items[] = [
            'product_id' => (int)($row['Product_ID'] ?? 0),
            'product_name' => (string)($row['product_name'] ?? 'Unknown Product'),
            'unit' => (string)($row['unit_name'] ?? '-'),
            'quantity' => (float)($row['ordered_qty'] ?? 0),
            'unit_price' => (float)($row['unit_price'] ?? 0),
        ];
    }

    $delivery_address = trim((string)($order['delivery_address'] ?? ''));
    if ($delivery_address === '') {
        $delivery_stmt = $conn->prepare("SELECT delivery_address FROM delivery WHERE Order_ID = ? ORDER BY Delivery_ID DESC LIMIT 1");
        $delivery_stmt->execute([(int)$order['Order_ID']]);
        $delivery_address = trim((string)($delivery_stmt->fetchColumn() ?: ''));
    }
    if ($delivery_address === '') {
        $delivery_address = trim((string)($order['customer_address'] ?? ''));
    }

    echo json_encode([
        'success' => true,
        'order_id' => (int)$order['Order_ID'],
        'customer_id' => $customer_id,
        'customer_name' => (string)($order['customer_name'] ?? ''),
        'phone_number' => (string)($order['phone_number'] ?? ''),
        'order_status' => (string)($order['order_status'] ?? ''),
        'order_date' => (string)($order['order_date'] ?? ''),
        'delivery_date' => (string)($order['delivery_date'] ?? ''),
        'delivery_address' => $delivery_address,
        'discount_amount' => 0,
        'total_amount' => round((float)($order['total_amount'] ?? 0), 2),
        'items' => $items,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('get_customer_last_order error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load customer order history.',
    ]);
}
