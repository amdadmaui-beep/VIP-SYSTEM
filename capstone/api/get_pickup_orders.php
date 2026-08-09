<?php
/**
 * Pickup Orders API
 * Lists open pickup orders with their line items for the counter-settlement UI.
 * Pickup orders have no delivery row; they are settled at the counter.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/module_access.php';
require_once __DIR__ . '/../includes/rate_limiter.php';

requireRole([1, 2, 3]);

enforceRateLimit(rateLimitKey('pickup_orders'), 120, 60);

try {
    $orders_col = [];
    $ocr = $conn->query('SHOW COLUMNS FROM orders');
    if ($ocr) {
        while ($r = $ocr->fetch(PDO::FETCH_ASSOC)) {
            $orders_col[$r['Field']] = true;
        }
    }
    if (empty($orders_col['order_type'])) {
        echo json_encode(['success' => false, 'message' => 'Pickup orders are not enabled in this database.']);
        exit();
    }

    $order_status_col = !empty($orders_col['order_status']) ? 'order_status' : 'status';
    $created_expr = !empty($orders_col['created_at']) ? 'COALESCE(o.created_at, o.order_date)' : 'o.order_date';

    $type_filter = (string)($_GET['type'] ?? 'open');
    $where = "o.order_type = 'pickup'";
    $params = [];
    if ($type_filter === 'completed') {
        $where .= " AND LOWER(o.{$order_status_col}) IN ('completed')";
    } else {
        $where .= " AND LOWER(o.{$order_status_col}) NOT IN ('completed', 'cancelled')";
    }

    $orders_query = "SELECT 
        o.Order_ID,
        o.order_date,
        o.{$order_status_col} AS order_status,
        o.total_amount,
        o.remarks,
        COALESCE(o.customer_name_snapshot, c.customer_name) AS customer_name,
        COALESCE(o.customer_phone_snapshot, c.phone_number) AS phone_number,
        c.Customer_ID,
        ({$created_expr}) AS created_at
    FROM orders o
    INNER JOIN customers c ON o.Customer_ID = c.Customer_ID
    WHERE {$where}
    ORDER BY {$created_expr} DESC
    LIMIT 300";

    $stmt = $conn->prepare($orders_query);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items_stmt = $conn->prepare("SELECT 
        od.Order_detail_ID,
        od.Order_ID,
        od.Product_ID,
        p.product_name,
        COALESCE(u.unit_name, '') AS unit_name,
        od.ordered_qty,
        od.unit_price,
        p.wholesale_price,
        p.is_discontinued
    FROM order_details od
    INNER JOIN products p ON od.Product_ID = p.Product_ID
    LEFT JOIN units u ON p.unit_id = u.unit_id
    WHERE od.Order_ID = ?
    ORDER BY od.Order_detail_ID ASC");

    foreach ($orders as &$order) {
        $order_id = (int)$order['Order_ID'];
        $items_stmt->execute([$order_id]);
        $order['items'] = [];
        while ($item = $items_stmt->fetch(PDO::FETCH_ASSOC)) {
            $wholesale = floatval($item['wholesale_price'] ?? 0);
            $fallback = floatval($item['unit_price'] ?? 0);
            $order['items'][] = [
                'order_detail_id' => (int)$item['Order_detail_ID'],
                'product_id' => (int)$item['Product_ID'],
                'product_name' => $item['product_name'] ?? '',
                'unit_name' => $item['unit_name'] ?? '',
                'ordered_qty' => floatval($item['ordered_qty'] ?? 0),
                'unit_price' => $fallback,
                'wholesale_price' => $wholesale > 0 ? $wholesale : $fallback,
                'is_discontinued' => (int)($item['is_discontinued'] ?? 0)
            ];
        }
        $order['order_id'] = $order_id;
        $order['customer_id'] = (int)($order['Customer_ID'] ?? 0);
        unset($order['Customer_ID']);
    }
    unset($order);

    echo json_encode(['success' => true, 'orders' => $orders]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('get_pickup_orders error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An internal error occurred while loading pickup orders.']);
}