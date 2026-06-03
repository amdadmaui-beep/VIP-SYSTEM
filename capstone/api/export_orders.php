<?php
/**
 * Export Orders to CSV/Excel/PDF
 * 
 * Usage:
 *   /api/export_orders.php?format=csv&start=2024-01-01&end=2024-01-31
 *   /api/export_orders.php?format=excel&status=pending
 * 
 * Location: capstone/api/export_orders.php
 */

require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/exporter.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/module_access.php';

// Create request/response
$request = new ApiRequest();
$response = new ApiResponse();

// Authentication & Authorization
authMiddleware($request, $response, function() {});

$current_user_id = (int)$request->userId;
if (!isModuleAllowedForUser($conn, $current_user_id, 'orders', true)) {
    $response->error('Orders export access is currently restricted for your account.', 403);
}

// Get parameters
$format = strtolower($request->get('format', 'csv'));
$startDate = $request->get('start');
$endDate = $request->get('end');
$status = $request->get('status', '');

$ordersColumns = [];
$ordersColStmt = $conn->query("SHOW COLUMNS FROM orders");
if ($ordersColStmt) {
    foreach ($ordersColStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $ordersColumns[(string)($column['Field'] ?? '')] = true;
    }
}
$orderStatusCol = !empty($ordersColumns['order_status']) ? 'order_status' : 'status';
$orderDateExpr = !empty($ordersColumns['created_at']) ? 'DATE(COALESCE(o.created_at, o.order_date))' : 'DATE(o.order_date)';
$orderOrderExpr = !empty($ordersColumns['created_at']) ? 'COALESCE(o.created_at, o.order_date)' : 'o.order_date';
$deliveryDateSelect = !empty($ordersColumns['delivery_date']) ? 'o.delivery_date' : 'NULL AS delivery_date';
$paymentStatusSelect = !empty($ordersColumns['payment_status']) ? 'o.payment_status' : "'' AS payment_status";
$createdBySelect = !empty($ordersColumns['created_by']) ? "COALESCE(u.full_name, u.user_name, '') as created_by" : "'' AS created_by";
$createdByJoin = !empty($ordersColumns['created_by']) ? 'LEFT JOIN user u ON o.created_by = u.User_ID' : '';

// Validate dates
if (!$startDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $startDate = date('Y-m-01'); // First day of current month
}
if (!$endDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $endDate = date('Y-m-d'); // Today
}

// Build query
$params = [$startDate, $endDate];
$statusFilter = '';
if ($status) {
    $statusFilter = " AND o.{$orderStatusCol} = ?";
    $params[] = $status;
}

$query = "SELECT 
    o.Order_ID as order_id,
    c.customer_name,
    {$orderDateExpr} as order_date,
    {$deliveryDateSelect},
    COALESCE(o.total_amount, 0) as total_amount,
    o.{$orderStatusCol} as status,
    {$paymentStatusSelect},
    {$createdBySelect}
FROM orders o
LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
{$createdByJoin}
WHERE {$orderDateExpr} BETWEEN ? AND ?
{$statusFilter}
ORDER BY {$orderOrderExpr} DESC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format data
foreach ($data as &$row) {
    $row['total_amount'] = (float)$row['total_amount'];
}

// Export
try {
    $filename = 'orders_' . $startDate . '_to_' . $endDate;
    $title = 'Orders Report (' . $startDate . ' to ' . $endDate . ')';
    
    exportData($data, ExportColumns::orders(), $filename, $format, $title);
} catch (Exception $e) {
    $response->error('Export failed: ' . $e->getMessage(), 500);
}
