<?php

require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/exporter.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/module_access.php';

$request = new ApiRequest();
$response = new ApiResponse();

authMiddleware($request, $response, function() {});

$current_user_id = (int)$request->userId;
if (!isModuleAllowedForUser($conn, $current_user_id, 'inventory', true)) {
    $response->error('Inventory export access is currently restricted for your account.', 403);
}

$format = strtolower($request->get('format', 'csv'));
$category = $request->get('category', '');
$status = $request->get('status', '');

$conditions = ["p.is_discontinued = 0"];
$params = [];

if ($category) {
    $conditions[] = "p.category_id = ?";
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
    COALESCE(c.category_name, 'Uncategorized') as category,
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
LEFT JOIN product_categories c ON p.category_id = c.category_id
LEFT JOIN (
    SELECT Product_ID, MAX(updated_at) as last_updated 
    FROM stockin_inventory 
    GROUP BY Product_ID
) si ON p.Product_ID = si.Product_ID
WHERE {$whereClause}
ORDER BY category, p.product_name";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($data as &$row) {
    $row['unit_price'] = (float)$row['unit_price'];
    $row['current_stock'] = (int)$row['current_stock'];
    $row['min_stock'] = (int)$row['min_stock'];
}

try {
    $filename = 'inventory_' . date('Y-m-d');
    $title = 'Inventory Report - ' . date('F j, Y');
    
    exportData($data, ExportColumns::inventory(), $filename, $format, $title);
} catch (Exception $e) {
    $response->error('Export failed: ' . $e->getMessage(), 500);
}
