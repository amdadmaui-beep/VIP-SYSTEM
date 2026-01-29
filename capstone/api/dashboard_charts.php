<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db.php';

header('Content-Type: application/json');

try {
    // Sales Trend (Last 7 Days) - removed status filter for compatibility
    $salesTrendQuery = "SELECT 
                           DATE(s.created_at) as sale_date,
                           COALESCE(SUM(sd.subtotal), 0) as daily_total
                        FROM sales s
                        INNER JOIN sale_details sd ON s.Sale_ID = sd.Sale_ID
                        WHERE DATE(s.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                        GROUP BY DATE(s.created_at)
                        ORDER BY sale_date ASC";
    $salesTrendResult = $conn->query($salesTrendQuery);
    
    $salesTrend = [];
    $dates = [];
    $amounts = [];
    
    // Generate last 7 days array
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $dates[] = date('M j', strtotime($date));
        $amounts[$date] = 0;
    }
    
    if ($salesTrendResult) {
        while ($row = $salesTrendResult->fetch_assoc()) {
            $date = $row['sale_date'];
            if (isset($amounts[$date])) {
                $amounts[$date] = floatval($row['daily_total']);
            }
        }
    }
    
    $salesTrend = [
        'labels' => $dates,
        'data' => array_values($amounts)
    ];
    
    // Top Products (Last 30 Days) - removed status filter for compatibility
    $topProductsQuery = "SELECT 
                            p.product_name,
                            p.form,
                            p.unit,
                            COALESCE(SUM(sd.quantity), 0) as total_quantity,
                            COALESCE(SUM(sd.subtotal), 0) as total_revenue
                         FROM sale_details sd
                         INNER JOIN sales s ON sd.Sale_ID = s.Sale_ID
                         INNER JOIN products p ON sd.Product_ID = p.Product_ID
                         WHERE DATE(s.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                         GROUP BY p.Product_ID, p.product_name, p.form, p.unit
                         ORDER BY total_revenue DESC
                         LIMIT 10";
    $topProductsResult = $conn->query($topProductsQuery);
    
    $topProducts = [];
    $productLabels = [];
    $productQuantities = [];
    $productRevenues = [];
    
    if ($topProductsResult) {
        while ($row = $topProductsResult->fetch_assoc()) {
            $productName = $row['product_name'];
            if ($row['form']) {
                $productName .= ' (' . $row['form'] . ')';
            }
            if ($row['unit']) {
                $productName .= ' ' . $row['unit'];
            }
            
            $productLabels[] = $productName;
            $productQuantities[] = floatval($row['total_quantity']);
            $productRevenues[] = floatval($row['total_revenue']);
        }
    }
    
    $topProducts = [
        'labels' => $productLabels,
        'quantities' => $productQuantities,
        'revenues' => $productRevenues
    ];
    
    echo json_encode([
        'success' => true,
        'data' => [
            'sales_trend' => $salesTrend,
            'top_products' => $topProducts
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
