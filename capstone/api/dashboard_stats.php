<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db.php';

header('Content-Type: application/json');

try {
    // Detect available columns in sales table
    $salesCols = [];
    $colsResult = $conn->query("SHOW COLUMNS FROM sales");
    if ($colsResult) {
        while ($col = $colsResult->fetch_assoc()) {
            $salesCols[] = $col['Field'];
        }
    }
    $hasStatusCol = in_array('status', $salesCols);

    // Check if related tables exist
    $tablesExist = [];
    $tablesCheck = $conn->query("SHOW TABLES");
    if ($tablesCheck) {
        while ($t = $tablesCheck->fetch_row()) {
            $tablesExist[] = $t[0];
        }
    }
    $hasSaleSourceTable = in_array('sale_source', $tablesExist);
    $hasDeliveryTable = in_array('delivery', $tablesExist);
    $hasOrdersTable = in_array('orders', $tablesExist);
    $hasCustomersTable = in_array('customers', $tablesExist);
    
    // Get current month and last month dates
    $currentMonth = date('Y-m-01');
    $lastMonth = date('Y-m-01', strtotime('-1 month'));
    $today = date('Y-m-d');
    
    // Total Sales (This Month)
    $totalSales = 0;
    $salesQuery = "SELECT COALESCE(SUM(sd.subtotal), 0) as total_sales
                   FROM sales s
                   INNER JOIN sale_details sd ON s.Sale_ID = sd.Sale_ID
                   WHERE DATE(s.created_at) >= ?";
    $stmt = $conn->prepare($salesQuery);
    if ($stmt) {
        $stmt->bind_param("s", $currentMonth);
        $stmt->execute();
        $salesResult = $stmt->get_result();
        $totalSales = $salesResult->fetch_assoc()['total_sales'] ?? 0;
        $stmt->close();
    }
    
    // Last Month Sales for comparison
    $lastMonthSales = 0;
    $lastMonthQuery = "SELECT COALESCE(SUM(sd.subtotal), 0) as total_sales
                       FROM sales s
                       INNER JOIN sale_details sd ON s.Sale_ID = sd.Sale_ID
                       WHERE DATE(s.created_at) >= ? AND DATE(s.created_at) < ?";
    $stmt = $conn->prepare($lastMonthQuery);
    if ($stmt) {
        $stmt->bind_param("ss", $lastMonth, $currentMonth);
        $stmt->execute();
        $lastMonthResult = $stmt->get_result();
        $lastMonthSales = $lastMonthResult->fetch_assoc()['total_sales'] ?? 0;
        $stmt->close();
    }
    
    $salesChange = $lastMonthSales > 0 ? round((($totalSales - $lastMonthSales) / $lastMonthSales) * 100, 1) : 0;
    
    // Total Inventory (active products)
    $totalInventory = 0;
    $inventoryQuery = "SELECT COUNT(DISTINCT p.Product_ID) as total_products
                       FROM products p
                       WHERE p.is_discontinued = 0";
    $inventoryResult = $conn->query($inventoryQuery);
    if ($inventoryResult) {
        $totalInventory = $inventoryResult->fetch_assoc()['total_products'] ?? 0;
    }
    
    // Accounts Receivable (pending sales)
    $arCount = 0;
    $arTotal = 0;
    if ($hasStatusCol) {
        $arQuery = "SELECT COUNT(DISTINCT s.Sale_ID) as overdue_count,
                           COALESCE(SUM(sd.subtotal), 0) as total_ar
                    FROM sales s
                    INNER JOIN sale_details sd ON s.Sale_ID = sd.Sale_ID
                    WHERE s.status = 'Pending'";
        $arResult = $conn->query($arQuery);
        if ($arResult) {
            $arData = $arResult->fetch_assoc();
            $arCount = $arData['overdue_count'] ?? 0;
            $arTotal = $arData['total_ar'] ?? 0;
        }
    }
    
    // Total Customers
    $totalCustomers = 0;
    $newCustomers = 0;
    if ($hasCustomersTable) {
        $customersQuery = "SELECT COUNT(*) as total_customers FROM customers";
        $customersResult = $conn->query($customersQuery);
        if ($customersResult) {
            $totalCustomers = $customersResult->fetch_assoc()['total_customers'] ?? 0;
        }
        
        // Customers this month
        $customersThisMonthQuery = "SELECT COUNT(*) as new_customers
                                    FROM customers
                                    WHERE DATE(created_at) >= ?";
        $stmt = $conn->prepare($customersThisMonthQuery);
        if ($stmt) {
            $stmt->bind_param("s", $currentMonth);
            $stmt->execute();
            $customersThisMonthResult = $stmt->get_result();
            $newCustomers = $customersThisMonthResult->fetch_assoc()['new_customers'] ?? 0;
            $stmt->close();
        }
    }
    
    // Recent Sales Transactions - Build query dynamically
    // Build JOIN clauses based on available tables
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
    $recentSalesResult = $conn->query($recentSalesQuery);
    $recentSales = [];
    if ($recentSalesResult) {
        while ($row = $recentSalesResult->fetch_assoc()) {
            $recentSales[] = [
                'sale_id' => $row['Sale_ID'],
                'customer' => $row['customer_name'] ?? 'Walk-in Customer',
                'amount' => floatval($row['total_amount']),
                'date' => $row['sale_date'],
                'status' => $row['status'] ?? 'Completed'
            ];
        }
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
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

$conn->close();
?>
