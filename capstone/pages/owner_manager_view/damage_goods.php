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

// Monetary loss stats (quantity Ã— retail_price)
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
    return '&#8369;' . number_format($val, 2);
}
function wholeNumber($val): string {
    return number_format((float)$val, 0);
}

if (!function_exists('formatDamageDate')) {
    function formatDamageDate(?string $datetime): string {
        if (empty($datetime)) {
            return '—';
        }
        $ts = strtotime($datetime);
        return ($ts !== false) ? date('M j, Y g:i A', $ts) : '—';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Damage Goods Management - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/inventory.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* Modern Premium Damage Goods Styling */
        .damage-header-content {
            background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            border-left: 5px solid #ef4444;
            color: #f8fafc;
        }
        .damage-header-content .inventory-subtitle {
            color: #94a3b8;
        }
        .stat-icon.rose {
            background: #fef2f2;
            color: #ef4444;
            box-shadow: 0 4px 10px rgba(239, 68, 68, 0.1);
        }
        .stat-icon.purple {
            background: #f8fafc;
            color: #64748b;
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
            background: rgba(15, 23, 42, 0.4);
            padding: 0.35rem;
            border-radius: 12px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .damage-period-link {
            padding: 0.5rem 1.25rem;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.3s ease;
            color: #94a3b8;
        }
        .damage-period-link:hover {
            color: white;
        }
        .damage-period-link.active {
            background: #ef4444;
            color: white;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
        }
        .alert-card-premium {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-left: 4px solid #f59e0b;
            box-shadow: 0 10px 25px rgba(0,0,0,0.03);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.25rem 1.5rem;
            border-radius: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .alert-card-premium:hover {
            box-shadow: 0 15px 35px rgba(0,0,0,0.06);
            transform: translateY(-2px);
        }
        .stat-card-premium {
            background: #ffffff;
            border: 1px solid #f1f5f9;
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: 0 10px 25px rgba(0,0,0,0.02);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .stat-card-premium:hover {
            box-shadow: 0 15px 35px rgba(0,0,0,0.05);
            border-color: #e2e8f0;
        }
        .damage-history-table {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(0,0,0,0.02);
            border: 1px solid #f1f5f9;
            width: 100%;
            border-collapse: collapse;
        }
        .damage-history-table th {
            background: #f8fafc;
            color: #475569;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 1rem 1.5rem;
            border-bottom: 2px solid #e2e8f0;
            text-align: left;
        }
        .damage-history-table td {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            vertical-align: middle;
        }
        .damage-history-table tr:hover {
            background: #f8fafc;
        }
        .damage-history-table .damage-reason-cell {
            max-width: 250px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #64748b;
        }
        .damage-history-table .damage-total-row td {
            background: #fef2f2;
            border-top: 2px solid #fca5a5;
            color: #991b1b;
            font-weight: 700;
            padding: 1.25rem 1.5rem;
        }
        .damage-reason-field {
            width: 100%;
            border-radius: 12px;
            padding: 12px;
            border: 1px solid #e2e8f0;
            font-family: inherit;
            resize: vertical;
            min-height: 100px;
            background: #f8fafc;
        }
        .damage-reason-field:focus {
            background: white;
            border-color: #ef4444;
            outline: none;
            box-shadow: 0 0 0 3px rgba(239,68,68,0.1);
        }

        .damage-history-scroll {
            max-height: calc(100vh - 420px);
            min-height: 320px;
            overflow-y: auto;
            overflow-x: auto;
            border-radius: 16px;
            scrollbar-width: thin;
            scrollbar-color: #fca5a5 #fef2f2;
        }
        .damage-history-scroll::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        .damage-history-scroll::-webkit-scrollbar-track {
            background: #fef2f2;
            border-radius: 8px;
        }
        .damage-history-scroll::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #fca5a5, #ef4444);
            border-radius: 8px;
        }
        .damage-history-scroll::-webkit-scrollbar-thumb:hover {
            background: #dc2626;
        }

        .damage-modal-scroll {
            max-height: 70vh;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: #fca5a5 transparent;
        }
        .damage-modal-scroll::-webkit-scrollbar {
            width: 6px;
        }
        .damage-modal-scroll::-webkit-scrollbar-track {
            background: transparent;
        }
        .damage-modal-scroll::-webkit-scrollbar-thumb {
            background: #fca5a5;
            border-radius: 6px;
        }
        .damage-modal-scroll::-webkit-scrollbar-thumb:hover {
            background: #ef4444;
        }
        
        @media (max-width: 768px) {
            .inventory-header-content { padding: 1.5rem; }
            .damage-header-top { flex-direction: column; align-items: stretch; }
            .damage-period-filter { width: 100%; justify-content: space-between; }
            .damage-period-link { flex: 1 1 calc(50% - 0.35rem); text-align: center; }
            .alert-card-premium { flex-direction: column; align-items: flex-start; gap: 1rem; }
            .alert-card-premium > div:last-child { width: 100%; justify-content: center; }
            .damage-history-table thead { display: none; }
            .damage-history-table, .damage-history-table tbody, .damage-history-table tr, .damage-history-table td { display: block; width: 100%; }
            .damage-history-table tr { margin-bottom: 1rem; border: 1px solid #e2e8f0; border-radius: 12px; }
            .damage-history-table td { text-align: right; padding-left: 50%; position: relative; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: flex-start;}
            .damage-history-table td::before { content: attr(data-label); flex: 0 0 42%; text-align: left; font-weight: 600; color: #64748b; font-size: 0.8rem; text-transform: uppercase; }
            .damage-history-table .damage-reason-cell { max-width: none; white-space: normal; flex-direction: column; text-align: left; align-items: flex-start; }
            .damage-history-table .damage-total-row td { text-align: left; padding: 1rem; display: block; }
            .damage-history-table .damage-total-row td::before { display: none; }
        }
    </style>
</head>
<body>
<div class="dashboard-wrapper">
    <!-- Sidebar -->
    <?php
    require_once __DIR__ . '/../../includes/sidebar.php';
    renderSidebar($conn, ['base' => '../', 'active' => 'damage_goods']);
    ?>

    <main class="main-content" id="mainContent">
        <section class="inventory-header">
            <div class="inventory-header-content damage-header-content">
                <div class="damage-header-top">
                    <div>
                        <h1 class="inventory-title">
                            <i class="fas fa-heart-broken" style="color: #ef4444;"></i>
                            Damage Goods
                        </h1>
                        <p class="inventory-subtitle">Track and report product losses and damages</p>
                    </div>
                    
                    <!-- Period Filter UI -->
                    <div class="damage-period-filter">
                        <a href="?period=all" class="damage-period-link <?php echo $period === 'all' ? 'active' : ''; ?>">All Time</a>
                        <a href="?period=daily" class="damage-period-link <?php echo $period === 'daily' ? 'active' : ''; ?>">Daily</a>
                        <a href="?period=weekly" class="damage-period-link <?php echo $period === 'weekly' ? 'active' : ''; ?>">Weekly</a>
                        <div style="display:flex;gap:4px;align-items:center;">
                            <select id="filterMonth" style="padding:6px 10px;border-radius:8px;border:none;font-weight:600;font-size:0.8rem;background:<?php echo $period === 'custom_month' ? 'white' : 'rgba(255,255,255,0.1)'; ?>;color:<?php echo $period === 'custom_month' ? '#1e293b' : 'white'; ?>;">
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
                            <select id="filterYear" style="padding:6px 10px;border-radius:8px;border:none;font-weight:600;font-size:0.8rem;background:<?php echo $period === 'custom_month' ? 'white' : 'rgba(255,255,255,0.1)'; ?>;color:<?php echo $period === 'custom_month' ? '#1e293b' : 'white'; ?>;">
                                <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                                    <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                            <button onclick="applyCustomMonth()" style="padding:6px 14px;border-radius:8px;border:none;background:<?php echo $period === 'custom_month' ? '#ef4444' : 'rgba(255,255,255,0.1)'; ?>;color:white;font-weight:700;cursor:pointer;font-size:0.8rem; transition: background 0.3s ease;">
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
        <section style="margin-bottom: 1.5rem;">
            <div class="alert-card-premium" onclick="location.href='delivery_damage_queue.php'">
                <div style="display: flex; align-items: center; gap: 1rem;">
                    <div style="background: #fef3c7; color: #d97706; width: 45px; height: 45px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div>
                        <h4 style="color: #1e293b; margin: 0; font-weight: 700; font-size: 1.05rem;"><?php echo $pending_count; ?> Pending Rider Reports</h4>
                        <p style="color: #64748b; margin: 0.1rem 0 0 0; font-size: 0.85rem;">Open Damage Reports to review rider submissions or view staff photo evidence.</p>
                    </div>
                </div>
                <div style="color: #d97706; font-weight: 700; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; background: #fffbeb; padding: 0.5rem 1rem; border-radius: 8px; border: 1px solid #fde68a; display: flex; align-items: center; gap: 0.5rem;">
                    Review Now <i class="fas fa-arrow-right"></i>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <section class="inventory-stats" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem;">
            <div class="stat-card stat-card-premium">
                <div class="stat-content">
                    <div class="stat-info">
                        <p>Damaged Today</p>
                        <h3><?php echo wholeNumber($total_damaged_today); ?> <small style="font-size:0.55em;font-weight:500;">units</small></h3>
                    </div>
                    <div class="stat-icon rose"><i class="fas fa-calendar-day"></i></div>
                </div>
            </div>
            <div class="stat-card stat-card-premium">
                <div class="stat-content">
                    <div class="stat-info">
                        <p>Damaged This Month</p>
                        <h3><?php echo wholeNumber($total_damaged_month); ?> <small style="font-size:0.55em;font-weight:500;">units</small></h3>
                    </div>
                    <div class="stat-icon purple"><i class="fas fa-calendar-alt"></i></div>
                </div>
            </div>
            <div class="stat-card stat-card-premium">
                <div class="stat-content">
                    <div class="stat-info">
                        <p>Loss Today</p>
                        <h3 style="font-size:1.3rem; color:#ef4444;"><?php echo pesoLoss($loss_today); ?></h3>
                    </div>
                    <div class="stat-icon rose"><i class="fas fa-peso-sign"></i></div>
                </div>
            </div>
            <div class="stat-card stat-card-premium">
                <div class="stat-content">
                    <div class="stat-info">
                        <p>Loss This Month</p>
                        <h3 style="font-size:1.3rem; color:#ef4444;"><?php echo pesoLoss($loss_month); ?></h3>
                    </div>
                    <div class="stat-icon purple"><i class="fas fa-peso-sign"></i></div>
                </div>
            </div>
            <div class="stat-card stat-card-premium">
                <div class="stat-content">
                    <div class="stat-info">
                        <p>Total Loss (All Time)</p>
                        <h3 style="font-size:1.3rem; color:#dc2626;"><?php echo pesoLoss($loss_total); ?></h3>
                    </div>
                    <div class="stat-icon rose"><i class="fas fa-chart-line"></i></div>
                </div>
            </div>
            
            <?php if ($period === 'custom_month'): ?>
            <div class="stat-card stat-card-premium" style="border: 2px solid #ef4444;">
                <div class="stat-content">
                    <div class="stat-info">
                        <p>Filtered: <?php echo date('F Y', mktime(0, 0, 0, $filter_month, 1, $filter_year)); ?></p>
                        <h3 style="font-size:1.3rem; color:#ef4444;"><?php echo pesoLoss($filtered_loss); ?></h3>
                        <div style="font-size:0.8rem; color:#64748b; margin-top:0.25rem;">
                            <i class="fas fa-filter"></i>
                            <?php echo wholeNumber($filtered_total_units); ?> units
                        </div>
                    </div>
                    <div class="stat-icon rose"><i class="fas fa-calendar-check"></i></div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="stat-card stat-card-premium" style="display:flex; flex-direction:column; align-items:center; justify-content:center; background: #f8fafc; border: 2px dashed #cbd5e1; box-shadow: none; cursor: pointer; transition: all 0.3s ease;" onclick="openDamageModal()" onmouseover="this.style.borderColor='#ef4444'; this.style.background='#fef2f2';" onmouseout="this.style.borderColor='#cbd5e1'; this.style.background='#f8fafc';">
                <div style="background: #fee2e2; color: #ef4444; width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; margin-bottom: 0.75rem;">
                    <i class="fas fa-plus"></i>
                </div>
                <h3 style="color: #1e293b; font-size: 1.05rem; margin: 0; font-weight: 600;">Report Damage</h3>
                <p style="color: #64748b; font-size: 0.8rem; margin: 0.25rem 0 0 0; text-align: center;">Log a new damaged product</p>
            </div>
        </section>

        <section class="inventory-table-container" style="background: transparent; box-shadow: none; padding: 0;">
            <div class="table-responsive damage-history-scroll">
                <table class="damage-history-table">
                    <thead>
                        <tr>
                            <th>Date Reported</th>
                            <th>Product</th>
                            <th>Qty</th>
                            <th>Type</th>
                            <th>Reason / Remarks</th>
                            <th>Reported By</th>
                            <th>Unit Price</th>
                            <th>Est. Loss</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($history)): ?>
                            <tr><td colspan="8" style="text-align:center; padding: 3rem; color: #64748b;">No damage records found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($history as $row): ?>
                                <tr>
                                    <td data-label="Date Reported"><?php echo formatDamageDate($row['created_at'] ?? null); ?></td>
                                    <td data-label="Product"><strong style="color:#1e293b; font-weight:600;"><?php echo htmlspecialchars($row['product_name']); ?></strong></td>
                                    <td data-label="Qty"><span style="background:#f1f5f9; color:#475569; padding:0.25rem 0.5rem; border-radius:6px; font-weight:600; font-size:0.85rem;"><?php echo wholeNumber($row['quantity']); ?> <?php echo htmlspecialchars($row['unit_name'] ?? ''); ?></span></td>
                                    <td data-label="Type"><span style="background:#fff7ed; color:#c2410c; padding:0.25rem 0.5rem; border-radius:6px; font-weight:600; font-size:0.85rem;"><i class="fas fa-exclamation-circle" style="margin-right:4px;"></i> <?php echo htmlspecialchars($row['damage_type_label'] ?: $row['damage_type_raw']); ?></span></td>
                                    <td data-label="Reason / Remarks" class="damage-reason-cell" title="<?php echo htmlspecialchars($row['reason']); ?>">
                                        <?php echo htmlspecialchars($row['reason']); ?>
                                    </td>
                                    <td data-label="Reported By"><?php echo htmlspecialchars($row['reported_by_name']); ?></td>
                                    <td data-label="Unit Price" style="color:#64748b;">&#8369;<?php echo number_format((float)$row['retail_price'], 2); ?></td>
                                    <td data-label="Est. Loss">
                                        <strong style="color:#ef4444;">&#8369;<?php echo number_format((float)$row['estimated_loss'], 2); ?></strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="damage-total-row">
                                <td colspan="7" style="text-align:right; font-size:1.05rem;"><?php echo $period === 'custom_month' ? 'Total Loss (filtered period):' : 'Grand Total Loss (all records):'; ?></td>
                                <td style="font-size:1.2rem;">&#8369;<?php echo number_format(array_sum(array_column($history, 'estimated_loss')), 2); ?></td>
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
    <div class="inventory-modal-content" style="border-radius: 20px; border: none;">
        <div class="inventory-modal-header" style="background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%); border-bottom: none; border-radius: 20px 20px 0 0;">
            <h2 style="color: white; margin: 0; display: flex; align-items: center; gap: 0.5rem;"><i class="fas fa-plus-circle" style="color: #ef4444;"></i> Report Damaged Goods</h2>
            <span class="inventory-modal-close" onclick="closeDamageModal()" style="color: white; opacity: 0.8;">&times;</span>
        </div>
        <div class="inventory-modal-body damage-modal-scroll" style="padding: 2rem;">
            <form action="../api/damage_goods_backend.php" method="POST">
                <?php echo csrfTokenField(); ?>
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label for="product_id" style="font-weight: 600; color: #334155;">Select Product *</label>
                    <select name="product_id" id="prod_id" required onchange="updateMaxQty()" style="width: 100%; padding: 0.75rem 1rem; border-radius: 10px; border: 1px solid #cbd5e1;">
                        <option value="">-- Choose Product --</option>
                        <?php foreach ($products as $p): ?>
                            <option value="<?php echo $p['Product_ID']; ?>" data-max="<?php echo wholeNumber($p['current_quantity']); ?>">
                                <?php echo htmlspecialchars($p['product_name']); ?> (Current: <?php echo wholeNumber($p['current_quantity']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label for="quantity" style="font-weight: 600; color: #334155;">Quantity Damaged *</label>
                    <input type="number" name="quantity" id="qty_input" min="1" step="1" inputmode="numeric" required placeholder="Whole number of units" style="width: 100%; padding: 0.75rem 1rem; border-radius: 10px; border: 1px solid #cbd5e1;">
                </div>
                <div class="form-group" style="margin-bottom: 1.5rem;">
                    <label for="damage_type" style="font-weight: 600; color: #334155;">Damage Reason *</label>
                    <select name="damage_type" id="damage_type" required style="width: 100%; padding: 0.75rem 1rem; border-radius: 10px; border: 1px solid #cbd5e1;">
                        <?php if (empty($damage_types)): ?>
                            <option value="">No damage types configured in database</option>
                        <?php else: ?>
                            <?php foreach ($damage_types as $type): ?>
                                <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 2rem;">
                    <label for="reason" style="font-weight: 600; color: #334155;">Internal Remarks / Extra Details</label>
                    <textarea name="reason" id="reason" rows="3" class="damage-reason-field" placeholder="Explain what happened..."></textarea>
                </div>
                <div class="inventory-modal-actions" style="display: flex; gap: 1rem; border-top: 1px solid #e2e8f0; padding-top: 1.5rem;">
                    <button type="button" class="inventory-modal-btn inventory-modal-btn-secondary" onclick="closeDamageModal()" style="flex: 1; padding: 0.75rem; border-radius: 10px; background: white; border: 1px solid #cbd5e1; color: #475569; font-weight: 600;">Cancel</button>
                    <button type="submit" name="report_damage" class="btn-report-damage" style="flex: 2; padding: 0.75rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem;" <?php echo empty($damage_types) ? 'disabled' : ''; ?>>
                        <i class="fas fa-check-circle"></i> Save Damage Report
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
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
        Swal.fire({ icon: 'success', title: 'Success', text: <?php echo json_encode((string) $_SESSION['success'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, confirmButtonColor: '#1e293b' });
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        Swal.fire({ icon: 'error', title: 'Error', text: <?php echo json_encode((string) $_SESSION['error'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, confirmButtonColor: '#ef4444' });
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
</script>
<script src="../assets/js/script.js"></script>
</body>
</html>


