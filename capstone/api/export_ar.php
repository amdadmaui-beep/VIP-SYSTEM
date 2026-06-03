<?php
/**
 * Export Accounts Receivable to CSV/Excel/PDF
 * 
 * Usage:
 *   /api/export_ar.php?format=csv&status=overdue
 *   /api/export_ar.php?format=excel&start=2024-01-01&end=2024-01-31
 * 
 * Location: capstone/api/export_ar.php
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
if (!isModuleAllowedForUser($conn, $current_user_id, 'accounts_receivable', true)) {
    $response->error('AR export access is currently restricted for your account.', 403);
}

// Get parameters
$format = strtolower($request->get('format', 'csv'));
$status = $request->get('status', '');
$startDate = $request->get('start');
$endDate = $request->get('end');

// Build query conditions
$conditions = ["ar.amount_due > 0"];
$params = [];

if ($status) {
    $conditions[] = "ar.status = ?";
    $params[] = $status;
} else {
    $conditions[] = "ar.status NOT IN ('Paid', 'Closed')";
}

if ($startDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $conditions[] = "ar.invoice_date >= ?";
    $params[] = $startDate;
}

if ($endDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $conditions[] = "ar.invoice_date <= ?";
    $params[] = $endDate;
}

$whereClause = implode(' AND ', $conditions);

$query = "SELECT 
    ar.AR_ID as ar_id,
    c.customer_name,
    c.phone_number,
    ar.invoice_amount,
    ar.amount_due,
    ar.invoice_date,
    ar.due_date,
    ar.status,
    DATEDIFF(CURDATE(), ar.due_date) as days_overdue,
    s.sale_receipt_number as invoice_number
FROM account_receivable ar
LEFT JOIN customers c ON ar.Customer_ID = c.Customer_ID
LEFT JOIN sales s ON ar.Sale_ID = s.Sale_ID
WHERE {$whereClause}
ORDER BY ar.due_date ASC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate days overdue for display
foreach ($data as &$row) {
    $row['days_overdue'] = max(0, (int)$row['days_overdue']);
    $row['invoice_amount'] = (float)$row['invoice_amount'];
    $row['amount_due'] = (float)$row['amount_due'];
    $row['invoice_number'] = $row['invoice_number'] ?? 'N/A';
}

// Export
try {
    $dateSuffix = $startDate && $endDate ? $startDate . '_to_' . $endDate : date('Y-m-d');
    $filename = 'accounts_receivable_' . $dateSuffix;
    $title = 'Accounts Receivable Report';
    
    exportData($data, ExportColumns::ar(), $filename, $format, $title);
} catch (Exception $e) {
    $response->error('Export failed: ' . $e->getMessage(), 500);
}
