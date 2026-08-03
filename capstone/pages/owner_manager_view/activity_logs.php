<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

// Accessible to Owner (1), Manager (2), and Rider (4)
requireRole([1, 2, 4]);

// Filter parameters
$user_filter = $_GET['user'] ?? '';
$type_filter = $_GET['type'] ?? '';
$date_start = $_GET['start'] ?? '';
$date_end = $_GET['end'] ?? '';

// Build WHERE conditions
$where_conditions = [];
$params = [];

if (!empty($user_filter)) {
    $where_conditions[] = "al.User_ID = ?";
    $params[] = $user_filter;
}

if (!empty($type_filter)) {
    $where_conditions[] = "al.Activity_Type LIKE ?";
    $params[] = "%$type_filter%";
}

if (!empty($date_start)) {
    $where_conditions[] = "DATE(al.Log_Time) >= ?";
    $params[] = $date_start;
}

if (!empty($date_end)) {
    $where_conditions[] = "DATE(al.Log_Time) <= ?";
    $params[] = $date_end;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Pagination parameters (Performance Fix)
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = min(100, max(1, intval($_GET['per_page'] ?? 20))); // Max 100 per page
$offset = ($page - 1) * $per_page;

// Get total count for pagination (Performance Fix)
$count_query = "SELECT COUNT(*) FROM activity_logs al $where_clause";
$count_stmt = $conn->prepare($count_query);
$count_stmt->execute($params);
$total_records = (int) $count_stmt->fetchColumn();
$total_pages = max(1, ceil($total_records / $per_page));

// Ensure page is within valid range
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

// Fetch activity logs with user names and profile pictures (paginated).
// Scalar subquery avoids duplicate rows if multiple User_Profile rows ever exist per user.
$query = "SELECT al.*, u.user_name,
          (SELECT up2.profile_picture FROM User_Profile up2 WHERE up2.User_ID = al.User_ID LIMIT 1) AS profile_picture
          FROM activity_logs al 
          LEFT JOIN user u ON al.User_ID = u.User_ID 
          $where_clause
          ORDER BY al.Log_Time DESC 
          LIMIT ? OFFSET ?";
$stmt = $conn->prepare($query);
// Merge params with pagination params
$execute_params = array_merge($params, [$per_page, $offset]);
$stmt->execute($execute_params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Relative URL to capstone root for uploads (depends on entry script: pages/activity_logs.php vs owner_manager_view/activity_logs.php)
$reqDirBasename = basename(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))));
$activityLogsUploadPrefix = ($reqDirBasename === 'owner_manager_view') ? '../../' : '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logs - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        * {
            font-family: 'Poppins', sans-serif;
        }

        /* Header Banner */
        .logs-header-banner {
            background: linear-gradient(135deg, #6366f1 0%, #7c3aed 100%);
            border-radius: 24px;
            padding: 2.5rem;
            color: white;
            position: relative;
            overflow: hidden;
            margin-bottom: 2rem;
            box-shadow: 0 20px 40px rgba(99, 102, 241, 0.25);
        }

        .logs-header-banner::before {
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

        .logs-title {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
            z-index: 1;
        }

        .logs-subtitle {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-top: 8px;
            font-weight: 400;
            position: relative;
            z-index: 1;
        }

        /* Filter Section */
        .logs-filter-card {
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

        .logs-filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
            min-width: 160px;
        }

        .logs-filter-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
            letter-spacing: 0.5px;
        }

        .logs-filter-input {
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 0.9rem;
            font-weight: 500;
            color: #1e293b;
            background: #fafafa;
            transition: all 0.2s ease;
        }

        .logs-filter-input:focus {
            border-color: #6366f1;
            background: white;
            outline: none;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }

        .logs-btn-apply {
            background: linear-gradient(135deg, #6366f1 0%, #7c3aed 100%);
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

        .logs-btn-apply:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
        }

        .logs-btn-reset {
            color: #64748b;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            padding: 12px 16px;
            border-radius: 12px;
            transition: all 0.2s;
        }

        .logs-btn-reset:hover {
            color: #6366f1;
            background: rgba(99, 102, 241, 0.08);
        }
        /* Table Container */
        .log-table-container {
            background: white;
            border-radius: 24px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.04);
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }

        .log-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .log-table thead th {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            color: #64748b;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.7rem;
            letter-spacing: 0.8px;
            padding: 1rem 1.5rem;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
        }

        .log-table tbody tr {
            transition: all 0.2s ease;
        }

        .log-table tbody tr:hover {
            background: #f8fafc;
        }

        .log-table tbody td {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #f1f5f9;
            color: #475569;
            font-size: 0.9rem;
            vertical-align: middle;
        }

        /* Enhanced User Badge */
        .user-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.875rem;
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            color: #475569;
            border-radius: 9999px;
            font-weight: 600;
            font-size: 0.85rem;
            border: 1px solid #e2e8f0;
        }

        .user-badge i {
            color: #94a3b8;
        }

        .user-avatar-img {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #fff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .user-avatar-initial {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
            border: 2px solid #fff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        /* Enhanced Activity Type Badges */
        .activity-type {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 1px solid transparent;
            margin-bottom: 0.5rem;
        }

        /* Activity Type Colors */
        .act-order {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1d4ed8;
            border-color: #93c5fd;
        }
        .act-order::before {
            content: '\f07a';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            font-size: 0.65rem;
        }

        .act-sale {
            background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
            color: #15803d;
            border-color: #86efac;
        }
        .act-sale::before {
            content: '\f155';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            font-size: 0.65rem;
        }

        .act-inventory {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #b45309;
            border-color: #fcd34d;
        }
        .act-inventory::before {
            content: '\f466';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            font-size: 0.65rem;
        }

        .act-delivery {
            background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
            color: #4338ca;
            border-color: #a5b4fc;
        }
        .act-delivery::before {
            content: '\f0d1';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            font-size: 0.65rem;
        }

        .act-user {
            background: linear-gradient(135deg, #f3e8ff 0%, #e9d5ff 100%);
            color: #7c3aed;
            border-color: #d8b4fe;
        }
        .act-user::before {
            content: '\f007';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            font-size: 0.65rem;
        }

        .act-damage {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #dc2626;
            border-color: #fca5a5;
        }
        .act-damage::before {
            content: '\f7d9';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            font-size: 0.65rem;
        }

        .act-login, .act-logout {
            background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
            color: #0284c7;
            border-color: #7dd3fc;
        }
        .act-login::before {
            content: '\f2f6';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            font-size: 0.65rem;
        }
        .act-logout::before {
            content: '\f2f5';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            font-size: 0.65rem;
        }

        .act-default {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            color: #475569;
            border-color: #cbd5e1;
        }

        /* Timestamp styling */
        .time-text {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: #64748b;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .time-text i {
            color: #cbd5e1;
        }

        /* Action Details */
        .action-details {
            font-weight: 500;
            color: #1e293b;
            line-height: 1.5;
        }
    </style>
</head>
<body>
<div class="dashboard-wrapper">
    <!-- Sidebar -->
    <?php
    require_once __DIR__ . '/../../includes/sidebar.php';
    renderSidebar($conn, ['base' => '../', 'active' => 'activity_logs']);
    ?>
    <aside class="sidebar legacy-sidebar" style="display:none;">
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
                <a href="stock_ledger.php" class="menu-item">
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
                <div class="menu-label">Accounting</div>
                <a href="accounts_receivable.php" class="menu-item">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <span>Accounts Receivable</span>
                </a>
            </div>

            <div class="menu-section">
                <div class="menu-label">System</div>
                <a href="activity_logs.php" class="menu-item active">
                    <i class="fas fa-history"></i>
                    <span>Activity Logs</span>
                </a>
                <?php if (in_array($_SESSION['user_role'] ?? 1, [1, 2])): ?>
                <a href="user_management.php" class="menu-item">
                    <i class="fas fa-user-shield"></i>
                    <span>User Management</span>
                </a>
                <?php endif; ?>
                <a href="../logout.php" class="menu-item">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content" style="padding: 2rem;">
        <!-- Header Banner -->
        <section class="logs-header-banner">
            <h1 class="logs-title"><i class="fas fa-history"></i> Activity Logs</h1>
            <div class="logs-subtitle">Audit trail of system activities and user actions</div>
        </section>

        <!-- Filters Section -->
        <form method="GET" class="logs-filter-card">
            <div class="logs-filter-group">
                <label class="logs-filter-label">User</label>
                <select name="user" class="logs-filter-input">
                    <option value="">All Users</option>
                    <?php 
                    $users_query = "SELECT DISTINCT u.User_ID, u.user_name FROM activity_logs al LEFT JOIN user u ON al.User_ID = u.User_ID WHERE u.user_name IS NOT NULL ORDER BY u.user_name";
                    $users_stmt = $conn->query($users_query);
                    $users_list = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($users_list as $user): 
                    ?>
                    <option value="<?php echo $user['User_ID']; ?>" <?php echo ($_GET['user'] ?? '') == $user['User_ID'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($user['user_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="logs-filter-group">
                <label class="logs-filter-label">Activity Type</label>
                <select name="type" class="logs-filter-input">
                    <option value="">All Types</option>
                    <option value="ORDER" <?php echo ($_GET['type'] ?? '') == 'ORDER' ? 'selected' : ''; ?>>Order</option>
                    <option value="SALE" <?php echo ($_GET['type'] ?? '') == 'SALE' ? 'selected' : ''; ?>>Sale</option>
                    <option value="INVENTORY" <?php echo ($_GET['type'] ?? '') == 'INVENTORY' ? 'selected' : ''; ?>>Inventory</option>
                    <option value="DELIVERY" <?php echo ($_GET['type'] ?? '') == 'DELIVERY' ? 'selected' : ''; ?>>Delivery</option>
                    <option value="USER_MGMT" <?php echo ($_GET['type'] ?? '') == 'USER_MGMT' ? 'selected' : ''; ?>>User Management</option>
                    <option value="DAMAGE" <?php echo (($_GET['type'] ?? '') == 'DAMAGE') ? 'selected' : ''; ?>>Damage</option>
                    <option value="LOGIN" <?php echo (($_GET['type'] ?? '') == 'LOGIN') ? 'selected' : ''; ?>>Login</option>
                    <option value="LOGOUT" <?php echo (($_GET['type'] ?? '') == 'LOGOUT') ? 'selected' : ''; ?>>Logout</option>
                </select>
            </div>
            <div class="logs-filter-group">
                <label class="logs-filter-label">Date From</label>
                <input type="date" name="start" class="logs-filter-input" value="<?php echo htmlspecialchars($_GET['start'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="logs-filter-group">
                <label class="logs-filter-label">Date To</label>
                <input type="date" name="end" class="logs-filter-input" value="<?php echo htmlspecialchars($_GET['end'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <button type="submit" class="logs-btn-apply"><i class="fas fa-filter"></i> Apply Filters</button>
            <a href="activity_logs.php" class="logs-btn-reset"><i class="fas fa-rotate-left"></i> Reset</a>
        </form>

        <div class="log-table-container">
            <table class="log-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Activity</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="3" style="text-align: center; padding: 3rem; color: #94a3b8;">
                                <i class="fas fa-clipboard-list" style="font-size: 2rem; display: block; margin-bottom: 1rem; opacity: 0.5;"></i>
                                No activity logs found.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($logs as $log): 
                            // Determine badge class based on activity type
                            $type = strtoupper($log['Activity_Type'] ?? '');
                            $badge_class = 'act-default';
                            if (strpos($type, 'ORDER') !== false) $badge_class = 'act-order';
                            elseif (strpos($type, 'SALE') !== false) $badge_class = 'act-sale';
                            elseif (strpos($type, 'INVENTORY') !== false || strpos($type, 'STOCK') !== false) $badge_class = 'act-inventory';
                            elseif (strpos($type, 'DELIVERY') !== false) $badge_class = 'act-delivery';
                            elseif (strpos($type, 'USER') !== false || strpos($type, 'LOGIN') !== false || strpos($type, 'LOGOUT') !== false) {
                                if (strpos($type, 'LOGIN') !== false && strpos($type, 'LOGOUT') === false) $badge_class = 'act-login';
                                elseif (strpos($type, 'LOGOUT') !== false) $badge_class = 'act-logout';
                                else $badge_class = 'act-user';
                            }
                            elseif (strpos($type, 'DAMAGE') !== false) $badge_class = 'act-damage';
                        ?>
                        <tr>
                            <td style="width: 180px;">
                                <div class="user-badge">
                                    <?php
                                    $profilePic = trim((string)($log['profile_picture'] ?? ''));
                                    // Disk path: this file lives under pages/owner_manager_view/ — capstone root is two levels up.
                                    $baseDir = dirname(__DIR__, 2);
                                    $fullPath = $profilePic !== '' ? $baseDir . '/' . str_replace('\\', '/', $profilePic) : '';
                                    $isRemotePic = $profilePic !== '' && preg_match('#^https?://#i', $profilePic);
                                    $hasProfilePic = $isRemotePic || ($fullPath !== '' && is_file($fullPath));
                                    $profilePicSrc = $hasProfilePic
                                        ? ($isRemotePic ? $profilePic : $activityLogsUploadPrefix . str_replace('\\', '/', $profilePic) . '?v=' . time())
                                        : '';
                                    $initial = strtoupper(substr((string)($log['user_name'] ?? 'S'), 0, 1));
                                    if ($hasProfilePic):
                                    ?>
                                        <img src="<?php echo htmlspecialchars($profilePicSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($log['user_name'] ?? 'User'); ?>" class="user-avatar-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                        <div class="user-avatar-initial" style="display:none;"><?php echo $initial; ?></div>
                                    <?php else: ?>
                                        <div class="user-avatar-initial"><?php echo $initial; ?></div>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($log['user_name'] ?? 'System'); ?>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($log['Activity_Type'])): ?>
                                <div class="activity-type <?php echo $badge_class; ?>"><?php echo htmlspecialchars($log['Activity_Type']); ?></div>
                                <?php endif; ?>
                                <div class="action-details"><?php echo htmlspecialchars($log['Action_Details'] ?? $log['Activity'] ?? ''); ?></div>
                            </td>
                            <td style="width: 200px;">
                                <div class="time-text">
                                    <i class="far fa-clock"></i>
                                    <?php echo date('M j, Y | g:i A', strtotime($log['Log_Time'] ?? $log['Time'] ?? 'now')); ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <!-- Pagination Controls (Performance Fix) -->
                <?php if ($total_pages > 1): ?>
                <div class="pagination" style="display: flex; justify-content: center; align-items: center; gap: 0.5rem; padding: 1.5rem; border-top: 1px solid #e2e8f0;">
                    <span style="color: #64748b; font-size: 0.875rem;">Showing <?php echo (($page - 1) * $per_page) + 1; ?> - <?php echo min($page * $per_page, $total_records); ?> of <?php echo $total_records; ?> records</span>
                    
                    <div style="display: flex; gap: 0.25rem;">
                        <!-- Previous Page -->
                        <?php 
                        $filter_params = http_build_query(array_filter(['user' => $user_filter, 'type' => $type_filter, 'start' => $date_start, 'end' => $date_end, 'per_page' => $per_page]));
                        $filter_query = $filter_params ? '&' . $filter_params : '';
                        ?>
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?><?php echo $filter_query; ?>" 
                               class="pagination-btn" 
                               style="padding: 0.5rem 0.75rem; border: 1px solid #e2e8f0; border-radius: 6px; color: #475569; text-decoration: none; font-size: 0.875rem; transition: all 0.2s;">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php else: ?>
                            <span class="pagination-btn disabled" 
                                  style="padding: 0.5rem 0.75rem; border: 1px solid #e2e8f0; border-radius: 6px; color: #94a3b8; font-size: 0.875rem; cursor: not-allowed;">
                                <i class="fas fa-chevron-left"></i>
                            </span>
                        <?php endif; ?>
                        
                        <!-- Page Numbers -->
                        <?php 
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        if ($start_page > 1): ?>
                            <a href="?page=1<?php echo $filter_query; ?>" 
                               class="pagination-btn" 
                               style="padding: 0.5rem 0.75rem; border: 1px solid #e2e8f0; border-radius: 6px; color: #475569; text-decoration: none; font-size: 0.875rem;">1</a>
                            <?php if ($start_page > 2): ?>
                                <span style="padding: 0.5rem 0.25rem; color: #94a3b8;">...</span>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <?php if ($i == $page): ?>
                                <span class="pagination-btn active" 
                                      style="padding: 0.5rem 0.75rem; border: 1px solid #6366f1; border-radius: 6px; background: #6366f1; color: white; font-size: 0.875rem; font-weight: 500;"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?page=<?php echo $i; ?><?php echo $filter_query; ?>" 
                                   class="pagination-btn" 
                                   style="padding: 0.5rem 0.75rem; border: 1px solid #e2e8f0; border-radius: 6px; color: #475569; text-decoration: none; font-size: 0.875rem;"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <span style="padding: 0.5rem 0.25rem; color: #94a3b8;">...</span>
                            <?php endif; ?>
                            <a href="?page=<?php echo $total_pages; ?><?php echo $filter_query; ?>" 
                               class="pagination-btn" 
                               style="padding: 0.5rem 0.75rem; border: 1px solid #e2e8f0; border-radius: 6px; color: #475569; text-decoration: none; font-size: 0.875rem;"><?php echo $total_pages; ?></a>
                        <?php endif; ?>
                        
                        <!-- Next Page -->
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?><?php echo $filter_query; ?>" 
                               class="pagination-btn" 
                               style="padding: 0.5rem 0.75rem; border: 1px solid #e2e8f0; border-radius: 6px; color: #475569; text-decoration: none; font-size: 0.875rem; transition: all 0.2s;">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php else: ?>
                            <span class="pagination-btn disabled" 
                                  style="padding: 0.5rem 0.75rem; border: 1px solid #e2e8f0; border-radius: 6px; color: #94a3b8; font-size: 0.875rem; cursor: not-allowed;">
                                <i class="fas fa-chevron-right"></i>
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Per Page Selector -->
                    <form method="GET" style="display: flex; align-items: center; gap: 0.5rem;">
                        <input type="hidden" name="page" value="1">
                        <?php if ($user_filter): ?><input type="hidden" name="user" value="<?php echo htmlspecialchars($user_filter); ?>"><?php endif; ?>
                        <?php if ($type_filter): ?><input type="hidden" name="type" value="<?php echo htmlspecialchars($type_filter); ?>"><?php endif; ?>
                        <?php if ($date_start): ?><input type="hidden" name="start" value="<?php echo htmlspecialchars($date_start); ?>"><?php endif; ?>
                        <?php if ($date_end): ?><input type="hidden" name="end" value="<?php echo htmlspecialchars($date_end); ?>"><?php endif; ?>
                        <label style="color: #64748b; font-size: 0.875rem;">Show:</label>
                        <select name="per_page" onchange="this.form.submit()" 
                                style="padding: 0.375rem 0.5rem; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.875rem; color: #475569;">
                            <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>>10</option>
                            <option value="20" <?php echo $per_page == 20 ? 'selected' : ''; ?>>20</option>
                            <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100</option>
                        </select>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<script src="../assets/js/script.js"></script>
</body>
</html>
