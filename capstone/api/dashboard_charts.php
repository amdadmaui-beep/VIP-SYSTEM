<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

try {
    // Helpers (safe checks)
    $tablesExist = [];
    $tablesCheck = $conn->query("SHOW TABLES");
    if ($tablesCheck) {
        while ($t = $tablesCheck->fetch(PDO::FETCH_NUM)) {
            $tablesExist[] = $t[0];
        }
    }
    $hasTable = function(string $name) use ($tablesExist): bool {
        return in_array($name, $tablesExist, true);
    };
    $getCols = function(string $table) use ($conn, $hasTable): array {
        if (!$hasTable($table)) return [];
        $cols = [];
        $r = $conn->query("SHOW COLUMNS FROM `$table`");
        if ($r) {
            while ($c = $r->fetch(PDO::FETCH_ASSOC)) $cols[] = $c['Field'];
        }
        return $cols;
    };

    // Sales Trend (Last 7 Days)
    $salesTrendQuery = "SELECT 
                           DATE(s.created_at) as sale_date,
                           COALESCE(SUM(sd.subtotal), 0) as daily_total
                        FROM sales s
                        INNER JOIN sale_details sd ON s.Sale_ID = sd.Sale_ID
                        WHERE DATE(s.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                        GROUP BY DATE(s.created_at)
                        ORDER BY sale_date ASC";
    $salesTrendRows = cacheQuery($conn, $salesTrendQuery, [], 120);
    
    $salesTrend = [];
    $dates = [];
    $amounts = [];
    
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $dates[] = date('M j', strtotime($date));
        $amounts[$date] = 0;
    }
    
    foreach ($salesTrendRows as $row) {
        $date = $row['sale_date'];
        if (isset($amounts[$date])) {
            $amounts[$date] = floatval($row['daily_total']);
        }
    }
    
    $salesTrend = [
        'labels' => $dates,
        'data' => array_values($amounts)
    ];

    // Sales Trend Comparison (Last 7 Days exactly 1 Month Ago)
    $compAmounts = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days -1 month"));
        $compAmounts[$date] = 0;
    }
    $salesTrendCompQuery = "SELECT 
                           DATE(s.created_at) as sale_date,
                           COALESCE(SUM(sd.subtotal), 0) as daily_total
                        FROM sales s
                        INNER JOIN sale_details sd ON s.Sale_ID = sd.Sale_ID
                        WHERE DATE(s.created_at) >= DATE_SUB(DATE_SUB(CURDATE(), INTERVAL 7 DAY), INTERVAL 1 MONTH)
                          AND DATE(s.created_at) <= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)
                        GROUP BY DATE(s.created_at)
                        ORDER BY sale_date ASC";
    $compRows = cacheQuery($conn, $salesTrendCompQuery, [], 120);
    foreach ($compRows as $row) {
        $date = $row['sale_date'];
        if (isset($compAmounts[$date])) {
            $compAmounts[$date] = floatval($row['daily_total']);
        }
    }
    $salesTrendComp = array_values($compAmounts);

    // Monthly Sales Trend (Last 12 Months)
    $monthlyLabels = [];
    $monthlyMap = [];
    for ($i = 11; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime("first day of -$i month"));
        $monthlyLabels[] = date('M Y', strtotime($ym . "-01"));
        $monthlyMap[$ym] = 0;
    }
    $monthlyQuery = "SELECT DATE_FORMAT(s.created_at, '%Y-%m') as ym,
                            COALESCE(SUM(sd.subtotal), 0) as total
                     FROM sales s
                     INNER JOIN sale_details sd ON s.Sale_ID = sd.Sale_ID
                     WHERE DATE(s.created_at) >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                     GROUP BY ym
                     ORDER BY ym ASC";
    $monthlyRows = cacheQuery($conn, $monthlyQuery, [], 300);
    foreach ($monthlyRows as $row) {
        $ym = $row['ym'];
        if (isset($monthlyMap[$ym])) $monthlyMap[$ym] = floatval($row['total']);
    }
    $monthlyTrend = [
        'labels' => $monthlyLabels,
        'data' => array_values($monthlyMap)
    ];

    // Monthly Sales Trend Comparison (Previous 12 Months vs current)
    $monthlyCompMap = [];
    for ($i = 11; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime("first day of -$i month -1 year"));
        $monthlyCompMap[$ym] = 0;
    }
    $monthlyCompQuery = "SELECT DATE_FORMAT(s.created_at, '%Y-%m') as ym,
                            COALESCE(SUM(sd.subtotal), 0) as total
                     FROM sales s
                     INNER JOIN sale_details sd ON s.Sale_ID = sd.Sale_ID
                     WHERE DATE(s.created_at) >= DATE_SUB(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), INTERVAL 1 YEAR)
                       AND DATE(s.created_at) < DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
                     GROUP BY ym
                     ORDER BY ym ASC";
    // Using a safer 24-month overlap query to just grab exactly the last year's match
    $monthlyCompQuery = "SELECT DATE_FORMAT(DATE_ADD(s.created_at, INTERVAL 1 YEAR), '%Y-%m') as fake_ym,
                            COALESCE(SUM(sd.subtotal), 0) as total
                     FROM sales s
                     INNER JOIN sale_details sd ON s.Sale_ID = sd.Sale_ID
                     WHERE DATE(s.created_at) >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
                       AND DATE(s.created_at) < DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                     GROUP BY fake_ym
                     ORDER BY fake_ym ASC";
    $monthlyCompRows = cacheQuery($conn, $monthlyCompQuery, [], 300);
    
    $alignedMonthlyCompValues = array_fill(0, 12, 0);
    $monthlyMapKeys = array_keys($monthlyMap);
    
    foreach ($monthlyCompRows as $row) {
        $fake_ym = $row['fake_ym'];
        $keyIndex = array_search($fake_ym, $monthlyMapKeys);
        if ($keyIndex !== false) {
            $alignedMonthlyCompValues[$keyIndex] = floatval($row['total']);
        }
    }
    $monthlyTrendComp = $alignedMonthlyCompValues;
    
    // Top Products (Last 30 Days) - removed status filter for compatibility
    $topProductsQuery = "SELECT 
                            p.product_name,
                            u.unit_name,
                            COALESCE(SUM(sd.quantity), 0) as total_quantity,
                            COALESCE(SUM(sd.subtotal), 0) as total_revenue
                         FROM sale_details sd
                         INNER JOIN sales s ON sd.Sale_ID = s.Sale_ID
                         INNER JOIN products p ON sd.Product_ID = p.Product_ID
                         LEFT JOIN units u ON p.unit_id = u.unit_id
                         WHERE DATE(s.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                         GROUP BY p.Product_ID, p.product_name, u.unit_name
                         ORDER BY total_revenue DESC
                         LIMIT 10";
    $topProductsRows = cacheQuery($conn, $topProductsQuery, [], 300);
    
    $topProducts = [];
    $productLabels = [];
    $productQuantities = [];
    $productRevenues = [];
    
    foreach ($topProductsRows as $row) {
        $productName = $row['product_name'];
        if ($row['unit_name']) {
            $productName .= ' ' . $row['unit_name'];
        }
        
        $productLabels[] = $productName;
        $productQuantities[] = floatval($row['total_quantity']);
        $productRevenues[] = floatval($row['total_revenue']);
    }
    
    $topProducts = [
        'labels' => $productLabels,
        'quantities' => $productQuantities,
        'revenues' => $productRevenues
    ];

    // Least Selling Products (Last 30 Days) - bottom by revenue
    $leastProductsQuery = "SELECT 
                              p.product_name,
                              u.unit_name,
                              COALESCE(SUM(sd.quantity), 0) as total_quantity,
                              COALESCE(SUM(sd.subtotal), 0) as total_revenue
                           FROM sale_details sd
                           INNER JOIN sales s ON sd.Sale_ID = s.Sale_ID
                           INNER JOIN products p ON sd.Product_ID = p.Product_ID
                           LEFT JOIN units u ON p.unit_id = u.unit_id
                           WHERE DATE(s.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                           GROUP BY p.Product_ID, p.product_name, u.unit_name
                           ORDER BY total_revenue ASC
                           LIMIT 6";
    $leastProductsRows = cacheQuery($conn, $leastProductsQuery, [], 300);

    $leastLabels = [];
    $leastQuantities = [];
    $leastRevenues = [];

    foreach ($leastProductsRows as $row) {
        $productName = $row['product_name'];
        if ($row['unit_name']) {
            $productName .= ' ' . $row['unit_name'];
        }
        $leastLabels[] = $productName;
        $leastQuantities[] = floatval($row['total_quantity']);
        $leastRevenues[] = floatval($row['total_revenue']);
    }

    $leastProducts = [
        'labels' => $leastLabels,
        'quantities' => $leastQuantities,
        'revenues' => $leastRevenues
    ];

    // Sales by Payment Status (Paid vs Unpaid) - last 30 days
    $paidUnpaid = ['paid' => 0, 'unpaid' => 0];
    if ($hasTable('account_receivable')) {
        $total30Query = "SELECT COALESCE(SUM(sd.subtotal), 0) as total
                         FROM sales s
                         INNER JOIN sale_details sd ON s.Sale_ID = sd.Sale_ID
                         WHERE DATE(s.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $total30Rows = cacheQuery($conn, $total30Query, [], 120);
        $total30 = floatval($total30Rows[0]['total'] ?? 0);

        $unpaidQuery = "SELECT COALESCE(SUM(ar.amount_due), 0) as unpaid
                        FROM account_receivable ar
                        INNER JOIN sales s ON ar.Sale_ID = s.Sale_ID
                        WHERE DATE(s.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                          AND ar.status NOT IN ('Paid', 'Closed')
                          AND ar.amount_due > 0";
        $unpaidRows = cacheQuery($conn, $unpaidQuery, [], 120);
        $unpaid = floatval($unpaidRows[0]['unpaid'] ?? 0);

        $paidUnpaid['unpaid'] = max(0, $unpaid);
        $paidUnpaid['paid'] = max(0, $total30 - $unpaid);
    } else {
        // Fallback: treat everything as paid when AR table not present
        $total30Query = "SELECT COALESCE(SUM(sd.subtotal), 0) as total
                         FROM sales s
                         INNER JOIN sale_details sd ON s.Sale_ID = sd.Sale_ID
                         WHERE DATE(s.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $total30Rows = cacheQuery($conn, $total30Query, [], 120);
        $paidUnpaid['paid'] = floatval($total30Rows[0]['total'] ?? 0);
        $paidUnpaid['unpaid'] = 0;
    }

    // Production vs Sales (Last 7 Days) - quantities
    $prodSalesLabels = [];
    $prodMap = [];
    $soldMap = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $prodSalesLabels[] = date('M j', strtotime($d));
        $prodMap[$d] = 0;
        $soldMap[$d] = 0;
    }

    if ($hasTable('productions')) {
        $prodQuery = "SELECT DATE(production_date) as d, COALESCE(SUM(produced_qty), 0) as qty
                      FROM productions
                      WHERE DATE(production_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                      GROUP BY d";
        $prodRows = cacheQuery($conn, $prodQuery, [], 120);
        foreach ($prodRows as $row) {
            $d = $row['d'];
            if (isset($prodMap[$d])) $prodMap[$d] = floatval($row['qty']);
        }
    }

    $soldQuery = "SELECT DATE(s.created_at) as d, COALESCE(SUM(sd.quantity), 0) as qty
                  FROM sales s
                  INNER JOIN sale_details sd ON s.Sale_ID = sd.Sale_ID
                  WHERE DATE(s.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                  GROUP BY d";
    $soldRows = cacheQuery($conn, $soldQuery, [], 120);
    foreach ($soldRows as $row) {
        $d = $row['d'];
        if (isset($soldMap[$d])) $soldMap[$d] = floatval($row['qty']);
    }

    $productionVsSales = [
        'labels' => $prodSalesLabels,
        'produced' => array_values($prodMap),
        'sold' => array_values($soldMap),
    ];

    // Top Customers (Last 30 Days)
    $topCustomers = ['labels' => [], 'revenues' => []];
    $hasSaleSource = $hasTable('sale_source');
    $hasDelivery = $hasTable('delivery');
    $hasOrders = $hasTable('orders');
    $hasCustomers = $hasTable('customers');

    if ($hasSaleSource && $hasDelivery && $hasOrders && $hasCustomers) {
        $custQuery = "SELECT COALESCE(c.customer_name, 'Walk-in Customer') as customer_name,
                             COALESCE(SUM(sd.subtotal), 0) as total_revenue
                      FROM sales s
                      INNER JOIN sale_details sd ON s.Sale_ID = sd.Sale_ID
                      LEFT JOIN sale_source ss ON s.Sale_ID = ss.Sale_ID
                      LEFT JOIN delivery d ON ss.Delivery_ID = d.Delivery_ID
                      LEFT JOIN orders o ON d.Order_ID = o.Order_ID
                      LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
                      WHERE DATE(s.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                      GROUP BY customer_name
                      HAVING customer_name != 'Walk-in Customer'
                      ORDER BY total_revenue DESC
                      LIMIT 10";
        $custRows = cacheQuery($conn, $custQuery, [], 300);
        foreach ($custRows as $row) {
            $topCustomers['labels'][] = $row['customer_name'];
            $topCustomers['revenues'][] = floatval($row['total_revenue']);
        }
    } else {
        // Fallback: no customer join available
        $topCustomers = ['labels' => [], 'revenues' => []];
    }
    
    // Damage Goods Daily Trend (Last 7 Days)
    $damageTrendLabels = [];
    $damageTrendMap = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $damageTrendLabels[] = date('M j', strtotime($d));
        $damageTrendMap[$d] = 0;
    }
    if ($hasTable('damage_goods')) {
        $dmgTrendQuery = "SELECT DATE(created_at) as d, COALESCE(SUM(quantity), 0) as qty
                          FROM damage_goods
                          WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                          GROUP BY d";
        $dmgTrendRows = cacheQuery($conn, $dmgTrendQuery, [], 300);
        foreach ($dmgTrendRows as $row) {
            $d = $row['d'];
            if (isset($damageTrendMap[$d])) $damageTrendMap[$d] = intval($row['qty']);
        }
    }
    $damageTrend = [
        'labels' => $damageTrendLabels,
        'data' => array_values($damageTrendMap)
    ];

    // Top Damaged Products (Last 30 Days)
    $topDamaged = ['labels' => [], 'quantities' => [], 'losses' => []];
    if ($hasTable('damage_goods') && $hasTable('stockin_inventory') && $hasTable('products')) {
        $topDmgQuery = "SELECT p.product_name, SUM(dg.quantity) as total_qty,
                               COALESCE(SUM(dg.quantity * p.retail_price), 0) as loss
                        FROM damage_goods dg
                        JOIN stockin_inventory si ON dg.Inventory_ID = si.Inventory_ID
                        JOIN products p ON si.Product_ID = p.Product_ID
                        WHERE dg.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                        GROUP BY p.Product_ID, p.product_name
                        ORDER BY total_qty DESC
                        LIMIT 5";
        $topDmgRows = cacheQuery($conn, $topDmgQuery, [], 300);
        foreach ($topDmgRows as $row) {
            $topDamaged['labels'][] = $row['product_name'];
            $topDamaged['quantities'][] = intval($row['total_qty']);
            $topDamaged['losses'][] = floatval($row['loss']);
        }
    }

    // Damage Monthly Trend (Last 6 Months)
    $dmgMonthlyLabels = [];
    $dmgMonthlyMap = [];
    for ($i = 5; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime("first day of -$i month"));
        $dmgMonthlyLabels[] = date('M Y', strtotime($ym . "-01"));
        $dmgMonthlyMap[$ym] = 0;
    }
    if ($hasTable('damage_goods')) {
        $dmgMonthlyQuery = "SELECT DATE_FORMAT(created_at, '%Y-%m') as ym, COALESCE(SUM(quantity), 0) as qty
                            FROM damage_goods
                            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                            GROUP BY ym ORDER BY ym ASC";
        $dmgMonthlyRows = cacheQuery($conn, $dmgMonthlyQuery, [], 300);
        foreach ($dmgMonthlyRows as $row) {
            $ym = $row['ym'];
            if (isset($dmgMonthlyMap[$ym])) $dmgMonthlyMap[$ym] = intval($row['qty']);
        }
    }
    $damageMonthly = [
        'labels' => $dmgMonthlyLabels,
        'data' => array_values($dmgMonthlyMap)
    ];

    echo json_encode([
        'success' => true,
        'data' => [
            'sales_trend' => $salesTrend,
            'sales_trend_comp' => $salesTrendComp,
            'monthly_trend' => $monthlyTrend,
            'monthly_trend_comp' => $monthlyTrendComp,
            'top_products' => $topProducts,
            'payment_status' => $paidUnpaid,
            'production_vs_sales' => $productionVsSales,
            'top_customers' => $topCustomers,
            'damage_trend' => $damageTrend,
            'damage_monthly' => $damageMonthly,
            'top_damaged' => $topDamaged,
            'least_products' => $leastProducts
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    error_log('dashboard_charts error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'An internal error occurred.'
    ]);
}

// PDO doesn't need close() - resources are automatically freed
?>
