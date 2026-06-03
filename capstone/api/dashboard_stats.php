<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db.php';

header('Content-Type: application/json');

try {
    // Detect available columns in sales table
    $salesCols = $conn->query("SHOW COLUMNS FROM sales")->fetchAll(PDO::FETCH_COLUMN);
    $hasStatusCol = in_array('status', $salesCols);

    // Check if related tables exist
    $tablesExist = $conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $hasSaleSourceTable = in_array('sale_source', $tablesExist);
    $hasDeliveryTable = in_array('delivery', $tablesExist);
    $hasOrdersTable = in_array('orders', $tablesExist);
    $hasCustomersTable = in_array('customers', $tablesExist);
    
    // Get current month and last month dates
    $currentMonth = date('Y-m-01');
    $lastMonth = date('Y-m-01', strtotime('-1 month'));
    $today = date('Y-m-d');
    
    // Total Sales (This Month)
    $salesQuery = "SELECT COALESCE(SUM(sd.subtotal), 0) as total
                   FROM sales s
                   INNER JOIN sale_details sd ON s.Sale_ID = sd.Sale_ID
                   WHERE DATE(s.created_at) >= ?";
    $salesRows = cacheQuery($conn, $salesQuery, [$currentMonth], 60);
    $totalSales = (float) ($salesRows[0]['total'] ?? 0);
    
    // Last Month Sales for comparison
    $lastMonthQuery = "SELECT COALESCE(SUM(sd.subtotal), 0) as total
                       FROM sales s
                       INNER JOIN sale_details sd ON s.Sale_ID = sd.Sale_ID
                       WHERE DATE(s.created_at) >= ? AND DATE(s.created_at) < ?";
    $lastMonthRows = cacheQuery($conn, $lastMonthQuery, [$lastMonth, $currentMonth], 60);
    $lastMonthSales = (float) ($lastMonthRows[0]['total'] ?? 0);
    
    $salesChange = $lastMonthSales > 0 ? round((($totalSales - $lastMonthSales) / $lastMonthSales) * 100, 1) : 0;
    
    // Total Inventory (active products)
    $inventoryQuery = "SELECT COUNT(DISTINCT p.Product_ID)
                       FROM products p
                       WHERE p.is_discontinued = 0";
    $totalInventory = (int) $conn->query($inventoryQuery)->fetchColumn();
    
    // Accounts Receivable (pending sales)
    $arCount = 0;
    $arTotal = 0;
    if ($hasStatusCol) {
        $arQuery = "SELECT COUNT(DISTINCT s.Sale_ID) as overdue_count,
                           COALESCE(SUM(sd.subtotal), 0) as total_ar
                    FROM sales s
                    INNER JOIN sale_details sd ON s.Sale_ID = sd.Sale_ID
                    WHERE s.status = 'Pending'";
        $arRows = cacheQuery($conn, $arQuery, [], 60);
        if (!empty($arRows)) {
            $arCount = $arRows[0]['overdue_count'] ?? 0;
            $arTotal = $arRows[0]['total_ar'] ?? 0;
        }
    }
    
    // Total Customers
    $totalCustomers = 0;
    $newCustomers = 0;
    if ($hasCustomersTable) {
        $totalCustomers = (int) $conn->query("SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL")->fetchColumn();
        
        // Customers this month
        $customersThisMonthQuery = "SELECT COUNT(*)
                                    FROM customers
                                    WHERE deleted_at IS NULL AND DATE(created_at) >= ?";
        $stmt = $conn->prepare($customersThisMonthQuery);
        $stmt->execute([$currentMonth]);
        $newCustomers = (int) $stmt->fetchColumn();
    }
    
    // Recent Sales Transactions - Build query dynamically
    $joinClauses = "";
    $customerSelect = "'Walk-in Customer' as customer_name";
    
    if ($hasSaleSourceTable && $hasDeliveryTable && $hasOrdersTable && $hasCustomersTable) {
        $joinClauses = "LEFT JOIN sale_source ss ON s.Sale_ID = ss.Sale_ID
                        LEFT JOIN delivery d ON ss.Delivery_ID = d.Delivery_ID
                        LEFT JOIN orders o ON d.Order_ID = o.Order_ID
                        LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID";
        $customerSelect = "COALESCE(MAX(c.customer_name), 'Walk-in Customer') as customer_name";
    }
    
    $recentSalesQuery = "SELECT 
                            s.Sale_ID,
                            $customerSelect,
                            COALESCE(SUM(sd.subtotal), 0) as total_amount,
                            DATE(s.created_at) as sale_date,
                            " . ($hasStatusCol ? "MAX(s.status)" : "'Completed'") . " as status
                         FROM sales s
                         INNER JOIN sale_details sd ON s.Sale_ID = sd.Sale_ID
                         $joinClauses
                         GROUP BY s.Sale_ID, DATE(s.created_at)
                         ORDER BY s.created_at DESC
                         LIMIT 10";
    $recentSalesRows = cacheQuery($conn, $recentSalesQuery, [], 60);
    $recentSales = [];
    foreach ($recentSalesRows as $row) {
        $recentSales[] = [
            'sale_id' => $row['Sale_ID'],
            'customer' => $row['customer_name'] ?? 'Walk-in Customer',
            'amount' => floatval($row['total_amount']),
            'date' => $row['sale_date'],
            'status' => $row['status'] ?? 'Completed'
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'total_sales' => floatval($totalSales),
            'sales_change' => $salesChange,
            'total_inventory' => intval($totalInventory),
            'accounts_receivable' => floatval($arTotal),
            'ar_count' => intval($arCount),
            'total_customers' => intval($totalCustomers),
            'new_customers' => intval($newCustomers),
            'recent_sales' => $recentSales
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    error_log('dashboard_stats error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'An internal error occurred.'
    ]);
}

$conn = null;
?>
