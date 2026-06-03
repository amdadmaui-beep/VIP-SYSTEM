<?php
require_once '../includes/auth.php';
require_once '../includes/db.php';

header('Content-Type: application/json');

try {
    $productId = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;

    if ($productId <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid product ID.'
        ]);
        exit();
    }

    $query = "SELECT 
    p.Inventory_ID as Production_ID,
    p.date_in as production_date,
    p.production_type,
    p.produced_qty,
    p.bag_size,
    p.number_of_bags,
    pr.product_name,
    u_units.unit_name,
    u.user_name AS handled_by
FROM stockin_inventory p
INNER JOIN products pr ON p.Product_ID = pr.Product_ID
LEFT JOIN units u_units ON pr.unit_id = u_units.unit_id
LEFT JOIN user u ON p.handled_by = u.User_ID
WHERE p.Product_ID = ?
ORDER BY p.date_in DESC, p.Inventory_ID DESC
LIMIT 100";

    $stmt = $conn->prepare($query);
    $stmt->execute([$productId]);
    $results = $stmt->fetchAll();

    $history = [];
    foreach ($results as $row) {
        $history[] = [
            'production_id' => (int) $row['Production_ID'],
            'production_date' => $row['production_date'],
            'production_type' => $row['production_type'],
            'produced_qty' => (float) $row['produced_qty'],
            'bag_size' => $row['bag_size'] !== null ? (float) $row['bag_size'] : null,
            'number_of_bags' => $row['number_of_bags'] !== null ? (int) $row['number_of_bags'] : null,
            'product_name' => $row['product_name'],
            'unit' => $row['unit_name'],
            'handled_by' => $row['handled_by'] ?? 'N/A',
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => $history,
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}

