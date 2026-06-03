<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/roles_helper.php';
require_once __DIR__ . '/../../includes/damage_type_helper.php';

// Restricted to Managers and Owners
$allowed_roles = [1, 2, 4];
requireRole($allowed_roles);

$is_manager = in_array((int)($_SESSION['user_role'] ?? 0), [2, 4]);
$is_owner = (int)($_SESSION['user_role'] ?? 0) === 1;

// Filter period handling
$period = $_GET['period'] ?? 'all';
$filter_month = intval($_GET['filter_month'] ?? 0);
$filter_year = intval($_GET['filter_year'] ?? 0);
$where_clause = "";
$params = [];

switch ($period) {
    case 'daily':
        $where_clause = " WHERE DATE(dg.created_at) = CURDATE() ";
        break;
    case 'weekly':
        $where_clause = " WHERE dg.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) ";
        break;
    case 'custom_month':
        if ($filter_month >= 1 && $filter_month <= 12 && $filter_year >= 2000) {
            $where_clause = " WHERE MONTH(dg.created_at) = ? AND YEAR(dg.created_at) = ? ";
            $params = [$filter_month, $filter_year];
        } else {
            $period = 'all';
        }
        break;
    default:
        $period = 'all';
        $where_clause = "";
        break;
}

// Fetch damage history with retail price for monetary loss calculation
$history_query = "SELECT 
    dg.Damage_ID,
    p.product_name,
    p.retail_price,
    u_units.unit_name,
    dg.quantity,
    (dg.quantity * p.retail_price) as estimated_loss,
    dg.damage_type as damage_type_label,
    dg.damage_type as damage_type_raw,
    dg.reason,
    au.full_name as reported_by_name,
    dg.created_at
FROM damage_goods dg
INNER JOIN stockin_inventory si ON dg.Inventory_ID = si.Inventory_ID
INNER JOIN products p ON si.Product_ID = p.Product_ID
LEFT JOIN units u_units ON p.unit_id = u_units.unit_id
LEFT JOIN user au ON dg.reported_by = au.User_ID
$where_clause
ORDER BY dg.created_at DESC";

$history_stmt = $conn->prepare($history_query);
$history_stmt->execute($params);
$history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch products for the reporting form
$products_query = "SELECT p.Product_ID, p.product_name, u.unit_name, 
    COALESCE((SELECT quantity FROM stockin_inventory WHERE Product_ID = p.Product_ID ORDER BY updated_at DESC LIMIT 1), 0) as current_quantity
    FROM products p 
    LEFT JOIN units u ON p.unit_id = u.unit_id 
    WHERE p.is_discontinued = 0 
    ORDER BY p.product_name";
$products = $conn->query($products_query)->fetchAll(PDO::FETCH_ASSOC);

$damage_types = getDamageTypeOptions();

// Unit quantity stats
$total_damaged_today   = $conn->query("SELECT SUM(quantity) FROM damage_goods WHERE DATE(created_at) = CURDATE()")->fetchColumn() ?: 0;
$total_damaged_month   = $conn->query("SELECT SUM(quantity) FROM damage_goods WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())")->fetchColumn() ?: 0;

// Monetary loss stats (quantity × retail_price)
$loss_today = $conn->query("
    SELECT COALESCE(SUM(dg.quantity * p.retail_price), 0)
    FROM damage_goods dg
    INNER JOIN stockin_inventory si ON dg.Inventory_ID = si.Inventory_ID
    INNER JOIN products p ON si.Product_ID = p.Product_ID
    WHERE DATE(dg.created_at) = CURDATE()
")->fetchColumn() ?: 0;

$loss_month = $conn->query("
    SELECT COALESCE(SUM(dg.quantity * p.retail_price), 0)
    FROM damage_goods dg
    INNER JOIN stockin_inventory si ON dg.Inventory_ID = si.Inventory_ID
    INNER JOIN products p ON si.Product_ID = p.Product_ID
    WHERE MONTH(dg.created_at) = MONTH(CURDATE()) AND YEAR(dg.created_at) = YEAR(CURDATE())
")->fetchColumn() ?: 0;

$loss_total = $conn->query("
    SELECT COALESCE(SUM(dg.quantity * p.retail_price), 0)
    FROM damage_goods dg
    INNER JOIN stockin_inventory si ON dg.Inventory_ID = si.Inventory_ID
    INNER JOIN products p ON si.Product_ID = p.Product_ID
")->fetchColumn() ?: 0;

// Filtered period stats (for custom_month)
$filtered_loss = 0;
$filtered_total_units = 0;
if ($period === 'custom_month' && !empty($params)) {
    $f_stmt = $conn->prepare("
        SELECT COALESCE(SUM(dg.quantity), 0) as total_units,
               COALESCE(SUM(dg.quantity * p.retail_price), 0) as total_loss
        FROM damage_goods dg
        INNER JOIN stockin_inventory si ON dg.Inventory_ID = si.Inventory_ID
        INNER JOIN products p ON si.Product_ID = p.Product_ID
        $where_clause
    ");
    $f_stmt->execute($params);
    $f_row = $f_stmt->fetch(PDO::FETCH_ASSOC);
    $filtered_total_units = $f_row['total_units'];
    $filtered_loss = $f_row['total_loss'];
}

function pesoLoss(float $val): string {
    return '₱' . number_format($val, 2);
}
function wholeNumber($val): string {
    return number_format((float)$val, 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Damage Goods Management - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <link rel="stylesheet" href="../assets/css/inventory.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .damage-header-content {
            background: linear-gradient(135deg, #f43f5e 0%, #e11d48 100%);
            box-shadow: 0 20px 60px rgba(225, 29, 72, 0.3);
        }
        .stat-icon.rose {
            background: linear-gradient(135deg, #fff1f2, #ffe4e6);
            color: #e11d48;
        }
        .btn-report-damage {
            background: linear-gradient(135deg, #f43f5e 0%, #e11d48 100%);
            box-shadow: 0 4px 12px rgba(225, 29, 72, 0.3);
        }
        .btn-report-damage:hover {
            background: linear-gradient(135deg, #e11d48 0%, #f43f5e 100%);
            box-shadow: 0 8px 20px rgba(225, 29, 72, 0.4);
        }
        .damage-header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 1rem;
            width: 100%;
        }
        .damage-period-filter {
            display: flex;
            flex-wrap: wrap;
            background: rgba(255,255,255,0.15);
            padding: 0.35rem;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.2);
            backdrop-filter: blur(8px);
        }
        .damage-period-link {
            padding: 0.5rem 1.25rem;
            border-radius: 10px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .damage-history-table .damage-reason-cell {
            max-width: 220px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .damage-history-table .damage-total-row td {
            background: #fff1f2;
        }
        .damage-history-table .damage-total-label {
            text-align: right;
            padding: 1rem 1.5rem;
            color: #9f1239;
        }
        .damage-history-table .damage-total-value {
            color: #e11d48;
            font-size: 1.05rem;
        }
        .damage-reason-field {
            width: 100%;
            border-radius: 12px;
            padding: 10px;
            border: 1px solid #e2e8f0;
            font-family: inherit;
            resize: vertical;
            min-height: 96px;
        }
        @media (max-width: 768px) {
            .inventory-header-content {
                padding: 1.5rem;
            }
            .damage-header-top {
                flex-direction: column;
                align-items: stretch;
            }
            .damage-period-filter {
                width: 100%;
                justify-content: space-between;
            }
            .damage-period-link {
                flex: 1 1 calc(50% - 0.35rem);
                text-align: center;
                padding: 0.65rem 0.75rem;
            }
            .inventory-title {
                font-size: 1.5rem;
                align-items: flex-start;
            }
            .inventory-subtitle {
                font-size: 0.95rem;
            }
            .inventory-stats {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            .stat-card {
                padding: 1.25rem;
                border-radius: 20px;
            }
            .stat-content {
                gap: 1rem;
            }
            .stat-info h3 {
                font-size: 1.7rem;
            }
            .inventory-table-container {
                padding: 1rem;
            }
            .damage-history-table thead {
                display: none;
            }
            .damage-history-table,
            .damage-history-table tbody,
            .damage-history-table tr,
            .damage-history-table td {
                display: block;
                width: 100%;
            }
            .damage-history-table tbody {
                display: grid;
                gap: 1rem;
            }
            .damage-history-table tbody tr {
                border: 1px solid #e2e8f0;
                border-radius: 18px;
                padding: 1rem;
                background: #ffffff;
                box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
            }
            .damage-history-table tbody tr:hover {
                transform: none;
            }
            .damage-history-table td {
                border-bottom: none;
                padding: 0.45rem 0;
                display: flex;
                justify-content: space-between;
                gap: 1rem;
                align-items: flex-start;
                text-align: right;
            }
            .damage-history-table td::before {
                content: attr(data-label);
                flex: 0 0 42%;
                text-align: left;
                font-size: 0.72rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                color: #64748b;
            }
            .damage-history-table .damage-reason-cell {
                max-width: none;
                white-space: normal;
                overflow: visible;
                text-overflow: initial;
                flex-direction: column;
                align-items: flex-start;
                text-align: left;
            }
            .damage-history-table .damage-reason-cell::before {
                margin-bottom: 0.35rem;
            }
            .damage-history-table .damage-total-row {
                background: linear-gradient(135deg, #fff1f2, #ffe4e6);
            }
            .damage-history-table .damage-total-row td {
                display: block;
                text-align: left;
                padding: 0.2rem 0;
                background: transparent;
            }
            .damage-history-table .damage-total-row td::before {
                content: none;
            }
            .damage-history-table .damage-empty-row td::before {
                content: none;
            }
            .damage-history-table .damage-empty-row td {
                text-align: center;
            }
            .damage-history-table .damage-total-row td:empty {
                display: none;
            }
            .damage-history-table .damage-total-label {
                padding: 0;
                margin-bottom: 0.35rem;
            }
            .inventory-modal-content {
                width: calc(100% - 1rem);
                margin: 2vh auto;
                max-height: 96vh;
                overflow-y: auto;
            }
            .inventory-modal-header,
            .inventory-modal-body {
                padding-left: 1rem;
                padding-right: 1rem;
            }
            .inventory-modal-header h2 {
                font-size: 1.05rem;
            }
            .inventory-modal-actions {
                flex-direction: column-reverse;
            }
            .inventory-modal-btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-wrapper">
    <!-- Sidebar (Will be updated in next step) -->
    <?php
    require_once __DIR__ . '/../../includes/sidebar.php';
    renderSidebar($conn, ['base' => '../', 'active' => 'damage_goods']);
    ?>
    <aside class="sidebar legacy-sidebar" style="display:none;">
        <!-- Sidebar content similar to inventory.php -->
        <div class="sidebar-header">
            <div class="sidebar-brand">
                <div class="brand-icon"><i class="fas fa-snowflake"></i></div>
                <div class="brand-text"><h2>Villanueva</h2><p>Ice Plant System</p></div>
            </div>
        </div>
        <nav class="sidebar-menu">
            <div class="menu-section">
                <div class="menu-label">Main Menu</div>
                <a href="../index.php" class="menu-item"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
                <a href="sales.php" class="menu-item"><i class="fas fa-receipt"></i><span>Sales</span></a>
                <a href="inventory.php" class="menu-item"><i class="fas fa-cubes"></i><span>Inventory</span></a>
                <a href="damage_goods.php" class="menu-item active"><i class="fas fa-heart-broken"></i><span>Damage Goods</span></a>
                <a href="stock_ledger.php" class="menu-item"><i class="fas fa-file-invoice"></i><span>Stock Ledger</span></a>
                <a href="users.php" class="menu-item"><i class="fas fa-users"></i><span>Customers</span></a>
                <a href="orders.php" class="menu-item"><i class="fas fa-shopping-cart"></i><span>Orders</span></a>
                <a href="delivery.php" class="menu-item"><i class="fas fa-truck"></i><span>Delivery</span></a>
            </div>
            <div class="menu-section">
                <div class="menu-label">System</div>
                <a href="activity_logs.php" class="menu-item"><i class="fas fa-history"></i><span>Activity Logs</span></a>
                <?php if ($is_manager || $is_owner): ?>
                <a href="user_management.php" class="menu-item"><i class="fas fa-user-shield"></i><span>User Management</span></a>
                <?php endif; ?>
                <a href="../logout.php" class="menu-item"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </nav>
    </aside>

    <main class="main-content" id="mainContent">
        <section class="inventory-header">
            <div class="inventory-header-content damage-header-content">
                <div class="damage-header-top">
                    <div>
                        <h1 class="inventory-title">
                            <i class="fas fa-heart-broken"></i>
                            Damage Goods Management
                        </h1>
                        <p class="inventory-subtitle">Track and report product losses and damages</p>
                    </div>
                    
                    <!-- Period Filter UI -->
                    <div class="damage-period-filter">
                        <a href="?period=all" class="damage-period-link" style="color: <?php echo $period === 'all' ? '#e11d48' : 'white'; ?>; background: <?php echo $period === 'all' ? 'white' : 'transparent'; ?>;">All Time</a>
                        <a href="?period=daily" class="damage-period-link" style="color: <?php echo $period === 'daily' ? '#e11d48' : 'white'; ?>; background: <?php echo $period === 'daily' ? 'white' : 'transparent'; ?>;">Daily</a>
                        <a href="?period=weekly" class="damage-period-link" style="color: <?php echo $period === 'weekly' ? '#e11d48' : 'white'; ?>; background: <?php echo $period === 'weekly' ? 'white' : 'transparent'; ?>;">Weekly</a>
                        <div style="display:flex;gap:4px;align-items:center;">
                            <select id="filterMonth" style="padding:6px 10px;border-radius:8px;border:none;font-weight:600;font-size:0.8rem;background:<?php echo $period === 'custom_month' ? 'white' : 'rgba(255,255,255,0.25)'; ?>;color:<?php echo $period === 'custom_month' ? '#e11d48' : 'white'; ?>;">
                                <option value="1">January</option>
                                <option value="2">February</option>
                                <option value="3">March</option>
                                <option value="4">April</option>
                                <option value="5">May</option>
                                <option value="6">June</option>
                                <option value="7">July</option>
                                <option value="8">August</option>
                                <option value="9">September</option>
                                <option value="10">October</option>
                                <option value="11">November</option>
                                <option value="12">December</option>
                            </select>
                            <select id="filterYear" style="padding:6px 10px;border-radius:8px;border:none;font-weight:600;font-size:0.8rem;background:<?php echo $period === 'custom_month' ? 'white' : 'rgba(255,255,255,0.25)'; ?>;color:<?php echo $period === 'custom_month' ? '#e11d48' : 'white'; ?>;">
                                <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                                    <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                            <button onclick="applyCustomMonth()" style="padding:6px 14px;border-radius:8px;border:none;background:white;color:#e11d48;font-weight:700;cursor:pointer;font-size:0.8rem;">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        
        <?php
        $pending_count = countPendingDeliveryDamageReports($conn);
        if ($pending_count > 0 && userCanAccessDeliveryDamageQueue($conn, (int)($_SESSION['user_role'] ?? 0))):
        ?>
        <section class="inventory-stats" style="margin-bottom: 1.5rem; display: block;">
            <div class="stat-card" style="border-left: 4px solid #e11d48; background: #fff1f2; cursor: pointer; display: flex; align-items: center; justify-content: space-between; padding: 1.5rem; border-radius: 20px;" onclick="location.href='delivery_damage_queue.php'">
                <div style="display: flex; align-items: center; gap: 1.25rem;">
                    <div style="background: #ffe4e6; color: #e11d48; width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <div>
                        <h4 style="color: #9f1239; margin: 0; font-weight: 700; font-size: 1.1rem;"><?php echo $pending_count; ?> Pending Rider Reports</h4>
                        <p style="color: #be123c; margin: 0.1rem 0 0 0; font-size: 0.85rem; font-weight: 500;">Review damage reports submitted during deliveries.</p>
                    </div>
                </div>
                <div style="color: #e11d48; font-weight: 800; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; background: white; padding: 0.5rem 1rem; border-radius: 10px; border: 1px solid #fecdd3;">
                    Review Now <i class="fas fa-chevron-right" style="margin-left: 0.5rem; font-size: 0.7rem;"></i>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <section class="inventory-stats" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
            <div class="stat-card">
                <div class="stat-content">
                    <div class="stat-info">
                        <p>Damaged Today</p>
                        <h3><?php echo wholeNumber($total_damaged_today); ?> <small style="font-size:0.55em;font-weight:500;">units</small></h3>
                        <div class="stat-change neutral">Units reported today</div>
                    </div>
                    <div class="stat-icon rose"><i class="fas fa-calendar-day"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-content">
                    <div class="stat-info">
                        <p>Damaged This Month</p>
                        <h3><?php echo wholeNumber($total_damaged_month); ?> <small style="font-size:0.55em;font-weight:500;">units</small></h3>
                        <div class="stat-change neutral">Units reported this month</div>
                    </div>
                    <div class="stat-icon purple"><i class="fas fa-calendar-alt"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-content">
                    <div class="stat-info">
                        <p>Loss Today</p>
                        <h3 style="font-size:1.3rem;"><?php echo pesoLoss($loss_today); ?></h3>
                        <div class="stat-change negative"><i class="fas fa-arrow-down"></i> Est. monetary loss today</div>
                    </div>
                    <div class="stat-icon rose"><i class="fas fa-peso-sign"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-content">
                    <div class="stat-info">
                        <p>Loss This Month</p>
                        <h3 style="font-size:1.3rem;"><?php echo pesoLoss($loss_month); ?></h3>
                        <div class="stat-change negative"><i class="fas fa-arrow-down"></i> Est. monetary loss this month</div>
                    </div>
                    <div class="stat-icon purple"><i class="fas fa-peso-sign"></i></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-content">
                    <div class="stat-info">
                        <p>Total Loss (All Time)</p>
                        <h3 style="font-size:1.3rem;color:#e11d48;"><?php echo pesoLoss($loss_total); ?></h3>
                        <div class="stat-change negative"><i class="fas fa-skull"></i> Cumulative damage losses</div>
                    </div>
                    <div class="stat-icon rose"><i class="fas fa-chart-line"></i></div>
                </div>
            </div>
            <?php if ($period === 'custom_month'): ?>
            <div class="stat-card" style="border: 2px solid #e11d48;">
                <div class="stat-content">
                    <div class="stat-info">
                        <p>Filtered: <?php echo date('F Y', mktime(0, 0, 0, $filter_month, 1, $filter_year)); ?></p>
                        <h3 style="font-size:1.3rem;"><?php echo pesoLoss($filtered_loss); ?></h3>
                        <div class="stat-change negative">
                            <i class="fas fa-filter"></i>
                            <?php echo wholeNumber($filtered_total_units); ?> units
                        </div>
                    </div>
                    <div class="stat-icon rose"><i class="fas fa-calendar-alt"></i></div>
                </div>
            </div>
            <?php endif; ?>
            <div class="stat-card" style="display:flex;align-items:center;justify-content:center;background:transparent;border:none;box-shadow:none;">
                <button type="button" class="btn-add-product btn-report-damage" onclick="openDamageModal()" style="width:100%;height:100%;justify-content:center;font-size:1rem;padding:1.5rem;">
                    <i class="fas fa-plus-circle"></i>
                    Report New Damage
                </button>
            </div>
        </section>

        <section class="inventory-table-container">
            <div class="table-responsive">
                <table class="inventory-table damage-history-table">
                    <thead>
                        <tr>
                            <th>Date Reported</th>
                            <th>Product</th>
                            <th>Qty</th>
                            <th>Type</th>
                            <th>Reason/Remarks</th>
                            <th>Reported By</th>
                            <th>Unit Price</th>
                            <th>Est. Loss</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($history)): ?>
                            <tr><td colspan="8" style="text-align:center; padding: 3rem;">No damage records found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($history as $row): ?>
                                <tr>
                                    <td data-label="Date Reported"><?php echo date('M j, Y g:i A', strtotime($row['created_at'])); ?></td>
                                    <td data-label="Product"><strong><?php echo htmlspecialchars($row['product_name']); ?></strong></td>
                                    <td data-label="Qty"><span class="quantity-cell quantity-low"><?php echo wholeNumber($row['quantity']); ?> <?php echo htmlspecialchars($row['unit_name'] ?? ''); ?></span></td>
                                    <td data-label="Type"><span class="quantity-cell quantity-medium"><?php echo htmlspecialchars($row['damage_type_label'] ?: $row['damage_type_raw']); ?></span></td>
                                    <td data-label="Reason/Remarks" style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($row['reason']); ?>">
                                        <?php echo htmlspecialchars($row['reason']); ?>
                                    </td>
                                    <td data-label="Reported By"><?php echo htmlspecialchars($row['reported_by_name']); ?></td>
                                    <td data-label="Unit Price" style="color:#64748b;">₱<?php echo number_format((float)$row['retail_price'], 2); ?></td>
                                    <td data-label="Est. Loss">
                                        <strong style="color:#e11d48;">₱<?php echo number_format((float)$row['estimated_loss'], 2); ?></strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="background:#fff1f2; font-weight:800; border-top: 3px solid #e11d48; border-bottom: 2px solid #e11d48;">
                                <td colspan="8" style="text-align:right; padding:1.25rem 1.5rem; color:#881337; font-size:1.15rem; letter-spacing:0.02em;"><?php echo $period === 'custom_month' ? 'Total Loss (filtered period):' : 'Grand Total Loss (all records shown):'; ?></td>
                                <td style="color:#be123c; font-size:1.4rem; font-weight:800; text-align:right; padding:1.25rem 1rem;">₱<?php echo number_format(array_sum(array_column($history, 'estimated_loss')), 2); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<!-- Report Damage Modal -->
<div class="inventory-modal" id="damageModal">
    <div class="inventory-modal-content">
        <div class="inventory-modal-header" style="background: linear-gradient(135deg, #f43f5e 0%, #e11d48 100%);">
            <h2><i class="fas fa-plus-circle"></i> Report Damaged Goods</h2>
            <span class="inventory-modal-close" onclick="closeDamageModal()">&times;</span>
        </div>
        <div class="inventory-modal-body">
            <form action="../api/damage_goods_backend.php" method="POST">
                <?php echo csrfTokenField(); ?>
                <div class="form-group">
                    <label for="product_id">Select Product</label>
                    <select name="product_id" id="prod_id" required onchange="updateMaxQty()">
                        <option value="">-- Choose Product --</option>
                        <?php foreach ($products as $p): ?>
                            <option value="<?php echo $p['Product_ID']; ?>" data-max="<?php echo wholeNumber($p['current_quantity']); ?>">
                                <?php echo htmlspecialchars($p['product_name']); ?> (Current: <?php echo wholeNumber($p['current_quantity']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="quantity">Quantity Damaged</label>
                    <input type="number" name="quantity" id="qty_input" min="1" step="1" inputmode="numeric" required placeholder="Whole number of units">
                </div>
                <div class="form-group">
                    <label for="damage_type">Damage Reason</label>
                    <select name="damage_type" id="damage_type" required>
                        <?php if (empty($damage_types)): ?>
                            <option value="">No damage types configured in database</option>
                        <?php else: ?>
                            <?php foreach ($damage_types as $type): ?>
                                <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="reason">Internal Remarks / Reason</label>
                    <textarea name="reason" id="reason" rows="3" class="damage-reason-field" placeholder="Explain what happened..."></textarea>
                </div>
                <div class="inventory-modal-actions">
                    <button type="button" class="inventory-modal-btn inventory-modal-btn-secondary" onclick="closeDamageModal()">Cancel</button>
                    <button type="submit" name="report_damage" class="inventory-modal-btn inventory-modal-btn-primary" style="background: #e11d48;" <?php echo empty($damage_types) ? 'disabled' : ''; ?>>
                        <i class="fas fa-check-circle"></i> Save Report
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const labels = ['Date Reported', 'Product', 'Qty', 'Type', 'Reason / Remarks', 'Reported By', 'Unit Price', 'Est. Loss'];
        const historyTable = document.querySelector('.damage-history-table');

        historyTable?.querySelectorAll('tbody tr').forEach(function (row) {
            const cells = row.querySelectorAll('td');

            if (cells.length === labels.length) {
                cells.forEach(function (cell, index) {
                    if (!cell.dataset.label) {
                        cell.dataset.label = labels[index];
                    }
                });

                if (cells[4]) {
                    cells[4].classList.add('damage-reason-cell');
                }
                return;
            }

            if (cells.length === 1) {
                row.classList.add('damage-empty-row');
                return;
            }

            row.classList.add('damage-total-row');
            if (cells[0]) {
                cells[0].classList.add('damage-total-label');
            }
            if (cells[1]) {
                cells[1].classList.add('damage-total-value');
            }
        });
    });

    function applyCustomMonth() {
        const month = document.getElementById('filterMonth').value;
        const year = document.getElementById('filterYear').value;
        if (month && year) {
            window.location.href = '?period=custom_month&filter_month=' + month + '&filter_year=' + year;
        }
    }

    <?php if ($period === 'custom_month'): ?>
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('filterMonth').value = '<?php echo $filter_month; ?>';
        document.getElementById('filterYear').value = '<?php echo $filter_year; ?>';
    });
    <?php endif; ?>

    function openDamageModal() {
        document.getElementById('damageModal').style.display = 'block';
    }
    function closeDamageModal() {
        document.getElementById('damageModal').style.display = 'none';
    }
    function updateMaxQty() {
        const select = document.getElementById('prod_id');
        const input = document.getElementById('qty_input');
        const selected = select.options[select.selectedIndex];
        if (selected && selected.dataset.max) {
            input.max = Math.max(0, parseInt(selected.dataset.max, 10) || 0);
        }
    }

    <?php if (isset($_SESSION['success'])): ?>
        Swal.fire({ icon: 'success', title: 'Success', text: '<?php echo $_SESSION['success']; ?>', confirmButtonColor: '#e11d48' });
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        Swal.fire({ icon: 'error', title: 'Error', text: '<?php echo $_SESSION['error']; ?>', confirmButtonColor: '#e11d48' });
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
</script>
</body>
</html>
