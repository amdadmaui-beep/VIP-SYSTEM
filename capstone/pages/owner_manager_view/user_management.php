<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/module_access.php';

// Required roles: Owner (1), Manager (2)
requireRole([1, 2]);

$can_manage_users = in_array((int)($_SESSION['user_role'] ?? 0), [1, 2], true);

$success = '';
$error = '';
$status_filter = strtolower(trim((string)($_GET['status_filter'] ?? 'active')));
$allowed_status_filters = ['active', 'deactivated', 'all'];
if (!in_array($status_filter, $allowed_status_filters, true)) {
    $status_filter = 'active';
}

// Include backend
require_once __DIR__ . '/../../api/user_management_backend.php';

// Fetch all users with role names and profile data
$user_where = '';
if ($status_filter === 'active') {
    $user_where = "WHERE COALESCE(u.is_active, 1) = 1 AND LOWER(COALESCE(u.status, 'active')) != 'inactive'";
} elseif ($status_filter === 'deactivated') {
    $user_where = "WHERE COALESCE(u.is_active, 1) = 0 OR LOWER(COALESCE(u.status, 'active')) = 'inactive'";
}

$users_query = "SELECT u.User_ID, u.user_name, u.full_name, u.created_at, u.Role_ID, u.is_active, u.status,
                       COALESCE(r.role_name, '') AS role_name,
                       up.first_name, up.last_name, up.email, up.contact_no, up.profile_picture
                FROM user u
                LEFT JOIN roles r ON u.Role_ID = r.Role_ID
                LEFT JOIN User_Profile up ON u.User_ID = up.User_ID
                {$user_where}
                ORDER BY COALESCE(u.is_active, 1) DESC, u.created_at DESC";
$users_result = $conn->query($users_query);

// Fetch roles for dropdown
$roles_query = "SELECT Role_ID, role_name, role_description AS description FROM roles ORDER BY Role_ID";
$roles_result = $conn->query($roles_query);
$roles = [];
if ($roles_result) {
    while ($r = $roles_result->fetch(PDO::FETCH_ASSOC)) {
        $roles[] = $r;
    }
}

ensureUserModuleAccessTable($conn);
$module_defs = getManagedModuleDefinitions();
$user_module_rows = [];
try {
    $um_stmt = $conn->query("SELECT User_ID, module_key, is_allowed FROM user_module_access");
    if ($um_stmt) {
        $user_module_rows = $um_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $user_module_rows = [];
}

$user_module_map = [];
foreach ($user_module_rows as $row) {
    $uid = (int)($row['User_ID'] ?? 0);
    $mk = (string)($row['module_key'] ?? '');
    if ($uid <= 0 || $mk === '') continue;
    if (!isset($user_module_map[$uid])) $user_module_map[$uid] = [];
    $user_module_map[$uid][$mk] = ((int)$row['is_allowed'] === 1);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <link rel="stylesheet" href="../assets/css/style.css">
    <script>
        window.csrfToken = '<?php echo getCsrfToken(); ?>';
    </script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .um-header { margin-bottom: 1rem; }
        .um-header h1 { font-size: 1.75rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 0.75rem; }
        .um-header p { color: #64748b; margin-top: 0.25rem; }

        .um-card { background: white; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.06); border: 1px solid #f1f5f9; margin-bottom: 1.5rem; overflow: hidden; }
        .um-card-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; background: #fafbff; }
        .um-card-header h3 { font-size: 1rem; font-weight: 600; color: #1e293b; display: flex; align-items: center; gap: 0.5rem; margin: 0; }
        .um-card-body { padding: 1.5rem; }
        .um-toolbar { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
        .um-filter-form { display: flex; align-items: center; gap: 0.65rem; flex-wrap: wrap; }
        .um-filter-label { font-size: 0.8rem; font-weight: 600; color: #64748b; }
        .um-filter-select { min-width: 190px; padding: 0.65rem 2.5rem 0.65rem 0.9rem; border: 1px solid #dbe3f0; border-radius: 10px; background: #fff; color: #0f172a; font: inherit; }

        .um-table { width: 100%; border-collapse: collapse; }
        .um-table th { background: #f8fafc; padding: 0.875rem 1rem; text-align: left; font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #e2e8f0; }
        .um-table td { padding: 1rem; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; color: #334155; vertical-align: middle; }
        .um-table tr:last-child td { border-bottom: none; }
        .um-table tr:hover td { background: #fafbff; }

        .role-badge { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.3rem 0.75rem; border-radius: 20px; font-size: 0.78rem; font-weight: 600; }
        .role-badge-owner { background: #ede9fe; color: #5b21b6; }
        .role-badge-cashier { background: #dcfce7; color: #166534; }
        .role-badge-delivery_rider { background: #dbeafe; color: #1e40af; }
        .role-manager { background: #fef3c7; color: #92400e; }
        .role-default { background: #f1f5f9; color: #475569; }

        .status-badge { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.3rem 0.75rem; border-radius: 20px; font-size: 0.78rem; font-weight: 600; }
        .status-active { background: #dcfce7; color: #166534; }
        .status-inactive { background: #fee2e2; color: #991b1b; }

        .btn-add { background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: white; border: none; padding: 0.625rem 1.25rem; border-radius: 10px; font-size: 0.875rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem; transition: all 0.2s; box-shadow: 0 4px 10px rgba(99,102,241,0.25); }
        .btn-add:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(99,102,241,0.35); }

        .btn-edit { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; padding: 0.4rem 0.875rem; border-radius: 8px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 0.35rem; }
        .btn-edit:hover { background: #e2e8f0; color: #1e293b; }
        .btn-modules { background: rgba(133,91,251,0.16); color: #7132f5; border: 1px solid rgba(104,107,130,0.24); padding: 0.4rem 0.875rem; border-radius: 12px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 0.35rem; }
        .btn-modules:hover { background: rgba(133,91,251,0.22); }
        .btn-pin { background: #ffffff; color: #5741d8; border: 1px solid #5741d8; border-radius: 12px; }
        .btn-pin:hover { background: rgba(133,91,251,0.12); }
        .btn-toggle-active { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; padding: 0.4rem 0.875rem; border-radius: 8px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 0.35rem; }
        .btn-toggle-active:hover { background: #fde68a; }
        .btn-toggle-inactive { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; padding: 0.4rem 0.875rem; border-radius: 8px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 0.35rem; }
        .btn-toggle-inactive:hover { background: #bbf7d0; }

        .user-avatar { width: 36px; height: 36px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9rem; color: white; margin-right: 0.5rem; flex-shrink: 0; }
        .avatar-owner { background: linear-gradient(135deg, #6366f1, #8b5cf6); }
        .avatar-cashier { background: linear-gradient(135deg, #10b981, #059669); }
        .avatar-rider { background: linear-gradient(135deg, #3b82f6, #2563eb); }
        .avatar-manager { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .avatar-default { background: linear-gradient(135deg, #94a3b8, #64748b); }

        .user-info { display: flex; align-items: center; }
        .user-details { display: flex; flex-direction: column; }
        .user-fullname { font-weight: 600; color: #1e293b; }
        .user-username { font-size: 0.8rem; color: #94a3b8; }

        /* Modal */
        .modal { display: none; position: fixed; inset: 0; padding: 1rem; background: rgba(15,23,42,0.5); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal.active { display: flex; }
        .modal-box { background: white; border-radius: 20px; padding: 2rem; width: 100%; max-width: 520px; max-height: calc(100vh - 2rem); overflow-y: auto; box-shadow: 0 25px 60px rgba(0,0,0,0.2); animation: slideDown 0.3s ease; }
        .user-form-modal { max-width: 760px; }
        .modal-title { font-size: 1.2rem; font-weight: 700; color: #0f172a; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; }
        .modal-title i { color: #6366f1; }
        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
        .form-group { display: flex; flex-direction: column; gap: 0.4rem; min-width: 0; }
        .form-group.full { grid-column: 1 / -1; }
        .form-label { font-size: 0.8rem; font-weight: 600; color: #475569; display: block; line-height: 1.35; }
        .field-help { display: block; margin-top: 0.2rem; font-size: 0.74rem; font-weight: 500; color: #94a3b8; line-height: 1.4; }
        .form-control { width: 100%; box-sizing: border-box; padding: 0.75rem 1rem; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 0.9rem; font-family: 'Poppins', sans-serif; color: #0f172a; transition: all 0.2s; }
        .form-control:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.1); }
        .modal-actions { display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.5rem; }
        .btn-cancel { background: rgba(148,151,169,0.08); color: #101114; border: 1px solid rgba(104,107,130,0.24); padding: 0.625rem 1.25rem; border-radius: 12px; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .btn-cancel:hover { background: rgba(148,151,169,0.14); }
        .btn-save { background: #7132f5; color: white; border: 1px solid #5741d8; padding: 0.625rem 1.5rem; border-radius: 12px; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: all 0.2s; box-shadow: rgba(0,0,0,0.03) 0px 4px 24px; }
        .btn-save:hover { transform: translateY(-1px); }

        .alert-msg { padding: 1rem 1.25rem; border-radius: 12px; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.75rem; font-weight: 500; font-size: 0.9rem; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

        .empty-state { text-align: center; padding: 3rem; color: #94a3b8; }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; display: block; }

        .actions-cell { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .user-soft-delete-note { margin: 0; font-size: 0.82rem; color: #64748b; }
        .user-row-inactive td { background: #fcfcfd; }
        .user-row-inactive .user-fullname,
        .user-row-inactive .user-username { color: #94a3b8; }

        /* Role Info Cards */
        .role-info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        /* New heading/subtitle styling */
        .page-title { font-size: 2rem; font-weight: 700; color: #0f172a; margin: 0 0 0.25rem 0; }
        .page-subtitle { font-size: 1rem; color: #64748b; margin: 0 0 1.5rem 0; }
        .um-page-banner { position: relative; overflow: hidden; border-radius: 24px; padding: 2rem; margin-bottom: 2rem; color: #fff; background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); box-shadow: 0 20px 40px rgba(99,102,241,0.24); }
        .um-page-banner::before { content: ''; position: absolute; top: -80px; right: -70px; width: 260px; height: 260px; border-radius: 999px; background: rgba(255,255,255,0.1); pointer-events: none; }
        .um-page-banner .page-title { position: relative; z-index: 1; display: flex; align-items: center; gap: 0.75rem; color: #fff; margin: 0; font-size: 1.85rem; }
        .um-page-banner .page-subtitle { position: relative; z-index: 1; color: rgba(255,255,255,0.9); margin: 0.5rem 0 0; }
        /* Redesigned role info cards */
        .role-info-card { background: #ffffff; border-radius: 12px; padding: 1.5rem; box-shadow: 0 8px 16px rgba(0,0,0,0.08); border: 1px solid #e2e8f0; transition: transform 0.3s, box-shadow 0.3s; }
        .role-info-card:hover { transform: translateY(-4px); box-shadow: 0 12px 24px rgba(0,0,0,0.12); }
        .role-info-card.owner { background: linear-gradient(135deg, #e0e7ff, #f0f5ff); }
        .role-info-card.manager { background: linear-gradient(135deg, #fff7e6, #ffedd5); }
        .role-info-card.cashier { background: linear-gradient(135deg, #ecfdf5, #d1fae5); }
        .role-info-card.rider { background: linear-gradient(135deg, #eff6ff, #dbeafe); }
        .role-info-card.inventory { background: linear-gradient(135deg, #f5f3ff, #e0e7ff); }
        .role-info-card h4 { margin: 0 0 0.5rem 0; font-size: 1rem; font-weight: 700; color: #1e293b; }
        .role-info-card p { margin: 0; font-size: 0.85rem; color: #64748b; line-height: 1.4; }
        .role-info-card { background: white; border-radius: 16px; padding: 1.25rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border: 1px solid #f1f5f9; transition: transform 0.2s; }
        .role-info-card:hover { transform: translateY(-3px); }
        .role-info-card i { font-size: 1.5rem; margin-bottom: 0.75rem; display: block; }
        .role-info-card h4 { margin: 0 0 0.5rem 0; font-size: 0.95rem; font-weight: 700; color: #1e293b; }
        .role-info-card p { margin: 0; font-size: 0.8rem; color: #64748b; line-height: 1.4; }
        
        .role-info-card.owner i { color: #6366f1; }
        .role-info-card.manager i { color: #f59e0b; }
        .role-info-card.cashier i { color: #10b981; }
        .role-info-card.rider i { color: #3b82f6; }
        .role-info-card.inventory i { color: #8b5cf6; }

        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }

        /* Rider-inspired PIN modal styling */
        .pin-modal-box { max-width: 520px; border-radius: 16px; overflow: hidden; padding: 0; box-shadow: rgba(0,0,0,0.03) 0px 4px 24px; border: 1px solid #dedee5; }
        .pin-modal-header {
            background: linear-gradient(135deg, #7132f5 0%, #5741d8 100%);
            color: #fff;
            padding: 1.25rem 1.5rem;
        }
        .pin-modal-header .title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.05rem;
            font-weight: 700;
            margin: 0;
        }
        .pin-modal-header .subtitle {
            margin: 0.35rem 0 0 0;
            font-size: 0.82rem;
            opacity: 0.9;
        }
        .pin-modal-body { padding: 1.4rem; }
        .pin-user-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.45rem 0.75rem;
            border-radius: 999px;
            background: rgba(133,91,251,0.16);
            color: #5b1ecf;
            font-weight: 600;
            font-size: 0.82rem;
            margin-bottom: 1rem;
        }
        .pin-note {
            background: #ffffff;
            border: 1px solid #dedee5;
            border-radius: 12px;
            padding: 0.8rem 0.9rem;
            font-size: 0.8rem;
            color: #686b82;
            margin-bottom: 1rem;
        }

        @media (max-width: 768px) {
            .modal-box { padding: 1.35rem; border-radius: 18px; }
            .form-grid { grid-template-columns: 1fr; }
            .modal-actions { flex-direction: column-reverse; }
            .btn-cancel, .btn-save { width: 100%; justify-content: center; }
            .um-card-header { align-items: flex-start; }
            .um-toolbar, .um-filter-form { width: 100%; }
            .um-filter-select { width: 100%; }
        }
    </style>
</head>
<body>
<div class="dashboard-wrapper">
    <!-- Sidebar -->
    <?php
    require_once __DIR__ . '/../../includes/sidebar.php';
    renderSidebar($conn, ['base' => '../', 'active' => 'user_management']);
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
                <a href="../index.php" class="menu-item"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
                <a href="sales.php" class="menu-item"><i class="fas fa-receipt"></i><span>Sales</span></a>
                <a href="inventory.php" class="menu-item"><i class="fas fa-cubes"></i><span>Inventory</span></a>
                <a href="damage_goods.php" class="menu-item"><i class="fas fa-heart-broken"></i><span>Damage Goods</span></a>
                <a href="stock_ledger.php" class="menu-item"><i class="fas fa-file-invoice"></i><span>Stock Ledger</span></a>
                <a href="users.php" class="menu-item"><i class="fas fa-users"></i><span>Customers</span></a>
                <a href="orders.php" class="menu-item"><i class="fas fa-shopping-cart"></i><span>Orders</span></a>
                <a href="delivery.php" class="menu-item"><i class="fas fa-truck"></i><span>Delivery</span></a>
            </div>
            <div class="menu-section">
                <div class="menu-label">Accounting</div>
                <a href="accounts_receivable.php" class="menu-item"><i class="fas fa-file-invoice-dollar"></i><span>Accounts Receivable</span></a>
            </div>
            <div class="menu-section">
                <div class="menu-label">System</div>
                <a href="activity_logs.php" class="menu-item">
                    <i class="fas fa-history"></i>
                    <span>Activity Logs</span>
                </a>
                <?php if (in_array($_SESSION['user_role'] ?? 1, [1, 2])): ?>
                <a href="user_management.php" class="menu-item active"><i class="fas fa-user-shield"></i><span>User Management</span></a>
                <?php endif; ?>
                <a href="../logout.php" class="menu-item"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <div class="container">
            <section class="um-page-banner">
                <div class="header-content">
                    <h1 class="page-title"><i class="fas fa-users-cog header-icon"></i> User Management</h1>
                    <p class="page-subtitle">Manage system users and their access roles.</p>
                </div>
            </section>

            <div class="role-info-grid">
                <div class="role-info-card owner">
                    <i class="fas fa-crown"></i>
                    <h4>Owner</h4>
                    <p>Full administrative access to all modules, financial data, and system settings.</p>
                </div>
                <div class="role-info-card manager">
                    <i class="fas fa-user-tie"></i>
                    <h4>Manager</h4>
                    <p>Oversees operations, generates reports, and manages daily staff activities.</p>
                </div>
                <div class="role-info-card cashier">
                    <i class="fas fa-cash-register"></i>
                    <h4>Cashier</h4>
                    <p>Handles sales transactions, customer management, and order entries.</p>
                </div>
                <div class="role-info-card rider">
                    <i class="fas fa-motorcycle"></i>
                    <h4>Delivery Rider</h4>
                    <p>Manages assigned deliveries and updates order statuses in real-time.</p>
                </div>
                <div class="role-info-card inventory">
                    <i class="fas fa-boxes"></i>
                    <h4>Inventory Staff</h4>
                    <p>Records production, manages stock levels, and handles inventory adjustments.</p>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="alert-msg alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert-msg alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="um-card">
                <div class="um-card-header">
                    <div>
                        <h3><i class="fas fa-list"></i> System Users</h3>
                        <p class="user-soft-delete-note">Deactivated users stay in the database and can be restored anytime.</p>
                    </div>
                    <div class="um-toolbar">
                        <form method="GET" class="um-filter-form">
                            <label class="um-filter-label" for="status_filter">Show</label>
                            <select name="status_filter" id="status_filter" class="um-filter-select" onchange="this.form.submit()">
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active Users</option>
                                <option value="deactivated" <?php echo $status_filter === 'deactivated' ? 'selected' : ''; ?>>Deactivated Users</option>
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Users</option>
                            </select>
                        </form>
                        <?php if ($can_manage_users): ?>
                        <button class="btn-add" id="btnAddUser">
                            <i class="fas fa-user-plus"></i> Add New User
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="um-card-body" style="padding: 0;">
                    <?php if ($users_result && $users_result->rowCount() > 0): ?>
                    <table class="um-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>User</th>
                                <th>Contact Info</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($user = $users_result->fetch(PDO::FETCH_ASSOC)): 
                                // Use role_name from roles table; fallback only if role row is missing
                                $roleId = (int)($user['Role_ID'] ?? 0);
                                $roleName = trim((string)($user['role_name'] ?? ''));
                                if ($roleName === '') {
                                    $roleName = 'unassigned';
                                }
                                $roleKey = strtolower(str_replace(' ', '_', $roleName));
                                
                                $roleClass = 'role-badge-' . $roleKey;
                                $avatarClass = 'avatar-' . ($roleKey === 'delivery_rider' ? 'rider' : $roleKey);
                                $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                                $displayName = $fullName ?: ($user['full_name'] ?? $user['user_name']);
                                $fi = !empty($user['first_name']) ? strtoupper(trim(substr($user['first_name'], 0, 1))) : '';
                                $li = !empty($user['last_name']) ? strtoupper(trim(substr($user['last_name'], 0, 1))) : '';
                                if ($fi !== '' || $li !== '') {
                                    $initials = $fi . $li;
                                } elseif (!empty($user['full_name'])) {
                                    $parts = preg_split('/\s+/', trim($user['full_name']), -1, PREG_SPLIT_NO_EMPTY);
                                    $initials = strtoupper(substr($parts[0], 0, 1));
                                    if (count($parts) > 1) {
                                        $initials .= strtoupper(substr(end($parts), 0, 1));
                                    }
                                } else {
                                    $initials = strtoupper(substr($user['user_name'] ?? 'U', 0, 1));
                                }
                                $statusValue = strtolower(trim((string)($user['status'] ?? 'active')));
                                $isActive = ((int)($user['is_active'] ?? 1) === 1) && $statusValue !== 'inactive';
                                
                                $profilePic = $user['profile_picture'] ?? '';
                                $hasProfilePic = false;
                                $profilePicSrc = '';
                                if ($profilePic !== '') {
                                    $pfpPath = dirname(__DIR__, 2) . '/' . $profilePic;
                                    if (file_exists($pfpPath)) {
                                        $hasProfilePic = true;
                                        $profilePicSrc = '../' . $profilePic . '?v=' . time();
                                    }
                                }
                            ?>
                            <tr class="<?php echo $isActive ? '' : 'user-row-inactive'; ?>">
                                <td style="color:#94a3b8; font-size:0.8rem;">#<?php echo $user['User_ID']; ?></td>
                                <td>
                                    <div class="user-info">
                                        <?php if ($hasProfilePic): ?>
                                            <img src="<?php echo htmlspecialchars($profilePicSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($displayName); ?>" class="user-avatar <?php echo $avatarClass; ?>" style="object-fit: cover;" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                            <div class="user-avatar <?php echo $avatarClass; ?>" style="display:none;"><?php echo $initials; ?></div>
                                        <?php else: ?>
                                            <div class="user-avatar <?php echo $avatarClass; ?>"><?php echo $initials; ?></div>
                                        <?php endif; ?>
                                        <div class="user-details">
                                            <span class="user-fullname"><?php echo htmlspecialchars($displayName); ?></span>
                                            <span class="user-username">@<?php echo htmlspecialchars($user['user_name']); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div style="display:flex; flex-direction:column; gap:2px; font-size:0.85rem;">
                                        <span title="Email"><i class="fas fa-envelope" style="width:14px; color:#64748b;"></i> <?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?></span>
                                        <span title="Contact No"><i class="fas fa-phone" style="width:14px; color:#64748b;"></i> <?php echo htmlspecialchars($user['contact_no'] ?? 'N/A'); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    $roleColors = [
                                        'owner' => ['bg' => '#ede9fe', 'color' => '#5b21b6', 'border' => '#c4b5fd', 'icon' => 'fa-crown'],
                                        'cashier' => ['bg' => '#dcfce7', 'color' => '#166534', 'border' => '#86efac', 'icon' => 'fa-cash-register'],
                                        'delivery_rider' => ['bg' => '#dbeafe', 'color' => '#1e40af', 'border' => '#93c5fd', 'icon' => 'fa-motorcycle'],
                                        'manager' => ['bg' => '#fef3c7', 'color' => '#92400e', 'border' => '#fcd34d', 'icon' => 'fa-user-tie'],
                                        'inventory_staff' => ['bg' => '#f3e8ff', 'color' => '#7c3aed', 'border' => '#d8b4fe', 'icon' => 'fa-boxes'],
                                        'unassigned' => ['bg' => '#f1f5f9', 'color' => '#64748b', 'border' => '#cbd5e1', 'icon' => 'fa-user']
                                    ];
                                    $roleColor = $roleColors[$roleKey] ?? $roleColors['unassigned'];
                                    ?>
                                    <span style="display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.4rem 0.875rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.025em; background: <?php echo $roleColor['bg']; ?>; color: <?php echo $roleColor['color']; ?>; border: 1px solid <?php echo $roleColor['border']; ?>;">
                                        <i class="fas <?php echo $roleColor['icon']; ?>"></i> <?php echo ucwords(str_replace('_', ' ', $roleName)); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($isActive): ?>
                                    <span style="display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.4rem 0.875rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.025em; background: #dcfce7; color: #166534; border: 1px solid #86efac;">
                                        <i class="fas fa-check-circle"></i> Active
                                    </span>
                                    <?php else: ?>
                                    <span style="display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.4rem 0.875rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.025em; background: #fee2e2; color: '#991b1b'; border: 1px solid #fca5a5;">
                                        <i class="fas fa-times-circle"></i> Deactivated
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td style="color:#94a3b8; font-size:0.85rem;">
                                    <?php 
                                    if (!empty($user['created_at'])) {
                                        echo date('M d, Y', strtotime($user['created_at']));
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 0.375rem; flex-wrap: wrap;">
                                        <?php if ($can_manage_users): ?>
                                        <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($user)); ?>)" style="display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.4rem 0.75rem; border-radius: 8px; font-size: 0.75rem; font-weight: 600; color: #475569; background: #f8fafc; border: 1px solid #e2e8f0; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#e2e8f0'; this.style.color='#1e293b';" onmouseout="this.style.background='#f8fafc'; this.style.color='#475569';">
                                            <i class="fas fa-edit" style="color: #6366f1;"></i> Edit
                                        </button>
                                        <?php if (in_array($roleId, [1, 2])): // Owner or Manager ?>
                                        <button type="button" 
                                            data-user-id="<?php echo (int)$user['User_ID']; ?>"
                                            data-user-name="<?php echo htmlspecialchars($displayName); ?>"
                                            onclick="openPinModal(this)" style="display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.4rem 0.75rem; border-radius: 8px; font-size: 0.75rem; font-weight: 600; color: #7c3aed; background: #f5f3ff; border: 1px solid #ddd6fe; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#ede9fe';" onmouseout="this.style.background='#f5f3ff';">
                                            <i class="fas fa-key"></i> PIN
                                        </button>
                                        <?php endif; ?>
                                        <?php
                                        $uid = (int)$user['User_ID'];
                                        $role_module_keys = getRoleDefaultModuleKeys((string)($roleName ?? ''));
                                        $module_state = [];
                                        foreach ($role_module_keys as $mkey) {
                                            if (!isset($module_defs[$mkey])) continue;
                                            if (isset($user_module_map[$uid][$mkey])) {
                                                $module_state[$mkey] = $user_module_map[$uid][$mkey] ? 1 : 0;
                                            } else {
                                                $module_state[$mkey] = 1;
                                            }
                                        }
                                        ?>
                                        <button type="button"
                                            data-user-id="<?php echo (int)$user['User_ID']; ?>"
                                            data-user-name="<?php echo htmlspecialchars($displayName); ?>"
                                            data-role-modules="<?php echo htmlspecialchars(json_encode(array_values($role_module_keys)), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-module-map="<?php echo htmlspecialchars(json_encode($module_state), ENT_QUOTES, 'UTF-8'); ?>"
                                            onclick="openModuleAccessModal(this)" style="display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.4rem 0.75rem; border-radius: 8px; font-size: 0.75rem; font-weight: 600; color: #0369a1; background: #e0f2fe; border: 1px solid #bae6fd; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#bae6fd';" onmouseout="this.style.background='#e0f2fe';">
                                            <i class="fas fa-toggle-on"></i> Module Access
                                        </button>
                                        <?php if ($user['User_ID'] != $_SESSION['user_id']): ?>
                                        <form method="POST" style="display:inline; margin:0; padding:0;"
                                            data-user-name="<?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-next-state="<?php echo $isActive ? 'deactivate' : 'restore'; ?>"
                                            onsubmit="return confirmUserStatusChange(event, this);">
                                            <?php echo csrfTokenField(); ?>
                                            <input type="hidden" name="action" value="toggle_user">
                                            <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="user_id" value="<?php echo $user['User_ID']; ?>">
                                            <input type="hidden" name="is_active" value="<?php echo $isActive ? 0 : 1; ?>">
                                            <?php if ($isActive): ?>
                                            <button type="submit" style="display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.4rem 0.75rem; border-radius: 8px; font-size: 0.75rem; font-weight: 600; color: #b45309; background: #fef3c7; border: 1px solid #fcd34d; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#fde68a';" onmouseout="this.style.background='#fef3c7';">
                                                <i class="fas fa-user-slash"></i> Deactivate
                                            </button>
                                            <?php else: ?>
                                            <button type="submit" style="display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.4rem 0.75rem; border-radius: 8px; font-size: 0.75rem; font-weight: 600; color: #15803d; background: #dcfce7; border: 1px solid #86efac; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#bbf7d0';" onmouseout="this.style.background='#dcfce7';">
                                                <i class="fas fa-user-check"></i> Restore
                                            </button>
                                            <?php endif; ?>
                                        </form>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <p>No users found.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Add User Modal -->
<div class="modal" id="addUserModal">
    <div class="modal-box user-form-modal">
        <div class="modal-title"><i class="fas fa-user-plus"></i> Add New User</div>
        <form method="POST">
            <?php echo csrfTokenField(); ?>
            <input type="hidden" name="action" value="add_user">
            <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">First Name *</label>
                    <input type="text" name="first_name" class="form-control" placeholder="e.g. Juan" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Last Name *</label>
                    <input type="text" name="last_name" class="form-control" placeholder="e.g. Dela Cruz" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Username *</label>
                    <input type="text" name="user_name" class="form-control" placeholder="e.g. juan123" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Password *</label>
                    <input type="password" name="password" class="form-control" placeholder="Enter password" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" placeholder="e.g. juan@example.com">
                </div>
                <div class="form-group">
                    <label class="form-label">Contact No</label>
                    <input type="text" name="contact_no" class="form-control" placeholder="e.g. 09123456789">
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Role *</label>
                    <select name="role_id" class="form-control" required>
                        <?php foreach ($roles as $role): ?>
                        <option value="<?php echo $role['Role_ID']; ?>"><?php echo ucwords(str_replace('_', ' ', $role['role_name'])); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeModal('addUserModal')">Cancel</button>
                <button type="submit" class="btn-save"><i class="fas fa-save"></i> Add User</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal" id="editUserModal">
    <div class="modal-box user-form-modal">
        <div class="modal-title"><i class="fas fa-user-edit"></i> Edit User</div>
        <form method="POST">
            <?php echo csrfTokenField(); ?>
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="user_id" id="edit_user_id">
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">First Name *</label>
                    <input type="text" name="first_name" id="edit_first_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Last Name *</label>
                    <input type="text" name="last_name" id="edit_last_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Username *</label>
                    <input type="text" name="user_name" id="edit_user_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">
                        New Password
                        <span class="field-help">Leave blank to keep the current password.</span>
                    </label>
                    <input type="password" name="password" class="form-control" placeholder="Leave blank to keep current">
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" id="edit_email" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Contact No</label>
                    <input type="text" name="contact_no" id="edit_contact_no" class="form-control">
                </div>
                <div class="form-group" style="grid-column: span 2;">
                    <label class="form-label">Role *</label>
                    <select name="role_id" id="edit_role_id" class="form-control" required>
                        <?php foreach ($roles as $role): ?>
                        <option value="<?php echo $role['Role_ID']; ?>"><?php echo ucwords(str_replace('_', ' ', $role['role_name'])); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeModal('editUserModal')">Cancel</button>
                <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Module Access Modal -->
<div class="modal" id="moduleAccessModal">
    <div class="modal-box">
        <div class="modal-title"><i class="fas fa-lock"></i> Module Access</div>
        <form method="POST">
            <?php echo csrfTokenField(); ?>
            <input type="hidden" name="action" value="update_module_access">
            <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="target_user_id" id="module_target_user_id">
            <div class="form-group full">
                <label class="form-label">User</label>
                <input type="text" id="module_target_user_name" class="form-control" readonly>
            </div>
            <div class="form-group full">
                <label class="form-label">Module Access (enable/disable anytime)</label>
                <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;">
                    <?php foreach ($module_defs as $mkey => $mlabel): ?>
                    <label class="js-module-row" data-module-key="<?php echo htmlspecialchars($mkey); ?>" style="display:flex;align-items:center;gap:8px;margin:4px 0;">
                        <input type="checkbox" class="js-module-checkbox" id="module_<?php echo htmlspecialchars($mkey); ?>" name="allowed_modules[]" value="<?php echo htmlspecialchars($mkey); ?>">
                        <span><?php echo htmlspecialchars($mlabel); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeModal('moduleAccessModal')">Cancel</button>
                <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Module Access</button>
            </div>
        </form>
    </div>
</div>

<!-- PIN Management Modal -->
<div class="modal" id="pinModal">
    <div class="modal-box pin-modal-box">
        <div class="pin-modal-header">
            <p class="title"><i class="fas fa-key"></i> Supervisor PIN Management</p>
            <p class="subtitle">Secure and update supervisor PIN credentials.</p>
        </div>
        <div class="pin-modal-body">
        <form method="POST" id="pinForm">
            <?php echo csrfTokenField(); ?>
            <input type="hidden" name="action" value="update_pin">
            <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($status_filter, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="user_id" id="pin_user_id">
            <div class="form-group full">
                <label class="form-label">User</label>
                <input type="text" id="pin_user_name" class="form-control" readonly style="background: #f8fafc;">
            </div>
            <div class="pin-user-chip"><i class="fas fa-shield-alt"></i> PIN Update Session</div>
            <div class="pin-note">
                Use digits only (4-10). Enter the current PIN for verification before saving a new PIN.
            </div>
            <div class="form-group full">
                <label class="form-label">Current PIN <small style="font-weight:400;color:#94a3b8;">(enter current PIN to verify)</small></label>
                <input type="password" name="current_pin" id="current_pin" class="form-control" required maxlength="10" placeholder="Enter current PIN">
            </div>
            <div class="form-group full">
                <label class="form-label">New PIN <small style="font-weight:400;color:#94a3b8;">(4-10 digits)</small></label>
                <input type="password" name="new_pin" id="new_pin" class="form-control" required minlength="4" maxlength="10" pattern="[0-9]{4,10}" placeholder="Enter new PIN">
            </div>
            <div class="form-group full">
                <label class="form-label">Confirm New PIN</label>
                <input type="password" name="confirm_pin" id="confirm_pin" class="form-control" required minlength="4" maxlength="10" pattern="[0-9]{4,10}" placeholder="Confirm new PIN">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeModal('pinModal')">Cancel</button>
                <button type="submit" class="btn-save"><i class="fas fa-save"></i> Update PIN</button>
            </div>
        </form>
        </div>
    </div>
</div>

<script src="../assets/js/script.js"></script>
<script>
function openModal(id) {
    document.querySelectorAll('.modal').forEach(function(modal) {
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
    });
    const target = document.getElementById(id);
    if (target) {
        target.classList.add('active');
        target.setAttribute('aria-hidden', 'false');
    }
}

function closeModal(id) {
    const target = document.getElementById(id);
    if (target) {
        target.classList.remove('active');
        target.setAttribute('aria-hidden', 'true');
    }
}

const btnAddUser = document.getElementById('btnAddUser');
if (btnAddUser) {
    btnAddUser.addEventListener('click', () => openModal('addUserModal'));
}

window.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        e.target.classList.remove('active');
        e.target.setAttribute('aria-hidden', 'true');
    }
});

window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal.active').forEach(function(modal) {
            modal.classList.remove('active');
            modal.setAttribute('aria-hidden', 'true');
        });
    }
});

function openEditModal(user) {
    document.getElementById('edit_user_id').value = user.User_ID;
    document.getElementById('edit_first_name').value = user.first_name || '';
    document.getElementById('edit_last_name').value = user.last_name || '';
    document.getElementById('edit_user_name').value = user.user_name;
    document.getElementById('edit_email').value = user.email || '';
    document.getElementById('edit_contact_no').value = user.contact_no || '';
    document.getElementById('edit_role_id').value = user.Role_ID;
    openModal('editUserModal');
}

function openModuleAccessModal(btn) {
    const userId = btn.getAttribute('data-user-id') || '';
    const userName = btn.getAttribute('data-user-name') || '';
    const mapRaw = btn.getAttribute('data-module-map') || '{}';
    const roleModsRaw = btn.getAttribute('data-role-modules') || '[]';
    let moduleMap = {};
    let roleModules = [];
    try {
        moduleMap = JSON.parse(mapRaw);
    } catch (e) {
        moduleMap = {};
    }
    try {
        roleModules = JSON.parse(roleModsRaw);
    } catch (e) {
        roleModules = [];
    }
    const roleSet = new Set(roleModules);

    document.getElementById('module_target_user_id').value = userId;
    document.getElementById('module_target_user_name').value = userName;
    document.querySelectorAll('.js-module-row').forEach((row) => {
        const key = row.getAttribute('data-module-key') || '';
        row.style.display = roleSet.has(key) ? 'flex' : 'none';
    });
    document.querySelectorAll('.js-module-checkbox').forEach((cb) => {
        const key = cb.value;
        if (!roleSet.has(key)) {
            cb.checked = false;
            return;
        }
        cb.checked = String(moduleMap[key] ?? 1) === '1';
    });
    openModal('moduleAccessModal');
}

function openPinModal(btn) {
    const pinForm = document.getElementById('pinForm');
    if (pinForm) {
        pinForm.reset();
    }
    const userId = btn.getAttribute('data-user-id') || '';
    const userName = btn.getAttribute('data-user-name') || '';
    
    document.getElementById('pin_user_id').value = userId;
    document.getElementById('pin_user_name').value = userName;
    
    openModal('pinModal');
}

function confirmUserStatusChange(event, form) {
    event.preventDefault();
    const nextState = (form.getAttribute('data-next-state') || '').toLowerCase();
    const userName = form.getAttribute('data-user-name') || 'this user';
    const isRestore = nextState === 'restore';

    Swal.fire({
        icon: 'warning',
        title: isRestore ? 'Restore User?' : 'Deactivate User?',
        html: isRestore
            ? `<div style="text-align:left;line-height:1.6;">Restore <strong>${userName}</strong>? The account will stay in the database and login access will be enabled again.</div>`
            : `<div style="text-align:left;line-height:1.6;">Deactivate <strong>${userName}</strong>? This is a soft delete only. The account will remain in the database and can be restored later.</div>`,
        showCancelButton: true,
        confirmButtonText: isRestore ? 'Yes, Restore' : 'Yes, Deactivate',
        cancelButtonText: 'Cancel',
        confirmButtonColor: isRestore ? '#16a34a' : '#2563eb',
        cancelButtonColor: '#94a3b8',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed) {
            form.submit();
        }
    });

    return false;
}

<?php if (isset($_GET['success'])): ?>
Swal.fire({
    icon: 'success',
    title: '<?php echo addslashes((($_GET["success_action"] ?? "") === "deactivated") ? "User Deactivated" : (((($_GET["success_action"] ?? "") === "restored")) ? "User Restored" : "Success!")); ?>',
    text: '<?php echo addslashes($_GET['success']); ?>',
    confirmButtonText: 'OK',
    confirmButtonColor: '#2563eb'
}).then(() => {
    const url = new URL(window.location.href);
    url.searchParams.delete('success');
    url.searchParams.delete('success_action');
    window.history.replaceState({}, document.title, url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : ''));
});
<?php endif; ?>
<?php if (isset($_GET['error'])): ?>
Swal.fire({
    icon: 'error',
    title: 'Error',
    text: '<?php echo addslashes($_GET['error']); ?>',
    confirmButtonColor: '#dc2626'
}).then(() => {
    const url = new URL(window.location.href);
    url.searchParams.delete('error');
    window.history.replaceState({}, document.title, url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : ''));
});
<?php endif; ?>
</script>
</body>
</html>
// PDO automatically handles resource cleanup
?>
