<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/roles_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/delivery_damage_ui_helper.php';
require_once __DIR__ . '/../../includes/inventory_staff_chrome.php';
require_once __DIR__ . '/../../includes/adjustment_reason_helper.php';

$dashboard_ids = getDashboardRoleIds($conn);
$inv_ids = getInventoryStaffRoleIds($conn);
$allowed = array_unique(array_merge($dashboard_ids, $inv_ids));
requireRole(empty($allowed) ? [1] : $allowed);
$is_inventory_staff = in_array((int)($_SESSION['user_role'] ?? 0), $inv_ids);
$can_inv_manual_adjustment = isModuleAllowedForUser($conn, (int)($_SESSION['user_id'] ?? 0), 'inv_manual_adjustment', true);
$can_inv_record_production = isModuleAllowedForUser($conn, (int)($_SESSION['user_id'] ?? 0), 'inv_record_production', true);
$can_inv_production_history = isModuleAllowedForUser($conn, (int)($_SESSION['user_id'] ?? 0), 'inv_production_history', true);
$can_inv_adjustment_history = isModuleAllowedForUser($conn, (int)($_SESSION['user_id'] ?? 0), 'inv_adjustment_history', true);

require_once __DIR__ . '/../../api/manual_adjustment_backend.php';

$p_cols = $conn->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
$has_unit_id = in_array('unit_id', $p_cols);
$has_unit = in_array('unit', $p_cols);
$all_tables = array_column($conn->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM), 0);
$has_units = in_array('units', $all_tables);
$unit_fallback = $has_unit ? "p.unit" : "NULL";
$unit_sel = ($has_unit_id && $has_units) ? "COALESCE(u.unit_name, $unit_fallback)" : $unit_fallback;
$unit_join = ($has_unit_id && $has_units) ? "LEFT JOIN units u ON p.unit_id = u.unit_id" : "";
$unit_order = ($has_unit_id && $has_units) ? "u.unit_name, " : "";
$stockin_cols = $conn->query("SHOW COLUMNS FROM stockin_inventory")->fetchAll(PDO::FETCH_COLUMN);
$stockin_has_produced_qty = in_array('produced_qty', $stockin_cols);
$stockin_has_number_of_bags = in_array('number_of_bags', $stockin_cols);
$stockin_has_production_type = in_array('production_type', $stockin_cols);
$stockin_history_date_expr = in_array('date_in', $stockin_cols)
    ? (in_array('created_at', $stockin_cols) ? 'COALESCE(p.date_in, DATE(p.created_at))' : 'p.date_in')
    : (in_array('created_at', $stockin_cols) ? 'DATE(p.created_at)' : 'CURDATE()');
$stockin_unit_fallback = $has_unit ? "pr.unit" : "NULL";
$stockin_unit_join = ($has_unit_id && $has_units) ? "LEFT JOIN units u_units ON pr.unit_id = u_units.unit_id" : "";
$stockin_unit_select = ($has_unit_id && $has_units) ? "u_units.unit_name" : $stockin_unit_fallback;

// Fetch low stock items for alerts
$low_stock_query = "SELECT p.product_name, COALESCE((SELECT quantity FROM stockin_inventory WHERE Product_ID = p.Product_ID ORDER BY updated_at DESC LIMIT 1), 0) as current_quantity FROM products p WHERE p.is_discontinued = 0 HAVING current_quantity < 20 ORDER BY current_quantity ASC LIMIT 5";
$low_stock_alerts = $conn->query($low_stock_query)->fetchAll(PDO::FETCH_ASSOC);

$products_query = "SELECT p.Product_ID, p.product_name, {$unit_sel} as unit_name, COALESCE((SELECT quantity FROM stockin_inventory WHERE Product_ID = p.Product_ID ORDER BY updated_at DESC LIMIT 1), 0) as current_quantity FROM products p {$unit_join} WHERE p.is_discontinued = 0 ORDER BY {$unit_order}p.product_name";
$products_data = $conn->query($products_query)->fetchAll(PDO::FETCH_ASSOC);

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$reasons = getAdjustmentReasonOptions($conn);
$has_other_reason = in_array('Other (with remarks)', $reasons, true);

$adj_table = in_array('manual_adjustment', $all_tables) ? 'manual_adjustment' : 'adjustments';
$ad_cols = array_column($conn->query("SHOW COLUMNS FROM adjustment_details")->fetchAll(PDO::FETCH_ASSOC), 'Field');

$ad_detail_col = null;
if (in_array('Adjustmentdetail_ID', $ad_cols)) $ad_detail_col = 'Adjustmentdetail_ID';
elseif (in_array('AdjustmentDetail_ID', $ad_cols)) $ad_detail_col = 'AdjustmentDetail_ID';
elseif (in_array('Detail_ID', $ad_cols)) $ad_detail_col = 'Detail_ID';

$hist_unit_join = ($has_unit_id && $has_units) ? "LEFT JOIN units u_units ON p.unit_id = u_units.unit_id" : "";
$hist_unit_sel = ($has_unit_id && $has_units) ? "COALESCE(u_units.unit_name, $unit_fallback)" : $unit_fallback;
$order_detail_clause = $ad_detail_col ? ", ad.{$ad_detail_col} DESC" : "";

$history_query = "SELECT ma.adjustment_date, ma.notes, p.product_name, {$hist_unit_sel} as unit_name, ad.old_quantity, ad.new_quantity, ad.reason, appuser.user_name as handled_by 
FROM adjustment_details ad INNER JOIN {$adj_table} ma ON ad.Adjustment_ID = ma.Adjustment_ID 
INNER JOIN products p ON ad.Product_ID = p.Product_ID {$hist_unit_join} LEFT JOIN user appuser ON ma.created_by = appuser.User_ID 
WHERE ma.created_by = :uid ORDER BY ma.adjustment_date DESC, ma.Adjustment_ID DESC{$order_detail_clause} LIMIT 100";
$history_stmt = $conn->prepare($history_query);
$history_stmt->execute([':uid' => $current_user_id]);

$summary_query = "SELECT COUNT(*) AS total_adjustments, 
SUM(CASE WHEN ad.new_quantity - ad.old_quantity > 0 THEN 1 ELSE 0 END) AS positive_count, 
SUM(CASE WHEN ad.new_quantity - ad.old_quantity < 0 THEN 1 ELSE 0 END) AS negative_count, 
SUM(ad.new_quantity - ad.old_quantity) AS net_change, 
SUM(CASE WHEN DATE(ma.adjustment_date) = CURDATE() THEN 1 ELSE 0 END) AS today_count 
FROM adjustment_details ad INNER JOIN {$adj_table} ma ON ad.Adjustment_ID = ma.Adjustment_ID WHERE ma.created_by = :uid";
$summary_stmt = $conn->prepare($summary_query);
$summary_stmt->execute([':uid' => $current_user_id]);
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$total_adjustments = (int)($summary['total_adjustments'] ?? 0);
$positive_count = (int)($summary['positive_count'] ?? 0);
$negative_count = (int)($summary['negative_count'] ?? 0);
$net_change = (float)($summary['net_change'] ?? 0.0);
$today_count = (int)($summary['today_count'] ?? 0);

// Total stock-in quantity for Stock Added card
$stockin_sum_expr = 'COALESCE(si.quantity, 0)';
if ($stockin_has_produced_qty && $stockin_has_number_of_bags) {
    $stockin_sum_expr = 'COALESCE(si.produced_qty, si.number_of_bags, si.quantity, 0)';
} elseif ($stockin_has_produced_qty) {
    $stockin_sum_expr = 'COALESCE(si.produced_qty, si.quantity, 0)';
} elseif ($stockin_has_number_of_bags) {
    $stockin_sum_expr = 'COALESCE(si.number_of_bags, si.quantity, 0)';
}
$total_stocked_in = 0;
try {
    $total_stocked_in = (float)$conn->query("SELECT COALESCE(SUM($stockin_sum_expr), 0) FROM stockin_inventory si")->fetchColumn();
} catch (Throwable $e) {
    $total_stocked_in = 0;
}

$yesterday_query = "SELECT SUM(CASE WHEN DATE(ma.adjustment_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS y_count 
FROM adjustment_details ad INNER JOIN {$adj_table} ma ON ad.Adjustment_ID = ma.Adjustment_ID WHERE ma.created_by = :uid";
$y_stmt = $conn->prepare($yesterday_query);
$y_stmt->execute([':uid' => $current_user_id]);
$yesterday = $y_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$yesterday_count = (int)($yesterday['y_count'] ?? 0);

$trend_diff = $today_count - $yesterday_count;
$trend_sign = $trend_diff > 0 ? '+' : '';
$trend_class = $trend_diff > 0 ? 'text-indigo-600 bg-indigo-50' : ($trend_diff < 0 ? 'text-rose-600 bg-rose-50' : 'text-slate-500 bg-slate-100');
$trend_icon = $trend_diff > 0 ? 'fa-arrow-up' : ($trend_diff < 0 ? 'fa-arrow-down' : 'fa-minus');
if ($trend_diff == 0) $trend_text = "Same as yesterday";
else $trend_text = "{$trend_sign}{$trend_diff} vs yesterday";

// Chart Data (owners/managers only)
if (!$is_inventory_staff) {
    $chart_query = "SELECT DATE(ma.adjustment_date) as d, COUNT(*) as c FROM {$adj_table} ma INNER JOIN adjustment_details ad ON ma.Adjustment_ID = ad.Adjustment_ID WHERE ma.created_by = :uid GROUP BY DATE(ma.adjustment_date) ORDER BY d DESC LIMIT 7";
    $chart_stmt = $conn->prepare($chart_query);
    $chart_stmt->execute([':uid' => $current_user_id]);
    $chart_raw = $chart_stmt->fetchAll(PDO::FETCH_ASSOC);
    $chart_labels = []; $chart_data = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $chart_labels[] = date('D', strtotime($date));
        $found = 0;
        foreach ($chart_raw as $r) { if ($r['d'] === $date) { $found = (int)$r['c']; break; } }
        $chart_data[] = $found;
    }
} else {
    $chart_labels = [];
    $chart_data = [];
}

$user_info_stmt = $conn->prepare("SELECT u.full_name, r.role_name FROM user u LEFT JOIN roles r ON u.Role_ID = r.Role_ID WHERE u.User_ID = :uid");
$user_info_stmt->execute([':uid' => $current_user_id]);
$user_info = $user_info_stmt->fetch(PDO::FETCH_ASSOC);
$display_name = $user_info['full_name'] ?? $_SESSION['user_name'] ?? 'User';
$display_role = $user_info['role_name'] ?? 'Staff';

// Fetch profile picture
$profilePicture = '';
try {
    $stmt = $conn->prepare("SELECT profile_picture FROM User_Profile WHERE User_ID = ? LIMIT 1");
    $stmt->execute([$current_user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['profile_picture'])) {
        $profilePicture = $row['profile_picture'];
    }
} catch (Throwable $e) {
    // Ignore errors, fallback to initial
}

$ddr_role_id = (int)($_SESSION['user_role'] ?? 0);
$ddr_queue_show = ddr_table_exists($conn) && userCanAccessDeliveryDamageQueue($conn, $ddr_role_id);
$ddr_pending_n = $ddr_queue_show ? countPendingDeliveryDamageReports($conn) : 0;
$ddr_nav_href = $ddr_queue_show ? deliveryDamageQueueHrefForUser($conn, $ddr_role_id) : '';

// Calculate overdue orders count for notification badge
$overdue_orders_count = 0;
try {
    $orderStatusCol = 'order_status';
    $stmtCol = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
    if ($stmtCol && $stmtCol->rowCount() > 0) {
        $rowCol = $stmtCol->fetch(PDO::FETCH_ASSOC);
        $orderStatusCol = (string)$rowCol['Field'];
    }
    
    $orderColumns = [];
    $stmtCols = $conn->query("SHOW COLUMNS FROM orders");
    if ($stmtCols) {
        $orderColumns = $stmtCols->fetchAll(PDO::FETCH_COLUMN);
    }
    $hasDeliveryDate = in_array('delivery_date', $orderColumns, true);
    $hasScheduleDate = false;
    $tableCheck = $conn->query("SHOW TABLES LIKE 'delivery'");
    if ($tableCheck && $tableCheck->rowCount() > 0) {
        $hasScheduleDate = true;
    }
    
    $deliveryDateSelect = $hasDeliveryDate ? 'o.delivery_date' : 'o.order_date';
    $scheduleDateJoin = $hasScheduleDate ? "LEFT JOIN delivery d ON d.Order_ID = o.Order_ID" : "";
    $scheduleDateCol = $hasScheduleDate ? "COALESCE(d.schedule_date, $deliveryDateSelect)" : $deliveryDateSelect;
    
    $countQuery = "
        SELECT COUNT(DISTINCT o.Order_ID)
        FROM orders o
        $scheduleDateJoin
        LEFT JOIN order_preparation_tasks opt ON o.Order_ID = opt.Order_ID
        WHERE LOWER(COALESCE(o.{$orderStatusCol}, '')) NOT IN ('completed', 'cancelled', 'canceled', 'out for delivery', 'delivered', 'delivered (pending cash turnover)')
          AND COALESCE(opt.status, 'not_started') != 'ready'
          AND $scheduleDateCol < CURRENT_DATE()
    ";
    $countStmt = $conn->query($countQuery);
    if ($countStmt) {
        $overdue_orders_count = (int)$countStmt->fetchColumn();
    }
} catch (Throwable $e) {}

$total_notifications_n = getUnreadNotificationsCount($conn);

$stockin_history_qty_expr = 'COALESCE(p.quantity, 0)';
if ($stockin_has_produced_qty && $stockin_has_number_of_bags) {
    $stockin_history_qty_expr = 'COALESCE(p.produced_qty, p.number_of_bags, p.quantity, 0)';
} elseif ($stockin_has_produced_qty) {
    $stockin_history_qty_expr = 'COALESCE(p.produced_qty, p.quantity, 0)';
} elseif ($stockin_has_number_of_bags) {
    $stockin_history_qty_expr = 'COALESCE(p.number_of_bags, p.quantity, 0)';
}

$stockin_history_filters = [];
if ($stockin_has_production_type) {
    $stockin_history_filters[] = "(p.production_type = 'stockin' OR p.production_type IS NULL)";
}
if ($stockin_has_produced_qty && $stockin_has_number_of_bags) {
    $stockin_history_filters[] = "(p.produced_qty IS NOT NULL OR p.number_of_bags IS NOT NULL)";
} elseif ($stockin_has_produced_qty) {
    $stockin_history_filters[] = "p.produced_qty IS NOT NULL";
} elseif ($stockin_has_number_of_bags) {
    $stockin_history_filters[] = "p.number_of_bags IS NOT NULL";
}
$stockin_history_where = empty($stockin_history_filters) ? '' : ('WHERE ' . implode(' AND ', $stockin_history_filters));

$prod_history_query = "SELECT p.Inventory_ID as Production_ID, {$stockin_history_date_expr} as production_date, il.quantity_change as produced_qty, pr.product_name, {$stockin_unit_select} as unit_name, u.user_name as handled_by 
FROM stockin_inventory p INNER JOIN products pr ON p.Product_ID = pr.Product_ID {$stockin_unit_join} LEFT JOIN user u ON p.handled_by = u.User_ID LEFT JOIN inventory_ledger il ON p.Inventory_ID = il.transaction_id AND il.transaction_type = 'STOCK IN' {$stockin_history_where} ORDER BY production_date DESC, p.Inventory_ID DESC LIMIT 50";
$production_history = $conn->query($prod_history_query)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Adjustment Dashboard - Staff View</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Poppins', 'sans-serif'] },
                    colors: { primary: '#6366f1', secondary: '#4f46e5', accent: '#a855f7' }
                }
            }
        }
    </script>
    <style>
        body { background-color: #f8fafc; font-family: 'Poppins', sans-serif; -webkit-tap-highlight-color: transparent; padding-bottom: 80px; }
        .glass-header { background: rgba(255,255,255,0.9); backdrop-filter: blur(10px); }
        .fab {
            position: fixed; bottom: 24px; right: 24px; z-index: 50;
            background: linear-gradient(135deg, #6366f1, #a855f7); height: 56px; padding: 0 1.5rem;
            border-radius: 9999px; display: flex; align-items: center; justify-content: center; gap: 0.5rem;
            color: white; font-size: 1rem; font-weight: 700; box-shadow: 0 10px 25px rgba(99,102,241,0.4);
            transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1); white-space: nowrap;
        }
        .fab:active { transform: scale(0.95); }
        .hide-scroll::-webkit-scrollbar { display: none; }
        body, h1, h2, h3, h4, h5, h6, p, span, div, button, input, select, textarea { font-family: 'Poppins', sans-serif; }
        .hide-scroll::-webkit-scrollbar { display: none; }
        .hide-scroll { -ms-overflow-style: none; scrollbar-width: none; }
        
        .tab-content { display: none; animation: fadeIn 0.3s ease; }
        .tab-content.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        
        .modal-overlay { background: rgba(15,23,42,0.6); backdrop-filter: blur(4px); transition: opacity 0.3s ease; }
        .modal-box { max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; }
        .modal-body { overflow-y: auto; padding: 1.5rem; -webkit-overflow-scrolling: touch; }
    </style>
    <?php if ($is_inventory_staff) {
        inv_chrome_head_assets();
    } ?>
</head>
<body class="text-slate-800 antialiased max-w-lg mx-auto relative shadow-xl min-h-screen bg-white md:bg-slate-50">

    <!-- Sticky Header -->
    <header class="glass-header sticky top-0 z-40 px-5 pt-6 pb-4 border-b border-slate-100 shadow-sm">
        <?php if ($is_inventory_staff): ?>
            <?php
            $staff_chrome_nav = (isset($_GET['tab']) && $_GET['tab'] === 'history') ? 'history' : 'dashboard';
            $INV_CHROME = [
                'display_name' => $display_name,
                'display_role' => $display_role,
                'session_label' => 'Inventory Session',
                'nav_active' => $staff_chrome_nav,
                'inventory_href' => 'inventory_staff.php',
                'dashboard_href' => 'manual_adjustment.php',
                'history_href' => 'manual_adjustment.php?tab=history',
                'history_nav_id' => 'invNavHistory',
                'ddr_queue_show' => $ddr_queue_show,
                'ddr_nav_href' => $ddr_nav_href,
                'ddr_pending_n' => $ddr_pending_n,
                'total_notifications_n' => $total_notifications_n,
                'profile_picture' => $profilePicture,
            ];
            inv_chrome_render_header_block($INV_CHROME);
            ?>
        <?php else: ?>
        <div class="flex justify-between items-center mb-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-500 text-white flex items-center justify-center text-xl font-bold shadow-md">
                    <?php echo strtoupper(substr($display_name, 0, 1)); ?>
                </div>
                <div>
                    <span class="text-[10px] font-bold tracking-wider text-indigo-500 uppercase">Inventory Session</span>
                    <h2 class="text-base font-bold leading-tight"><?php echo htmlspecialchars($display_name); ?></h2>
                    <p class="text-xs text-slate-500 font-medium"><?php echo htmlspecialchars($display_role); ?></p>
                </div>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <?php if ($ddr_queue_show && $ddr_nav_href !== ''): ?>
                <a href="<?php echo htmlspecialchars($ddr_nav_href); ?>" class="relative inline-flex h-10 min-w-[2.5rem] px-2 rounded-xl bg-amber-50 text-amber-800 border border-amber-200 hover:bg-amber-100 items-center justify-center transition-colors" title="Damage Reports — rider review and staff photo evidence">
                    <i class="fas fa-bell text-sm"></i>
                    <?php if ($ddr_pending_n > 0): ?>
                    <span class="absolute -top-1 -right-1 min-w-[1.125rem] h-[1.125rem] px-0.5 rounded-full bg-red-500 text-white text-[9px] font-black leading-none flex items-center justify-center"><?php echo $ddr_pending_n > 99 ? '99+' : (int)$ddr_pending_n; ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                <button onclick="location.href='../logout.php'" class="h-10 px-3 rounded-xl bg-slate-100 text-slate-500 hover:bg-slate-200 hover:text-red-500 transition-colors flex flex-col items-center justify-center">
                    <i class="fas fa-sign-out-alt text-sm"></i>
                    <span class="text-[9px] font-bold mt-0.5">Logout</span>
                </button>
            </div>
        </div>

        <div class="flex gap-2 overflow-x-auto hide-scroll pb-1">
            <button onclick="switchTab('dashboard', this)" class="nav-tab active flex-shrink-0 px-4 py-2.5 rounded-xl text-xs font-bold bg-indigo-600 text-white shadow-md shadow-indigo-200 transition-all flex items-center gap-2">
                <i class="fas fa-chart-line"></i> Dashboard
            </button>
            <button type="button" onclick="location.href='inventory.php'" class="flex-shrink-0 px-4 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 transition-all flex items-center gap-2">
                <i class="fas fa-cubes"></i> Inventory
            </button>
            <?php if ($ddr_queue_show && $ddr_nav_href !== ''): ?>
            <button type="button" onclick="location.href='<?php echo htmlspecialchars($ddr_nav_href); ?>'" class="flex-shrink-0 px-4 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 transition-all flex items-center gap-2">
                <i class="fas fa-clipboard-check"></i> Damage Reports
            </button>
            <?php endif; ?>
            <button type="button" id="navTabHistory" onclick="switchTab('history', this)" class="nav-tab flex-shrink-0 px-4 py-2.5 rounded-xl text-xs font-bold text-slate-500 hover:bg-slate-100 transition-all flex items-center gap-2">
                <i class="fas fa-history"></i> History
            </button>
        </div>
        <?php endif; ?>
    </header>
    <?php if ($is_inventory_staff) {
        inv_chrome_render_mobile_drawer($INV_CHROME);
    } ?>

    <main class="p-5">
        <!-- DASHBOARD TAB -->
        <div id="pane-dashboard" class="tab-content active">
            
            <!-- Alerts Section -->
            <?php if (count($low_stock_alerts) > 0): ?>
            <div class="mb-5 bg-rose-50 border border-rose-100 rounded-2xl p-4">
                <h4 class="text-rose-600 text-[11px] font-black uppercase tracking-wider mb-2 flex items-center gap-1.5"><i class="fas fa-exclamation-triangle"></i> Low Stock Alerts</h4>
                <div class="space-y-1.5">
                    <?php foreach($low_stock_alerts as $alert): 
                        $qty = (float)$alert['current_quantity'];
                        $is_zero = ($qty == 0);
                    ?>
                    <div class="flex justify-between items-center bg-white p-2 rounded-lg text-sm">
                        <span class="font-bold text-slate-700"><?php echo htmlspecialchars($alert['product_name']); ?></span>
                        <span class="font-black <?php echo $is_zero ? 'text-red-500' : 'text-rose-500'; ?>"><?php echo number_format($qty, 0); ?> pcs</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Primary Action Buttons for Visibility -->
            <div class="mb-5 grid grid-cols-1 gap-2">
                <button onclick="openProductionModal()" class="w-full py-4 bg-indigo-600 hover:bg-indigo-700 text-white rounded-2xl font-bold text-sm shadow-lg shadow-indigo-100 transition-all flex items-center justify-center gap-2">
                    <i class="fas fa-plus-circle"></i> Record Stock In
                </button>
                <button onclick="openAdjustmentModal()" class="w-full py-4 bg-slate-800 hover:bg-slate-900 text-white rounded-2xl font-bold text-sm shadow-lg shadow-slate-200 transition-all flex items-center justify-center gap-2">
                    <i class="fas fa-edit"></i> Manual Adjustment
                </button>
            </div>

            <!-- Stats Grid w/ Trends -->
            <div class="grid grid-cols-2 gap-3 mb-5">
                <div class="bg-white border md:border-none border-slate-100 p-4 rounded-2xl shadow-sm text-center relative overflow-hidden">
                    <div class="text-3xl font-black text-indigo-600 mb-0.5"><?php echo $today_count; ?></div>
                    <div class="text-[10px] font-bold text-slate-400 tracking-wider uppercase mb-2">Done Today</div>
                    <div class="inline-flex items-center gap-1 text-[9px] font-bold px-2 py-0.5 rounded-md <?php echo $trend_class; ?>">
                        <i class="fas <?php echo $trend_icon; ?>"></i> <?php echo $trend_text; ?>
                    </div>
                </div>
                <!-- Mini Chart Box -->
                <div class="bg-gradient-to-br from-indigo-500 to-purple-500 p-4 rounded-2xl shadow-md text-center text-white flex flex-col justify-center">
                    <div class="text-3xl font-black mb-0.5"><?php echo number_format($total_adjustments); ?></div>
                    <div class="text-[10px] font-bold text-indigo-100 tracking-wider uppercase">Total Records</div>
                </div>
                
                <div class="bg-white border md:border-none border-slate-100 p-4 rounded-2xl shadow-sm text-center">
                    <div class="text-xl font-black text-emerald-500 mb-1">+<?php echo number_format($total_stocked_in); ?></div>
                    <div class="text-[10px] font-bold text-slate-400 tracking-wider uppercase">Stock Added</div>
                </div>
                <div class="bg-white border md:border-none border-slate-100 p-4 rounded-2xl shadow-sm text-center">
                    <div class="text-xl font-black text-rose-500 mb-1">-<?php echo number_format($negative_count); ?></div>
                    <div class="text-[10px] font-bold text-slate-400 tracking-wider uppercase">Loss / Damage</div>
                </div>
            </div>

            <?php if (!$is_inventory_staff): ?>
            <div class="bg-white border md:border-none border-slate-100 p-4 rounded-2xl shadow-sm mb-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-bold text-sm text-slate-800"><i class="fas fa-chart-area text-indigo-500 mr-1.5"></i> Activity Trend</h3>
                    <select class="text-xs border-none bg-slate-50 text-slate-500 font-semibold rounded-lg py-1 px-2 outline-none">
                        <option>Last 7 Days</option>
                    </select>
                </div>
                <div class="w-full h-40">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="flex items-center gap-2 mb-3">
                <h3 class="font-black text-sm text-slate-800"><i class="far fa-clock text-indigo-500 mr-1"></i> Recent Adjustments</h3>
            </div>
            
            <div class="space-y-3 pb-24">
                <?php 
                $history_stmt->execute([':uid' => $current_user_id]);
                if ($history_stmt->rowCount() > 0): ?>
                    <?php 
                    $limit = 5; $count = 0;
                    while ($row = $history_stmt->fetch(PDO::FETCH_ASSOC)): 
                        if ($count++ >= $limit) break;
                        $change = $row['new_quantity'] - $row['old_quantity'];
                        $is_pos = $change >= 0;
                        $color = $is_pos ? 'emerald' : 'red';
                        $icon = $is_pos ? 'fa-arrow-up' : 'fa-arrow-down';
                    ?>
                    <div class="bg-white p-4 rounded-2xl shadow-sm border border-slate-100" onclick='viewAdjDetail(<?php echo htmlspecialchars(json_encode($row)); ?>)'>
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-<?php echo $color; ?>-50 text-<?php echo $color; ?>-500 flex items-center justify-center">
                                    <i class="fas <?php echo $icon; ?>"></i>
                                </div>
                                <div>
                                    <h4 class="font-bold text-sm text-slate-800"><?php echo htmlspecialchars($row['product_name']); ?></h4>
                                    <p class="text-[10px] font-medium text-slate-400">
                                        <?php echo date('M j, g:i A', strtotime($row['adjustment_date'])); ?>
                                    </p>
                                </div>
                            </div>
                            <div class="bg-<?php echo $color; ?>-50 text-<?php echo $color; ?>-700 px-3 py-1 rounded-lg text-sm font-black flex items-center gap-1">
                                <?php echo ($is_pos ? '+' : '') . number_format($change, 0); ?>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="text-center py-10 bg-white rounded-2xl border border-dashed border-slate-200">
                        <i class="fas fa-clipboard-check text-4xl text-slate-200 mb-3"></i>
                        <p class="text-sm font-semibold text-slate-400">All caught up today!</p>
                    </div>
                <?php endif; ?>
            </div>
            
        </div>

        <!-- HISTORY TAB -->
        <div id="pane-history" class="tab-content">
            <!-- Date Filter -->
            <div class="flex gap-2 mb-4">
                <div class="flex-1">
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">From</label>
                    <input type="date" id="historyDateFrom" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-sm font-semibold text-slate-700 outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <div class="flex-1">
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">To</label>
                    <input type="date" id="historyDateTo" class="w-full px-3 py-2 bg-white border border-slate-200 rounded-xl text-sm font-semibold text-slate-700 outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <div class="flex items-end">
                    <button onclick="filterHistory()" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-sm font-bold transition-all">
                        <i class="fas fa-filter"></i>
                    </button>
                </div>
            </div>
            <div class="flex p-1 bg-slate-100 rounded-xl mb-5">
                <button onclick="switchSubTab('productions', this)" class="sub-tab active flex-1 py-2 text-xs font-bold bg-white text-indigo-600 rounded-lg shadow-sm transition-all text-center">
                    <i class="fas fa-boxes-stacked mr-1"></i> Stock In
                </button>
                <button onclick="switchSubTab('adjustments', this)" class="sub-tab flex-1 py-2 text-xs font-bold text-slate-500 hover:text-slate-700 transition-all text-center">
                    <i class="fas fa-sliders-h mr-1"></i> Adjustments
                </button>
            </div>

            <!-- Productions History -->
            <div id="sub-productions" class="sub-pane block">
                <?php if (count($production_history) > 0): ?>
                    <div class="space-y-3 pb-24 max-h-[60vh] overflow-y-auto">
                    <?php foreach ($production_history as $h): ?>
                        <div class="bg-white p-4 rounded-2xl shadow-sm border border-slate-100 flex items-center justify-between history-item" data-date="<?php echo $h['production_date']; ?>">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-500 flex items-center justify-center">
                                    <i class="fas fa-plus"></i>
                                </div>
                                <div>
                                    <h4 class="font-bold text-sm text-slate-800"><?php echo htmlspecialchars($h['product_name']); ?></h4>
                                    <p class="text-[10px] font-medium text-slate-400">
                                        <?php echo date('M j, Y', strtotime($h['production_date'])); ?> • <?php echo htmlspecialchars($h['handled_by'] ?? 'Staff'); ?>
                                    </p>
                                </div>
                            </div>
                            <div class="bg-indigo-50 text-indigo-700 px-3 py-1 rounded-lg text-sm font-black">
                                +<?php echo number_format((float)($h['produced_qty'] ?? 0), 0); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-10">
                        <i class="fas fa-history text-4xl text-slate-200 mb-3 block"></i>
                        <span class="text-sm font-semibold text-slate-400">No recent stock in records.</span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Adjustments History -->
            <div id="sub-adjustments" class="sub-pane hidden">
                <?php 
                $history_stmt->execute([':uid' => $current_user_id]);
                if ($history_stmt->rowCount() > 0): ?>
                    <div class="space-y-3 pb-24 max-h-[60vh] overflow-y-auto">
                    <?php while ($a = $history_stmt->fetch(PDO::FETCH_ASSOC)): 
                        $change = (float)$a['new_quantity'] - (float)$a['old_quantity'];
                        $is_pos = $change >= 0;
                        $color = $is_pos ? 'emerald' : 'red';
                        $icon = $is_pos ? 'fa-arrow-up' : 'fa-arrow-down';
                    ?>
                        <div class="bg-white p-4 rounded-2xl shadow-sm border border-slate-100 history-item" data-date="<?php echo $a['adjustment_date']; ?>" onclick='viewAdjDetail(<?php echo htmlspecialchars(json_encode($a)); ?>)'>
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-xl bg-<?php echo $color; ?>-50 text-<?php echo $color; ?>-500 flex items-center justify-center">
                                        <i class="fas <?php echo $icon; ?>"></i>
                                    </div>
                                    <div>
                                        <h4 class="font-bold text-sm text-slate-800"><?php echo htmlspecialchars($a['product_name']); ?></h4>
                                        <p class="text-[10px] font-medium text-slate-400">
                                            <?php echo date('M j, Y', strtotime($a['adjustment_date'])); ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="bg-<?php echo $color; ?>-50 text-<?php echo $color; ?>-700 px-3 py-1 rounded-lg text-sm font-black">
                                    <?php echo ($is_pos ? '+' : '') . number_format($change, 0); ?>
                                </div>
                            </div>
                            <div class="text-xs font-medium text-slate-500 bg-slate-50 px-3 py-2 rounded-lg border border-slate-100">
                                Reason: <span class="text-slate-700 font-bold"><?php echo htmlspecialchars($a['reason'] ?? 'N/A'); ?></span>
                                <?php $adjRemarks = normalizeAdjustmentNotes((string)($a['notes'] ?? '')); ?>
                                <?php if ($adjRemarks !== ''): ?>
                                    <div class="mt-1 text-slate-500">Remarks: <span class="text-slate-700 font-semibold"><?php echo htmlspecialchars($adjRemarks); ?></span></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-10">
                        <i class="fas fa-sliders-h text-4xl text-slate-200 mb-3 block"></i>
                        <span class="text-sm font-semibold text-slate-400">No recent adjustments.</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Check if inventory staff to show Add Production, else Add Adjustment -->
    <?php if ($is_inventory_staff): ?>
    <button onclick="openProductionModal()" class="fab" id="fabBtn">
        <i class="fas fa-plus"></i>
        <span>Record Stock In</span>
    </button>
    <?php else: ?>
    <button onclick="openAdjustmentModal()" class="fab" id="fabBtn">
        <i class="fas fa-edit"></i>
        <span>New Adjustment</span>
    </button>
    <?php endif; ?>

    <!-- Production Modal (For Staff) -->
    <div class="modal-overlay hidden fixed inset-0 z-50 flex items-end md:items-center justify-center" id="productionModal">
        <div class="modal-box w-full max-w-lg bg-white rounded-t-3xl md:rounded-3xl shadow-2xl relative transform transition-transform translate-y-full" id="prodModalContent">
            <div class="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-white rounded-t-3xl">
                <h3 class="font-black text-lg text-slate-800 flex items-center gap-2"><i class="fas fa-boxes-stacked text-indigo-500"></i> Record Stock In</h3>
                <button onclick="closeProductionModal()" class="w-8 h-8 flex items-center justify-center rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200 "><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body hide-scroll pb-10">
                <form method="POST" action="inventory_staff.php">
                    <?php echo csrfTokenField(); ?>
                    <input type="hidden" name="production_type" value="stockin">
                    
                    <div class="mb-5">
                        <label class="block text-xs font-bold text-slate-600 uppercase tracking-wide mb-2">Select Product <span class="text-red-500">*</span></label>
                        <select name="product_id" required class="w-full px-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold text-slate-700 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none appearance-none">
                            <option value="">-- Choose --</option>
                            <?php foreach ($products_data as $p): ?>
                                <option value="<?php echo $p['Product_ID']; ?>">
                                    <?php echo htmlspecialchars($p['product_name']); ?> 
                                    <?php if (!empty($p['unit_name'])): ?> (<?php echo htmlspecialchars($p['unit_name']); ?>)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="relative pointer-events-none -mt-9 mr-4 text-right text-slate-400"><i class="fas fa-chevron-down text-xs"></i></div>
                    </div>

                    <div class="mb-5 mt-6">
                        <label class="block text-xs font-bold text-slate-600 uppercase tracking-wide mb-2">Stock In Date <span class="text-red-500">*</span></label>
                        <input type="date" name="production_date" id="prodDate" required class="w-full px-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold text-slate-700 outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div class="mb-8">
                        <label class="block text-xs font-bold text-slate-600 uppercase tracking-wide mb-2">Quantity / Packs <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <i class="fas fa-hashtag absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                            <input type="number" name="number_of_bags" min="1" step="1" required placeholder="e.g. 50" class="w-full pl-11 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xl font-black text-slate-800 outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                    </div>

                    <button type="submit" class="w-full py-4 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold text-sm shadow-lg shadow-indigo-200 transition-all flex items-center justify-center gap-2">
                        <i class="fas fa-save"></i> Save Stock In
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Manual Adjustment Modal -->
    <div class="modal-overlay hidden fixed inset-0 z-50 flex items-end md:items-center justify-center" id="manualAdjustmentModal">
        <div class="modal-box w-full max-w-lg bg-white rounded-t-3xl md:rounded-3xl shadow-2xl relative transform transition-transform translate-y-full" id="adjModalContent">
            <div class="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-white rounded-t-3xl">
                <h3 class="font-black text-lg text-slate-800 flex items-center gap-2"><i class="fas fa-edit text-indigo-500"></i> New Adjustment</h3>
                <button onclick="closeAdjustmentModal()" class="w-8 h-8 flex items-center justify-center rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200 "><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body hide-scroll pb-10">
                <form method="post" action="../api/manual_adjustment_backend.php">
                    <?php echo csrfTokenField(); ?>
                    <input type="hidden" name="save_adjustment" value="1">
                    
                    <div class="mb-5">
                        <label class="block text-xs font-bold text-slate-600 uppercase tracking-wide mb-2">Product <span class="text-red-500">*</span></label>
                        <select name="product_id" id="modal_product_id" required onchange="updateQtyPreview()" class="w-full px-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold text-slate-700 focus:ring-2 focus:ring-indigo-500 outline-none appearance-none">
                            <option value="">-- Choose --</option>
                            <?php foreach ($products_data as $p): ?>
                                <option value="<?php echo $p['Product_ID']; ?>" data-qty="<?php echo $p['current_quantity']; ?>">
                                    <?php echo htmlspecialchars($p['product_name']); ?> 
                                    <?php if (!empty($p['unit_name'])): ?> (<?php echo htmlspecialchars($p['unit_name']); ?>)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="relative pointer-events-none -mt-9 mr-4 text-right text-slate-400"><i class="fas fa-chevron-down text-xs"></i></div>
                    </div>

                    <div class="bg-indigo-50 border border-indigo-100 rounded-2xl p-4 mb-6 relative mt-6">
                        
                        <div class="mb-6">
                            <label class="block text-xs font-bold text-indigo-700 uppercase tracking-wide mb-2">Adjustment (±) <span class="text-red-500">*</span></label>
                            <input type="number" name="adjustment_value" id="modal_adj_val" step="1" required placeholder="+10 or -5" oninput="updateQtyPreview()" class="w-full px-4 py-3 bg-white border border-indigo-200 rounded-xl text-xl font-black text-indigo-700 outline-none focus:ring-2 focus:ring-indigo-500 text-center">
                        </div>

                        <div class="mb-6 rounded-2xl bg-white/80 border border-indigo-100 px-4 py-3 shadow-sm">
                            <span class="block text-[11px] font-black uppercase tracking-[0.2em] text-indigo-500 mb-2">Current Qty</span>
                            <span id="modal_current_qty_label" class="inline-flex items-center rounded-full bg-indigo-600 px-4 py-2 text-lg font-black text-white shadow-sm">Select Product</span>
                        </div>

                        <div class="border-t border-indigo-200 pt-5">
                            <div class="flex items-center justify-between rounded-2xl border border-indigo-100 bg-white px-4 py-3">
                                <span class="text-sm font-black text-slate-600">New Qty</span>
                                <span id="modal_result_qty" class="inline-flex min-w-[4.5rem] items-center justify-center rounded-full bg-indigo-100 px-4 py-2 text-xl font-black text-indigo-700">0</span>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-xs font-bold text-slate-600 uppercase tracking-wide mb-2">Reason <span class="text-red-500">*</span></label>
                        <select name="reason" id="modal_reason" required onchange="toggleAdjustmentRemarks()" class="w-full px-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold text-slate-700 focus:ring-2 focus:ring-indigo-500 outline-none appearance-none" <?php echo empty($reasons) ? 'disabled' : ''; ?>>
                            <option value=""><?php echo empty($reasons) ? 'No reasons configured in database' : 'Select Reason'; ?></option>
                            <?php foreach ($reasons as $reason): ?>
                                <option value="<?php echo htmlspecialchars($reason); ?>"><?php echo htmlspecialchars($reason); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="relative pointer-events-none -mt-9 mr-4 text-right text-slate-400"><i class="fas fa-chevron-down text-xs"></i></div>
                    </div>

                    <div class="mb-8">
                        <label class="block text-xs font-bold text-slate-600 uppercase tracking-wide mb-2">Remarks <span id="remarksRequiredBadge" class="text-red-500 hidden">*</span></label>
                        <textarea name="remarks" id="modal_remarks" rows="3" maxlength="500" placeholder="Add details for this adjustment..." class="w-full px-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium text-slate-700 outline-none focus:ring-2 focus:ring-indigo-500 resize-none"></textarea>
                        <p id="remarksHelpText" class="mt-2 text-[11px] font-medium text-slate-400">
                            Optional for standard reasons. Required when you choose "Other (with remarks)".
                        </p>
                    </div>

                    <button type="submit" class="w-full py-4 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold text-sm shadow-lg shadow-indigo-200 transition-all flex items-center justify-center gap-2" <?php echo empty($reasons) ? 'disabled' : ''; ?>>
                        <i class="fas fa-save"></i> Save Adjustment
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script>
        // Init Chart.js Trend Line
        document.addEventListener('DOMContentLoaded', function() {
            const chartCanvas = document.getElementById('trendChart');
            if (chartCanvas) {
            const ctx = chartCanvas.getContext('2d');
            const gradient = ctx.createLinearGradient(0, 0, 0, 160);
            gradient.addColorStop(0, 'rgba(99, 102, 241, 0.2)');
            gradient.addColorStop(1, 'rgba(99, 102, 241, 0)');
            
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($chart_labels); ?>,
                    datasets: [{
                        label: 'Adjustments',
                        data: <?php echo json_encode($chart_data); ?>,
                        borderColor: '#6366f1',
                        backgroundColor: gradient,
                        borderWidth: 3,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: '#6366f1',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: {
                        backgroundColor: '#1e293b', padding: 10, cornerRadius: 8,
                        titleFont: { family: 'Poppins', size: 11 }, bodyFont: { family: 'Poppins', size: 13, weight: 'bold' }
                    } },
                    scales: {
                        x: { display: true, grid: { display: false }, ticks: { font: { family: 'Poppins', size: 10 } } },
                        y: { display: false, border: { display: false }, beginAtZero: true }
                    }
                }
            });
            }
        });

        const CAN_INV_MANUAL_ADJUSTMENT = <?php echo $can_inv_manual_adjustment ? 'true' : 'false'; ?>;
        const CAN_INV_RECORD_PRODUCTION = <?php echo $can_inv_record_production ? 'true' : 'false'; ?>;
        const CAN_INV_PRODUCTION_HISTORY = <?php echo $can_inv_production_history ? 'true' : 'false'; ?>;
        const CAN_INV_ADJUSTMENT_HISTORY = <?php echo $can_inv_adjustment_history ? 'true' : 'false'; ?>;
        const ADJUSTMENT_OTHER_REASON = <?php echo json_encode($has_other_reason ? 'Other (with remarks)' : ''); ?>;

        // Tab system
        function switchTab(id, btn) {
            if (id === 'history' && !CAN_INV_PRODUCTION_HISTORY && !CAN_INV_ADJUSTMENT_HISTORY) {
                Swal.fire('Access Restricted', "You can’t access this module right now.", 'warning');
                return;
            }
            document.querySelectorAll('.nav-tab').forEach(b => {
                b.classList.remove('bg-indigo-600', 'text-white', 'shadow-md', 'shadow-indigo-200');
                b.classList.add('text-slate-500', 'hover:bg-slate-100');
            });
            btn.classList.remove('text-slate-500', 'hover:bg-slate-100');
            btn.classList.add('bg-indigo-600', 'text-white', 'shadow-md', 'shadow-indigo-200');
            
            document.querySelectorAll('.tab-content').forEach(p => p.classList.remove('active'));
            document.getElementById('pane-' + id).classList.add('active');
            
            // Adjust FAB
            const fab = document.getElementById('fabBtn');
            if (id === 'history') fab.style.display = 'none';
            else fab.style.display = 'flex';
        }

        // Sub-tabs
        function switchSubTab(id, btn) {
            if ((id === 'productions' && !CAN_INV_PRODUCTION_HISTORY) || (id === 'adjustments' && !CAN_INV_ADJUSTMENT_HISTORY)) {
                Swal.fire('Access Restricted', "You can’t access this module right now.", 'warning');
                return;
            }
            document.querySelectorAll('.sub-tab').forEach(b => {
                b.classList.remove('bg-white', 'text-indigo-600', 'shadow-sm');
                b.classList.add('text-slate-500', 'hover:text-slate-700');
            });
            btn.classList.remove('text-slate-500', 'hover:text-slate-700');
            btn.classList.add('bg-white', 'text-indigo-600', 'shadow-sm');
            
            document.querySelectorAll('.sub-pane').forEach(p => { p.classList.remove('block'); p.classList.add('hidden'); });
            document.getElementById('sub-' + id).classList.remove('hidden'); document.getElementById('sub-' + id).classList.add('block');
        }

        // Modals
        function setupModal(id, contentId) {
            const m = document.getElementById(id);
            const c = document.getElementById(contentId);
            return {
                open: () => {
                    m.classList.remove('hidden');
                    setTimeout(() => c.classList.remove('translate-y-full'), 10);
                },
                close: () => {
                    c.classList.add('translate-y-full');
                    setTimeout(() => m.classList.add('hidden'), 300);
                },
                init: function() { m.addEventListener('click', e => { if (e.target === m) this.close(); }); }
            };
        }

        const prodModal = setupModal('productionModal', 'prodModalContent');
        const adjModal = setupModal('manualAdjustmentModal', 'adjModalContent');
        prodModal.init(); adjModal.init();

        function openProductionModal() {
            if (!CAN_INV_RECORD_PRODUCTION) {
                Swal.fire('Access Restricted', "You can’t access this module right now.", 'warning');
                return;
            }
            prodModal.open();
            const d = document.getElementById('prodDate');
            if (d && !d.value) {
                const now = new Date();
                d.value = now.getFullYear() + '-' + String(now.getMonth()+1).padStart(2,'0') + '-' + String(now.getDate()).padStart(2,'0');
            }
        }
        function closeProductionModal() { prodModal.close(); }

        function openAdjustmentModal() {
            if (!CAN_INV_MANUAL_ADJUSTMENT) {
                Swal.fire('Access Restricted', "You can’t access this module right now.", 'warning');
                return;
            }
            adjModal.open();
            toggleAdjustmentRemarks();
        }
        function closeAdjustmentModal() { adjModal.close(); }

        function toggleAdjustmentRemarks() {
            const reasonSelect = document.getElementById('modal_reason');
            const remarksField = document.getElementById('modal_remarks');
            const requiredBadge = document.getElementById('remarksRequiredBadge');
            const helpText = document.getElementById('remarksHelpText');
            if (!reasonSelect || !remarksField || !requiredBadge || !helpText) return;

            const isOther = ADJUSTMENT_OTHER_REASON !== '' && reasonSelect.value === ADJUSTMENT_OTHER_REASON;
            remarksField.required = isOther;
            requiredBadge.classList.toggle('hidden', !isOther);
            helpText.textContent = isOther
                ? 'Remarks are required for "Other (with remarks)".'
                : 'Optional for standard reasons. Required when you choose "Other (with remarks)".';
        }

        function updateQtyPreview() {
            const select = document.getElementById('modal_product_id');
            const displayLabel = document.getElementById('modal_current_qty_label');
            const displayResult = document.getElementById('modal_result_qty');
            const inputAdj = document.getElementById('modal_adj_val');

            const selected = select.options[select.selectedIndex];
            const productName = selected?.text || '';
            const current = parseFloat(selected?.getAttribute('data-qty')) || 0;
            const adjust = parseFloat(inputAdj.value) || 0;
            
            if (select.value) {
                displayLabel.textContent = `${current.toLocaleString()} units`;
            } else {
                displayLabel.textContent = 'Select Product';
            }
            
            displayResult.textContent = (current + adjust).toLocaleString();
            displayResult.className = `text-lg font-black ${current + adjust < 0 ? 'text-red-500' : 'text-indigo-600'}`;
        }

        function escapeAdjHtml(value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        function viewAdjDetail(data) {
            const remarks = (data.notes || '').trim();
            const showRemarks = remarks !== '' && remarks !== 'Manual inventory adjustment';
            Swal.fire({
                title: data.product_name,
                html: `<div class="text-left text-sm">
                    <p class="mb-1"><strong class="text-slate-700">Reason:</strong> ${escapeAdjHtml(data.reason)}</p>
                    ${showRemarks ? `<p class="mb-1"><strong class="text-slate-700">Remarks:</strong> ${escapeAdjHtml(remarks)}</p>` : ''}
                    <p class="mb-1"><strong class="text-slate-700">Handler:</strong> ${escapeAdjHtml(data.handled_by)}</p>
                    <p><strong class="text-slate-700">Qty Changed:</strong> ${data.new_quantity - data.old_quantity}</p>
                </div>`,
                confirmButtonColor: '#4f46e5',
                confirmButtonText: 'Understood',
                customClass: { popup: 'rounded-2xl border-none shadow-xl' }
            });
        }

        function showBlueSuccessModal(title, message) {
            Swal.fire({
                title: title,
                html: `
                    <div class="pt-2 pb-1">
                        <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-blue-50 text-3xl text-blue-600">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <p class="text-sm font-medium text-slate-500">${message}</p>
                    </div>
                `,
                confirmButtonText: 'Continue',
                confirmButtonColor: '#2563eb',
                customClass: {
                    popup: 'rounded-[28px] border border-blue-100 shadow-2xl',
                    title: 'text-slate-800 text-2xl font-black pt-2',
                    confirmButton: 'rounded-xl px-6 py-3 text-sm font-bold'
                }
            });
        }

        // Filter history by date range
        function filterHistory() {
            const fromDate = document.getElementById('historyDateFrom').value;
            const toDate = document.getElementById('historyDateTo').value;
            
            // Filter productions
            const prodItems = document.querySelectorAll('#sub-productions .history-item');
            prodItems.forEach(item => {
                const dateStr = item.getAttribute('data-date');
                if (!dateStr) return;
                const itemDate = new Date(dateStr);
                const from = fromDate ? new Date(fromDate) : null;
                const to = toDate ? new Date(toDate) : null;
                
                let show = true;
                if (from && itemDate < from) show = false;
                if (to && itemDate > to) show = false;
                
                item.style.display = show ? 'block' : 'none';
            });
            
            // Filter adjustments
            const adjItems = document.querySelectorAll('#sub-adjustments .history-item');
            adjItems.forEach(item => {
                const dateStr = item.getAttribute('data-date');
                if (!dateStr) return;
                const itemDate = new Date(dateStr);
                const from = fromDate ? new Date(fromDate) : null;
                const to = toDate ? new Date(toDate) : null;
                
                let show = true;
                if (from && itemDate < from) show = false;
                if (to && itemDate > to) show = false;
                
                item.style.display = show ? 'block' : 'none';
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            const p = new URLSearchParams(window.location.search);
            if (p.get('tab') === 'history') {
                // Owner/manager UI uses #navTabHistory; inventory staff chrome uses #invNavHistory on the History <a>.
                const btn = document.getElementById('navTabHistory') || document.getElementById('invNavHistory');
                if (btn) switchTab('history', btn);
            }
            toggleAdjustmentRemarks();
        });
    </script>
    <?php if ($is_inventory_staff): ?>
    <script src="../assets/js/inventory-staff-chrome.js"></script>
    <?php endif; ?>
    <script src="../assets/js/script.js"></script>
    <script src="../assets/js/module_access_realtime.js"></script>
    <script>
        // Use SweetAlert to show success/error messages from backend
        <?php if (isset($_SESSION['success_msg'])): ?>
            showBlueSuccessModal('Saved Successfully', <?php echo json_encode($_SESSION['success_msg']); ?>);
            <?php unset($_SESSION['success_msg']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_msg'])): ?>
            Swal.fire({
                icon: 'error',
                title: 'Unable to Save',
                text: <?php echo json_encode($_SESSION['error_msg']); ?>,
                confirmButtonColor: '#ef4444',
                customClass: { popup: 'rounded-[28px] shadow-2xl' }
            });
            <?php unset($_SESSION['error_msg']); ?>
        <?php endif; ?>
    </script>
</body>
</html>
