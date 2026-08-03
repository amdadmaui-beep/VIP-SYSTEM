<?php
session_start();

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/roles_helper.php';
require_once __DIR__ . '/../../includes/delivery_damage_ui_helper.php';

$dashboard_ids = getDashboardRoleIds($conn);
requireRole(empty($dashboard_ids) ? [1] : $dashboard_ids);

// ============================================
// Detect available columns in sales table
// ============================================
$salesCols = [];
$colsResult = $conn->query("SHOW COLUMNS FROM sales");
if ($colsResult) {
    while ($col = $colsResult->fetch(PDO::FETCH_ASSOC)) {
        $salesCols[] = $col['Field'];
    }
}
$hasStatusCol = in_array('status', $salesCols);
$hasDeliveryIdCol = in_array('Delivery_ID', $salesCols);

// Check if related tables exist
$tablesExist = [];
$tablesCheck = $conn->query("SHOW TABLES");
if ($tablesCheck) {
    while ($t = $tablesCheck->fetch(PDO::FETCH_NUM)) {
        $tablesExist[] = $t[0];
    }
}
$hasSaleSourceTable = in_array('sale_source', $tablesExist);
$hasDeliveryTable = in_array('delivery', $tablesExist);
$hasOrdersTable = in_array('orders', $tablesExist);
$hasCustomersTable = in_array('customers', $tablesExist);
$hasDamageGoodsTable = in_array('damage_goods', $tablesExist);
$hasDeliveryDamageReportsTable = in_array('delivery_damage_report', $tablesExist);
$hasStockinTable = in_array('stockin_inventory', $tablesExist);
$hasProductsTable = in_array('products', $tablesExist);

$pendingDeliveryDamageCount = 0;
$showDdrDashboardBanner = false;
if ($hasDeliveryDamageReportsTable) {
    $ddrRoleId = (int)($_SESSION['user_role'] ?? 0);
    $invStaffIdsDdr = getInventoryStaffRoleIds($conn);
    $ddrReviewRoles = array_values(array_unique(array_merge([1, 2, 4], $invStaffIdsDdr)));
    if (in_array($ddrRoleId, $ddrReviewRoles, true)) {
        $showDdrDashboardBanner = true;
        try {
            $pendingDeliveryDamageCount = (int)$conn->query(
                "SELECT COUNT(*) FROM delivery_damage_report r
                 LEFT JOIN damage_report_reviews rev ON rev.report_id = r.report_id
                 WHERE COALESCE(rev.status, 'pending_review') = 'pending_review'"
            )->fetchColumn();
        } catch (Throwable $e) {
            $pendingDeliveryDamageCount = 0;
        }
    }
}

// Fetch dashboard statistics directly
$currentMonth = date('Y-m-01');
$lastMonth = date('Y-m-01', strtotime('-1 month'));

// Total Sales (This Month)
$totalSales = 0;
$salesChange = 0;
$salesQuery = "SELECT COALESCE(SUM(sd.subtotal), 0) as total_sales
               FROM sales s
               INNER JOIN sale_details sd ON s.Sale_ID = sd.Sale_ID
               WHERE DATE(s.created_at) >= '$currentMonth'";
$salesResult = $conn->query($salesQuery);
if ($salesResult && $row = $salesResult->fetch(PDO::FETCH_ASSOC)) {
    $totalSales = floatval($row['total_sales']);
}

// Last Month Sales
$lastMonthSales = 0;
$lastMonthQuery = "SELECT COALESCE(SUM(sd.subtotal), 0) as total_sales
                   FROM sales s
                   INNER JOIN sale_details sd ON s.Sale_ID = sd.Sale_ID
                   WHERE DATE(s.created_at) >= '$lastMonth' AND DATE(s.created_at) < '$currentMonth'";
$lastMonthResult = $conn->query($lastMonthQuery);
if ($lastMonthResult && $row = $lastMonthResult->fetch(PDO::FETCH_ASSOC)) {
    $lastMonthSales = floatval($row['total_sales']);
}
if ($lastMonthSales > 0) {
    $salesChange = round((($totalSales - $lastMonthSales) / $lastMonthSales) * 100, 1);
}
$monthlyRevenue = $totalSales; // alias for Quick Insights widget


// Today's Sales
$todaysSales = 0;
$todaysSalesQuery = "SELECT COALESCE(SUM(sd.subtotal), 0) as total_sales
                    FROM sales s
                    INNER JOIN sale_details sd ON s.Sale_ID = sd.Sale_ID
                    WHERE DATE(s.created_at) = CURDATE()";
$todaysSalesResult = $conn->query($todaysSalesQuery);
if ($todaysSalesResult && $row = $todaysSalesResult->fetch(PDO::FETCH_ASSOC)) {
    $todaysSales = floatval($row['total_sales']);
}

// Accounts Receivable - Use existing account_receivable table with amount_due column
$arTotal = 0;
$arCount = 0;
$arTableCheck = $conn->query("SHOW TABLES LIKE 'account_receivable'");
if ($arTableCheck && $arTableCheck->rowCount() > 0) {
    // Use AR table - amount_due is the balance column in your schema
    $arQuery = "SELECT COUNT(*) as ar_count, COALESCE(SUM(amount_due), 0) as total_ar
                FROM account_receivable 
                WHERE status IN ('Open', 'Partial', 'Overdue', 'Pending')";
    $arResult = $conn->query($arQuery);
    if ($arResult && $row = $arResult->fetch(PDO::FETCH_ASSOC)) {
        $arCount = intval($row['ar_count']);
        $arTotal = floatval($row['total_ar']);
    }
} elseif ($hasStatusCol) {
    // Fall back to sales table
    $arQuery = "SELECT COUNT(DISTINCT s.Sale_ID) as ar_count,
                       COALESCE(SUM(sd.subtotal), 0) as total_ar
                FROM sales s
                INNER JOIN sale_details sd ON s.Sale_ID = sd.Sale_ID
                WHERE s.status = 'Pending'";
    $arResult = $conn->query($arQuery);
    if ($arResult && $row = $arResult->fetch(PDO::FETCH_ASSOC)) {
        $arCount = intval($row['ar_count']);
        $arTotal = floatval($row['total_ar']);
    }
}

// Calculate Overdue AR Value, Customer Count, and Per-Customer List for Quick Insights
$overdueARValue = 0;
$customersWithOverdue = 0;
$overdueCustomersList = [];
if ($arTableCheck && $arTableCheck->rowCount() > 0) {
    $overdueStmt = $conn->query("SELECT COALESCE(SUM(amount_due), 0) as total FROM account_receivable WHERE due_date < CURDATE() AND status NOT IN ('Paid', 'Closed')");
    $overdueARValue = (float)($overdueStmt ? $overdueStmt->fetchColumn() : 0);
    
    $custOverdueStmt = $conn->query("SELECT COUNT(DISTINCT customer_id) as count FROM account_receivable WHERE due_date < CURDATE() AND status NOT IN ('Paid', 'Closed')");
    $customersWithOverdue = (int)($custOverdueStmt ? $custOverdueStmt->fetchColumn() : 0);

    // Per-customer overdue list — join with customers table if it exists
    $custListQuery = "SELECT 
                        ar.customer_id,
                        COALESCE(c.customer_name, CONCAT('Customer #', ar.customer_id)) as customer_name,
                        SUM(ar.amount_due) as total_overdue,
                        MIN(ar.due_date) as earliest_due
                      FROM account_receivable ar
                      LEFT JOIN customers c ON ar.customer_id = c.Customer_ID
                      WHERE ar.due_date < CURDATE() AND ar.status NOT IN ('Paid', 'Closed')
                      GROUP BY ar.customer_id
                      ORDER BY total_overdue DESC
                      LIMIT 10";
    $custListResult = $conn->query($custListQuery);
    if ($custListResult) {
        while ($row = $custListResult->fetch(PDO::FETCH_ASSOC)) {
            $overdueCustomersList[] = $row;
        }
    }
}

// Collectible AR balances per customer (all outstanding, not just overdue)
$collectibleARList = [];
$collectibleTotal = 0;
$collectibleCustomerCount = 0;
if ($arTableCheck && $arTableCheck->rowCount() > 0) {
    $collectibleQuery = "SELECT 
                            ar.customer_id,
                            COALESCE(c.customer_name, CONCAT('Customer #', ar.customer_id)) as customer_name,
                            COALESCE(c.phone_number, '') as phone_number,
                            SUM(ar.amount_due) as total_collectible,
                            COUNT(ar.AR_ID) as invoice_count,
                            MIN(ar.due_date) as earliest_due,
                            MAX(ar.due_date) as latest_due,
                            SUM(CASE WHEN COALESCE(DATEDIFF(CURDATE(), ar.due_date), 0) <= 0 THEN ar.amount_due ELSE 0 END) as current_amount,
                            SUM(CASE WHEN COALESCE(DATEDIFF(CURDATE(), ar.due_date), 0) BETWEEN 1 AND 30 THEN ar.amount_due ELSE 0 END) as bucket_1_30,
                            SUM(CASE WHEN COALESCE(DATEDIFF(CURDATE(), ar.due_date), 0) BETWEEN 31 AND 60 THEN ar.amount_due ELSE 0 END) as bucket_31_60,
                            SUM(CASE WHEN COALESCE(DATEDIFF(CURDATE(), ar.due_date), 0) BETWEEN 61 AND 90 THEN ar.amount_due ELSE 0 END) as bucket_61_90,
                            SUM(CASE WHEN COALESCE(DATEDIFF(CURDATE(), ar.due_date), 0) > 90 THEN ar.amount_due ELSE 0 END) as bucket_90_plus
                        FROM account_receivable ar
                        LEFT JOIN customers c ON ar.customer_id = c.Customer_ID
                        WHERE ar.status NOT IN ('Paid', 'Closed') AND ar.amount_due > 0
                        GROUP BY ar.customer_id
                        ORDER BY total_collectible DESC
                        LIMIT 20";
    $collectibleResult = $conn->query($collectibleQuery);
    if ($collectibleResult) {
        while ($row = $collectibleResult->fetch(PDO::FETCH_ASSOC)) {
            $collectibleARList[] = $row;
        }
    }
    $collectibleCustomerCount = count($collectibleARList);
    $collectibleTotal = array_sum(array_map(function($r) { return floatval($r['total_collectible'] ?? 0); }, $collectibleARList));
}

// Pending Orders
$pendingOrders = 0;
if ($hasOrdersTable) {
    $orderCols = [];
    $oc = $conn->query("SHOW COLUMNS FROM orders");
    if ($oc) {
        while ($c = $oc->fetch(PDO::FETCH_ASSOC))
            $orderCols[] = $c['Field'];
    }
    $statusCol = null;
    foreach (['order_status', 'status'] as $cand) {
        if (in_array($cand, $orderCols, true)) {
            $statusCol = $cand;
            break;
        }
    }
    if ($statusCol) {
        $pendingOrdersQuery = "SELECT COUNT(*) as cnt
                               FROM orders
                               WHERE LOWER($statusCol) NOT IN ('completed','cancelled')";
        $pendingOrdersResult = $conn->query($pendingOrdersQuery);
        if ($pendingOrdersResult && $row = $pendingOrdersResult->fetch(PDO::FETCH_ASSOC)) {
            $pendingOrders = intval($row['cnt']);
        }
    }
}

// Low Stocks count (real DB-backed)
$lowStocksCount = 0;
$lowStockProducts = [];
if ($hasStockinTable && $hasProductsTable) {
    $productCols = [];
    $prodColsRes = $conn->query("SHOW COLUMNS FROM products");
    if ($prodColsRes) {
        while ($c = $prodColsRes->fetch(PDO::FETCH_ASSOC)) {
            $productCols[] = $c['Field'];
        }
    }

    // Use safety_stock when available, otherwise fallback to 50 units.
    $hasSafetyStock = in_array('safety_stock', $productCols, true);
    $lowStockQuery = "SELECT COUNT(*) AS cnt
                      FROM (
                          SELECT p.Product_ID,
                                 COALESCE(SUM(si.quantity), 0) AS current_qty,
                                 " . ($hasSafetyStock ? "COALESCE(p.safety_stock, 50)" : "50") . " AS safety_threshold
                          FROM products p
                          LEFT JOIN stockin_inventory si ON p.Product_ID = si.Product_ID
                          WHERE p.is_discontinued = 0
                          GROUP BY p.Product_ID
                          HAVING current_qty <= safety_threshold
                      ) AS low_stock_items";
    $lowStockResult = $conn->query($lowStockQuery);
    if ($lowStockResult && $row = $lowStockResult->fetch(PDO::FETCH_ASSOC)) {
        $lowStocksCount = intval($row['cnt']);
    }

    // Fetch detailed low-stock products for the table
    $lowStockDetailQuery = "SELECT p.Product_ID, p.product_name,
                                   COALESCE((
                                       SELECT quantity FROM stockin_inventory
                                       WHERE Product_ID = p.Product_ID
                                       ORDER BY COALESCE(updated_at, created_at, date_in) DESC, Inventory_ID DESC
                                       LIMIT 1
                                   ), 0) AS current_qty,
                                   " . ($hasSafetyStock ? "COALESCE(p.safety_stock, 50)" : "50") . " AS replenishment_level,
                                   COALESCE((
                                       SELECT storage_limit FROM stockin_inventory
                                       WHERE Product_ID = p.Product_ID
                                       ORDER BY COALESCE(updated_at, created_at, date_in) DESC, Inventory_ID DESC
                                       LIMIT 1
                                   ), 100) AS storage_limit
                            FROM products p
                            WHERE p.is_discontinued = 0
                            HAVING current_qty <= replenishment_level
                            ORDER BY current_qty ASC";
    $lowStockDetailResult = $conn->query($lowStockDetailQuery);
    if ($lowStockDetailResult) {
        while ($detailRow = $lowStockDetailResult->fetch(PDO::FETCH_ASSOC)) {
            $lowStockProducts[] = $detailRow;
        }
    }
}

// Low stock email notification (once per day via session throttle)
if ($hasStockinTable && $hasProductsTable && $lowStocksCount > 0) {
    $today = date('Y-m-d');
    $lastNotified = $_SESSION['low_stock_notified_date'] ?? '';
    if ($lastNotified !== $today) {
        try {
            require_once __DIR__ . '/../../includes/inventory_staff_chrome.php';
            $notifyResult = notifyLowStockToStaff($conn);
            if (!empty($notifyResult['sent'])) {
                $_SESSION['low_stock_notified_date'] = $today;
            }
        } catch (Throwable $e) {
            // Email failure should not break dashboard
        }
    }
}

// Recent Sales - Build query dynamically based on available tables
$recentSales = [];
$statusSelect = $hasStatusCol ? "s.status" : "'Completed' as status";

// Build JOIN clauses based on available tables
$joinClauses = "";
$customerSelect = "'Walk-in Customer' as customer_name";
$customerGroupBy = "";

if ($hasSaleSourceTable && $hasDeliveryTable && $hasOrdersTable && $hasCustomersTable) {
    $joinClauses = "LEFT JOIN sale_source ss ON s.Sale_ID = ss.Sale_ID
                    LEFT JOIN delivery d ON ss.Delivery_ID = d.Delivery_ID
                    LEFT JOIN orders o ON d.Order_ID = o.Order_ID
                    LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID";
    $customerSelect = "COALESCE(MAX(c.customer_name), 'Walk-in Customer') as customer_name";
}

$recentQuery = "SELECT 
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
$recentResult = $conn->query($recentQuery);
if ($recentResult) {
    while ($row = $recentResult->fetch(PDO::FETCH_ASSOC)) {
        $recentSales[] = $row;
    }
}

// Recent Orders
$recentOrders = [];
if ($hasOrdersTable && $hasCustomersTable) {
    // $orderCols was built earlier during pendingOrders check; rebuild if needed
    if (empty($orderCols)) {
        $orderCols = [];
        $oc2 = $conn->query("SHOW COLUMNS FROM orders");
        if ($oc2) { while ($c = $oc2->fetch(PDO::FETCH_ASSOC)) $orderCols[] = $c['Field']; }
    }
    $orderStatusColRec = in_array('status', $orderCols) ? 'status' : (in_array('order_status', $orderCols) ? 'order_status' : null);
    $hasOrderDateCol = in_array('order_date', $orderCols);
    $hasCreatedAt = in_array('created_at', $orderCols);
    $orderDateExpr = $hasOrderDateCol ? 'DATE(o.order_date)' : ($hasCreatedAt ? 'DATE(o.created_at)' : 'NOW()');
    $orderDateAlias = $hasOrderDateCol ? 'order_date' : 'order_date';
    $statusSelectOrder = $orderStatusColRec ? "o.$orderStatusColRec as status" : "'Pending' as status";
    $hasTotalAmt = in_array('total_amount', $orderCols);
    $orderAmtExpr = $hasTotalAmt ? 'o.total_amount' : '0';
    $recentOrdersQuery = "SELECT 
                            o.Order_ID, 
                            c.customer_name, 
                            $orderAmtExpr as total_amount,
                            $orderDateExpr as order_date,
                            $statusSelectOrder
                          FROM orders o
                          LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
                          ORDER BY " . ($hasOrderDateCol ? 'o.order_date' : ($hasCreatedAt ? 'o.created_at' : 'o.Order_ID')) . " DESC LIMIT 10";
    $recentOrdersResult = $conn->query($recentOrdersQuery);
    if ($recentOrdersResult) {
        while ($row = $recentOrdersResult->fetch(PDO::FETCH_ASSOC)) {
            $recentOrders[] = $row;
        }
    }
}

// ============================================
// Deadline Notifications — Orders & Deliveries
// ============================================
$deadlineOverdueOrders = 0;
$deadlineDueTodayOrders = 0;
$deadlineDueWeekOrders = 0;
$deadlineOverdueDeliveries = 0;
$deadlineDueTodayDeliveries = 0;
$deadlineDueWeekDeliveries = 0;
if ($hasOrdersTable) {
    $oc2 = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
    $osc2 = $oc2 && $oc2->rowCount() > 0 ? $oc2->fetch(PDO::FETCH_ASSOC)['Field'] : 'order_status';
    $statusExclude = "LOWER(o.$osc2) NOT IN ('completed','cancelled','delivered (pending cash turnover)')";
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN delivery d ON o.Order_ID = d.Order_ID WHERE COALESCE(d.schedule_date, o.delivery_date, o.order_date) < CURDATE() AND $statusExclude");
        $stmt->execute(); $deadlineOverdueOrders = (int)$stmt->fetchColumn();
    } catch (Throwable $e) { $deadlineOverdueOrders = 0; }
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN delivery d ON o.Order_ID = d.Order_ID WHERE COALESCE(d.schedule_date, o.delivery_date, o.order_date) = CURDATE() AND $statusExclude");
        $stmt->execute(); $deadlineDueTodayOrders = (int)$stmt->fetchColumn();
    } catch (Throwable $e) { $deadlineDueTodayOrders = 0; }
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN delivery d ON o.Order_ID = d.Order_ID WHERE COALESCE(d.schedule_date, o.delivery_date, o.order_date) BETWEEN CURDATE() + INTERVAL 1 DAY AND CURDATE() + INTERVAL 7 DAY AND $statusExclude");
        $stmt->execute(); $deadlineDueWeekOrders = (int)$stmt->fetchColumn();
    } catch (Throwable $e) { $deadlineDueWeekOrders = 0; }
}
if ($hasDeliveryTable) {
    $delStatusExclude = "LOWER(delivery_status) NOT IN ('delivered','completed','cancelled')";
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM delivery WHERE schedule_date < CURDATE() AND $delStatusExclude");
        $stmt->execute(); $deadlineOverdueDeliveries = (int)$stmt->fetchColumn();
    } catch (Throwable $e) { $deadlineOverdueDeliveries = 0; }
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM delivery WHERE schedule_date = CURDATE() AND $delStatusExclude");
        $stmt->execute(); $deadlineDueTodayDeliveries = (int)$stmt->fetchColumn();
    } catch (Throwable $e) { $deadlineDueTodayDeliveries = 0; }
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM delivery WHERE schedule_date BETWEEN CURDATE() + INTERVAL 1 DAY AND CURDATE() + INTERVAL 7 DAY AND $delStatusExclude");
        $stmt->execute(); $deadlineDueWeekDeliveries = (int)$stmt->fetchColumn();
    } catch (Throwable $e) { $deadlineDueWeekDeliveries = 0; }
}

// ============================================
// Delivery Schedule Data (for Calendar)
// ============================================
$upcomingDeliveries = [];
$overdueDeliveries = [];
$deliveryDatesJson = '[]';
if ($hasDeliveryTable) {
    // All undelivered deliveries (including yesterday and overdue)
    $delJoin = "";
    $delSelect = "COALESCE(d.delivered_to, 'Scheduled Delivery') as customer_name, '' as phone_number";
    if ($hasOrdersTable && $hasCustomersTable) {
        $delJoin = "LEFT JOIN orders o ON d.Order_ID = o.Order_ID
                    LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID";
        $delSelect = "COALESCE(c.customer_name, d.delivered_to, 'Scheduled Delivery') as customer_name,
                      COALESCE(c.phone_number, '') as phone_number";
    }
    $delQuery = "SELECT d.Delivery_ID, d.schedule_date, d.delivery_address,
                        d.delivery_status, $delSelect
                 FROM delivery d
                 $delJoin
                 WHERE d.delivery_status NOT IN ('Delivered', 'Completed', 'Cancelled')
                 ORDER BY 
                    CASE WHEN d.schedule_date < CURDATE() THEN 0 ELSE 1 END,
                    d.schedule_date ASC
                 LIMIT 35";
    $delResult = $conn->query($delQuery);
    if ($delResult) {
        while ($row = $delResult->fetch(PDO::FETCH_ASSOC)) {
            $rowScheduleDate = !empty($row['schedule_date']) ? $row['schedule_date'] : date('Y-m-d');
            $row['is_overdue'] = strtotime($rowScheduleDate) < strtotime('today');
            if ($row['is_overdue']) {
                $overdueDeliveries[] = $row;
            } else {
                $upcomingDeliveries[] = $row;
            }
        }
    }

    // All delivery dates for calendar highlighting (ALL undelivered dates including overdue)
    $calStart = date('Y-m-01', strtotime('-3 months')); // Include past 3 months for overdue
    $calEnd = date('Y-m-t', strtotime('+1 month'));
    $dateQuery = "SELECT DISTINCT DATE(schedule_date) as del_date,
                        CASE WHEN schedule_date < CURDATE() AND delivery_status NOT IN ('Delivered', 'Completed', 'Cancelled') THEN 1 ELSE 0 END as is_overdue
                  FROM delivery
                  WHERE schedule_date BETWEEN '$calStart' AND '$calEnd'
                    AND delivery_status NOT IN ('Cancelled', 'Delivered', 'Completed')
                  ORDER BY del_date";
    $dateResult = $conn->query($dateQuery);
    $deliveryDatesArr = [];
    $overdueDatesArr = [];
    if ($dateResult) {
        while ($row = $dateResult->fetch(PDO::FETCH_ASSOC)) {
            $deliveryDatesArr[] = $row['del_date'];
            if ($row['is_overdue']) {
                $overdueDatesArr[] = $row['del_date'];
            }
        }
    }
    $deliveryDatesJson = json_encode($deliveryDatesArr);
    $overdueDatesJson = json_encode($overdueDatesArr);
}

// ============================================
// Scheduled Orders (not yet assigned to delivery)
// ============================================
$scheduledOrders = [];
if ($hasOrdersTable) {
    $orderCols = [];
    $oc = $conn->query("SHOW COLUMNS FROM orders");
    if ($oc) {
        while ($c = $oc->fetch(PDO::FETCH_ASSOC))
            $orderCols[] = $c['Field'];
    }
    $statusCol = null;
    foreach (['order_status', 'status'] as $cand) {
        if (in_array($cand, $orderCols, true)) {
            $statusCol = $cand;
            break;
        }
    }
    if ($statusCol) {
        $schedJoin = "";
        $schedSelect = "o.Order_ID, o.order_date as schedule_date, 'Scheduled Order' as customer_name";
        if ($hasCustomersTable) {
            $schedJoin = "LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID";
            $schedSelect = "o.Order_ID, o.order_date as schedule_date, COALESCE(c.customer_name, 'Walk-in Customer') as customer_name, COALESCE(c.phone_number, '') as phone_number, COALESCE(c.address, '') as delivery_address";
        }
        $schedQuery = "SELECT $schedSelect
                      FROM orders o
                      $schedJoin
                      WHERE o.$statusCol IN ('Pending', 'Confirmed', 'Processing')
                        AND o.Order_ID NOT IN (SELECT Order_ID FROM delivery WHERE Order_ID IS NOT NULL)
                      ORDER BY o.order_date ASC
                      LIMIT 10";
        $schedResult = $conn->query($schedQuery);
        if ($schedResult) {
            while ($row = $schedResult->fetch(PDO::FETCH_ASSOC)) {
                $row['is_scheduled_order'] = true;
                $scheduledOrders[] = $row;
            }
        }
    }
}

// ============================================
// Damage Goods Analytics
// ============================================
$damageThisMonth = 0;
$damageLastMonth = 0;
$damageLoss = 0;
$damageChange = 0;

if ($hasDamageGoodsTable) {
    $dmgQuery = "SELECT COALESCE(SUM(quantity), 0) as total FROM damage_goods WHERE created_at >= '$currentMonth'";
    $dmgResult = $conn->query($dmgQuery);
    if ($dmgResult && $row = $dmgResult->fetch(PDO::FETCH_ASSOC)) {
        $damageThisMonth = intval($row['total']);
    }

    $dmgLastQuery = "SELECT COALESCE(SUM(quantity), 0) as total FROM damage_goods WHERE created_at >= '$lastMonth' AND created_at < '$currentMonth'";
    $dmgLastResult = $conn->query($dmgLastQuery);
    if ($dmgLastResult && $row = $dmgLastResult->fetch(PDO::FETCH_ASSOC)) {
        $damageLastMonth = intval($row['total']);
    }

    if ($damageLastMonth > 0) {
        $damageChange = round((($damageThisMonth - $damageLastMonth) / $damageLastMonth) * 100, 1);
    }

    // Monetary loss (last 30 days)
    if ($hasStockinTable && $hasProductsTable) {
        $lossQuery = "SELECT COALESCE(SUM(dg.quantity * p.retail_price), 0) as total_loss
                      FROM damage_goods dg
                      JOIN stockin_inventory si ON dg.Inventory_ID = si.Inventory_ID
                      JOIN products p ON si.Product_ID = p.Product_ID
                      WHERE dg.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $lossResult = $conn->query($lossQuery);
        if ($lossResult && $row = $lossResult->fetch(PDO::FETCH_ASSOC)) {
            $damageLoss = floatval($row['total_loss']);
        }
    }
}

// Format currency function
function formatPeso($amount)
{
    return '₱' . number_format($amount, 2);
}

?>
