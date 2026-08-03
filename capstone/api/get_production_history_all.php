<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

try {
    $query = "SELECT 
    p.Inventory_ID as Production_ID,
    p.date_in as production_date,
    p.production_type,
    p.produced_qty,
    p.bag_size,
    p.number_of_bags,
    p.Order_ID,
    pr.product_name,
    u_units.unit_name as unit,
    u.user_name as handled_by,
    o.Order_ID as order_id_display,
    o.order_date as order_date_display
FROM stockin_inventory p
INNER JOIN products pr ON p.Product_ID = pr.Product_ID
LEFT JOIN units u_units ON pr.unit_id = u_units.unit_id
LEFT JOIN user u ON p.handled_by = u.User_ID
LEFT JOIN orders o ON p.Order_ID = o.Order_ID
ORDER BY p.date_in DESC, p.Inventory_ID DESC
LIMIT 100";

    $result = $conn->query($query);

    if (!$result) {
        echo json_encode([
            'success' => false,
            'message' => 'Error fetching production history.',
        ]);
        exit();
    }

    $history = [];
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $history[] = $row;
    }

    echo json_encode([
        'success' => true,
        'data' => $history,
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
    ]);
}

