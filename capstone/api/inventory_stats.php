<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db.php';

header('Content-Type: application/json');

try {
    // Check if stockin_inventory table exists
    $tablesCheck = $conn->query("SHOW TABLES LIKE 'stockin_inventory'");
    $hasStockinTable = $tablesCheck && $tablesCheck->rowCount() > 0;
    
    // Total Products
    $totalProductsRes = $conn->query("SELECT COUNT(*) as cnt FROM products WHERE is_discontinued = 0");
    $totalProductsRow = $totalProductsRes ? $totalProductsRes->fetch(PDO::FETCH_ASSOC) : null;
    $totalProducts = $totalProductsRow ? (int) $totalProductsRow['cnt'] : 0;
    
    $lowStock = 0;
    $totalValue = 0;
    $outOfStock = 0;
    
    if ($hasStockinTable) {
        // Low Stock Items - check against safety_stock if available, otherwise use threshold of 10
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
                            AND (
                                si.quantity IS NULL 
                                OR si.quantity < COALESCE(p.safety_stock, 10)
                            )";
        $lowStockRes = $conn->query($lowStockQuery);
        $lowStockRow = $lowStockRes ? $lowStockRes->fetch(PDO::FETCH_ASSOC) : null;
        $lowStock = $lowStockRow ? (int) $lowStockRow['low_stock_count'] : 0;
        
        // Total Inventory Value
        $totalValueQuery = "SELECT COALESCE(SUM(
                              (SELECT quantity FROM stockin_inventory 
                               WHERE Product_ID = p.Product_ID 
                               ORDER BY updated_at DESC LIMIT 1) * p.wholesale_price
                            ), 0) as total_value
                            FROM products p
                            WHERE p.is_discontinued = 0";
        $totalValueRes = $conn->query($totalValueQuery);
        $totalValueRow = $totalValueRes ? $totalValueRes->fetch(PDO::FETCH_ASSOC) : null;
        $totalValue = $totalValueRow ? (float) $totalValueRow['total_value'] : 0;
        
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
        $outOfStockRes = $conn->query($outOfStockQuery);
        $outOfStockRow = $outOfStockRes ? $outOfStockRes->fetch(PDO::FETCH_ASSOC) : null;
        $outOfStock = $outOfStockRow ? (int) $outOfStockRow['out_of_stock_count'] : 0;
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

$conn = null;
?>
