<?php
/**
 * Export Inventory to CSV/Excel/PDF
 * 
 * Usage:
 *   /api/export_inventory.php?format=csv
 *   /api/export_inventory.php?format=excel&category=ice-cubes
 * 
 * Location: capstone/api/export_inventory.php
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
if (!isModuleAllowedForUser($conn, $current_user_id, 'inventory', true)) {
    $response->error('Inventory export access is currently restricted for your account.', 403);
}

// Get parameters
$format = strtolower($request->get('format', 'csv'));
$category = $request->get('category', '');
$status = $request->get('status', '');

// Build query conditions
$conditions = ["p.is_discontinued = 0"];
$params = [];

if ($category) {
    $conditions[] = "p.category = ?";
    $params[] = $category;
}

if ($status === 'low') {
    $conditions[] = "p.current_stock <= p.min_stock";
} elseif ($status === 'out') {
    $conditions[] = "p.current_stock = 0";
}

$whereClause = implode(' AND ', $conditions);

$query = "SELECT 
    p.Product_ID as product_id,
    p.product_name,
    p.category,
    p.current_stock,
    p.min_stock,
    p.unit_price,
    CASE 
        WHEN p.current_stock = 0 THEN 'Out of Stock'
        WHEN p.current_stock <= p.min_stock THEN 'Low Stock'
        ELSE 'In Stock'
    END as status,
    si.last_updated as last_updated
FROM products p
LEFT JOIN (
    SELECT Product_ID, MAX(updated_at) as last_updated 
    FROM stockin_inventory 
    GROUP BY Product_ID
) si ON p.Product_ID = si.Product_ID
WHERE {$whereClause}
ORDER BY p.category, p.product_name";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format data
foreach ($data as &$row) {
    $row['unit_price'] = (float)$row['unit_price'];
    $row['current_stock'] = (int)$row['current_stock'];
    $row['min_stock'] = (int)$row['min_stock'];
}

// Export
try {
    $filename = 'inventory_' . date('Y-m-d');
    $title = 'Inventory Report - ' . date('F j, Y');
    
    exportData($data, ExportColumns::inventory(), $filename, $format, $title);
} catch (Exception $e) {
    $response->error('Export failed: ' . $e->getMessage(), 500);
}
