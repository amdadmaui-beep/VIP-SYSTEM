<?php
session_start();

require_once 'includes/auth.php';
require_once 'includes/db.php';

// ============================================
// Detect available columns in sales table
// ============================================
$salesCols = [];
$colsResult = $conn->query("SHOW COLUMNS FROM sales");
if ($colsResult) {
    while ($col = $colsResult->fetch_assoc()) {
        $salesCols[] = $col['Field'];
    }
}
$hasStatusCol = in_array('status', $salesCols);
$hasDeliveryIdCol = in_array('Delivery_ID', $salesCols);

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
if ($salesResult && $row = $salesResult->fetch_assoc()) {
    $totalSales = floatval($row['total_sales']);
}

// Last Month Sales
$lastMonthSales = 0;
$lastMonthQuery = "SELECT COALESCE(SUM(sd.subtotal), 0) as total_sales
                   FROM sales s
                   INNER JOIN sale_details sd ON s.Sale_ID = sd.Sale_ID
                   WHERE DATE(s.created_at) >= '$lastMonth' AND DATE(s.created_at) < '$currentMonth'";
$lastMonthResult = $conn->query($lastMonthQuery);
if ($lastMonthResult && $row = $lastMonthResult->fetch_assoc()) {
    $lastMonthSales = floatval($row['total_sales']);
}
if ($lastMonthSales > 0) {
    $salesChange = round((($totalSales - $lastMonthSales) / $lastMonthSales) * 100, 1);
}

// Total Inventory (active products)
$totalInventory = 0;
$inventoryQuery = "SELECT COUNT(*) as total FROM products WHERE is_discontinued = 0";
$inventoryResult = $conn->query($inventoryQuery);
if ($inventoryResult && $row = $inventoryResult->fetch_assoc()) {
    $totalInventory = intval($row['total']);
}

// Accounts Receivable - Use existing account_receivable table with amount_due column
$arTotal = 0;
$arCount = 0;
$arTableCheck = $conn->query("SHOW TABLES LIKE 'account_receivable'");
if ($arTableCheck && $arTableCheck->num_rows > 0) {
    // Use AR table - amount_due is the balance column in your schema
    $arQuery = "SELECT COUNT(*) as ar_count, COALESCE(SUM(amount_due), 0) as total_ar
                FROM account_receivable 
                WHERE status IN ('Open', 'Partial', 'Overdue', 'Pending')";
    $arResult = $conn->query($arQuery);
    if ($arResult && $row = $arResult->fetch_assoc()) {
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
    if ($arResult && $row = $arResult->fetch_assoc()) {
        $arCount = intval($row['ar_count']);
        $arTotal = floatval($row['total_ar']);
    }
}

// Total Customers
$totalCustomers = 0;
$newCustomers = 0;
if ($hasCustomersTable) {
    $customersQuery = "SELECT COUNT(*) as total FROM customers";
    $customersResult = $conn->query($customersQuery);
    if ($customersResult && $row = $customersResult->fetch_assoc()) {
        $totalCustomers = intval($row['total']);
    }

    $newCustomersQuery = "SELECT COUNT(*) as new_customers FROM customers WHERE DATE(created_at) >= '$currentMonth'";
    $newCustomersResult = $conn->query($newCustomersQuery);
    if ($newCustomersResult && $row = $newCustomersResult->fetch_assoc()) {
        $newCustomers = intval($row['new_customers']);
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
    while ($row = $recentResult->fetch_assoc()) {
        $recentSales[] = $row;
    }
}

// Sales Trend (Last 7 Days)
$salesTrend = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $salesTrend[$date] = ['label' => date('M j', strtotime($date)), 'amount' => 0];
}
$trendQuery = "SELECT DATE(s.created_at) as sale_date, COALESCE(SUM(sd.subtotal), 0) as daily_total
               FROM sales s
               INNER JOIN sale_details sd ON s.Sale_ID = sd.Sale_ID
               WHERE DATE(s.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
               GROUP BY DATE(s.created_at)";
$trendResult = $conn->query($trendQuery);
if ($trendResult) {
    while ($row = $trendResult->fetch_assoc()) {
        if (isset($salesTrend[$row['sale_date']])) {
            $salesTrend[$row['sale_date']]['amount'] = floatval($row['daily_total']);
        }
    }
}

// Top Products
$topProducts = [];
$topQuery = "SELECT p.product_name, p.form, p.unit, COALESCE(SUM(sd.subtotal), 0) as total_revenue
             FROM sale_details sd
             INNER JOIN sales s ON sd.Sale_ID = s.Sale_ID
             INNER JOIN products p ON sd.Product_ID = p.Product_ID
             WHERE DATE(s.created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             GROUP BY p.Product_ID, p.product_name, p.form, p.unit
             ORDER BY total_revenue DESC
             LIMIT 5";
$topResult = $conn->query($topQuery);
if ($topResult) {
    while ($row = $topResult->fetch_assoc()) {
        $name = $row['product_name'];
        if ($row['form']) $name .= ' (' . $row['form'] . ')';
        if ($row['unit']) $name .= ' ' . $row['unit'];
        $topProducts[] = ['name' => $name, 'revenue' => floatval($row['total_revenue'])];
    }
}

// Format currency function
function formatPeso($amount) {
    return '₱' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<div class="dashboard-wrapper">
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-brand">
                <div class="brand-icon">
                    <i class="fas fa-snowflake"></i>
                </div>
                <div class="brand-text">
                    <h2>Villanueva</h2>
                    <p>Ice Plant System</p>
                </div>
            </div>
            <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
                <i class="fas fa-angles-left"></i>
            </button>
        </div>

        <nav class="sidebar-menu">
            <div class="menu-section">
                <div class="menu-label">Main Menu</div>
                <a href="index.php" class="menu-item active">
                    <i class="fas fa-th-large"></i>
                    <span>Dashboard</span>
                </a>
                <a href="pages/sales.php" class="menu-item">
                    <i class="fas fa-receipt"></i>
                    <span>Sales</span>
                </a>
                <a href="pages/inventory.php" class="menu-item">
                    <i class="fas fa-cubes"></i>
                    <span>Inventory</span>
                </a>
                 <a href="production.php" class="menu-item">
                    <i class="fas fa-industry"></i>
                    <span>Production</span>
                </a>
                <a href="pages/users.php" class="menu-item">
                    <i class="fas fa-users"></i>
                    <span>Customers</span>
                </a>
                
                <a href="pages/orders.php" class="menu-item">
                    <i class="fas fa-shopping-cart"></i>
                    <span>Orders</span>
                </a>
            </div>

            <div class="menu-section">
                <div class="menu-label">Accounting</div>
                <a href="pages/accounts_receivable.php" class="menu-item">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <span>Accounts Receivable</span>
                    <?php 
                    // Get AR count - use existing table structure
                    $ar_count_badge = 0;
                    $ar_check_badge = $conn->query("SHOW TABLES LIKE 'account_receivable'");
                    if ($ar_check_badge && $ar_check_badge->num_rows > 0) {
                        $ar_count_result = $conn->query("SELECT COUNT(*) as cnt FROM account_receivable WHERE status IN ('Open', 'Partial', 'Overdue', 'Pending') AND amount_due > 0");
                        if ($ar_count_result) {
                            $ar_count_badge = intval($ar_count_result->fetch_assoc()['cnt']);
                        }
                    }
                    if ($ar_count_badge > 0): ?>
                    <span class="menu-item-badge"><?php echo $ar_count_badge; ?></span>
                    <?php endif; ?>
                </a>
                <a href="#" class="menu-item">
                    <i class="fas fa-money-check-alt"></i>
                    <span>Payments</span>
                </a>
                <a href="pages/reports.php" class="menu-item">
                    <i class="fas fa-chart-line"></i>
                    <span>Reports</span>
                </a>
            </div>

            <div class="menu-section">
                <div class="menu-label">System</div>
                <a href="#" class="menu-item">
                    <i class="fas fa-cog"></i>
                    <span>Settings</span>
                </a>
                <a href="#" class="menu-item">
                    <i class="fas fa-user-circle"></i>
                    <span>Profile</span>
                </a>
            </div>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <button class="mobile-sidebar-toggle" id="mobileSidebarToggle" aria-label="Toggle sidebar">
            <i class="fas fa-bars"></i>
        </button>
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <div class="welcome-content">
                <h2>
                    <i class="fas fa-snowflake"></i>
                    Welcome back, <?php echo isset($_SESSION['user_name']) ? htmlspecialchars($_SESSION['user_name']) : 'User'; ?>!
                </h2>
                <p>Here's a snapshot of your Villanueva Ice Plant today.</p>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="pages/sales.php" class="action-btn">
                <div class="action-btn-icon">
                    <i class="fas fa-receipt"></i>
                </div>
                <div class="action-btn-text">New Sale</div>
            </a>
            <a href="pages/products.php" class="action-btn">
                <div class="action-btn-icon">
                    <i class="fas fa-box"></i>
                </div>
                <div class="action-btn-text">Add Inventory</div>
            </a>
            <a href="#" class="action-btn">
                <div class="action-btn-icon">
                    <i class="fas fa-credit-card"></i>
                </div>
                <div class="action-btn-text">Record Payment</div>
            </a>
            <a href="pages/users.php" class="action-btn">
                <div class="action-btn-icon">
                    <i class="fas fa-user-plus"></i>
                </div>
                <div class="action-btn-text">New Customer</div>
            </a>
        </div>

        <!-- Statistics Cards -->
        <div class="stat-row">
            <div class="stat-card">
                <div class="stat-content">
                    <div class="stat-info">
                        <div class="stat-label">Total Sales (This Month)</div>
                        <div class="stat-value"><?php echo formatPeso($totalSales); ?></div>
                        <div class="stat-change <?php echo $salesChange >= 0 ? 'positive' : 'negative'; ?>">
                            <i class="fas fa-arrow-<?php echo $salesChange >= 0 ? 'up' : 'down'; ?>"></i> <?php echo abs($salesChange); ?>% from last month
                        </div>
                    </div>
                    <div class="stat-icon purple">
                        <i class="fas fa-peso-sign"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-content">
                    <div class="stat-info">
                        <div class="stat-label">Total Inventory</div>
                        <div class="stat-value"><?php echo $totalInventory; ?></div>
                        <div class="stat-change neutral">
                            <i class="fas fa-cubes"></i> <?php echo $totalInventory; ?> active products
                        </div>
                    </div>
                    <div class="stat-icon green">
                        <i class="fas fa-cubes"></i>
                    </div>
                </div>
            </div>

            <a href="pages/products_add.php" class="stat-card stat-card-link" style="text-decoration: none; cursor: pointer; display: block;" role="button" aria-label="Add product">
                <div class="stat-content">
                    <div class="stat-info">
                        <div class="stat-label">Add Product</div>
                        <div class="stat-value" style="font-size: 1.5rem; color: #6366f1;">+</div>
                        <div class="stat-change neutral">
                            <i class="fas fa-plus-circle"></i> Add new product
                        </div>
                    </div>
                    <div class="stat-icon purple">
                        <i class="fas fa-box-open"></i>
                    </div>
                </div>
            </a>

            <div class="stat-card">
                <div class="stat-content">
                    <div class="stat-info">
                        <div class="stat-label">Accounts Receivable</div>
                        <div class="stat-value"><?php echo formatPeso($arTotal); ?></div>
                        <div class="stat-change <?php echo $arCount > 0 ? 'negative' : 'neutral'; ?>">
                            <i class="fas fa-exclamation-triangle"></i> <?php echo $arCount; ?> pending
                        </div>
                    </div>
                    <div class="stat-icon amber">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-content">
                    <div class="stat-info">
                        <div class="stat-label">Total Customers</div>
                        <div class="stat-value"><?php echo $totalCustomers; ?></div>
                        <div class="stat-change positive">
                            <i class="fas fa-arrow-up"></i> <?php echo $newCustomers; ?> this month
                        </div>
                    </div>
                    <div class="stat-icon pink">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="grid-2">
            <div class="chart-container">
                <h3 class="chart-title">
                    <i class="fas fa-chart-line"></i>
                    Sales Trend (Last 7 Days)
                </h3>
                <div style="position: relative; height: 300px;">
                    <canvas id="salesTrendChart"></canvas>
                </div>
            </div>

            <div class="chart-container">
                <h3 class="chart-title">
                    <i class="fas fa-chart-bar"></i>
                    Top Products (Last 30 Days)
                </h3>
                <div style="position: relative; height: 300px;">
                    <canvas id="topProductsChart"></canvas>
                </div>
                <?php if (empty($topProducts)): ?>
                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; color: #94a3b8;">
                    <i class="fas fa-box-open" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                    <p>No sales data yet</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Transactions -->
        <div class="table-container">
            <h3 class="chart-title">
                <i class="fas fa-receipt"></i>
                Recent Sales Transactions
            </h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Sale ID</th>
                        <th>Customer</th>
                        <th>Amount</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentSales)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 2rem; color: #94a3b8;">
                            <i class="fas fa-inbox"></i> No recent sales transactions
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($recentSales as $sale): ?>
                    <tr>
                        <td>#<?php echo $sale['Sale_ID']; ?></td>
                        <td><?php echo htmlspecialchars($sale['customer_name'] ?? 'Walk-in Customer'); ?></td>
                        <td><?php echo formatPeso($sale['total_amount']); ?></td>
                        <td><?php echo date('M j, Y', strtotime($sale['sale_date'])); ?></td>
                        <td>
                            <?php 
                            $status = strtolower($sale['status'] ?? 'pending');
                            if ($status === 'completed'): ?>
                            <span class="badge badge-success"><i class="fas fa-check-circle"></i> Completed</span>
                            <?php elseif ($status === 'pending'): ?>
                            <span class="badge badge-warning"><i class="fas fa-clock"></i> Pending</span>
                            <?php elseif ($status === 'cancelled'): ?>
                            <span class="badge badge-danger"><i class="fas fa-times-circle"></i> Cancelled</span>
                            <?php else: ?>
                            <span class="badge badge-info"><i class="fas fa-circle"></i> <?php echo htmlspecialchars($sale['status']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><a href="pages/sale_view.php?id=<?php echo $sale['Sale_ID']; ?>" class="action-link">View <i class="fas fa-arrow-right"></i></a></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>

<script src="assets/js/script.js"></script>
<script>
// Chart Data from PHP
const salesTrendData = {
    labels: [<?php echo implode(',', array_map(function($d) { return "'" . $d['label'] . "'"; }, $salesTrend)); ?>],
    data: [<?php echo implode(',', array_column($salesTrend, 'amount')); ?>]
};

const topProductsData = {
    labels: [<?php echo implode(',', array_map(function($p) { return "'" . addslashes($p['name']) . "'"; }, $topProducts)); ?>],
    revenues: [<?php echo implode(',', array_column($topProducts, 'revenue')); ?>]
};

// Initialize Charts
document.addEventListener('DOMContentLoaded', function() {
    initSalesTrendChart();
    initTopProductsChart();
});

function initSalesTrendChart() {
    const ctx = document.getElementById('salesTrendChart');
    if (!ctx) return;
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: salesTrendData.labels,
            datasets: [{
                label: 'Sales (₱)',
                data: salesTrendData.data,
                borderColor: '#6366f1',
                backgroundColor: 'rgba(99, 102, 241, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointRadius: 6,
                pointHoverRadius: 8,
                pointBackgroundColor: '#6366f1',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.9)',
                    padding: 12,
                    callbacks: {
                        label: function(context) {
                            return '₱' + context.parsed.y.toLocaleString('en-US', { minimumFractionDigits: 2 });
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) { return '₱' + value.toLocaleString(); }
                    },
                    grid: { color: 'rgba(241, 245, 249, 0.8)' }
                },
                x: { grid: { display: false } }
            }
        }
    });
}

function initTopProductsChart() {
    const ctx = document.getElementById('topProductsChart');
    if (!ctx) return;
    
    const labels = topProductsData.labels.length > 0 ? topProductsData.labels : ['No data'];
    const data = topProductsData.revenues.length > 0 ? topProductsData.revenues : [0];
    
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Revenue (₱)',
                data: data,
                backgroundColor: [
                    'rgba(99, 102, 241, 0.8)',
                    'rgba(139, 92, 246, 0.8)',
                    'rgba(168, 85, 247, 0.8)',
                    'rgba(192, 132, 252, 0.8)',
                    'rgba(221, 214, 254, 0.8)'
                ],
                borderColor: ['#6366f1', '#8b5cf6', '#a855f7', '#c084fc', '#ddd6fe'],
                borderWidth: 2,
                borderRadius: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.9)',
                    padding: 12,
                    callbacks: {
                        label: function(context) {
                            return '₱' + context.parsed.x.toLocaleString('en-US', { minimumFractionDigits: 2 });
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) { return '₱' + value.toLocaleString(); }
                    },
                    grid: { color: 'rgba(241, 245, 249, 0.8)' }
                },
                y: { grid: { display: false } }
            }
        }
    });
}
</script>
</body>
</html>
