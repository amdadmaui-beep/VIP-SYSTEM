<?php
/**
 * Export Activity Logs to CSV/Excel/PDF
 * 
 * Usage:
 *   /api/export_activity_logs.php?format=csv&start=2024-01-01&end=2024-01-31
 *   /api/export_activity_logs.php?format=excel&user=123
 * 
 * Location: capstone/api/export_activity_logs.php
 */

require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/exporter.php';
require_once __DIR__ . '/../includes/db.php';

// Create request/response
$request = new ApiRequest();
$response = new ApiResponse();

// Authentication (activity logs accessible to Owner, Manager, Rider)
authMiddleware($request, $response, function() {});

$allowedRoles = [1, 2, 4]; // Owner, Manager, Rider
$userRole = $request->userRole ?? 0;
if (!in_array($userRole, $allowedRoles, true)) {
    $response->error('Activity logs access is restricted.', 403);
}

// Get parameters
$format = strtolower($request->get('format', 'csv'));
$startDate = $request->get('start');
$endDate = $request->get('end');
$userId = $request->get('user');
$type = $request->get('type', '');

// Validate dates
if (!$startDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $startDate = date('Y-m-01'); // First day of current month
}
if (!$endDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $endDate = date('Y-m-d'); // Today
}

// Build query conditions
$conditions = ["DATE(al.Log_Time) BETWEEN ? AND ?"];
$params = [$startDate, $endDate];

if ($userId && is_numeric($userId)) {
    $conditions[] = "al.User_ID = ?";
    $params[] = $userId;
}

if ($type) {
    $conditions[] = "al.Activity_Type = ?";
    $params[] = $type;
}

$whereClause = implode(' AND ', $conditions);

$query = "SELECT 
    al.Log_ID as log_id,
    COALESCE(u.user_name, 'System') as user_name,
    al.Activity_Type as activity_type,
    al.Action_Details as action_details,
    al.Log_Time as log_time,
    COALESCE(al.IP_Address, 'N/A') as ip_address
FROM activity_logs al
LEFT JOIN user u ON al.User_ID = u.User_ID
WHERE {$whereClause}
ORDER BY al.Log_Time DESC
LIMIT 10000"; // Safety limit

$stmt = $conn->prepare($query);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format data
foreach ($data as &$row) {
    $row['log_time'] = date('Y-m-d H:i:s', strtotime($row['log_time']));
}

// Export
try {
    $filename = 'activity_logs_' . $startDate . '_to_' . $endDate;
    $title = 'Activity Logs Report (' . $startDate . ' to ' . $endDate . ')';
    
    exportData($data, ExportColumns::activityLogs(), $filename, $format, $title);
} catch (Exception $e) {
    $response->error('Export failed: ' . $e->getMessage(), 500);
}
