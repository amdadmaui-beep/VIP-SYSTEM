<?php
/**
 * Export Customers to CSV/Excel/PDF
 * 
 * Usage:
 *   /api/export_customers.php?format=csv
 *   /api/export_customers.php?format=excel&status=active
 * 
 * Location: capstone/api/export_customers.php
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
if (!isModuleAllowedForUser($conn, $current_user_id, 'customers', true)) {
    $response->error('Customers export access is currently restricted for your account.', 403);
}

// Get parameters
$format = strtolower($request->get('format', 'csv'));
$status = $request->get('status', '');

// Build query conditions
$conditions = ["c.deleted_at IS NULL"];
$params = [];

if ($status) {
    $conditions[] = "c.status = ?";
    $params[] = $status;
} else {
    $conditions[] = "c.status = 'active'";
}

$whereClause = implode(' AND ', $conditions);

// Get customers with AR balances
$query = "SELECT 
    c.Customer_ID as customer_id,
    c.customer_name,
    c.phone_number,
    c.email,
    c.address,
    COALESCE(c.credit_limit, 0) as credit_limit,
    COALESCE(SUM(ar.amount_due), 0) as outstanding_balance,
    c.status,
    DATE(c.created_at) as created_date
FROM customers c
LEFT JOIN account_receivable ar ON c.Customer_ID = ar.Customer_ID 
    AND ar.status NOT IN ('Paid', 'Closed')
WHERE {$whereClause}
GROUP BY c.Customer_ID
ORDER BY c.customer_name ASC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format data
foreach ($data as &$row) {
    $row['credit_limit'] = (float)$row['credit_limit'];
    $row['outstanding_balance'] = (float)$row['outstanding_balance'];
}

// Export
try {
    $filename = 'customers_' . date('Y-m-d');
    $title = 'Customers Report - ' . date('F j, Y');
    
    exportData($data, ExportColumns::customers(), $filename, $format, $title);
} catch (Exception $e) {
    $response->error('Export failed: ' . $e->getMessage(), 500);
}
