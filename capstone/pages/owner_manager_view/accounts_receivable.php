<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/ar_reminder_helper.php';

// Accessible to Owner (1) and Manager (2, 4)
requireRole([1, 2, 4]);

// Fetch customers for dropdown
$customers = [];
$customers_query = "SELECT Customer_ID, customer_name, phone_number, email FROM customers WHERE deleted_at IS NULL ORDER BY customer_name";
$customers_result = $conn->query($customers_query);
if ($customers_result) {
    while ($row = $customers_result->fetch(PDO::FETCH_ASSOC)) {
        $customers[] = $row;
    }
}

// Get AR summary using your existing table structure
// account_receivable: AR_ID, Sale_ID, Customer_ID, amount_due, due_date, status, invoice_date, invoice_amount, opening_balance
$summary = [
    'total_outstanding' => 0,
    'total_overdue' => 0,
    'open_count' => 0,
    'overdue_count' => 0,
    'collected_this_month' => 0,
    'aging' => [
        'current' => 0,
        '1_30' => 0,
        '31_60' => 0,
        '61_90' => 0,
        '90_plus' => 0
    ]
];

// Check if account_receivable table exists
$ar_table_exists = false;
$table_check = $conn->query("SHOW TABLES LIKE 'account_receivable'");
if ($table_check && $table_check->rowCount() > 0) {
    $ar_table_exists = true;
    
    // Total outstanding (amount_due is the remaining balance in your schema)
    $outstanding_query = $conn->query("SELECT COALESCE(SUM(amount_due), 0) as total 
        FROM account_receivable WHERE status IN ('Open', 'Partial', 'Overdue', 'Pending') AND amount_due > 0");
    if ($outstanding_query) {
        $outstanding_row = $outstanding_query->fetch(PDO::FETCH_ASSOC);
        $summary['total_outstanding'] = $outstanding_row ? floatval($outstanding_row['total']) : 0;
    }
    
    // Total overdue
    $overdue_query = $conn->query("SELECT COALESCE(SUM(amount_due), 0) as total, COUNT(*) as count
        FROM account_receivable WHERE due_date < CURDATE() AND status NOT IN ('Paid', 'Closed') AND amount_due > 0");
    if ($overdue_query) {
        $row = $overdue_query->fetch(PDO::FETCH_ASSOC);
        $summary['total_overdue'] = floatval($row['total']);
        $summary['overdue_count'] = intval($row['count']);
    }
    
    // Open count
    $open_query = $conn->query("SELECT COUNT(*) as count FROM account_receivable WHERE status NOT IN ('Paid', 'Closed') AND amount_due > 0");
    if ($open_query) {
        $open_row = $open_query->fetch(PDO::FETCH_ASSOC);
        $summary['open_count'] = $open_row ? intval($open_row['count']) : 0;
    }

    // Aging Buckets
    $aging_query = $conn->query("
        SELECT 
            SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) <= 0 THEN amount_due ELSE 0 END) as current,
            SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 1 AND 30 THEN amount_due ELSE 0 END) as bucket_1_30,
            SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 31 AND 60 THEN amount_due ELSE 0 END) as bucket_31_60,
            SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) BETWEEN 61 AND 90 THEN amount_due ELSE 0 END) as bucket_61_90,
            SUM(CASE WHEN DATEDIFF(CURDATE(), due_date) > 90 THEN amount_due ELSE 0 END) as bucket_90_plus
        FROM account_receivable 
        WHERE status NOT IN ('Paid', 'Closed') AND amount_due > 0
    ");
    if ($aging_query) {
        $aging = $aging_query->fetch(PDO::FETCH_ASSOC);
        $summary['aging'] = [
            'current' => floatval($aging['current'] ?? 0),
            '1_30' => floatval($aging['bucket_1_30'] ?? 0),
            '31_60' => floatval($aging['bucket_31_60'] ?? 0),
            '61_90' => floatval($aging['bucket_61_90'] ?? 0),
            '90_plus' => floatval($aging['bucket_90_plus'] ?? 0)
        ];
    }
}

// Check if ar_payment table exists for collected this month
$payment_table_check = $conn->query("SHOW TABLES LIKE 'ar_payment'");
if ($payment_table_check && $payment_table_check->rowCount() > 0) {
    $month_start = date('Y-m-01');
    $collected_query = $conn->query("SELECT COALESCE(SUM(amount_paid), 0) as total FROM ar_payment WHERE payment_date >= '$month_start'");
    if ($collected_query) {
        $collected_row = $collected_query->fetch(PDO::FETCH_ASSOC);
        $summary['collected_this_month'] = $collected_row ? floatval($collected_row['total']) : 0;
    }
}

// Pagination parameters (Performance Fix)
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = min(100, max(1, intval($_GET['per_page'] ?? 20))); // Max 100 per page
$offset = ($page - 1) * $per_page;

// Get all open AR records with customer info
$ar_records = [];
$total_records = 0;
$total_pages = 1;
if ($ar_table_exists) {
    // Ensure reminder-tracking table exists for AR email badge display.
    try {
        arReminderEnsureTracking($conn);
    } catch (Throwable $e) {
        // Non-blocking for page rendering.
    }

    // Get total count for pagination (Performance Fix)
    $count_query = "SELECT COUNT(*) FROM account_receivable ar 
                    WHERE ar.status NOT IN ('Paid', 'Closed') AND ar.amount_due > 0";
    $count_result = $conn->query($count_query);
    $total_records = $count_result ? (int) $count_result->fetchColumn() : 0;
    $total_pages = max(1, ceil($total_records / $per_page));
    
    // Ensure page is within valid range
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;

    // Main query with pagination
    $ar_query = "SELECT ar.*, c.customer_name, c.phone_number, c.email, rem.last_sent_at,
                        DATEDIFF(CURDATE(), ar.due_date) as days_overdue
                 FROM account_receivable ar
                 LEFT JOIN customers c ON ar.Customer_ID = c.Customer_ID
                 LEFT JOIN (
                    SELECT AR_ID, MAX(sent_at) AS last_sent_at
                    FROM ar_email_reminders
                    GROUP BY AR_ID
                 ) rem ON rem.AR_ID = ar.AR_ID
                 WHERE ar.status NOT IN ('Paid', 'Closed') AND ar.amount_due > 0
                 ORDER BY ar.due_date ASC
                 LIMIT ? OFFSET ?";
    $ar_stmt = $conn->prepare($ar_query);
    $ar_stmt->execute([$per_page, $offset]);
    $ar_records = $ar_stmt->fetchAll(PDO::FETCH_ASSOC);
}

function formatPeso($amount) {
    return '₱' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
    <title>Accounts Receivable - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        :root {
            --ar-primary: #6366f1;
            --ar-secondary: #4f46e5;
            --ar-success: #22c55e;
            --ar-warning: #f59e0b;
            --ar-danger: #ef4444;
            --ar-critical: #7f1d1d;
            --ar-bg: #f8fafc;
            --ar-card-bg: #ffffff;
            --ar-text-main: #1e293b;
            --ar-text-muted: #64748b;
        }

        body {
            background-color: var(--ar-bg);
            color: var(--ar-text-main);
        }

        .ar-header {
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            color: white;
            padding: 2.5rem;
            border-radius: 24px;
            margin-bottom: 2.5rem;
            box-shadow: 0 10px 25px rgba(99, 102, 241, 0.2);
            position: relative;
            overflow: hidden;
        }
        .ar-header::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            pointer-events: none;
        }
        .ar-header h1 {
            margin: 0 0 0.5rem 0;
            font-size: 2.25rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .ar-header p {
            margin: 0;
            font-size: 1.1rem;
            opacity: 0.9;
            font-weight: 400;
        }
        .ar-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }
        .ar-stat-card {
            background: var(--ar-card-bg);
            border-radius: 20px;
            padding: 1.75rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            border: 1px solid rgba(226, 232, 240, 0.8);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .ar-stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 25px rgba(0,0,0,0.1);
        }
        .ar-stat-card .stat-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--ar-text-main);
            margin-top: 0.25rem;
        }
        .ar-stat-card .stat-label {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--ar-text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .ar-stat-card small {
            margin-top: 0.5rem;
            font-weight: 500;
            color: var(--ar-text-muted);
        }
        .ar-stat-card.danger { border-left: 5px solid var(--ar-danger); }
        .ar-stat-card.danger .stat-value { color: var(--ar-danger); }
        .ar-stat-card.warning { border-left: 5px solid var(--ar-warning); }
        .ar-stat-card.warning .stat-value { color: var(--ar-warning); }
        .ar-stat-card.success { border-left: 5px solid var(--ar-success); }
        .ar-stat-card.success .stat-value { color: var(--ar-success); }
        
        .ar-actions {
            display: flex;
            gap: 1.25rem;
            margin-bottom: 2rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .ar-btn {
            padding: 0.875rem 1.75rem;
            border-radius: 14px;
            border: none;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 0.95rem;
        }
        .ar-btn-primary {
            background: var(--ar-primary);
            color: white;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
        }
        .ar-btn-primary:hover {
            background: var(--ar-secondary);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.4);
        }
        .ar-btn-success {
            background: var(--ar-success);
            color: white;
            box-shadow: 0 4px 15px rgba(34, 197, 94, 0.3);
        }
        .ar-btn-success:hover {
            background: #16a34a;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(34, 197, 94, 0.4);
        }
        
        .ar-table-container {
            background: var(--ar-card-bg);
            border-radius: 24px;
            padding: 2rem;
            box-shadow: 0 4px 25px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
        }
        .ar-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 0.75rem;
        }
        .ar-table th {
            padding: 1.25rem 1rem;
            text-align: left;
            font-weight: 700;
            color: var(--ar-text-muted);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 2px solid #f1f5f9;
        }
        .ar-table td {
            padding: 1.25rem 1rem;
            background: #ffffff;
            vertical-align: middle;
        }
        .ar-table tbody tr {
            transition: all 0.2s ease;
        }
        .ar-table tbody tr:hover td {
            background: #f8fafc;
            transform: scale(1.002);
        }
        .ar-table td:first-child { border-radius: 12px 0 0 12px; }
        .ar-table td:last-child { border-radius: 0 12px 12px 0; }
        
        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 10px;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-block;
        }
        .status-open { background: #eff6ff; color: #2563eb; }
        .status-partial { background: #fffbeb; color: #d97706; }
        .status-overdue { background: #fef2f2; color: #dc2626; animation: pulse-red 2s infinite; }
        .status-paid { background: #f0fdf4; color: #16a34a; }
        
        @keyframes pulse-red {
            0% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.4); }
            70% { box-shadow: 0 0 0 6px rgba(220, 38, 38, 0); }
            100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0); }
        }
        
        .action-btns {
            display: flex;
            gap: 0.75rem;
        }
        .action-btn {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 1rem;
        }
        .action-btn-pay { background: #f0fdf4; color: #16a34a; }
        .action-btn-pay:hover { background: #16a34a; color: white; transform: rotate(15deg); }
        .action-btn-retry { background: #fffbeb; color: #d97706; }
        .action-btn-retry:hover { background: #d97706; color: white; transform: rotate(-15deg); }
        .action-btn-remind { background: #fef2f2; color: #dc2626; }
        .action-btn-remind:hover { background: #dc2626; color: white; transform: scale(1.1); }
        .action-btn-sms { background: #ecfdf5; color: #16a34a; }
        .action-btn-sms:hover { background: #16a34a; color: white; transform: scale(1.1); }
        .action-btn-link { background: #fdf2f8; color: #db2777; }
        .action-btn-link:hover { background: #db2777; color: white; transform: scale(1.1); }
        .action-btn-view { background: #eff6ff; color: #2563eb; }
        .action-btn-view:hover { background: #2563eb; color: white; transform: scale(1.1); }
        .email-reminder-badge {
            display: inline-block;
            margin-top: 6px;
            font-size: 0.72rem;
            font-weight: 700;
            color: #166534;
            background: #dcfce7;
            border: 1px solid #86efac;
            padding: 3px 8px;
            border-radius: 999px;
        }
        
        /* Aging Report Styles */
        .aging-report-section {
            background: white;
            padding: 2rem;
            border-radius: 24px;
            margin-bottom: 2.5rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.04);
            border: 1px solid #e2e8f0;
        }
        .aging-report {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 1.25rem;
            margin-top: 1.5rem;
        }
        .aging-bucket {
            text-align: center;
            padding: 1.5rem 1rem;
            background: #f8fafc;
            border-radius: 18px;
            border-bottom: 5px solid #cbd5e1;
            transition: all 0.3s ease;
        }
        .aging-bucket:hover {
            transform: translateY(-3px);
            background: white;
            box-shadow: 0 8px 15px rgba(0,0,0,0.05);
        }
        .aging-bucket.current { border-bottom-color: var(--ar-success); }
        .aging-bucket.warning { border-bottom-color: var(--ar-warning); }
        .aging-bucket.danger { border-bottom-color: var(--ar-danger); }
        .aging-bucket.critical { border-bottom-color: var(--ar-critical); }
        .aging-bucket.active-filter {
            background: white;
            box-shadow: 0 0 0 3px var(--ar-primary);
            transform: translateY(-5px);
        }
        
        .aging-label {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--ar-text-muted);
            text-transform: uppercase;
            margin-bottom: 0.75rem;
            letter-spacing: 0.5px;
        }
        .aging-value {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--ar-text-main);
        }

        .filter-tabs {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 2rem;
            background: #f1f5f9;
            padding: 0.5rem;
            border-radius: 16px;
            width: fit-content;
        }
        .filter-tab {
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            border: none;
            background: transparent;
            cursor: pointer;
            font-weight: 700;
            color: var(--ar-text-muted);
            transition: all 0.2s ease;
            font-size: 0.9rem;
        }
        .filter-tab:hover { color: var(--ar-primary); }
        .filter-tab.active {
            background: white;
            color: var(--ar-primary);
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
        }

        /* Modal: overlay and visibility */
        .modal {
            display: none;
            position: fixed;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.5);
            z-index: 1050;
            overflow-y: auto;
            padding: 2rem;
            box-sizing: border-box;
            align-items: center;
            justify-content: center;
        }
        .modal.active {
            display: flex;
        }
        .modal-content {
            position: relative;
            margin: auto;
            width: 100%;
            max-width: 520px;
            background: var(--ar-card-bg);
            border-radius: 28px;
            padding: 0;
            border: none;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: hidden;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem 2rem;
            border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            color: white;
        }
        .modal-header h2 {
            margin: 0;
            font-size: 1.35rem;
            font-weight: 800;
            color: white;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .modal-close {
            background: none;
            border: none;
            color: rgba(255,255,255,0.9);
            font-size: 1.75rem;
            line-height: 1;
            cursor: pointer;
            padding: 0.25rem;
            margin: 0;
            transition: color 0.2s ease;
        }
        .modal-close:hover {
            color: white;
        }
        .modal .modal-content > form,
        .modal .modal-content > div {
            padding: 1.5rem 2rem 2rem;
        }
        .form-group {
            margin-bottom: 1.25rem;
        }
        .form-group label {
            display: block;
            font-weight: 700;
            font-size: 0.9rem;
            color: var(--ar-text-main);
            margin-bottom: 0.6rem;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            box-sizing: border-box;
            border-radius: 14px;
            padding: 0.875rem 1.25rem;
            border: 2px solid #f1f5f9;
            background: #f8fafc;
            font-weight: 500;
            font-size: 0.95rem;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: var(--ar-primary);
            background: white;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
            outline: none;
        }
        .form-submit {
            width: 100%;
            padding: 1rem 1.5rem;
            border-radius: 14px;
            border: none;
            background: linear-gradient(135deg, var(--ar-primary) 0%, var(--ar-secondary) 100%);
            color: white;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 0.5rem;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .form-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.35);
        }
        .customer-balance {
            background: #f8fafc;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid #e2e8f0;
        }
        .balance-row {
            display: flex;
            justify-content: space-between;
            padding: 0.35rem 0;
            font-size: 0.9rem;
        }
        .modal form p {
            margin: 0.75rem 0 0;
            font-size: 0.8rem;
            color: #64748b;
            text-align: center;
        }
    </style>

</head>
<body>
<div class="dashboard-wrapper">
    <!-- Sidebar -->
    <?php
    require_once __DIR__ . '/../../includes/sidebar.php';
    renderSidebar($conn, ['base' => '../', 'active' => 'accounts_receivable']);
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
                <a href="accounts_receivable.php" class="menu-item active">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <span>Accounts Receivable</span>
                </a>
            </div>
            <div class="menu-section">
                <div class="menu-label">System</div>
                <a href="activity_logs.php" class="menu-item">
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
    <main class="main-content" id="mainContent">
        <button class="mobile-sidebar-toggle" id="mobileSidebarToggle" aria-label="Toggle sidebar">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Header -->
        <div class="ar-header">
            <h1><i class="fas fa-file-invoice-dollar"></i> Accounts Receivable</h1>
            <p>Manage customer balances, payments, and collections</p>
        </div>

        <!-- Stats Cards -->
        <div class="ar-stats">
            <div class="ar-stat-card danger">
                <div class="stat-label">Total Outstanding</div>
                <div class="stat-value"><?php echo formatPeso($summary['total_outstanding']); ?></div>
            </div>
            <div class="ar-stat-card warning">
                <div class="stat-label">Overdue Amount</div>
                <div class="stat-value"><?php echo formatPeso($summary['total_overdue']); ?></div>
                <small><?php echo $summary['overdue_count']; ?> overdue invoices</small>
            </div>
            <div class="ar-stat-card success">
                <div class="stat-label">Collected This Month</div>
                <div class="stat-value"><?php echo formatPeso($summary['collected_this_month']); ?></div>
            </div>
        </div>

        <!-- Aging Report -->
        <div class="aging-report-section">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h3 style="margin: 0; font-weight: 800; color: var(--ar-text-main);">
                    <i class="fas fa-chart-bar" style="color: var(--ar-primary);"></i> AR Aging Report
                </h3>
                <div style="display: flex; gap: 0.5rem;">
                    <button class="ar-btn" style="padding: 0.5rem 1rem; font-size: 0.8rem; background: var(--ar-primary); color: white;" onclick="viewAgingReport()">
                        <i class="fas fa-file-invoice"></i> View Detailed Report
                    </button>
                    <button class="ar-btn" style="padding: 0.5rem 1rem; font-size: 0.8rem; background: #f1f5f9; color: var(--ar-text-muted);" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <button class="ar-btn" style="padding: 0.5rem 1rem; font-size: 0.8rem; background: #f1f5f9; color: var(--ar-text-muted);" onclick="exportARData()">
                        <i class="fas fa-file-export"></i> Export CSV
                    </button>
                </div>
            </div>
            <div class="aging-report">
                <div class="aging-bucket current" onclick="filterByAging(0)" style="cursor: pointer;">
                    <div class="aging-label">Current</div>
                    <div class="aging-value"><?php echo formatPeso($summary['aging']['current']); ?></div>
                    <div style="font-size: 0.7rem; color: var(--ar-success); margin-top: 0.5rem; font-weight: 600;">Within Terms</div>
                </div>
                <div class="aging-bucket warning" onclick="filterByAging(30)" style="cursor: pointer;">
                    <div class="aging-label">1-30 Days</div>
                    <div class="aging-value"><?php echo formatPeso($summary['aging']['1_30']); ?></div>
                    <div style="font-size: 0.7rem; color: var(--ar-warning); margin-top: 0.5rem; font-weight: 600;">Follow-up Needed</div>
                </div>
                <div class="aging-bucket danger" onclick="filterByAging(60)" style="cursor: pointer;">
                    <div class="aging-label">31-60 Days</div>
                    <div class="aging-value"><?php echo formatPeso($summary['aging']['31_60']); ?></div>
                    <div style="font-size: 0.7rem; color: var(--ar-danger); margin-top: 0.5rem; font-weight: 600;">Urgent Action</div>
                </div>
                <div class="aging-bucket danger" onclick="filterByAging(90)" style="cursor: pointer;">
                    <div class="aging-label">61-90 Days</div>
                    <div class="aging-value"><?php echo formatPeso($summary['aging']['61_90']); ?></div>
                    <div style="font-size: 0.7rem; color: var(--ar-danger); margin-top: 0.5rem; font-weight: 600;">Collections Call</div>
                </div>
                <div class="aging-bucket critical" onclick="filterByAging(91)" style="cursor: pointer;">
                    <div class="aging-label">90+ Days</div>
                    <div class="aging-value"><?php echo formatPeso($summary['aging']['90_plus']); ?></div>
                    <div style="font-size: 0.7rem; color: white; background: var(--ar-critical); padding: 2px 6px; border-radius: 4px; display: inline-block; margin-top: 0.5rem; font-weight: 600;">Legal Notice</div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="ar-actions">
            <?php if (!hasRole(1)): ?>
            <button class="ar-btn ar-btn-primary" onclick="openCreateARModal()">
                <i class="fas fa-plus"></i> New AR Record
            </button>
            <button class="ar-btn ar-btn-success" onclick="openPaymentModal()">
                <i class="fas fa-money-bill-wave"></i> Record Payment
            </button>
            <?php endif; ?>
            <button class="ar-btn" style="background: #6366f1; color: white;" onclick="openARHistoryModal()">
                <i class="fas fa-history"></i> AR History
            </button>
            <div style="flex-grow: 1;"></div>
            <div class="search-box" style="position: relative; max-width: 300px; width: 100%;">
                <i class="fas fa-search" style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: #64748b;"></i>
                <input type="text" id="arSearch" placeholder="Search customer or AR ID..." 
                    style="width: 100%; padding: 0.875rem 1rem 0.875rem 2.5rem; border-radius: 12px; border: 1px solid #e5e7eb; font-size: 0.9rem;">
            </div>
        </div>

        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <button class="filter-tab active" data-filter="all">All Open</button>
            <button class="filter-tab" data-filter="overdue">Overdue</button>
            <button class="filter-tab" data-filter="partial">Partial Payments</button>
        </div>

        <!-- AR Table -->
        <div class="ar-table-container">
            <?php if (empty($ar_records)): ?>
            <div class="empty-state">
                <i class="fas fa-file-invoice-dollar"></i>
                <h3>No Open Accounts Receivable</h3>
                <p>All accounts are paid or no AR records exist yet.</p>
            </div>
            <?php else: ?>
            <table class="ar-table">
                <thead>
                    <tr>
                        <th>AR ID</th>
                        <th>Customer</th>
                        <th>Phone Number</th>
                        <th>Invoice Date</th>
                        <th>Due Date</th>
                        <th>Original Amount</th>
                        <th>Balance Due</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="arTableBody">
                    <?php foreach ($ar_records as $ar): 
                        $is_overdue = strtotime($ar['due_date']) < strtotime('today') && !in_array($ar['status'], ['Paid', 'Closed']);
                        $status_lower = strtolower($ar['status']);
                        $status_class = $is_overdue ? 'overdue' : $status_lower;
                    ?>
                    <tr data-status="<?php echo $is_overdue ? 'overdue' : $status_lower; ?>">
                        <td><strong>AR-<?php echo $ar['AR_ID']; ?></strong></td>
                        <td>
                            <?php echo htmlspecialchars($ar['customer_name'] ?? 'Unknown'); ?>
                        </td>
                        <td>
                            <?php if (!empty($ar['phone_number'])): ?>
                            <?php echo htmlspecialchars($ar['phone_number']); ?>
                            <?php else: ?>
                            <span style="color: #94a3b8;">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo date('d/m/y', strtotime($ar['invoice_date'])); ?></td>
                        <td>
                            <?php echo date('d/m/y', strtotime($ar['due_date'])); ?>
                        </td>
                        <td><?php echo formatPeso($ar['invoice_amount']); ?></td>
                        <td><strong><?php echo formatPeso($ar['amount_due']); ?></strong></td>
                        <td>
                            <?php
                            $arStatusColors = [
                                                'open' => ['bg' => '#dbeafe', 'color' => '#1d4ed8', 'border' => '#93c5fd', 'icon' => 'fa-folder-open'],
                                                'partial' => ['bg' => '#fef3c7', 'color' => '#b45309', 'border' => '#fcd34d', 'icon' => 'fa-adjust'],
                                                'overdue' => ['bg' => '#fee2e2', 'color' => '#dc2626', 'border' => '#fca5a5', 'icon' => 'fa-exclamation-circle'],
                                                'paid' => ['bg' => '#dcfce7', 'color' => '#15803d', 'border' => '#86efac', 'icon' => 'fa-check-circle'],
                                                'closed' => ['bg' => '#f1f5f9', 'color' => '#64748b', 'border' => '#cbd5e1', 'icon' => 'fa-archive']
                                            ];
                                            $arStatus = $is_overdue ? 'overdue' : $status_lower;
                                            $arColors = $arStatusColors[$arStatus] ?? $arStatusColors['open'];
                            ?>
                            <span style="display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.5rem 1rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.025em; background: <?php echo $arColors['bg']; ?>; color: <?php echo $arColors['color']; ?>; border: 1px solid <?php echo $arColors['border']; ?>;<?php echo $is_overdue ? ' animation: pulse-red 2s infinite;' : ''; ?>">
                                <i class="fas <?php echo $arColors['icon']; ?>"></i> <?php echo $is_overdue ? 'Overdue' : ucfirst($ar['status']); ?>
                            </span>
                        </td>
                        <td>
                            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                <?php if (!hasRole(1)): ?>
                                <button onclick="openPaymentModal(<?php echo $ar['Customer_ID']; ?>, '<?php echo htmlspecialchars(addslashes($ar['customer_name'] ?? 'Unknown')); ?>', <?php echo $ar['AR_ID']; ?>)" title="Record Payment" style="width: 38px; height: 38px; border-radius: 10px; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); font-size: 0.9rem; background: #dcfce7; color: #16a34a;" onmouseover="this.style.background='#16a34a'; this.style.color='white'; this.style.transform='scale(1.1)';" onmouseout="this.style.background='#dcfce7'; this.style.color='#16a34a'; this.style.transform='scale(1)';">
                                    <i class="fas fa-money-bill"></i>
                                </button>
                                <button onclick="sendARReminder(<?php echo $ar['AR_ID']; ?>, '<?php echo htmlspecialchars(addslashes($ar['customer_name'] ?? 'Unknown')); ?>', '<?php echo addslashes($ar['email'] ?? ''); ?>', <?php echo (float)$ar['amount_due']; ?>, '<?php echo addslashes($ar['due_date'] ?? ''); ?>')" title="Send Email Reminder" style="width: 38px; height: 38px; border-radius: 10px; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); font-size: 0.9rem; background: #dbeafe; color: #2563eb;" onmouseover="this.style.background='#2563eb'; this.style.color='white'; this.style.transform='scale(1.1)';" onmouseout="this.style.background='#dbeafe'; this.style.color='#2563eb'; this.style.transform='scale(1)';">
                                    <i class="fas fa-bell"></i>
                                </button>
                                <?php endif; ?>
                                <button onclick="viewARDetails(<?php echo $ar['AR_ID']; ?>)" title="View Details" style="width: 38px; height: 38px; border-radius: 10px; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); font-size: 0.9rem; background: #f1f5f9; color: #64748b;" onmouseover="this.style.background='#64748b'; this.style.color='white'; this.style.transform='scale(1.1)';" onmouseout="this.style.background='#f1f5f9'; this.style.color='#64748b'; this.style.transform='scale(1)';">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <?php if (!empty($ar['last_sent_at'])): ?>
                            <div class="email-reminder-badge" title="Last email reminder">
                                Last emailed: <?php echo date('d/m/y g:i A', strtotime($ar['last_sent_at'])); ?>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- Pagination Controls (Performance Fix) -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination" style="display: flex; justify-content: center; align-items: center; gap: 0.5rem; padding: 1.5rem; border-top: 1px solid #e2e8f0;">
                <span style="color: #64748b; font-size: 0.875rem;">Showing <?php echo (($page - 1) * $per_page) + 1; ?> - <?php echo min($page * $per_page, $total_records); ?> of <?php echo $total_records; ?> records</span>
                
                <div style="display: flex; gap: 0.25rem;">
                    <!-- Previous Page -->
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>&per_page=<?php echo $per_page; ?>" 
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
                        <a href="?page=1&per_page=<?php echo $per_page; ?>" 
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
                            <a href="?page=<?php echo $i; ?>&per_page=<?php echo $per_page; ?>" 
                               class="pagination-btn" 
                               style="padding: 0.5rem 0.75rem; border: 1px solid #e2e8f0; border-radius: 6px; color: #475569; text-decoration: none; font-size: 0.875rem;"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($end_page < $total_pages): ?>
                        <?php if ($end_page < $total_pages - 1): ?>
                            <span style="padding: 0.5rem 0.25rem; color: #94a3b8;">...</span>
                        <?php endif; ?>
                        <a href="?page=<?php echo $total_pages; ?>&per_page=<?php echo $per_page; ?>" 
                           class="pagination-btn" 
                           style="padding: 0.5rem 0.75rem; border: 1px solid #e2e8f0; border-radius: 6px; color: #475569; text-decoration: none; font-size: 0.875rem;"><?php echo $total_pages; ?></a>
                    <?php endif; ?>
                    
                    <!-- Next Page -->
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&per_page=<?php echo $per_page; ?>" 
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
            
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Create AR Modal -->
<div class="modal" id="createARModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-plus-circle"></i> Create AR Record</h2>
            <button class="modal-close" onclick="closeModal('createARModal')">&times;</button>
        </div>
        <form id="createARForm" onsubmit="submitCreateAR(event)">
            <div class="form-group">
                <label>Customer *</label>
                <select name="customer_id" id="arCustomerId" required onchange="loadCustomerBalance(this.value, 'create_')">
                    <option value="">Select Customer</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?php echo $c['Customer_ID']; ?>"><?php echo htmlspecialchars($c['customer_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="customer-balance" id="create_customerBalanceInfo" style="display: none; margin-bottom: 1rem;">
                <div class="balance-row">
                    <span>Current Outstanding:</span>
                    <span id="create_totalOutstanding">₱0.00</span>
                </div>
            </div>
            <div class="form-group">
                <label>Invoice Amount *</label>
                <input type="number" name="invoice_amount" step="0.01" min="0.01" required placeholder="0.00">
            </div>
            <div class="form-group">
                <label>Amount Due (Balance) *</label>
                <input type="number" name="amount_due" step="0.01" min="0.01" required placeholder="0.00">
            </div>
            <div class="form-group">
                <label>Invoice Date</label>
                <input type="date" name="invoice_date" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="form-group">
                <label>Due Date *</label>
                <input type="date" name="due_date" required value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
            </div>
            <button type="submit" class="form-submit">
                <i class="fas fa-save"></i> Create AR Record
            </button>
        </form>
    </div>
</div>

<!-- AR History Modal -->
<div class="modal" id="arHistoryModal">
    <div class="modal-content" style="max-width: 1100px; width: 95vw;">
        <div class="modal-header">
            <h2><i class="fas fa-history"></i> AR History</h2>
            <button class="modal-close" onclick="closeModal('arHistoryModal')">&times;</button>
        </div>
        <div style="padding: 1.5rem;">
            <div style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-end; margin-bottom: 1.5rem;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label>From Date</label>
                    <input type="date" id="arHistoryDateFrom" class="form-control" style="padding: 0.5rem 0.75rem;">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label>To Date</label>
                    <input type="date" id="arHistoryDateTo" class="form-control" style="padding: 0.5rem 0.75rem;">
                </div>
                <button type="button" onclick="loadARHistory()" class="ar-btn ar-btn-primary">
                    <i class="fas fa-search"></i> Filter
                </button>
                <button type="button" onclick="exportARHistoryCSV()" class="ar-btn" style="background: #22c55e; color: white;">
                    <i class="fas fa-file-excel"></i> Export to Spreadsheet
                </button>
            </div>
            <div style="max-height: 65vh; overflow-y: auto;">
                <table class="ar-table" id="arHistoryTable">
                    <thead>
                        <tr>
                            <th>AR ID</th>
                            <th>Sale ID</th>
                            <th>Customer</th>
                            <th>Invoice Date</th>
                            <th>Due Date</th>
                            <th>Invoice Amount</th>
                            <th>Balance Due</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="arHistoryBody">
                        <tr><td colspan="8" style="text-align: center; padding: 2rem; color: #64748b;">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal" id="paymentModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-money-bill-wave"></i> Record Payment</h2>
            <button class="modal-close" onclick="closeModal('paymentModal')">&times;</button>
        </div>
        <form id="paymentForm" onsubmit="submitPayment(event)">
            <input type="hidden" name="ar_id" id="paymentArId">
            <div class="form-group">
                <label>Customer *</label>
                <select name="customer_id" id="paymentCustomerId" required onchange="loadCustomerBalance(this.value, 'pay_')">
                    <option value="">Select Customer</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?php echo $c['Customer_ID']; ?>"><?php echo htmlspecialchars($c['customer_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="customer-balance" id="pay_customerBalanceInfo" style="display: none;">
                <div class="balance-row">
                    <span>Open Invoices:</span>
                    <span id="pay_openInvoicesCount">0</span>
                </div>
                <div class="balance-row balance-total">
                    <span>Total Outstanding:</span>
                    <span id="pay_totalOutstanding">₱0.00</span>
                </div>
            </div>
            <div class="form-group">
                <label>Payment Amount *</label>
                <input type="number" name="amount_paid" step="0.01" min="0.01" required placeholder="0.00">
            </div>
            <div class="form-group">
                <label>Payment Date</label>
                <input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <button type="submit" class="form-submit">
                <i class="fas fa-check"></i> Record Payment (FIFO)
            </button>
            <p style="font-size: 0.8rem; color: #64748b; margin-top: 0.75rem; text-align: center;">
                Payment will be applied to oldest invoices first
            </p>
        </form>
    </div>
</div>

<!-- AR Details Modal -->
<div class="modal" id="arDetailsModal">
    <div class="modal-content" style="max-width: 700px;">
        <div class="modal-header">
            <h2><i class="fas fa-file-invoice"></i> AR Details</h2>
            <button class="modal-close" onclick="closeModal('arDetailsModal')">&times;</button>
        </div>
        <div id="arDetailsContent">
            Loading...
        </div>
    </div>
</div>

<!-- Detailed Aging Report Modal -->
<div class="modal" id="agingReportModal">
    <div class="modal-content" style="max-width: 1000px; width: 95%;">
        <div class="modal-header">
            <h2><i class="fas fa-chart-bar"></i> Detailed Customer Aging Report</h2>
            <button class="modal-close" onclick="closeModal('agingReportModal')">&times;</button>
        </div>
        <div id="agingReportContent" style="max-height: 70vh; overflow-y: auto;">
            <p style="text-align:center;"><i class="fas fa-spinner fa-spin"></i> Generating report...</p>
        </div>
    </div>
</div>

<!-- Email Sending Wait Modal -->
<div class="modal" id="emailSendingModal">
    <div class="modal-content" style="max-width: 420px;">
        <div class="modal-header">
            <h2 id="emailSendingTitle"><i class="fas fa-envelope-circle-check"></i> Sending Email Reminder</h2>
        </div>
        <div style="padding: 1.5rem 2rem 2rem; text-align: center;">
            <p id="emailSendingMessage" style="margin: 0 0 1rem 0; color: #334155; font-weight: 600;">Please wait while we send the reminder to the customer.</p>
            <div style="font-size: 2rem; color: #6366f1;">
                <i class="fas fa-spinner fa-spin"></i>
            </div>
            <p style="margin-top: 1rem; color: #64748b; font-size: 0.85rem;">This usually takes a few seconds.</p>
        </div>
    </div>
</div>

<!-- Email Confirm Modal -->
<div class="modal" id="emailConfirmModal">
    <div class="modal-content" style="max-width: 460px;">
        <div class="modal-header">
            <h2 id="emailConfirmTitle"><i class="fas fa-paper-plane"></i> Confirm Email Reminder</h2>
            <button class="modal-close" onclick="closeModal('emailConfirmModal')">&times;</button>
        </div>
        <div style="padding: 1.5rem 2rem 2rem;">
            <p id="emailConfirmMessage" style="margin: 0 0 1rem 0; color: #334155; font-weight: 600; line-height: 1.5;">Are you sure?</p>
            <div style="display: flex; gap: 0.75rem; justify-content: flex-end;">
                <button type="button" class="ar-btn" style="background:#e2e8f0;color:#0f172a;" onclick="closeModal('emailConfirmModal')">
                    Cancel
                </button>
                <button type="button" class="ar-btn ar-btn-primary" id="emailConfirmActionBtn" onclick="confirmSendARReminder()">
                    <i class="fas fa-envelope"></i> Send Email
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Email Result Modal -->
<div class="modal" id="emailResultModal">
    <div class="modal-content" style="max-width: 420px;">
        <div class="modal-header">
            <h2 id="emailResultTitle"><i class="fas fa-circle-info"></i> Reminder Status</h2>
            <button class="modal-close" onclick="closeModal('emailResultModal')">&times;</button>
        </div>
        <div style="padding: 1.5rem 2rem 2rem; text-align: center;">
            <p id="emailResultMessage" style="margin: 0 0 1rem 0; color: #334155; font-weight: 600;">Status</p>
            <button type="button" class="form-submit" style="max-width: 180px; margin: 0 auto;" onclick="closeEmailResultModal()">
                <i class="fas fa-check"></i> OK
            </button>
        </div>
    </div>
</div>

<script src="../assets/js/script.js"></script>
<script>
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
window.csrfToken = csrfToken;

// Modal functions
function openModal(modalId) {
    document.getElementById(modalId).classList.add('active');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

function openCreateARModal() {
    document.getElementById('createARForm').reset();
    openModal('createARModal');
}

function openPaymentModal(customerId = null, customerName = null, arId = null) {
    document.getElementById('paymentForm').reset();
    if (customerId) {
        document.getElementById('paymentCustomerId').value = customerId;
        loadCustomerBalance(customerId, 'pay_');
    }
    if (arId) {
        document.getElementById('paymentArId').value = arId;
    }
    openModal('paymentModal');
}

let arHistoryRecords = [];

function openARHistoryModal() {
    openModal('arHistoryModal');
    document.getElementById('arHistoryDateFrom').value = '';
    document.getElementById('arHistoryDateTo').value = '';
    loadARHistory();
}

async function loadARHistory() {
    const dateFrom = document.getElementById('arHistoryDateFrom').value;
    const dateTo = document.getElementById('arHistoryDateTo').value;
    let url = '../api/ar_backend.php?action=get_ar_history';
    if (dateFrom) url += '&date_from=' + encodeURIComponent(dateFrom);
    if (dateTo) url += '&date_to=' + encodeURIComponent(dateTo);
    
    document.getElementById('arHistoryBody').innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 2rem;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>';
    
    try {
        const response = await fetch(url);
        const data = await response.json();
        arHistoryRecords = data.records || [];
        
        if (arHistoryRecords.length === 0) {
            document.getElementById('arHistoryBody').innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 2rem; color: #64748b;">No AR records found.</td></tr>';
            return;
        }
        
        const esc = (s) => String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        let html = '';
        arHistoryRecords.forEach(r => {
            const status = (r.amount_due <= 0) ? 'Paid' : (r.status || 'Open');
            const statusClass = status === 'Paid' ? 'status-paid' : (parseInt(r.days_overdue) > 0 ? 'overdue' : 'status-' + (r.status || '').toLowerCase());
            html += `<tr>
                <td><strong>AR-${r.AR_ID}</strong></td>
                <td>${r.Sale_ID ? '#' + r.Sale_ID : '-'}</td>
                <td>${esc(r.customer_name || 'Unknown')}</td>
                <td>${r.invoice_date ? new Date(r.invoice_date).toLocaleDateString() : '-'}</td>
                <td>${r.due_date ? new Date(r.due_date).toLocaleDateString() : '-'}</td>
                <td>₱${parseFloat(r.invoice_amount || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                <td><strong>₱${parseFloat(r.amount_due || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</strong></td>
                <td><span class="status-badge status-${statusClass}">${status}</span></td>
            </tr>`;
        });
        document.getElementById('arHistoryBody').innerHTML = html;
    } catch (err) {
        document.getElementById('arHistoryBody').innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 2rem; color: #ef4444;">Error loading data.</td></tr>';
        console.error(err);
    }
}

function exportARHistoryCSV() {
    if (arHistoryRecords.length === 0) {
        alert('No data to export. Use Filter to load AR history first.');
        return;
    }
    const headers = ['AR ID', 'Sale ID', 'Customer', 'Invoice Date', 'Due Date', 'Invoice Amount', 'Balance Due', 'Status'];
    const rows = arHistoryRecords.map(r => [
        'AR-' + r.AR_ID,
        r.Sale_ID || '',
        (r.customer_name || 'Unknown').replace(/"/g, '""'),
        r.invoice_date || '',
        r.due_date || '',
        parseFloat(r.invoice_amount || 0).toFixed(2),
        parseFloat(r.amount_due || 0).toFixed(2),
        (r.amount_due <= 0) ? 'Paid' : (r.status || 'Open')
    ]);
    const csv = [headers.join(','), ...rows.map(row => row.map(c => typeof c === 'string' && c.includes(',') ? '"' + c + '"' : c).join(','))].join('\n');
    const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'AR_History_' + new Date().toISOString().slice(0, 10) + '.csv';
    a.click();
    URL.revokeObjectURL(a.href);
}

// Load customer balance
async function loadCustomerBalance(customerId, containerPrefix = '') {
    if (!customerId) {
        document.getElementById(containerPrefix + 'customerBalanceInfo').style.display = 'none';
        return;
    }
    
    try {
        const response = await fetch(`../api/ar_backend.php?action=get_customer_ar&customer_id=${customerId}`);
        const data = await response.json();
        
        if (data.success) {
            const openInvoicesElem = document.getElementById(containerPrefix + 'openInvoicesCount');
            const totalOutstandingElem = document.getElementById(containerPrefix + 'totalOutstanding');
            const creditLimitElem = document.getElementById(containerPrefix + 'creditLimit');
            
            if (openInvoicesElem) openInvoicesElem.textContent = data.open_count || 0;
            if (totalOutstandingElem) totalOutstandingElem.textContent = '₱' + parseFloat(data.total_outstanding || 0).toLocaleString('en-US', {minimumFractionDigits: 2});
            if (creditLimitElem) creditLimitElem.textContent = '₱' + parseFloat(data.credit_limit || 0).toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById(containerPrefix + 'customerBalanceInfo').style.display = 'block';

            // Auto-set due date based on customer's aging_days when creating AR
            if (containerPrefix === 'create_') {
                const form = document.getElementById('createARForm');
                const dueInput = form?.querySelector('input[name="due_date"]');
                const invoiceDateInput = form?.querySelector('input[name="invoice_date"]');
                const aging = parseInt(data.aging_days || 30);
                const baseDateStr = (invoiceDateInput?.value || new Date().toISOString().slice(0, 10));
                const baseDate = new Date(baseDateStr + 'T00:00:00');
                if (dueInput && !Number.isNaN(baseDate.getTime())) {
                    baseDate.setDate(baseDate.getDate() + aging);
                    dueInput.value = baseDate.toISOString().slice(0, 10);
                }
            }
        }
    } catch (error) {
        console.error('Error loading customer balance:', error);
    }
}

// Submit Create AR
async function submitCreateAR(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    formData.append('action', 'create_ar');
    formData.append('csrf_token', csrfToken);
    
    try {
        const response = await fetch('../api/ar_backend.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            alert('AR Record created successfully!');
            closeModal('createARModal');
            location.reload();
        } else {
            if (data.error === 'Credit limit exceeded') {
                let details = data.details;
                let message = `DENIED: Credit Limit Exceeded!\n\n`;
                message += `Credit Limit: ₱${parseFloat(details.credit_limit).toLocaleString('en-US', {minimumFractionDigits: 2})}\n`;
                message += `Current Outstanding: ₱${parseFloat(details.current_outstanding).toLocaleString('en-US', {minimumFractionDigits: 2})}\n`;
                message += `New AR Amount: ₱${parseFloat(details.new_ar_amount).toLocaleString('en-US', {minimumFractionDigits: 2})}\n`;
                message += `Total After New: ₱${parseFloat(details.total_after_new).toLocaleString('en-US', {minimumFractionDigits: 2})}\n\n`;
                message += `Outstanding Invoices:\n`;
                details.breaching_invoices.forEach(inv => {
                    message += `- AR-${inv.AR_ID}: ₱${parseFloat(inv.amount_due).toLocaleString('en-US', {minimumFractionDigits: 2})} (Due: ${inv.due_date})\n`;
                });
                message += `\nRecommendation: ${data.recommendation}`;
                alert(message);
            } else {
                alert('Error: ' + data.error);
            }
        }
    } catch (error) {
        alert('Error creating AR record');
        console.error(error);
    }
}

// Submit Payment
async function submitPayment(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    formData.append('action', 'record_payment');
    formData.append('csrf_token', csrfToken);
    
    try {
        const response = await fetch('../api/ar_backend.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            let message = 'Payment recorded successfully!';
            if (data.applications && data.applications.length > 0) {
                message += '\n\nApplied to:';
                data.applications.forEach(app => {
                    message += `\n- AR-${app.ar_id}: ₱${parseFloat(app.applied).toLocaleString('en-US', {minimumFractionDigits: 2})}`;
                });
            }
            if (data.credit_balance > 0) {
                message += `\n\nCredit balance: ₱${parseFloat(data.credit_balance).toLocaleString('en-US', {minimumFractionDigits: 2})}`;
            }
            alert(message);
            closeModal('paymentModal');
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        alert('Error recording payment');
        console.error(error);
    }
}

// View AR Details
async function viewARDetails(arId) {
    openModal('arDetailsModal');
    document.getElementById('arDetailsContent').innerHTML = '<p style="text-align:center;"><i class="fas fa-spinner fa-spin"></i> Loading...</p>';
    
    try {
        const response = await fetch(`../api/ar_backend.php?action=get_ar_details&ar_id=${arId}`);
        const data = await response.json();
        
        if (data.success) {
            const ar = data.ar;
            let html = `
                <div style="margin-bottom: 1.5rem;">
                    <h3 style="margin: 0 0 0.5rem 0;">AR-${ar.AR_ID}</h3>
                    <p style="margin: 0; color: #64748b;">${ar.customer_name || 'Unknown'} - ${ar.phone_number || 'No phone'}</p>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                    <div style="background: #f8fafc; padding: 1rem; border-radius: 10px;">
                        <small style="color: #64748b;">Invoice Amount</small>
                        <p style="font-size: 1.25rem; font-weight: 700; margin: 0;">₱${parseFloat(ar.invoice_amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</p>
                    </div>
                    <div style="background: #f8fafc; padding: 1rem; border-radius: 10px;">
                        <small style="color: #64748b;">Amount Due</small>
                        <p style="font-size: 1.25rem; font-weight: 700; margin: 0; color: #dc2626;">₱${parseFloat(ar.amount_due).toLocaleString('en-US', {minimumFractionDigits: 2})}</p>
                    </div>
                    <div style="background: #f8fafc; padding: 1rem; border-radius: 10px;">
                        <small style="color: #64748b;">Invoice Date</small>
                        <p style="font-weight: 600; margin: 0;">${new Date(ar.invoice_date).toLocaleDateString()}</p>
                    </div>
                    <div style="background: #f8fafc; padding: 1rem; border-radius: 10px;">
                        <small style="color: #64748b;">Due Date</small>
                        <p style="font-weight: 600; margin: 0;">${new Date(ar.due_date).toLocaleDateString()}</p>
                    </div>
                </div>
            `;
            
            // Payment History
            if (data.payments && data.payments.length > 0) {
                html += `<h4 style="margin: 1.5rem 0 1rem 0;"><i class="fas fa-money-bill"></i> Payment History</h4>`;
                html += `<table style="width: 100%; font-size: 0.85rem; border-collapse: collapse;">
                    <tr style="background: #f8fafc;"><th style="padding: 0.5rem; text-align: left;">Date</th><th style="padding: 0.5rem; text-align: left;">Amount</th><th style="padding: 0.5rem; text-align: left;">Balance After</th></tr>`;
                data.payments.forEach(p => {
                    html += `<tr><td style="padding: 0.5rem; border-bottom: 1px solid #e5e7eb;">${new Date(p.payment_date).toLocaleDateString()}</td>
                        <td style="padding: 0.5rem; border-bottom: 1px solid #e5e7eb;">₱${parseFloat(p.amount_paid).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                        <td style="padding: 0.5rem; border-bottom: 1px solid #e5e7eb;">₱${parseFloat(p.remaining_balance).toLocaleString('en-US', {minimumFractionDigits: 2})}</td></tr>`;
                });
                html += `</table>`;
            }
            
            // Retry Attempts
            if (data.retries && data.retries.length > 0) {
                html += `<h4 style="margin: 1.5rem 0 1rem 0;"><i class="fas fa-phone"></i> Collection Attempts</h4>`;
                data.retries.forEach(r => {
                    html += `<div style="background: #f8fafc; padding: 0.75rem; border-radius: 8px; margin-bottom: 0.5rem;">
                        <strong>#${r.attempt_no}</strong> - ${new Date(r.created_at).toLocaleDateString()} - ${r.status}
                        ${r.remarks ? `<br><small style="color: #64748b;">${r.remarks}</small>` : ''}
                    </div>`;
                });
            }
            
            document.getElementById('arDetailsContent').innerHTML = html;
        } else {
            document.getElementById('arDetailsContent').innerHTML = '<p style="color: #dc2626;">Error loading details: ' + (data.error || 'Unknown error') + '</p>';
        }
    } catch (error) {
        document.getElementById('arDetailsContent').innerHTML = '<p style="color: #dc2626;">Error loading details</p>';
        console.error(error);
    }
}

// Search functionality
document.getElementById('arSearch').addEventListener('input', function() {
    const searchTerm = this.value.toLowerCase();
    const rows = document.querySelectorAll('#arTableBody tr');
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
    });
});

// View Detailed Aging Report
async function viewAgingReport() {
    openModal('agingReportModal');
    const container = document.getElementById('agingReportContent');
    container.innerHTML = '<p style="text-align:center; padding: 2rem;"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Generating detailed aging report...</p>';
    
    try {
        const response = await fetch('../api/ar_backend.php?action=get_aging_report');
        const data = await response.json();
        
        if (data.success && data.report) {
            let html = '';
            
            if (data.report.length === 0) {
                html = '<div class="empty-state"><i class="fas fa-check-circle"></i><h3>No outstanding balances</h3><p>All customers are fully paid.</p></div>';
            } else {
                data.report.forEach(customer => {
                    const statusClass = customer.is_over_limit ? 'status-overdue' : (customer.near_limit ? 'status-partial' : '');
                    const statusText = customer.is_over_limit ? 'OVER LIMIT' : (customer.near_limit ? 'NEAR LIMIT' : 'OK');
                    
                    html += `
                        <div style="border: 1px solid #e5e7eb; border-radius: 12px; margin-bottom: 2rem; overflow: hidden; background: white;">
                            <div style="background: #f8fafc; padding: 1.25rem; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <h3 style="margin: 0; color: var(--ar-text-main);">${customer.customer_name}</h3>
                                    <small style="color: var(--ar-text-muted);">Credit Limit: ₱${customer.credit_limit.toLocaleString('en-US', {minimumFractionDigits: 2})}</small>
                                </div>
                                <div style="text-align: right;">
                                    <div style="font-weight: 700; color: ${customer.is_over_limit ? 'var(--ar-danger)' : 'var(--ar-text-main)'};">
                                        Total Outstanding: ₱${customer.total_outstanding.toLocaleString('en-US', {minimumFractionDigits: 2})}
                                    </div>
                                    ${statusText !== 'OK' ? `<span class="status-badge ${statusClass}" style="font-size: 0.7rem;">${statusText}</span>` : ''}
                                </div>
                            </div>
                            
                            <div style="padding: 1.25rem;">
                                <!-- Buckets Summary -->
                                <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 0.5rem; margin-bottom: 1.5rem;">
                                    ${Object.entries(customer.buckets).map(([key, bucket]) => `
                                        <div style="text-align: center; padding: 0.5rem; background: ${bucket.total > 0 ? '#fff1f2' : '#f0fdf4'}; border-radius: 8px; border: 1px solid ${bucket.total > 0 ? '#fecaca' : '#bbf7d0'};">
                                            <div style="font-size: 0.7rem; color: #64748b; text-transform: uppercase;">${key.replace('_', '-')}</div>
                                            <div style="font-weight: 700; font-size: 0.9rem; color: ${bucket.total > 0 ? '#be123c' : '#15803d'};">₱${bucket.total.toLocaleString('en-US', {minimumFractionDigits: 2})}</div>
                                        </div>
                                    `).join('')}
                                </div>
                                
                                <!-- Recommendations -->
                                ${customer.recommendations.length > 0 ? `
                                    <div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
                                        <h4 style="margin: 0 0 0.5rem 0; color: #92400e; font-size: 0.9rem;"><i class="fas fa-lightbulb"></i> Recommendations:</h4>
                                        <ul style="margin: 0; padding-left: 1.25rem; font-size: 0.85rem; color: #92400e;">
                                            ${customer.recommendations.map(rec => `<li>${rec}</li>`).join('')}
                                        </ul>
                                    </div>
                                ` : ''}
                                
                                <!-- Invoices Table -->
                                <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                                    <thead>
                                        <tr style="border-bottom: 2px solid #e5e7eb; color: var(--ar-text-muted);">
                                            <th style="padding: 0.75rem; text-align: left;">Invoice #</th>
                                            <th style="padding: 0.75rem; text-align: left;">Issue Date</th>
                                            <th style="padding: 0.75rem; text-align: left;">Due Date</th>
                                            <th style="padding: 0.75rem; text-align: right;">Amount</th>
                                            <th style="padding: 0.75rem; text-align: right;">Balance</th>
                                            <th style="padding: 0.75rem; text-align: center;">Days</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${Object.values(customer.buckets).flatMap(b => b.invoices).map(inv => `
                                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                                <td style="padding: 0.75rem;"><strong>AR-${inv.ar_id}</strong></td>
                                                <td style="padding: 0.75rem;">${new Date(inv.invoice_date).toLocaleDateString()}</td>
                                                <td style="padding: 0.75rem;">${new Date(inv.due_date).toLocaleDateString()}</td>
                                                <td style="padding: 0.75rem; text-align: right;">₱${inv.invoice_amount.toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                                                <td style="padding: 0.75rem; text-align: right; font-weight: 600;">₱${inv.amount_due.toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                                                <td style="padding: 0.75rem; text-align: center;">
                                                    <span style="color: ${inv.days_outstanding > 0 ? 'var(--ar-danger)' : 'var(--ar-success)'}; font-weight: 600;">
                                                        ${inv.days_outstanding > 0 ? inv.days_outstanding + ' overdue' : 'Current'}
                                                    </span>
                                                </td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    `;
                });
            }
            
            container.innerHTML = html;
        } else {
            container.innerHTML = `<p style="color: var(--ar-danger); text-align: center;">Error: ${data.error || 'Failed to load report'}</p>`;
        }
    } catch (error) {
        container.innerHTML = '<p style="color: var(--ar-danger); text-align: center;">Error connecting to server</p>';
        console.error(error);
    }
}

function filterByAging(days) {
    const rows = document.querySelectorAll('#arTableBody tr');
    const buckets = document.querySelectorAll('.aging-bucket');
    
    buckets.forEach(b => b.classList.remove('active-filter'));
    // Find bucket to highlight
    if (days === 0) buckets[0].classList.add('active-filter');
    else if (days === 30) buckets[1].classList.add('active-filter');
    else if (days === 60) buckets[2].classList.add('active-filter');
    else if (days === 90) buckets[3].classList.add('active-filter');
    else if (days === 91) buckets[4].classList.add('active-filter');

    rows.forEach(row => {
        const overdueSpan = row.querySelector('.days-overdue');
        const daysOverdue = overdueSpan ? parseInt(overdueSpan.textContent) : 0;
        
        if (days === 0) {
            row.style.display = daysOverdue <= 0 ? '' : 'none';
        } else if (days === 30) {
            row.style.display = (daysOverdue >= 1 && daysOverdue <= 30) ? '' : 'none';
        } else if (days === 60) {
            row.style.display = (daysOverdue >= 31 && daysOverdue <= 60) ? '' : 'none';
        } else if (days === 90) {
            row.style.display = (daysOverdue >= 61 && daysOverdue <= 90) ? '' : 'none';
        } else if (days === 91) {
            row.style.display = daysOverdue > 90 ? '' : 'none';
        }
    });
}

function exportARData() {
    const rows = document.querySelectorAll('.ar-table-container .ar-table tr');
    let csv = [];
    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll('td, th');
        for (let j = 0; j < cols.length - 1; j++) { // Skip actions column
            let text = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ").replace(/₱/g, "").trim();
            row.push('"' + text + '"');
        }
        csv.push(row.join(","));
    }
    const csvContent = "data:text/csv;charset=utf-8," + csv.join("\n");
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "accounts_receivable_report.csv");
    document.body.appendChild(link);
    link.click();
}

// Filter tabs
document.querySelectorAll('.filter-tab').forEach(tab => {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        
        const filter = this.dataset.filter;
        const rows = document.querySelectorAll('#arTableBody tr');
        
        rows.forEach(row => {
            const status = row.dataset.status;
            if (filter === 'all') {
                row.style.display = '';
            } else if (filter === 'overdue' && status === 'overdue') {
                row.style.display = '';
            } else if (filter === 'partial' && status === 'partial') {
                row.style.display = '';
            } else if (filter !== 'all') {
                row.style.display = 'none';
            }
        });
    });
});

// Close modal on outside click
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            if (this.id === 'emailSendingModal') return;
            this.classList.remove('active');
        }
    });
});

// Send Reminder
let pendingReminderPayload = null;
let reloadAfterEmailResultClose = false;

async function sendARReminder(arId, customerName, email, amountDue, dueDate) {
    const message = `Send EMAIL reminder to ${customerName}${email ? ` (${email})` : ''}?<br><br>`
        + `Reference: <strong>AR-${arId}</strong><br>`
        + `Balance Due: <strong>₱${Number(amountDue || 0).toLocaleString('en-US', {minimumFractionDigits: 2})}</strong><br>`
        + `Due Date: <strong>${dueDate}</strong>`;

    pendingReminderPayload = { arId, channel: 'email' };
    document.getElementById('emailConfirmTitle').innerHTML = '<i class="fas fa-paper-plane"></i> Confirm Email Reminder';
    document.getElementById('emailSendingTitle').innerHTML = '<i class="fas fa-envelope-circle-check"></i> Sending Email Reminder';
    document.getElementById('emailSendingMessage').textContent = 'Please wait while we send the reminder to the customer.';
    document.getElementById('emailConfirmActionBtn').innerHTML = '<i class="fas fa-envelope"></i> Send Email';
    document.getElementById('emailConfirmMessage').innerHTML = message;
    openModal('emailConfirmModal');
}

function showEmailResultModal(success, message) {
    const title = document.getElementById('emailResultTitle');
    const text = document.getElementById('emailResultMessage');
    title.innerHTML = success
        ? '<i class="fas fa-circle-check" style="color:#22c55e;"></i> Reminder Sent'
        : '<i class="fas fa-circle-xmark" style="color:#ef4444;"></i> Send Failed';
    text.textContent = message;
    openModal('emailResultModal');
}

function closeEmailResultModal() {
    closeModal('emailResultModal');
    if (reloadAfterEmailResultClose) {
        reloadAfterEmailResultClose = false;
        location.reload();
    }
}

async function confirmSendARReminder() {
    if (!pendingReminderPayload || !pendingReminderPayload.arId) return;
    closeModal('emailConfirmModal');
    openModal('emailSendingModal');
    const sendStartedAt = Date.now();
    const formData = new FormData();
    formData.append('action', 'send_ar_reminder_email');
    formData.append('ar_id', pendingReminderPayload.arId);
    formData.append('csrf_token', csrfToken);

    try {
        const response = await fetch('../api/ar_backend.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        const elapsed = Date.now() - sendStartedAt;
        const minDisplayMs = 2000;
        if (elapsed < minDisplayMs) {
            await new Promise(resolve => setTimeout(resolve, minDisplayMs - elapsed));
        }
        if (data.success) {
            reloadAfterEmailResultClose = true;
            showEmailResultModal(true, 'Reminder sent successfully via email!');
        } else {
            showEmailResultModal(false, 'Failed to send email reminder: ' + (data.error || 'Unknown error'));
        }
    } catch (error) {
        showEmailResultModal(false, 'Error sending email reminder.');
        console.error(error);
    } finally {
        pendingReminderPayload = null;
        closeModal('emailSendingModal');
    }
}

</script>
</body>
</html>
<?php // PDO doesn't need close() - resources are automatically freed ?>
