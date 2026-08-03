<?php
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/roles_helper.php';
require_once __DIR__ . '/../../includes/order_cancellation_helper.php';
require_once __DIR__ . '/../../includes/rider_availability_helper.php';
require_once __DIR__ . '/../../includes/stock_reservation_helper.php';

$management_ids = getManagementRoleIds($conn);
requireRole(empty($management_ids) ? [1] : $management_ids);
$manager_role_ids = getManagerRoleIds($conn);
$is_manager_user = in_array((int)($_SESSION['user_role'] ?? 0), $manager_role_ids, true);

// Treat only true 'owner' accounts as view-only (by Role_ID)
$is_owner_view_only = ((int)($_SESSION['user_role'] ?? 0) === 1);

// Include backend for POST handling
require_once __DIR__ . '/../../api/orders_backend.php';

$order_cancellation_reasons = getOrderCancellationReasonOptions($conn);

// Get today's date
$date_result = $conn->query("SELECT CURDATE() as today, CURTIME() as now");
$today_row = $date_result->fetch(PDO::FETCH_ASSOC);
$today_date = $today_row['today'];
$now_time = substr($today_row['now'], 0, 5); // HH:MM format

// Fetch customers for dropdown
$customers_query = "SELECT Customer_ID, customer_name, phone_number, address FROM customers WHERE deleted_at IS NULL ORDER BY customer_name";
$customers_result = $conn->query($customers_query);

// Fetch product categories (exclude soft-deleted)
$categories_query = "SELECT category_id, category_name FROM product_categories WHERE deleted_at IS NULL ORDER BY category_id";
$categories_result = $conn->query($categories_query);
$product_categories = [];
while ($cat = $categories_result->fetch(PDO::FETCH_ASSOC)) {
    $product_categories[] = $cat;
}

// Fetch products for order items
$products_data = [];
try {
    $products_query = "SELECT p.Product_ID, p.product_name, u.unit_name, p.wholesale_price, p.retail_price,
        p.product_image AS image_url, p.category_id
        FROM products p
        LEFT JOIN units u ON p.unit_id = u.unit_id
        WHERE p.is_discontinued = 0
        ORDER BY u.unit_name, p.product_name";
    $products_result = $conn->query($products_query);
    if ($products_result) {
        while ($product = $products_result->fetch(PDO::FETCH_ASSOC)) {
            $product['current_quantity'] = getAvailableStock($conn, (int)$product['Product_ID']);
            $products_data[] = $product;
        }
    }
} catch (Throwable $e) {
    error_log('orders.php products query: ' . $e->getMessage());
}

// Fetch riders for Delivery Person dropdown
$rider_role_ids = getRiderRoleIds($conn);
$order_riders = getAvailableRidersForAssignment($conn, $rider_role_ids);

// Detect orders table status column (order_status vs status)
$order_status_col = 'order_status';
$cols_res = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
if ($cols_res && $cols_res->rowCount() > 0) {
    $row = $cols_res->fetch(PDO::FETCH_ASSOC);
    $order_status_col = $row['Field'];
}

$orders_col = [];
$ocr = $conn->query('SHOW COLUMNS FROM orders');
if ($ocr) {
    while ($r = $ocr->fetch(PDO::FETCH_ASSOC)) {
        $orders_col[$r['Field']] = true;
    }
}
$addr_o_expr = !empty($orders_col['delivery_address']) ? "NULLIF(TRIM(o.delivery_address), '')" : 'NULL';
$order_created_expr = !empty($orders_col['created_at']) ? 'COALESCE(o.created_at, o.order_date)' : 'o.order_date';
$order_sort_expr = !empty($orders_col['created_at']) ? 'COALESCE(o.created_at, o.order_date)' : 'o.order_date';

// Detect whether delivery.schedule_date exists (schema drift workaround)
$deliveryDateCol = 'o.delivery_date';
$hasDeliveryScheduleDate = false;
$schColCheck = $conn->query("SHOW COLUMNS FROM delivery LIKE 'schedule_date'");
if ($schColCheck && $schColCheck->fetch()) {
    $hasDeliveryScheduleDate = true;
    $deliveryDateCol = 'd.schedule_date';
}
$deliveryDateSelect = $deliveryDateCol;
if ($hasDeliveryScheduleDate && !empty($orders_col['delivery_date'])) {
    $deliveryDateSelect = "COALESCE(NULLIF(d.schedule_date, '0000-00-00'), o.delivery_date)";
}

$riderIdSelect = 'NULL AS assigned_rider_user_id';
if (riderWorkflowHasColumn($conn, 'delivery', 'assigned_rider_id')) {
    $riderIdSelect = 'd.assigned_rider_id AS assigned_rider_user_id';
} elseif (riderWorkflowHasColumn($conn, 'delivery', 'delivered_by_user_id')) {
    $riderIdSelect = 'd.delivered_by_user_id AS assigned_rider_user_id';
}

// Fetch orders with filters
$status_filter = $_GET['status'] ?? 'active';
$status_where = '';

// Get order statistics
$order_stats = ['total' => 0, 'active' => 0, 'pending' => 0, 'scheduled' => 0, 'completed' => 0, 'cancelled' => 0];
try {
    $stats_query = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN LOWER(o.{$order_status_col}) NOT IN ('completed','cancelled','delivered (pending cash turnover)') THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN LOWER(o.{$order_status_col}) IN ('pending', 'requested', 'confirmed') THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN o.{$order_status_col} = 'Scheduled for Delivery' THEN 1 ELSE 0 END) as scheduled,
        SUM(CASE WHEN o.{$order_status_col} = 'Completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN LOWER(o.{$order_status_col}) = 'cancelled' THEN 1 ELSE 0 END) as cancelled
    FROM orders o";
    $stats_result = $conn->query($stats_query);
    if ($stats_result) {
        $order_stats = $stats_result->fetch(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    // Stats not critical, continue without them
}

// Deadline notification queries
$overdueOrders = 0;
$dueTodayOrders = 0;
$dueWeekOrders = 0;
try {
    $statusExcl = "LOWER(o.{$order_status_col}) NOT IN ('completed','cancelled','delivered (pending cash turnover)')";
    $stmt = $conn->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN delivery d ON o.Order_ID = d.Order_ID WHERE COALESCE($deliveryDateCol, o.order_date) < CURDATE() AND $statusExcl");
    $stmt->execute(); $overdueOrders = (int)$stmt->fetchColumn();
    $stmt = $conn->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN delivery d ON o.Order_ID = d.Order_ID WHERE COALESCE($deliveryDateCol, o.order_date) = CURDATE() AND $statusExcl");
    $stmt->execute(); $dueTodayOrders = (int)$stmt->fetchColumn();
    $stmt = $conn->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN delivery d ON o.Order_ID = d.Order_ID WHERE COALESCE($deliveryDateCol, o.order_date) BETWEEN CURDATE() + INTERVAL 1 DAY AND CURDATE() + INTERVAL 7 DAY AND $statusExcl");
    $stmt->execute(); $dueWeekOrders = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    $overdueOrders = 0; $dueTodayOrders = 0; $dueWeekOrders = 0;
}

// Make filter tabs functional (whitelist + special "pending")
$allowed_statuses = [
    'Requested',
    'pending',
    'Scheduled for Delivery',
    'Completed',
    'Cancelled',
    'cancelled'
];

if (!empty($status_filter) && $status_filter !== 'all') {
    if ($status_filter === 'active') {
        $status_where = "WHERE LOWER(o.{$order_status_col}) NOT IN ('completed','cancelled','delivered (pending cash turnover)')";
    } elseif ($status_filter === 'pending') {
        // Pending = Requested + pending + legacy confirmed rows
        $status_where = "WHERE LOWER(o.{$order_status_col}) IN ('pending', 'requested', 'confirmed')";
    } elseif (strtolower($status_filter) === 'cancelled') {
        // Cancelled tab should show both Cancelled/cancelled
        $status_where = "WHERE LOWER(o.{$order_status_col}) = 'cancelled'";
    } elseif ($status_filter === 'Scheduled') {
        $status_where = "WHERE o.{$order_status_col} = 'Scheduled for Delivery'";
    } elseif ($status_filter === 'Completed') {
        $status_where = "WHERE o.{$order_status_col} = ?";
        $status_params = [$status_filter];
    } elseif ($status_filter === 'missed') {
        $statusExcl = "LOWER(o.{$order_status_col}) NOT IN ('completed','cancelled','delivered (pending cash turnover)')";
        $status_where = "WHERE $deliveryDateCol IS NOT NULL AND $deliveryDateCol < CURDATE() AND $statusExcl";
    } elseif ($status_filter === 'due_today') {
        $statusExcl = "LOWER(o.{$order_status_col}) NOT IN ('completed','cancelled','delivered (pending cash turnover)')";
        $status_where = "WHERE $deliveryDateCol IS NOT NULL AND $deliveryDateCol = CURDATE() AND $statusExcl";
    } elseif ($status_filter === 'due_week') {
        $statusExcl = "LOWER(o.{$order_status_col}) NOT IN ('completed','cancelled','delivered (pending cash turnover)')";
        $status_where = "WHERE $deliveryDateCol IS NOT NULL AND $deliveryDateCol BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND $statusExcl";
    }
} else {
    // All orders (when ?status=all or no filter matches)
    $status_where = "";
}

// Search by Order ID (overrides status filter so any order can be found)
$search_id = intval($_GET['search_id'] ?? 0);
if ($search_id > 0) {
    $status_where = "WHERE o.Order_ID = ?";
    $status_params = [$search_id];
}

// Text search (order # or customer name/phone) - overrides status filter like search_id
$search_q = trim((string)($_GET['q'] ?? ''));
if ($search_q !== '' && $search_id <= 0) {
    $like = '%' . $search_q . '%';
    $status_where = "WHERE (o.Order_ID = ? OR o.customer_name_snapshot LIKE ? OR c.customer_name LIKE ? OR o.customer_phone_snapshot LIKE ?)";
    $status_params = [is_numeric($search_q) ? (int)$search_q : 0, $like, $like, $like];
}

// Pagination parameters (Performance Fix)
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = min(100, max(1, intval($_GET['per_page'] ?? 20))); // Max 100 per page
$offset = ($page - 1) * $per_page;

// Get total count for pagination (Performance Fix)
$count_query = "SELECT COUNT(*) FROM orders o LEFT JOIN delivery d ON o.Order_ID = d.Order_ID INNER JOIN customers c ON o.Customer_ID = c.Customer_ID $status_where";
if (!empty($status_params)) {
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->execute($status_params);
} else {
    $count_stmt = $conn->query($count_query);
}
$total_records = (int) $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

$orders_query = "SELECT 
    o.Order_ID,
    o.order_date,
    o.{$order_status_col} as order_status,
    o.total_amount,
    o.remarks,
    {$order_created_expr} as created_at,
    COALESCE({$addr_o_expr}, NULLIF(TRIM(d.delivery_address), ''), c.address) AS list_delivery_address,
    d.Delivery_ID,
    {$deliveryDateSelect} as delivery_date,
    d.delivery_status,
    COALESCE(o.customer_name_snapshot, c.customer_name) as customer_name,
    COALESCE(o.customer_phone_snapshot, c.phone_number) as phone_number,
    COALESCE(o.customer_address_snapshot, c.address) as customer_address,
    d.delivered_by as delivery_person_name,
    {$riderIdSelect}
FROM orders o
INNER JOIN customers c ON o.Customer_ID = c.Customer_ID
LEFT JOIN delivery d ON o.Order_ID = d.Order_ID
$status_where
ORDER BY {$order_sort_expr} DESC
LIMIT ? OFFSET ?";

// Add pagination params
if (!empty($status_params)) {
    $params = array_merge($status_params, [$per_page, $offset]);
    $stmt = $conn->prepare($orders_query);
    $stmt->execute($params);
    $orders_result = $stmt;
} else {
    $stmt = $conn->prepare($orders_query);
    $stmt->execute([$per_page, $offset]);
    $orders_result = $stmt;
}

if (!$orders_result) {
    error_log("Error fetching orders");
    $orders_result = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders - VIP Villanueva Ice Plant</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>">
    <link rel="stylesheet" href="../assets/css/orders.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/orders.css'); ?>">
    <link rel="stylesheet" href="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.css" integrity="sha384-MinO0mNliZ3vwppuPOUnGa+iq619pfMhLVUXfC4LHwSCvF9H+6P/KO4Q7qBOYV5V" crossorigin="anonymous">
    <script src="https://unpkg.com/sweetalert2@11"></script>
    <style>
        /* eGov.ph Design Language - Orders Module */
        body, .dashboard-wrapper {
            font-family: 'Inter', sans-serif !important;
        }

        /* Order Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stats-grid .stat-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 1.25rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 0.875rem;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            position: relative;
            overflow: hidden;
        }

        .stats-grid .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #7350F5, #8b6cf7, #6242E0);
        }

        .stats-grid .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(115, 80, 245, 0.15);
            border-color: #c4b5fd;
        }

        .stats-grid .stat-card.active {
            border-color: #7350F5;
            box-shadow: 0 4px 15px rgba(115, 80, 245, 0.22);
        }

        .stats-grid .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .stats-grid .stat-icon.all {
            background: linear-gradient(135deg, #7350F5 0%, #6242E0 100%);
            color: #ffffff;
        }

        .stats-grid .stat-icon.pending {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #b45309;
        }

        .stats-grid .stat-icon.scheduled {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1d4ed8;
        }

        .stats-grid .stat-icon.completed {
            background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
            color: #4338ca;
        }

        .stats-grid .stat-icon.cancelled {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #b91c1c;
        }

        .stats-grid .stat-content {
            flex: 1;
            min-width: 0;
        }

        .stats-grid .stat-content h4 {
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
            margin: 0 0 0.125rem 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stats-grid .stat-content p {
            font-size: 1.5rem;
            font-weight: 800;
            color: #1e293b;
            margin: 0;
        }

        .orders-search-form {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .orders-search-input {
            padding: 0.5rem 1rem;
            border: 2px solid #c4b5fd;
            border-radius: 10px;
            font-size: 0.875rem;
            width: 220px;
            outline: none;
            background: #faf8ff;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .orders-search-input:focus {
            border-color: #7350F5;
            box-shadow: 0 0 0 3px rgba(115, 80, 245, 0.2);
            background: #ffffff;
        }

        .orders-search-btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 10px;
            background: #7350F5;
            color: #ffffff;
            font-weight: 600;
            font-size: 0.8125rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            transition: background 0.2s ease, transform 0.2s ease;
        }

        .orders-search-btn:hover {
            background: #6242E0;
            transform: translateY(-1px);
        }

        .orders-search-clear {
            padding: 0.5rem 1rem;
            border-radius: 10px;
            font-size: 0.8125rem;
            font-weight: 600;
            color: #7350F5;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }

        /* Header Banner — brand accent */
        .page-header-banner {
            background: #7350F5;
            border-radius: 24px;
            padding: 2rem;
            color: #ffffff;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            box-shadow: 0 20px 40px rgba(115, 80, 245, 0.28);
            position: relative;
            overflow: hidden;
        }

        .page-header-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -5%;
            width: 280px;
            height: 280px;
            background: rgba(255,255,255,0.06);
            border-radius: 50%;
            pointer-events: none;
        }

        .page-header-banner::after {
            content: '';
            position: absolute;
            bottom: -60%;
            left: 5%;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.04);
            border-radius: 50%;
            pointer-events: none;
        }

        .header-content h1 {
            font-size: 1.65rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
            z-index: 1;
            letter-spacing: -0.02em;
        }

        .header-content p {
            font-size: 0.9rem;
            opacity: 0.85;
            margin: 0.4rem 0 0 0;
            position: relative;
            z-index: 1;
        }

        .btn-add-new {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.35rem;
            background: white;
            color: #0038A8;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
            position: relative;
            z-index: 1;
        }

        .btn-add-new:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
            background: #f0f6ff;
        }
    </style>
    <?php echo csrfBootstrapTags(); ?>
</head>
<body>
<div class="dashboard-wrapper">
    <!-- Sidebar -->
    <?php
    require_once __DIR__ . '/../../includes/sidebar.php';
    renderSidebar($conn, ['base' => '../', 'active' => 'orders']);
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
                <a href="orders.php" class="menu-item active">
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
                    <span class="menu-item-badge">3</span>
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
    <main class="main-content" style="padding: 2rem;">
        <!-- Header Banner -->
        <div class="page-header-banner">
            <div class="header-content">
                <h1><i class="fas fa-shopping-cart"></i> Order Management</h1>
                <p>Manage customer orders from phone calls through delivery completion.</p>
            </div>
            <?php if (!$is_owner_view_only): ?>
            <button onclick="showCreateOrderModal()" class="btn-add-new">
                <i class="fas fa-plus"></i> New Order
            </button>
            <?php endif; ?>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_GET['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div id="order-error-data" style="display:none;"><?php echo htmlspecialchars($_GET['error']); ?></div>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    var errMsg = document.getElementById('order-error-data');
                    if (errMsg) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Order Blocked',
                            text: errMsg.textContent,
                            confirmButtonColor: '#ef4444',
                            confirmButtonText: 'OK'
                        }).then(function() {
                            window.history.replaceState({}, document.title, window.location.pathname);
                        });
                    }
                });
            </script>
        <?php endif; ?>

        <!-- Order Stats Cards -->
        <div class="stats-grid">
            <a href="orders.php?status=active" class="stat-card <?php echo $status_filter === 'active' ? 'active' : ''; ?>">
                <div class="stat-icon" style="background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); color: white; box-shadow: 0 8px 20px rgba(99,102,241,0.3);">
                    <i class="fas fa-play-circle"></i>
                </div>
                <div class="stat-content">
                    <h4>Active</h4>
                    <p><?php echo $order_stats['active'] ?? 0; ?></p>
                </div>
            </a>
            <a href="orders.php?status=all" class="stat-card <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
                <div class="stat-icon all">
                    <i class="fas fa-shopping-bag"></i>
                </div>
                <div class="stat-content">
                    <h4>All Orders</h4>
                    <p><?php echo $order_stats['total'] ?? 0; ?></p>
                </div>
            </a>
            <a href="orders.php?status=pending" class="stat-card <?php echo $status_filter === 'pending' ? 'active' : ''; ?>">
                <div class="stat-icon pending">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-content">
                    <h4>Pending</h4>
                    <p><?php echo $order_stats['pending'] ?? 0; ?></p>
                </div>
            </a>
            <a href="orders.php?status=Scheduled" class="stat-card <?php echo $status_filter === 'Scheduled' ? 'active' : ''; ?>">
                <div class="stat-icon scheduled">
                    <i class="fas fa-calendar"></i>
                </div>
                <div class="stat-content">
                    <h4>Scheduled</h4>
                    <p><?php echo $order_stats['scheduled'] ?? 0; ?></p>
                </div>
            </a>
            <a href="orders.php?status=missed" class="stat-card <?php echo $status_filter === 'missed' ? 'active' : ''; ?>">
                <div class="stat-icon" style="background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); color: white; box-shadow: 0 8px 20px rgba(220, 38, 38, 0.3);">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-content">
                    <h4>Missed</h4>
                    <p><?php echo $overdueOrders; ?></p>
                </div>
            </a>
            <a href="orders.php?status=Completed" class="stat-card <?php echo $status_filter === 'Completed' ? 'active' : ''; ?>">
                <div class="stat-icon completed">
                    <i class="fas fa-check-double"></i>
                </div>
                <div class="stat-content">
                    <h4>Completed</h4>
                    <p><?php echo $order_stats['completed'] ?? 0; ?></p>
                </div>
            </a>
            <a href="orders.php?status=Cancelled" class="stat-card <?php echo strtolower($status_filter) === 'cancelled' ? 'active' : ''; ?>">
                <div class="stat-icon cancelled">
                    <i class="fas fa-times"></i>
                </div>
                <div class="stat-content">
                    <h4>Cancelled</h4>
                    <p><?php echo $order_stats['cancelled'] ?? 0; ?></p>
                </div>
            </a>
        </div>

        <!-- Order Deadline Notification Cards -->
        <?php if ($overdueOrders > 0 || $dueTodayOrders > 0 || $dueWeekOrders > 0): ?>
        <div class="order-deadline-alerts" style="display:flex;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
            <?php if ($overdueOrders > 0): ?>
            <a href="orders.php?status=missed" style="flex:1;min-width:200px;background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:1rem;display:flex;align-items:center;gap:0.75rem;text-decoration:none;cursor:pointer;transition:box-shadow .2s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(220,38,38,0.2)'" onmouseout="this.style.boxShadow='none'">
                <span style="width:40px;height:40px;border-radius:10px;background:#fee2e2;display:flex;align-items:center;justify-content:center;color:#dc2626;font-size:1.25rem;flex-shrink:0;"><i class="fas fa-exclamation-triangle"></i></span>
                <div>
                    <strong style="font-size:0.875rem;color:#991b1b;"><?php echo $overdueOrders; ?> Overdue Order<?php echo $overdueOrders === 1 ? '' : 's'; ?></strong>
                    <p style="font-size:0.75rem;color:#b91c1c;margin:0;">Past delivery deadline</p>
                </div>
            </a>
            <?php endif; ?>
            <?php if ($dueTodayOrders > 0): ?>
            <a href="orders.php?status=due_today" style="flex:1;min-width:200px;background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:1rem;display:flex;align-items:center;gap:0.75rem;text-decoration:none;cursor:pointer;transition:box-shadow .2s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(217,119,6,0.2)'" onmouseout="this.style.boxShadow='none'">
                <span style="width:40px;height:40px;border-radius:10px;background:#fef3c7;display:flex;align-items:center;justify-content:center;color:#d97706;font-size:1.25rem;flex-shrink:0;"><i class="fas fa-clock"></i></span>
                <div>
                    <strong style="font-size:0.875rem;color:#92400e;"><?php echo $dueTodayOrders; ?> Due Today</strong>
                    <p style="font-size:0.75rem;color:#a16207;margin:0;">Needs delivery today</p>
                </div>
            </a>
            <?php endif; ?>
            <?php if ($dueWeekOrders > 0): ?>
            <a href="orders.php?status=due_week" style="flex:1;min-width:200px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:1rem;display:flex;align-items:center;gap:0.75rem;text-decoration:none;cursor:pointer;transition:box-shadow .2s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(37,99,235,0.2)'" onmouseout="this.style.boxShadow='none'">
                <span style="width:40px;height:40px;border-radius:10px;background:#dbeafe;display:flex;align-items:center;justify-content:center;color:#2563eb;font-size:1.25rem;flex-shrink:0;"><i class="fas fa-calendar-alt"></i></span>
                <div>
                    <strong style="font-size:0.875rem;color:#1e40af;"><?php echo $dueWeekOrders; ?> Due This Week</strong>
                    <p style="font-size:0.75rem;color:#1d4ed8;margin:0;">Scheduled within 7 days</p>
                </div>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Search by Order ID or Customer -->
        <form method="GET" class="orders-search-form">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
            <input type="hidden" name="page" value="1">
            <input type="text" name="q" class="orders-search-input" placeholder="Search order #, customer name or phone..."
                   value="<?php echo $search_q !== '' ? htmlspecialchars($search_q, ENT_QUOTES, 'UTF-8') : ($search_id > 0 ? $search_id : ''); ?>">
            <button type="submit" class="orders-search-btn">
                <i class="fas fa-search"></i> Search
            </button>
            <?php if ($search_q !== '' || $search_id > 0): ?>
                <a href="?status=<?php echo urlencode($status_filter); ?>" class="orders-search-clear">
                    <i class="fas fa-times"></i> Clear
                </a>
            <?php endif; ?>
        </form>

            <!-- Orders List -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-list"></i> Orders List</h3>
                </div>
                <div class="card-body">
                    <?php if ($orders_result && $orders_result->rowCount() > 0): ?>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Order #</th>
                                        <th>Customer</th>
                                        <th>Delivery Address</th>
                                        <th>Order Date</th>
                                        <th>Delivery Date</th>
                                        <th>Status</th>
                                        <th>Total Amount</th>
                                        <th>Delivery Person</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($order = $orders_result->fetch(PDO::FETCH_ASSOC)): 
                                        // Generate status class - handle both old and new status formats
                                        $status_for_class = strtolower($order['order_status']);
                                        $status_for_class = str_replace([' ', '(', ')', 'pendingcash', 'turnover'], ['', '', '', '', ''], $status_for_class);
                                        $status_class = 'status-' . $status_for_class;
                                        // Overdue flag
                                        $is_overdue = false;
                                        if (!empty($order['delivery_date']) && strtotime($order['delivery_date']) < strtotime('today')) {
                                            $status_lower = strtolower($order['order_status']);
                                            if (!in_array($status_lower, ['completed', 'cancelled', 'delivered', 'delivered (pending cash turnover)'])) {
                                                $is_overdue = true;
                                            }
                                        }
                                    ?>
                                        <tr <?php echo $is_overdue ? 'style="background:#fef2f2;border-left:4px solid #dc2626;"' : ''; ?>>
                                            <td><strong>#<?php echo $order['Order_ID']; ?></strong></td>
                                            <td>
                                                <?php echo htmlspecialchars($order['customer_name']); ?><br>
                                                <small style="color: #64748b;"><?php echo htmlspecialchars($order['phone_number']); ?></small>
                                            </td>
                                            <td style="max-width: 320px;">
                                                <?php
                                                $addr = trim((string)($order['list_delivery_address'] ?? ''));
                                                if ($addr === '') {
                                                    echo '<span style="color: #94a3b8;">—</span>';
                                                } else {
                                                    $short = strlen($addr) > 52 ? substr($addr, 0, 49) . '…' : $addr;
                                                    echo '<span title="' . htmlspecialchars($addr) . '">' . htmlspecialchars($short) . '</span>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php echo date('M d, Y', strtotime($order['order_date'])); ?>
                                            </td>
            <td>
                <?php if (!empty($order['delivery_date'])): ?>
                    <?php echo date('M d, Y', strtotime($order['delivery_date'])); ?>
                    <?php if ($is_overdue): ?>
                        <br><span style="display:inline-flex;align-items:center;gap:0.25rem;margin-top:0.25rem;font-size:0.65rem;font-weight:700;color:#dc2626;background:#fee2e2;padding:0.15rem 0.45rem;border-radius:4px;"><i class="fas fa-exclamation-circle"></i> MISSED</span>
                    <?php endif; ?>
                <?php else: ?>
                    <span style="color: #94a3b8;">Not scheduled</span>
                <?php endif; ?>
            </td>
                                            <td>
                                                <?php
                                                $orderStatusColors = [
                                                    'pending' => ['bg' => '#f3f4f6', 'color' => '#374151', 'border' => '#d1d5db', 'icon' => 'fa-clock'],
                                                    'requested' => ['bg' => '#f3f4f6', 'color' => '#374151', 'border' => '#d1d5db', 'icon' => 'fa-clock'],
                                                    'scheduled' => ['bg' => '#e0e7ff', 'color' => '#3730a3', 'border' => '#a5b4fc', 'icon' => 'fa-calendar'],
                                                    'scheduledfordelivery' => ['bg' => '#e0e7ff', 'color' => '#3730a3', 'border' => '#a5b4fc', 'icon' => 'fa-calendar'],
                                                    'outfordelivery' => ['bg' => '#fce7f3', 'color' => '#9f1239', 'border' => '#f9a8d4', 'icon' => 'fa-truck'],
                                                    'delivered' => ['bg' => '#fef3c7', 'color' => '#92400e', 'border' => '#fcd34d', 'icon' => 'fa-box'],
                                                    'deliveredpendingcash' => ['bg' => '#fef3c7', 'color' => '#92400e', 'border' => '#fcd34d', 'icon' => 'fa-box'],
                                                    'completed' => ['bg' => '#d1fae5', 'color' => '#065f46', 'border' => '#6ee7b7', 'icon' => 'fa-check-double'],
                                                    'cancelled' => ['bg' => '#fee2e2', 'color' => '#991b1b', 'border' => '#fca5a5', 'icon' => 'fa-times-circle']
                                                ];
                                                $orderStatusKey = str_replace([' ', '(', ')', 'pendingcash', 'turnover'], ['', '', '', '', ''], strtolower($order['order_status']));
                                                $orderColors = $orderStatusColors[$orderStatusKey] ?? $orderStatusColors['pending'];
                                                ?>
                                                <span style="display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.5rem 0.875rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.025em; background: <?php echo $orderColors['bg']; ?>; color: <?php echo $orderColors['color']; ?>; border: 1px solid <?php echo $orderColors['border']; ?>;">
                                                    <i class="fas <?php echo $orderColors['icon']; ?>"></i> <?php echo htmlspecialchars($order['order_status']); ?>
                                                </span>
                                            </td>
                                            <td><strong>₱<?php echo number_format($order['total_amount'], 2); ?></strong></td>
                                            <td>
                                                <?php if (!empty($order['delivery_person_name'])): ?>
                                                    <span><?php echo htmlspecialchars($order['delivery_person_name']); ?></span>
                                                <?php else: ?>
                                                    <span style="color: #94a3b8;">Not assigned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="white-space: nowrap;">
                                                <div class="action-group">
                                                    <button onclick="viewOrderDetails(<?php echo $order['Order_ID']; ?>)" title="View Details" class="table-action-btn table-action-btn-view">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if (!hasRole(1)): ?>
                                                        <?php
                                                        $status_lower = strtolower(trim((string)$order['order_status']));
                                                        $is_completed = ($status_lower === 'completed');
                                                        $is_cancelled = ($status_lower === 'cancelled');
                                                        $is_pending = in_array($status_lower, ['pending', 'requested'], true);
                                                        $is_schedule_ready = in_array($status_lower, ['pending', 'requested', 'confirmed'], true);
                                                        $is_scheduled = ($status_lower === 'scheduled for delivery');
                                                        $is_delivered = in_array($status_lower, ['delivered (pending cash turnover)', 'delivered'], true);
                                                        ?>
                                                        <?php if (!$is_completed && !$is_cancelled): ?>
                                                            <button type="button" title="Edit Order" data-order-id="<?php echo intval($order['Order_ID']); ?>" onclick="openEditOrder(<?php echo intval($order['Order_ID']); ?>)" class="table-action-btn table-action-btn-edit" <?php echo !$is_schedule_ready ? 'disabled' : ''; ?>>
                                                                <i class="fas fa-pen"></i>
                                                            </button>
                                                            <?php
                                                            $schedule_date_raw = '';
                                                            if (!empty($order['delivery_date']) && strtotime((string)$order['delivery_date']) !== false) {
                                                                $schedule_date_raw = date('Y-m-d', strtotime((string)$order['delivery_date']));
                                                            }
                                                            $schedule_rider_id = (int)($order['assigned_rider_user_id'] ?? 0);
                                                            ?>
                                                            <button title="Mark as Scheduled for Delivery" type="button"
                                                                data-order-id="<?php echo intval($order['Order_ID']); ?>"
                                                                data-delivery-date="<?php echo htmlspecialchars($schedule_date_raw, ENT_QUOTES, 'UTF-8'); ?>"
                                                                data-rider-id="<?php echo $schedule_rider_id; ?>"
                                                                data-rider-name="<?php echo htmlspecialchars((string)($order['delivery_person_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                                data-notes="<?php echo htmlspecialchars((string)($order['remarks'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                                class="table-action-btn table-action-btn-label table-action-btn-schedule mark-scheduled-btn" <?php echo !$is_schedule_ready ? 'disabled' : ''; ?>>
                                                                <i class="fas fa-check-square"></i> Scheduled
                                                            </button>
                                                        <?php endif; ?>
                                                        <?php if ($is_delivered && !$is_completed): ?>
                                                            <a href="sales.php?delivery_order_id=<?php echo $order['Order_ID']; ?>" title="Record Sale (Payment Received)" class="table-action-btn table-action-btn-label table-action-btn-sale">
                                                                <i class="fas fa-money-bill-wave"></i> Record Sale
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if ($is_manager_user && !$is_completed && !$is_cancelled): ?>
                                                            <button onclick="cancelOrder(<?php echo $order['Order_ID']; ?>)" title="Cancel Order" class="table-action-btn table-action-btn-cancel">
                                                                <i class="fas fa-times"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination Controls (Performance Fix) -->
                        <?php if ($total_pages > 1): ?>
                        <div class="pagination" style="display: flex; justify-content: center; align-items: center; gap: 0.5rem; padding: 1.5rem; border-top: 1px solid #e2e8f0; background: white; border-radius: 0 0 12px 12px;">
                            <span style="color: #64748b; font-size: 0.875rem;">Showing <?php echo (($page - 1) * $per_page) + 1; ?> - <?php echo min($page * $per_page, $total_records); ?> of <?php echo $total_records; ?> orders</span>
                            
                            <div style="display: flex; gap: 0.25rem;">
                                <!-- Previous Page -->
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?php echo $page - 1; ?>&per_page=<?php echo $per_page; ?>&status=<?php echo urlencode($status_filter); ?>" 
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
                                    <a href="?page=1&per_page=<?php echo $per_page; ?>&status=<?php echo urlencode($status_filter); ?>" 
                                       class="pagination-btn" 
                                       style="padding: 0.5rem 0.75rem; border: 1px solid #e2e8f0; border-radius: 6px; color: #475569; text-decoration: none; font-size: 0.875rem;">1</a>
                                    <?php if ($start_page > 2): ?>
                                        <span style="padding: 0.5rem 0.25rem; color: #94a3b8;">...</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <?php if ($i == $page): ?>
                                        <span class="pagination-btn active" 
                                              style="padding: 0.5rem 0.75rem; border: 1px solid #0038A8; border-radius: 6px; background: #0038A8; color: white; font-size: 0.875rem; font-weight: 500;"><?php echo $i; ?></span>
                                    <?php else: ?>
                                        <a href="?page=<?php echo $i; ?>&per_page=<?php echo $per_page; ?>&status=<?php echo urlencode($status_filter); ?>" 
                                           class="pagination-btn" 
                                           style="padding: 0.5rem 0.75rem; border: 1px solid #e2e8f0; border-radius: 6px; color: #475569; text-decoration: none; font-size: 0.875rem;"><?php echo $i; ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                
                                <?php if ($end_page < $total_pages): ?>
                                    <?php if ($end_page < $total_pages - 1): ?>
                                        <span style="padding: 0.5rem 0.25rem; color: #94a3b8;">...</span>
                                    <?php endif; ?>
                                    <a href="?page=<?php echo $total_pages; ?>&per_page=<?php echo $per_page; ?>&status=<?php echo urlencode($status_filter); ?>" 
                                       class="pagination-btn" 
                                       style="padding: 0.5rem 0.75rem; border: 1px solid #e2e8f0; border-radius: 6px; color: #475569; text-decoration: none; font-size: 0.875rem;"><?php echo $total_pages; ?></a>
                                <?php endif; ?>
                                
                                <!-- Next Page -->
                                <?php if ($page < $total_pages): ?>
                                    <a href="?page=<?php echo $page + 1; ?>&per_page=<?php echo $per_page; ?>&status=<?php echo urlencode($status_filter); ?>" 
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
                                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
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
                    <?php else: ?>
                        <div style="text-align: center; padding: 3rem; color: #64748b;">
                            <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.3;"></i>
                            <p style="margin-top: 1rem;">No orders found.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Create Order Modal -->
<div id="createOrderModal" class="create-order-modal" style="display: none;">
    <div class="create-order-panel-pos" role="dialog" aria-labelledby="createOrderTitle" aria-modal="true">
        <header class="create-order-header">
            <div class="create-order-header-text">
                <h2 id="createOrderTitle"><i class="fas fa-file-invoice"></i> New delivery order</h2>
                <p class="create-order-sub">Place a customer order · <strong>Cash on delivery</strong> · <span style="color: #ef4444; font-weight: 700;"><i class="fas fa-truck"></i> Min. 10 items for delivery</span></p>
            </div>
            <button type="button" class="create-order-close" onclick="closeCreateOrderModal()" aria-label="Close">&times;</button>
        </header>

        <div class="pos-layout-wrapper">
            <!-- Left Side: Product Catalog -->
            <div class="pos-catalog-side">
                <div class="catalog-controls">
                    <div class="catalog-search">
                        <i class="fas fa-search"></i>
                        <input type="text" id="catalogSearchInput" placeholder="Search products..." oninput="filterCatalog()">
                    </div>
                    <div class="catalog-filters">
                        <button type="button" class="catalog-filter-tab active" data-category-id="0" onclick="setCatalogFilter(0, this)">
                            <i class="fas fa-th-large"></i> All Items
                        </button>
                        <?php foreach ($product_categories as $cat): ?>
                            <?php if (intval($cat['category_id']) === 1) continue; ?>
                            <button type="button" class="catalog-filter-tab" data-category-id="<?php echo intval($cat['category_id']); ?>" onclick="setCatalogFilter(<?php echo intval($cat['category_id']); ?>, this)">
                                <?php echo htmlspecialchars($cat['category_name']); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="catalog-grid" id="productCatalog">
                    <!-- Products will be rendered here via JS -->
                </div>
            </div>

            <!-- Right Side: Order Cart -->
            <div class="pos-cart-side">
                <form id="createOrderForm" method="POST" class="pos-cart-form">
                    <?php echo csrfTokenField(); ?>
                    <input type="hidden" name="action" value="create_order">
                    
                    <div class="cart-steps-scroll">
                        <div class="cart-step-group">
                            <h3 class="cart-step-title"><span class="step-num">1</span> Customer Details</h3>
                            <div class="cart-field">
                                <div class="cart-field-head">
                                    <label for="customer_id">Customer <span class="co-req">*</span></label>
                                    <button type="button" id="loadLastOrderBtn" class="btn btn-secondary btn-sm customer-last-order-btn" onclick="loadLastOrderForSelectedCustomer()" disabled>
                                        <i class="fas fa-rotate-left"></i> Load Last Order
                                    </button>
                                </div>
                                <select id="customer_id" name="customer_id" required onchange="onCustomerChange(this); checkCustomerCredit(this.value);" class="pos-input">
                                    <option value="">Select customer…</option>
                                    <?php
                                    $customers_dropdown_result = $conn->query($customers_query);
                                    while ($customer = $customers_dropdown_result->fetch(PDO::FETCH_ASSOC)):
                                    ?>
                                        <option value="<?php echo $customer['Customer_ID']; ?>">
                                            <?php echo htmlspecialchars($customer['customer_name'] . ' · ' . $customer['phone_number']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                                <div id="lastOrderHint" class="customer-last-order-hint">Select a customer to load their latest non-cancelled order items.</div>
                                <div id="credit_warning" class="co-credit-warning" style="display: none;">
                                    <i class="fas fa-exclamation-triangle"></i> <span id="credit_warning_msg"></span>
                                </div>
                            </div>
                        </div>

                        <div class="cart-step-group">
                            <h3 class="cart-step-title"><span class="step-num">2</span> Schedule &amp; Rider</h3>
                            <div class="cart-field-grid">
                                <div class="cart-field">
                                    <label for="order_date">Order Date</label>
                                    <input type="date" id="order_date" name="order_date" required value="<?php echo $today_date; ?>" class="pos-input">
                                </div>
                                <div class="cart-field">
                                    <label for="delivery_date">Pref. Delivery</label>
                                    <input type="date" id="delivery_date" name="delivery_date" class="pos-input">
                                </div>
                            </div>
                            <div class="cart-field">
                                <label for="delivery_person">Assign Rider</label>
                                <select id="delivery_person" name="delivery_person" class="pos-input">
                                    <option value="">Later / unassigned</option>
                                    <?php foreach ($order_riders as $r): ?>
                                    <option value="<?php echo (int)$r['User_ID']; ?>"><?php echo htmlspecialchars($r['name'] ?? 'User #' . $r['User_ID']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="cart-field" style="margin-top: 4px;">
                                <label style="display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; cursor: pointer;">
                                    <input type="checkbox" id="is_ar" name="is_ar" value="1" style="width: 18px; height: 18px;">
                                    <i class="fas fa-file-invoice-dollar" style="color: #0038A8; font-size: 1rem;"></i>
                                    <strong style="font-size: 0.85rem; color: #0f172a;">Account Receivable</strong>
                                </label>
                                <div style="font-size: 0.75rem; color: #64748b; margin-top: 4px; margin-left: 28px;">
                                    Check if the customer pays on credit. Rider will be notified.
                                </div>
                            </div>
                        </div>

                        <div class="cart-step-group cart-items-group">
                            <h3 class="cart-step-title"><span class="step-num">3</span> Current Order <span id="cartCount" class="step-num" style="background:var(--pos-border); color:var(--pos-text-mute); margin-left:auto; width:auto; padding:0 8px;">0 items</span></h3>
                            <div class="cart-items-list" id="cartItemsContainer">
                                <!-- Selected items will go here -->
                                <div class="cart-empty-msg">No items in cart</div>
                            </div>
                        </div>
                    </div><!-- end cart-steps-scroll -->

                    <div class="cart-summary-section">
                        <div class="summary-line">
                            <span>Subtotal</span>
                            <span id="co-live-subtotal">₱0.00</span>
                        </div>
                        <input type="hidden" id="discount_amount" name="discount_amount" value="0">
                        <div class="summary-line grand-total">
                            <span>Total Amount Due</span>
                            <span id="co-live-grand">₱0.00</span>
                        </div>
                    </div>

                    <div class="pos-footer-actions">
                        <button type="button" onclick="closeCreateOrderModal()" class="btn-cancel-pos" title="Cancel Order">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" id="submitOrderBtn" class="btn btn-primary pos-confirm-btn">
                            <i class="fas fa-save"></i> Save Order
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>



<!-- Receipt Modal View -->
<div id="receiptModal" class="receipt-modal" style="display: none;">
    <div class="receipt-paper">
        <header class="receipt-header">
            <h3>VIP VILLANUEVA ICE PLANT</h3>
            <p>San Jose, Antique</p>
            <div class="receipt-divider"></div>
            <p id="receiptDate"></p>
            <h4 id="receiptCustomer"></h4>
        </header>
        <div class="receipt-body">
            <table class="receipt-table">
                <thead>
                    <tr>
                        <th class="text-left">ITEM</th>
                        <th class="text-right">QTY</th>
                        <th class="text-right">PRICE</th>
                        <th class="text-right">TOTAL</th>
                    </tr>
                </thead>
                <tbody id="receiptItems">
                    <!-- Items -->
                </tbody>
            </table>
            <div class="receipt-divider"></div>
            <div class="receipt-totals">
                <div class="total-row"><span>SUBTOTAL</span><span id="receiptSubtotal"></span></div>
                <div class="total-row grand"><span>GRAND TOTAL</span><span id="receiptGrand"></span></div>
            </div>
            <div class="receipt-divider"></div>
            <p class="receipt-footer">THIS IS NOT AN OFFICIAL RECEIPT.<br>Thank you for your order!</p>
        </div>
        <button type="button" class="btn-close-receipt" onclick="closeReceiptPreview()">Close Preview</button>
    </div>
</div>

<script>window.ORDERS_PRODUCT_IMAGE_BASE = '../uploads/products/';</script>
<script src="../assets/js/script.js?v=<?php echo time(); ?>"></script>
<script src="../assets/js/orders.js?v=<?php echo time(); ?>"></script>
<script src="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.js" integrity="sha384-SYKAG6cglRMN0RVvhNeBY0r3FYKNOJtznwA0v7B5Vp9tr31xAHsZC0DqkQ/pZDmj" crossorigin="anonymous"></script>
<script>
    // Pass PHP data to JavaScript AFTER orders.js is loaded
    <?php 
    // Ensure products_data is always an array and properly encoded
    if (!isset($products_data) || !is_array($products_data)) {
        $products_data = [];
    }
    // Clean the data to ensure valid JSON
    $clean_products = [];
    foreach ($products_data as $product) {
        $clean_products[] = [
            'Product_ID' => intval($product['Product_ID'] ?? 0),
            'product_name' => $product['product_name'] ?? '',
            'unit' => $product['unit_name'] ?? '',
            'wholesale_price' => floatval($product['wholesale_price'] ?? 0),
            'retail_price' => floatval($product['retail_price'] ?? 0),
            'current_quantity' => floatval($product['current_quantity'] ?? 0),
            'category_id' => intval($product['category_id'] ?? 0),
            'image_url' => $product['image_url'] ?? null
        ];
    }
    $json_products = json_encode($clean_products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    if ($json_products === false) {
        $json_products = '[]'; // Fallback to empty array if encoding fails
        error_log("JSON encoding error: " . json_last_error_msg());
    }
    // Pass riders data to JavaScript
    $clean_riders = [];
    foreach ($order_riders as $r) {
        $clean_riders[] = [
            'User_ID' => intval($r['User_ID'] ?? 0),
            'name' => $r['name'] ?? 'User #' . ($r['User_ID'] ?? 0)
        ];
    }
    $json_riders = json_encode($clean_riders, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    if ($json_riders === false) {
        $json_riders = '[]';
    }
    ?>
    const productsData = <?php echo $json_products; ?>;
    const ridersData = <?php echo $json_riders; ?>;
    window.orderCancellationReasons = <?php echo json_encode(array_values($order_cancellation_reasons), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
    
    // Debug: Log if productsData is loaded correctly
    console.log('Products data loaded:', productsData.length, 'products');
    console.log('Riders data loaded:', ridersData.length, 'riders');
    
    async function checkCustomerCredit(customerId) {
        const warningDiv = document.getElementById('credit_warning');
        const warningMsg = document.getElementById('credit_warning_msg');
        const submitBtn = document.getElementById('submitOrderBtn');
        
        // Flags for submission logic
        window.customerHasBalance = false;
        window.customerRemainingBalance = 0;
        window.customerIsOverLimit = false;
        window.customerAvailableCredit = 0;
        
        if (!customerId) {
            warningDiv.style.display = 'none';
            submitBtn.disabled = false;
            return;
        }
        
        try {
            const response = await fetch(`../api/get_customer_credit.php?customer_id=${customerId}`);
            const result = await response.json();
            
            if (result.success) {
                const unpaid = parseFloat(result.data.total_unpaid || 0);
                const creditLimit = parseFloat(result.data.credit_limit || 0);
                window.customerRemainingBalance = unpaid;
                window.customerHasBalance = unpaid > 0;
                window.customerIsOverLimit = result.data.is_over_limit;
                window.customerAvailableCredit = parseFloat(result.data.available_credit || 0);
                
                if (unpaid > 0) {
                    let msg = `Customer has a remaining balance of ₱${unpaid.toLocaleString(undefined, {minimumFractionDigits: 2})}.`;
                    if (result.data.is_over_limit) {
                        msg += ` Credit limit fully used. Orders allowed but consider Cash-Only terms.`;
                        warningDiv.style.backgroundColor = '#fef2f2';
                        warningDiv.style.color = '#991b1b';
                        warningDiv.style.borderColor = '#fecaca';
                    } else {
                        const available = parseFloat(result.data.available_credit || 0);
                        msg += ` Available credit: ₱${available.toLocaleString(undefined, {minimumFractionDigits: 2})}.`;
                        warningDiv.style.backgroundColor = '#fff7ed';
                        warningDiv.style.color = '#9a3412';
                        warningDiv.style.borderColor = '#fed7aa';
                    }
                    submitBtn.disabled = false;
                    submitBtn.title = "";
                    warningMsg.textContent = msg;
                    warningDiv.style.display = 'block';
                } else {
                    warningDiv.style.display = 'none';
                    submitBtn.disabled = false;
                    submitBtn.title = "";
                }
            } else {
                warningDiv.style.display = 'none';
                submitBtn.disabled = false;
            }
        } catch (error) {
            console.error('Error checking customer credit:', error);
        }
    }

    // Attach event listeners to update status buttons using data attributes
    // Wait for both DOM and orders.js to be loaded
    (function() {
        function attachUpdateStatusListeners() {
            if (typeof updateOrderStatus === 'function') {
                // Update Status buttons
                document.querySelectorAll('.update-status-btn').forEach(function(button) {
                    // Remove any existing listeners
                    const newButton = button.cloneNode(true);
                    button.parentNode.replaceChild(newButton, button);
                    
                    newButton.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        const orderId = parseInt(this.getAttribute('data-order-id'));
                        const status = this.getAttribute('data-status');
                        console.log('Button clicked:', { orderId, status });
                        if (orderId && status) {
                            updateOrderStatus(orderId, status);
                        } else {
                            console.error('Missing data attributes:', { orderId, status });
                            alert('Error: Missing order information. Please refresh the page.');
                        }
                    });
                });
                console.log('Update status button listeners attached');
            } else {
                // Retry after a short delay if updateOrderStatus is not yet defined
                setTimeout(attachUpdateStatusListeners, 100);
            }
        }
        
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', attachUpdateStatusListeners);
        } else {
            attachUpdateStatusListeners();
        }
    })();


</script>

</body>
</html>
<?php
// PDO doesn't need free() or close() - resources are automatically freed
?>
