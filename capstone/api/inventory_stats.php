<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db.php';

header('Content-Type: application/json');

try {
    // Check if stockin_inventory table exists
    $hasStockinTable = false;
    $tablesCheck = $conn->query("SHOW TABLES LIKE 'stockin_inventory'");
    if ($tablesCheck && $tablesCheck->num_rows > 0) {
        $hasStockinTable = true;
    }
    
    // Total Products
    $totalProducts = 0;
    $totalProductsQuery = "SELECT COUNT(*) as total FROM products WHERE is_discontinued = 0";
    $totalProductsResult = $conn->query($totalProductsQuery);
    if ($totalProductsResult) {
        $totalProducts = $totalProductsResult->fetch_assoc()['total'] ?? 0;
    }
    
    $lowStock = 0;
    $totalValue = 0;
    $outOfStock = 0;
    
    if ($hasStockinTable) {
        // Low Stock Items (< 10) - Using subquery for latest inventory per product
        $lowStockQuery = "SELECT COUNT(DISTINCT p.Product_ID) as low_stock_count
                          FROM products p
                          LEFT JOIN (
                              SELECT si1.Product_ID, si1.quantity
                              FROM stockin_inventory si1
                              INNER JOIN (
                                  SELECT Product_ID, MAX(updated_at) as max_updated
                                  FROM stockin_inventory
                                  GROUP BY Product_ID
                              ) si2 ON si1.Product_ID = si2.Product_ID 
                                    AND si1.updated_at = si2.max_updated
                          ) si ON p.Product_ID = si.Product_ID
                          WHERE p.is_discontinued = 0
                            AND (si.quantity IS NULL OR si.quantity < 10)";
        $lowStockResult = $conn->query($lowStockQuery);
        if ($lowStockResult) {
            $lowStock = $lowStockResult->fetch_assoc()['low_stock_count'] ?? 0;
        }
        
        // Total Inventory Value
        $totalValueQuery = "SELECT COALESCE(SUM(
                              (SELECT quantity FROM stockin_inventory 
                               WHERE Product_ID = p.Product_ID 
                               ORDER BY updated_at DESC LIMIT 1) * p.wholesale_price
                            ), 0) as total_value
                            FROM products p
                            WHERE p.is_discontinued = 0";
        $totalValueResult = $conn->query($totalValueQuery);
        if ($totalValueResult) {
            $totalValue = $totalValueResult->fetch_assoc()['total_value'] ?? 0;
        }
        
        // Out of Stock Items - Using subquery for latest inventory per product
        $outOfStockQuery = "SELECT COUNT(DISTINCT p.Product_ID) as out_of_stock_count
                            FROM products p
                            LEFT JOIN (
                                SELECT si1.Product_ID, si1.quantity
                                FROM stockin_inventory si1
                                INNER JOIN (
                                    SELECT Product_ID, MAX(updated_at) as max_updated
                                    FROM stockin_inventory
                                    GROUP BY Product_ID
                                ) si2 ON si1.Product_ID = si2.Product_ID 
                                      AND si1.updated_at = si2.max_updated
                            ) si ON p.Product_ID = si.Product_ID
                            WHERE p.is_discontinued = 0
                              AND (si.quantity IS NULL OR si.quantity <= 0)";
        $outOfStockResult = $conn->query($outOfStockQuery);
        if ($outOfStockResult) {
            $outOfStock = $outOfStockResult->fetch_assoc()['out_of_stock_count'] ?? 0;
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'total_products' => intval($totalProducts),
            'low_stock' => intval($lowStock),
            'total_value' => floatval($totalValue),
            'out_of_stock' => intval($outOfStock)
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

$conn->close();
?>
