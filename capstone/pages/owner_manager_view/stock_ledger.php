<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/roles_helper.php';

// Accessible to Owner (1), Manager (2, 4), and Inventory Staff
$staff_ids = getInventoryStaffRoleIds($conn);
$allowed_roles = array_unique(array_merge([1, 2, 4], $staff_ids));
requireRole($allowed_roles);

// Get filters
$product_filter = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;
$type_filter = isset($_GET['type']) ? $_GET['type'] : null;
$date_start = isset($_GET['start']) ? $_GET['start'] : date('Y-m-d', strtotime('-30 days'));
$date_end = isset($_GET['end']) ? $_GET['end'] : date('Y-m-d');

// Fetch products for filter
$products = $conn->query("SELECT Product_ID, product_name FROM products WHERE is_discontinued = 0 ORDER BY product_name")->fetchAll();

// Build Query with pagination
$ledger_per_page = 50;
$ledger_page = max(1, intval($_GET['ledger_page'] ?? 1));
$ledger_offset = ($ledger_page - 1) * $ledger_per_page;

$query = "SELECT l.*, p.product_name, u_units.unit_name, au.user_name as handled_by_name, up.profile_picture
          FROM inventory_ledger l
          JOIN products p ON l.product_id = p.Product_ID
          LEFT JOIN units u_units ON p.unit_id = u_units.unit_id
          LEFT JOIN user au ON l.handled_by = au.User_ID
          LEFT JOIN User_Profile up ON au.User_ID = up.User_ID
          WHERE DATE(l.created_at) BETWEEN ? AND ?";
$params = [$date_start, $date_end];

if ($product_filter) {
    $query .= " AND l.product_id = ?";
    $params[] = $product_filter;
}
if ($type_filter) {
    $query .= " AND l.transaction_type = ?";
    $params[] = $type_filter;
}

// Get total count for pagination
$count_query = str_replace("SELECT l.*, p.product_name, u_units.unit_name, au.user_name as handled_by_name, up.profile_picture",
                           "SELECT COUNT(*) as total", $query);
$count_stmt = $conn->prepare($count_query);
$count_stmt->execute($params);
$ledger_total_items = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$ledger_total_pages = max(1, ceil($ledger_total_items / $ledger_per_page));

$query .= " ORDER BY l.created_at DESC, l.ledger_id DESC LIMIT $ledger_per_page OFFSET $ledger_offset";
$stmt = $conn->prepare($query);
$stmt->execute($params);
$ledger_entries = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Ledger - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <link rel="stylesheet" href="../assets/css/inventory.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --primary-light: #818cf8;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --dark: #1e293b;
            --gray: #64748b;
            --light-gray: #f1f5f9;
            --mono: var(--font-main);
            --font-main: 'Poppins', sans-serif;
        }

        * {
            font-family: var(--font-main);
        }

        /* Standard Sidebar Adjustment for Ledger */
        .main-content {
            margin-left: 280px;
            padding: 2rem;
            min-height: 100vh;
            background: #f8fafc;
        }

        @media (max-width: 992px) {
            .main-content { margin-left: 0; }
            .sidebar { transform: translateX(-100%); transition: transform 0.3s; }
            .sidebar.active { transform: translateX(0); }
        }

        /* Premium Header with VIP Theme */
        .ledger-header-banner {
            background: linear-gradient(135deg, #6366f1 0%, #7c3aed 100%);
            border-radius: 24px;
            padding: 2.5rem;
            color: white;
            position: relative;
            overflow: hidden;
            margin-bottom: 2rem;
            box-shadow: 0 20px 40px rgba(99, 102, 241, 0.25);
        }

        .ledger-header-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 400px;
            height: 400px;
            background: rgba(255,255,255,0.08);
            border-radius: 50%;
            pointer-events: none;
        }

        .ledger-header-banner::after {
            content: '';
            position: absolute;
            inset: 0;
            background-image: 
                radial-gradient(circle at 20% 80%, rgba(255,255,255,0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255,255,255,0.08) 0%, transparent 40%);
            pointer-events: none;
        }

        .l-title {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
            z-index: 1;
        }

        .l-subtitle {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-top: 8px;
            font-weight: 400;
            position: relative;
            z-index: 1;
        }

        /* Modern Filter Card */
        .l-filter-card {
            background: white;
            border-radius: 20px;
            padding: 1.75rem;
            margin-bottom: 2rem;
            border: 1px solid #e2e8f0;
            display: flex;
            gap: 1.25rem;
            flex-wrap: wrap;
            align-items: flex-end;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        }

        .l-filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
            min-width: 160px;
        }

        .l-filter-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--gray);
            letter-spacing: 0.5px;
        }

        .l-filter-input {
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--dark);
            background: #fafafa;
            transition: all 0.2s ease;
        }

        .l-filter-input:focus {
            border-color: var(--primary);
            background: white;
            outline: none;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }

        .l-btn-apply {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            padding: 12px 28px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
        }

        .l-btn-apply:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
        }

        .l-btn-reset {
            color: var(--gray);
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            padding: 12px 16px;
            border-radius: 12px;
            transition: all 0.2s;
        }

        .l-btn-reset:hover {
            color: var(--primary);
            background: rgba(99, 102, 241, 0.08);
        }

        /* Export Buttons */
        .l-btn-export {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            color: var(--dark);
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        .l-btn-export:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%);
        }

        .l-btn-export-secondary {
            background: white;
            border: 1px solid #e2e8f0;
        }

        .l-btn-export-secondary:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        /* Ledger Table */
        .l-table-card {
            background: white;
            border-radius: 24px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 24px rgba(0,0,0,0.04);
        }

        .l-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .l-table th {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            padding: 16px 20px;
            text-align: left;
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            border-bottom: 2px solid #e2e8f0;
        }

        .l-table tbody tr {
            transition: background-color 0.15s ease;
        }

        .l-table tbody tr:hover {
            background-color: #f8fafc;
        }

        .l-table td {
            padding: 18px 20px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.9rem;
            vertical-align: middle;
        }

        /* Enhanced Badge Styles */
        .l-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            border: 1px solid transparent;
        }

        .b-sales {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #dc2626;
            border-color: #fca5a5;
        }
        .b-sales::before {
            content: '↓';
            font-size: 0.7rem;
        }

        .b-stock-in {
            background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
            color: #16a34a;
            border-color: #86efac;
        }
        .b-stock-in::before {
            content: '↑';
            font-size: 0.7rem;
        }

        .b-adjustments {
            background: linear-gradient(135deg, #fef9c3 0%, #fde047 100%);
            color: #a16207;
            border-color: #facc15;
        }
        .b-adjustments::before {
            content: '↻';
            font-size: 0.7rem;
        }

        .b-damage-loss {
            background: linear-gradient(135deg, #ffedd5 0%, #fed7aa 100%);
            color: #ea580c;
            border-color: #fdba74;
        }
        .b-damage-loss::before {
            content: '⚠';
            font-size: 0.7rem;
        }

        .b-initial {
            background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
            color: #0284c7;
            border-color: #7dd3fc;
        }
        .b-initial::before {
            content: '●';
            font-size: 0.5rem;
        }

        .l-qty-bold {
            font-family: var(--mono);
            font-weight: 700;
            font-size: 1rem;
        }

        .l-total-qty {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            border-radius: 12px;
            padding: 10px 16px;
            text-align: center;
            font-weight: 700;
            color: var(--dark);
            border: 1px solid #e2e8f0;
            font-family: var(--mono);
            font-size: 0.95rem;
        }

        .l-plus { color: var(--success); }
        .l-minus { color: var(--danger); }

        /* User Avatar */
        .l-user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 700;
            color: var(--primary-dark);
            border: 2px solid white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .l-user-avatar-img {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .l-user-name {
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--dark);
        }

        /* Timestamp styling */
        .l-date {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.9rem;
        }

        .l-time {
            font-size: 0.75rem;
            color: var(--gray);
            font-family: var(--mono);
        }

        /* Product cell */
        .l-product-name {
            font-weight: 600;
            color: var(--primary-dark);
            font-size: 0.95rem;
        }

        .l-product-unit {
            font-size: 0.75rem;
            color: var(--gray);
            margin-top: 2px;
        }

        /* Current Qty */
        .l-current-qty {
            font-family: var(--mono);
            font-weight: 600;
            color: var(--gray);
            font-size: 0.95rem;
        }

        /* Notes/Reference */
        .l-ref {
            display: block;
            font-weight: 700;
            color: #94a3b8;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        .l-notes {
            font-size: 0.8rem;
            color: #475569;
            line-height: 1.4;
        }

        .sidebar .menu-item.active {
            background-color: var(--primary) !important;
            color: white !important;
        }

        /* Empty state */
        .l-empty {
            text-align: center;
            padding: 4rem;
            color: var(--gray);
        }

        .l-empty i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
    </style>
</head>
<body>

<div class="dashboard-wrapper">
    <!-- Corrected Sidebar to match system standard -->
    <?php
    require_once __DIR__ . '/../../includes/sidebar.php';
    renderSidebar($conn, ['base' => '../', 'active' => 'stock_ledger']);
    ?>
    <aside class="sidebar legacy-sidebar" style="display:none;">
        <div class="sidebar-header">
            <div class="sidebar-brand">
                <div class="brand-icon"><i class="fas fa-snowflake"></i></div>
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
                <a href="../index.php" class="menu-item">
                    <i class="fas fa-th-large"></i>
                    <span>Dashboard</span>
                </a>
                <a href="sales.php" class="menu-item">
                    <i class="fas fa-receipt"></i>
                    <span>Sales</span>
                </a>
                <a href="inventory.php" class="menu-item">
                    <i class="fas fa-cubes"></i>
                    <span>Inventory</span>
                </a>
                <a href="damage_goods.php" class="menu-item">
                    <i class="fas fa-heart-broken"></i>
                    <span>Damage Goods</span>
                </a>
                <a href="stock_ledger.php" class="menu-item active">
                    <i class="fas fa-file-invoice"></i>
                    <span>Stock Ledger</span>
                </a>
                <a href="users.php" class="menu-item">
                    <i class="fas fa-users"></i>
                    <span>Customers</span>
                </a>
                <a href="orders.php" class="menu-item">
                    <i class="fas fa-shopping-cart"></i>
                    <span>Orders</span>
                </a>
                <a href="delivery.php" class="menu-item">
                    <i class="fas fa-truck"></i>
                    <span>Delivery</span>
                </a>
            </div>

            <div class="menu-section">
                <div class="menu-label">System</div>
                <a href="activity_logs.php" class="menu-item">
                    <i class="fas fa-history"></i>
                    <span>Activity Logs</span>
                </a>
                <a href="../logout.php" class="menu-item">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </nav>
    </aside>

    <main class="main-content">
        <!-- Dashboard Header -->
        <section class="ledger-header-banner">
            <h1 class="l-title"><i class="fas fa-book"></i> Stock Transaction Ledger</h1>
            <div class="l-subtitle">Real-time Stock Movements & Historical Audit Trail</div>
        </section>

        <!-- Filters Section -->
        <form method="GET" class="l-filter-card">
            <div class="l-filter-group">
                <label class="l-filter-label">Product Selection</label>
                <select name="product_id" class="l-filter-input">
                    <option value="">-- All Products --</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?php echo $p['Product_ID']; ?>" <?php echo $product_filter == $p['Product_ID'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($p['product_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="l-filter-group">
                <label class="l-filter-label">Transaction Type</label>
                <select name="type" class="l-filter-input">
                    <option value="">-- All Types --</option>
                    <option value="STOCK IN" <?php echo $type_filter == 'STOCK IN' ? 'selected' : ''; ?>>Stock In</option>
                    <option value="SALES" <?php echo $type_filter == 'SALES' ? 'selected' : ''; ?>>Sales</option>
                    <option value="DAMAGE LOSS" <?php echo $type_filter == 'DAMAGE LOSS' ? 'selected' : ''; ?>>Damage Loss</option>
                    <option value="ADJUSTMENTS" <?php echo $type_filter == 'ADJUSTMENTS' ? 'selected' : ''; ?>>Adjustments</option>
                    <option value="INITIAL" <?php echo $type_filter == 'INITIAL' ? 'selected' : ''; ?>>Initial Balance</option>
                </select>
            </div>
            <div class="l-filter-group">
                <label class="l-filter-label">Date From</label>
                <input type="date" name="start" class="l-filter-input" value="<?php echo $date_start; ?>">
            </div>
            <div class="l-filter-group">
                <label class="l-filter-label">Date To</label>
                <input type="date" name="end" class="l-filter-input" value="<?php echo $date_end; ?>">
            </div>
            <button type="submit" class="l-btn-apply"><i class="fas fa-filter"></i> Apply Filters</button>
            <a href="stock_ledger.php" class="l-btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
        </form>

        <!-- Export Actions -->
        <div style="display: flex; gap: 0.75rem; margin-bottom: 1.25rem;">
            <button type="button" class="l-btn-export" onclick="exportLedger('range')">
                <i class="fas fa-file-export"></i> Export Range
            </button>
            <button type="button" class="l-btn-export l-btn-export-secondary" onclick="exportLedger('today')">
                <i class="fas fa-calendar-day"></i> Export Today
            </button>
        </div>

        <!-- Ledger Table -->
        <div class="l-table-card">
            <div class="table-scrollable">
            <table class="l-table">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Product Details</th>
                        <th>Current Qty</th>
                        <th>Movement Type</th>
                        <th>Movement</th>
                        <th>Total Quantity</th>
                        <th>Notes / Reference</th>
                        <th>Handled By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ledger_entries)): ?>
                        <tr><td colspan="8" class="l-empty"><i class="fas fa-search"></i><br>No transactions match your filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($ledger_entries as $entry): 
                            $type_slug = strtolower(str_replace(' ', '-', $entry['transaction_type']));
                            $badge_class = 'b-' . $type_slug;
                            
                            $qty_change = (int)$entry['quantity_change'];
                            $bal_after = (int)$entry['balance_after'];
                            $current_qty = $bal_after - $qty_change;
                            
                            $qty_class = $qty_change > 0 ? 'l-plus' : ($qty_change < 0 ? 'l-minus' : '');
                            $qty_prefix = $qty_change > 0 ? '+' : '';
                        ?>
                            <tr>
                                <td>
                                    <div class="l-date"><?php echo date('M d, Y', strtotime($entry['created_at'])); ?></div>
                                    <div class="l-time"><?php echo date('H:i:s', strtotime($entry['created_at'])); ?></div>
                                </td>
                                <td>
                                    <div class="l-product-name"><?php echo htmlspecialchars($entry['product_name']); ?></div>
                                    <div class="l-product-unit"><?php echo htmlspecialchars($entry['unit_name'] ?? 'Units'); ?></div>
                                </td>
                                <td>
                                    <div class="l-current-qty"><?php echo $current_qty; ?></div>
                                </td>
                                <td>
                                    <span class="l-badge <?php echo $badge_class; ?>">
                                        <?php echo $entry['transaction_type']; ?>
                                    </span>
                                </td>
                                <td class="l-qty-bold <?php echo $qty_class; ?>">
                                    <?php echo $qty_prefix . $qty_change; ?>
                                </td>
                                <td>
                                    <div class="l-total-qty"><?php echo $bal_after; ?></div>
                                </td>
                                <td>
                                    <?php if ($entry['transaction_id']): ?>
                                        <span class="l-ref">REF: #<?php echo $entry['transaction_id']; ?></span>
                                    <?php endif; ?>
                                    <div class="l-notes"><?php echo htmlspecialchars($entry['notes'] ?? ''); ?></div>
                                </td>
                                <td>
                                    <?php
                                    $pfp = trim((string)($entry['profile_picture'] ?? ''));
                                    $baseDir = dirname(__DIR__, 2);
                                    $fullPfpPath = $pfp !== '' ? $baseDir . '/' . str_replace('\\', '/', $pfp) : '';
                                    $hasPfp = $fullPfpPath !== '' && is_file($fullPfpPath);
                                    $pfpSrc = $hasPfp ? '../' . str_replace('\\', '/', $pfp) . '?v=' . time() : '';
                                    $initial = strtoupper(substr($entry['handled_by_name'] ?? 'S', 0, 1));
                                    ?>
                                    <div style="display:flex; align-items:center; gap:10px;">
                                        <?php if ($hasPfp): ?>
                                            <img src="<?php echo htmlspecialchars($pfpSrc, ENT_QUOTES, 'UTF-8'); ?>"
                                                 alt="<?php echo htmlspecialchars($entry['handled_by_name'] ?? 'User'); ?>"
                                                 class="l-user-avatar-img"
                                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                            <div class="l-user-avatar" style="display:none;"><?php echo $initial; ?></div>
                                        <?php else: ?>
                                            <div class="l-user-avatar"><?php echo $initial; ?></div>
                                        <?php endif; ?>
                                        <span class="l-user-name"><?php echo htmlspecialchars($entry['handled_by_name'] ?? 'System'); ?></span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>

            <!-- Pagination for Stock Ledger -->
            <?php if ($ledger_total_pages > 1): ?>
            <div style="display: flex; justify-content: center; align-items: center; gap: 0.5rem; padding: 1.5rem; border-top: 1px solid #e2e8f0; background: #f8fafc;">
                <a href="?start=<?php echo urlencode($date_start); ?>&end=<?php echo urlencode($date_end); ?><?php echo $product_filter ? '&product_id=' . $product_filter : ''; ?><?php echo $type_filter ? '&type=' . urlencode($type_filter) : ''; ?>&ledger_page=<?php echo max(1, $ledger_page - 1); ?>" class="btn btn-secondary btn-sm" <?php echo $ledger_page <= 1 ? 'style="pointer-events:none;opacity:0.5;"' : ''; ?>>
                    <i class="fas fa-chevron-left"></i> Previous
                </a>
                <span style="font-size: 0.875rem; color: #475569; font-weight: 600;">
                    Page <?php echo $ledger_page; ?> of <?php echo $ledger_total_pages; ?> (<?php echo $ledger_total_items; ?> total)
                </span>
                <a href="?start=<?php echo urlencode($date_start); ?>&end=<?php echo urlencode($date_end); ?><?php echo $product_filter ? '&product_id=' . $product_filter : ''; ?><?php echo $type_filter ? '&type=' . urlencode($type_filter) : ''; ?>&ledger_page=<?php echo min($ledger_total_pages, $ledger_page + 1); ?>" class="btn btn-secondary btn-sm" <?php echo $ledger_page >= $ledger_total_pages ? 'style="pointer-events:none;opacity:0.5;"' : ''; ?>>
                    Next <i class="fas fa-chevron-right"></i>
                </a>
            </div>
            <?php endif; ?>

            <style>
                .table-scrollable {
                    max-height: 600px;
                    overflow-y: auto;
                    overflow-x: auto;
                }
                .table-scrollable::-webkit-scrollbar {
                    width: 8px;
                    height: 8px;
                }
                .table-scrollable::-webkit-scrollbar-track {
                    background: #f1f5f9;
                    border-radius: 4px;
                }
                .table-scrollable::-webkit-scrollbar-thumb {
                    background: #cbd5e1;
                    border-radius: 4px;
                }
                .table-scrollable::-webkit-scrollbar-thumb:hover {
                    background: #94a3b8;
                }
            </style>
        </div>
    </main>
</div>

<script>
    // System standard sidebar interactions
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('active');
        });
    }

    // Export functionality
    function exportLedger(type) {
        const urlParams = new URLSearchParams(window.location.search);
        if (type === 'today') {
            const today = new Date().toISOString().split('T')[0];
            urlParams.set('start', today);
            urlParams.set('end', today);
        }
        urlParams.set('export', '1');
        window.location.href = 'stock_ledger.php?' + urlParams.toString();
    }
</script>

</body>
</html>
