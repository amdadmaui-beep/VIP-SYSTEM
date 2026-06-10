<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/roles_helper.php';
require_once __DIR__ . '/../../includes/rider_availability_helper.php';
require_once __DIR__ . '/../../includes/delivery_cancellation_helper.php';
require_once __DIR__ . '/../../includes/order_cancellation_helper.php';
require_once __DIR__ . '/../../includes/preparation_tasks_helper.php';

// Accessible to Owner and Manager roles (from DB)
$dashboard_ids = getDashboardRoleIds($conn);
requireRole(empty($dashboard_ids) ? [1] : $dashboard_ids);

// Only riders can set status to Delivered
$rider_ids = getRiderRoleIds($conn);
$can_confirm_delivered = in_array($_SESSION['user_role'] ?? 0, $rider_ids);

// Treat only true 'owner' accounts as view-only (by Role_ID)
$is_owner_view_only = ((int)($_SESSION['user_role'] ?? 0) === 1);

// Include backend for POST handling
require_once __DIR__ . '/../../api/delivery_backend.php';
ensureRiderWorkflowSchema($conn);
prepTasksEnsureSchema($conn);

// Fetch riders for Delivery Person dropdown and status board
$rider_status_rows = getRiderAvailabilityRows($conn, $rider_ids);
$riders = getAvailableRidersForAssignment($conn, $rider_ids);
$rider_lookup = [];
$rider_groups = [
    'Available' => [],
    'On Delivery' => [],
    'Off Duty' => []
];
foreach ($rider_status_rows as $rider_row) {
    $rider_id = (int)($rider_row['User_ID'] ?? 0);
    if ($rider_id > 0) {
        $rider_lookup[$rider_id] = $rider_row;
    }
    $group_key = (string)($rider_row['rider_availability_status'] ?? 'Available');
    if (!isset($rider_groups[$group_key])) {
        $rider_groups[$group_key] = [];
    }
    $rider_groups[$group_key][] = $rider_row;
}
$available_rider_options = array_map(static function (array $row): array {
    $load = (int)($row['active_delivery_count'] ?? 0);
    return [
        'User_ID' => (int)($row['User_ID'] ?? 0),
        'name' => (string)($row['name'] ?? ('User #' . (int)($row['User_ID'] ?? 0))),
        'active_delivery_count' => $load,
    ];
}, $riders);
$delivery_cancellation_reasons = getDeliveryCancellationReasonOptions($conn);
$manager_cancel_reasons = getManagerCancellationReasons();

// Detect orders table status column (order_status vs status)
$order_status_col = 'order_status';
$cols_res = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
if ($cols_res && $cols_res->rowCount() > 0) {
    $row = $cols_res->fetch(PDO::FETCH_ASSOC);
    $order_status_col = $row['Field'];
}

// Fetch deliveries with filters
$status_filter = $_GET['status'] ?? '';
$status_where = '';
$status_params = [];

$allowed_statuses = [
    'Scheduled',
    'In Transit',
    'Delivered',
    'Returning',
    'Completed',
    'Cancelled'
];

if (!empty($status_filter) && $status_filter !== 'all') {
    if (in_array($status_filter, $allowed_statuses, true)) {
        if ($status_filter === 'Returning') {
            $status_where = "WHERE d.delivery_status = 'Returning'";
        } elseif ($status_filter === 'Cancelled') {
            $status_where = "WHERE d.delivery_status = 'Cancelled'";
        } else {
            $status_where = "WHERE d.delivery_status = ?";
            $status_params = [$status_filter];
        }
    } elseif ($status_filter === 'missed') {
        $status_where = "WHERE d.schedule_date IS NOT NULL AND d.schedule_date < CURDATE() AND LOWER(d.delivery_status) NOT IN ('delivered','completed','cancelled')";
    } elseif ($status_filter === 'due_today') {
        $status_where = "WHERE d.schedule_date = CURDATE() AND LOWER(d.delivery_status) NOT IN ('delivered','completed','cancelled')";
    } elseif ($status_filter === 'due_week') {
        $status_where = "WHERE d.schedule_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND LOWER(d.delivery_status) NOT IN ('delivered','completed','cancelled')";
    }
}

// Search by Delivery ID or Order ID (overrides status filter so any delivery can be found)
$del_search_id = intval($_GET['search_id'] ?? 0);
if ($del_search_id > 0) {
    $status_where = "WHERE d.Delivery_ID = ? OR d.Order_ID = ?";
    $status_params = [$del_search_id, $del_search_id];
}

$has_assigned_col = false;
$has_delivered_by_user_col = false;
try {
    $ac = $conn->query("SHOW COLUMNS FROM delivery LIKE 'assigned_rider_id'");
    $has_assigned_col = $ac && $ac->rowCount() > 0;
    $dbc = $conn->query("SHOW COLUMNS FROM delivery LIKE 'delivered_by_user_id'");
    $has_delivered_by_user_col = $dbc && $dbc->rowCount() > 0;
} catch (Exception $e) {}

$rider_join = "";
$rider_select = "";
if ($has_assigned_col) {
    $rider_join = "LEFT JOIN user ra ON d.assigned_rider_id = ra.User_ID";
    $rider_select = ", COALESCE(ra.full_name, ra.user_name) as assigned_rider_name";
} elseif ($has_delivered_by_user_col) {
    $rider_join = "LEFT JOIN user ra ON d.delivered_by_user_id = ra.User_ID";
    $rider_select = ", COALESCE(ra.full_name, ra.user_name) as assigned_rider_name";
} else {
    $rider_select = ", d.delivered_by as assigned_rider_name";
}

$hide_assign_column = in_array($status_filter, ['Delivered', 'Returning', 'Completed', 'Cancelled'], true);
$show_assign_column = !$hide_assign_column;

// Pagination for deliveries
$items_per_page = 20;
$current_page = max(1, intval($_GET['page'] ?? 1));
$offset = ($current_page - 1) * $items_per_page;

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM delivery d
                LEFT JOIN orders o ON d.Order_ID = o.Order_ID
                $rider_join
                $status_where";
if (!empty($status_params)) {
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->execute($status_params);
} else {
    $count_stmt = $conn->query($count_query);
}
$total_items = $count_stmt ? $count_stmt->fetch(PDO::FETCH_ASSOC)['total'] : 0;
$total_pages = max(1, ceil($total_items / $items_per_page));
$current_page = min($current_page, $total_pages);
$offset = ($current_page - 1) * $items_per_page;

$d_cols = array_column($conn->query("SHOW COLUMNS FROM delivery")->fetchAll(PDO::FETCH_ASSOC), 'Field');
$d_proof_col = in_array('proof_of_delivery_path', $d_cols) ? 'd.proof_of_delivery_path' : 'NULL';

$deliveries_query = "SELECT d.*, o.Order_ID, o.order_date, o.{$order_status_col} as order_status,
                    o.is_ar,
                    COALESCE(o.customer_name_snapshot, c.customer_name) as customer_name, c.phone_number, COALESCE(opt.status, 'not_started') AS prep_status{$rider_select},
                    COALESCE(dp_latest.file_path, {$d_proof_col}) as proof_of_delivery_path
                    FROM delivery d
                    LEFT JOIN orders o ON d.Order_ID = o.Order_ID
                    LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
                    LEFT JOIN order_preparation_tasks opt ON opt.Order_ID = o.Order_ID
                    $rider_join
                    LEFT JOIN (
                        SELECT p1.delivery_id, p1.file_path
                        FROM delivery_proofs p1
                        INNER JOIN (
                            SELECT delivery_id, MAX(proof_id) AS max_proof_id
                            FROM delivery_proofs
                            GROUP BY delivery_id
                        ) p2 ON p2.max_proof_id = p1.proof_id
                    ) dp_latest ON dp_latest.delivery_id = d.Delivery_ID
                    $status_where
                    ORDER BY d.Delivery_ID DESC, COALESCE(d.updated_at, d.created_at) DESC
                    LIMIT $items_per_page OFFSET $offset";

// Get delivery statistics
$delivery_stats = ['total' => 0, 'scheduled' => 0, 'transit' => 0, 'delivered' => 0, 'returning' => 0, 'completed' => 0, 'cancelled' => 0];
try {
    $stats_query = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN d.delivery_status = 'Scheduled' THEN 1 ELSE 0 END) as scheduled,
        SUM(CASE WHEN d.delivery_status = 'In Transit' THEN 1 ELSE 0 END) as transit,
        SUM(CASE WHEN d.delivery_status = 'Delivered' THEN 1 ELSE 0 END) as delivered,
        SUM(CASE WHEN d.delivery_status = 'Returning' THEN 1 ELSE 0 END) as returning,
        SUM(CASE WHEN d.delivery_status = 'Completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN d.delivery_status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled
    FROM delivery d";
    $stats_result = $conn->query($stats_query);
    if ($stats_result) {
        $delivery_stats = $stats_result->fetch(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    // Stats not critical
}

// Deadline notification queries
$dlvOverdue = 0;
$dlvDueToday = 0;
$dlvDueWeek = 0;
try {
    $delStatusExcl = "LOWER(delivery_status) NOT IN ('delivered','completed','cancelled')";
    $stmt = $conn->prepare("SELECT COUNT(*) FROM delivery WHERE schedule_date < CURDATE() AND $delStatusExcl");
    $stmt->execute(); $dlvOverdue = (int)$stmt->fetchColumn();
    $stmt = $conn->prepare("SELECT COUNT(*) FROM delivery WHERE schedule_date = CURDATE() AND $delStatusExcl");
    $stmt->execute(); $dlvDueToday = (int)$stmt->fetchColumn();
    $stmt = $conn->prepare("SELECT COUNT(*) FROM delivery WHERE schedule_date BETWEEN CURDATE() + INTERVAL 1 DAY AND CURDATE() + INTERVAL 7 DAY AND $delStatusExcl");
    $stmt->execute(); $dlvDueWeek = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    $dlvOverdue = 0; $dlvDueToday = 0; $dlvDueWeek = 0;
}

if (!empty($status_params)) {
    $stmt = $conn->prepare($deliveries_query);
    $stmt->execute($status_params);
    $deliveries_result = $stmt;
} else {
    $deliveries_result = $conn->query($deliveries_query);
}

// Fetch transfer history for delivery badges and timeline
$transfer_map = [];
try {
    $transfer_stmt = $conn->query("SELECT dt.*, fu.full_name AS from_rider_name, tu.full_name AS to_rider_name
        FROM delivery_transfers dt
        LEFT JOIN user fu ON dt.from_rider_id = fu.User_ID
        LEFT JOIN user tu ON dt.to_rider_id = tu.User_ID
        ORDER BY dt.created_at DESC");
    while ($t = $transfer_stmt->fetch(PDO::FETCH_ASSOC)) {
        $did = (int)$t['Delivery_ID'];
        if (!isset($transfer_map[$did])) {
            $transfer_map[$did] = [];
        }
        $t['from_rider_name'] = $t['from_rider_name'] ?: 'Unknown';
        $t['to_rider_name'] = $t['to_rider_name'] ?: ('User #' . $t['to_rider_id']);
        $transfer_map[$did][] = $t;
    }
} catch (Throwable $e) {
    $transfer_map = [];
}

// Fetch orders that need delivery assignment
$orders_query = "SELECT o.Order_ID, o.order_date, COALESCE(o.customer_name_snapshot, c.customer_name) as customer_name, COALESCE(o.customer_address_snapshot, c.address) as delivery_address
                 FROM orders o
                 INNER JOIN customers c ON o.Customer_ID = c.Customer_ID
                 LEFT JOIN (
                    SELECT d1.Order_ID, d1.Delivery_ID, d1.delivery_status
                    FROM delivery d1
                    INNER JOIN (
                        SELECT Order_ID, MAX(Delivery_ID) AS latest_delivery_id
                        FROM delivery
                        WHERE Order_ID IS NOT NULL
                        GROUP BY Order_ID
                    ) latest ON latest.latest_delivery_id = d1.Delivery_ID
                 ) ld ON ld.Order_ID = o.Order_ID
                 WHERE o.{$order_status_col} IN ('Requested', 'pending', 'Confirmed', 'Scheduled for Delivery')
                   AND (ld.Delivery_ID IS NULL OR ld.delivery_status IN ('Returning', 'Cancelled'))
                 ORDER BY o.order_date DESC
                 LIMIT 50";
$orders_result = $conn->query($orders_query);

// Find riders with Vehicle issue deliveries for bulk transfer
$vehicle_issue_riders = [];
$bulk_transfer_data = [];
try {
    $viq = "SELECT d.assigned_rider_id, COALESCE(u.full_name, u.user_name) as rider_name
            FROM delivery d
            LEFT JOIN user u ON d.assigned_rider_id = u.User_ID
            WHERE d.delivery_status = 'Returning' 
              AND d.cancellation_reason = 'Vehicle issue'
              AND d.assigned_rider_id IS NOT NULL AND d.assigned_rider_id > 0
            GROUP BY d.assigned_rider_id";
    $vir = $conn->query($viq);
    while ($vrow = $vir->fetch(PDO::FETCH_ASSOC)) {
        $rid = (int)$vrow['assigned_rider_id'];
        $adq = "SELECT d.Delivery_ID, d.Order_ID, d.delivery_status, 
                       COALESCE(o.customer_name_snapshot, c.customer_name) as customer_name,
                       d.schedule_date
                FROM delivery d
                LEFT JOIN orders o ON d.Order_ID = o.Order_ID
                LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
                WHERE d.assigned_rider_id = ?
                  AND d.delivery_status NOT IN ('Completed', 'Cancelled', 'Delivered')
                ORDER BY d.Delivery_ID";
        $ads = $conn->prepare($adq);
        $ads->execute([$rid]);
        $active_deliveries = $ads->fetchAll(PDO::FETCH_ASSOC);
        
        $vehicle_issue_riders[$rid] = true;
        $bulk_deliveries = [];
        foreach ($active_deliveries as $ad) {
            $bulk_deliveries[] = [
                'delivery_id' => (int)$ad['Delivery_ID'],
                'order_id' => (int)$ad['Order_ID'],
                'customer_name' => $ad['customer_name'] ?? 'Unknown',
                'status' => $ad['delivery_status'] ?? 'Unknown',
                'schedule_date' => $ad['schedule_date'] ?? null
            ];
        }
        $bulk_transfer_data[$rid] = [
            'rider_name' => $vrow['rider_name'] ?: ('User #' . $rid),
            'deliveries' => $bulk_deliveries
        ];
    }
} catch (Throwable $e) {
    $vehicle_issue_riders = [];
    $bulk_transfer_data = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Management - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <link rel="stylesheet" href="../assets/css/orders.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/orders.css'); ?>">
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/style.css'); ?>">
    <?php echo csrfBootstrapTags(); ?>
    <style>
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .rider-board {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .rider-board-column {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            box-shadow: 0 4px 15px rgba(15, 23, 42, 0.05);
            overflow: hidden;
        }

        .rider-board-head {
            padding: 1rem 1.1rem;
            color: #0f172a;
            font-weight: 800;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .rider-board-head.available { background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%); color: #166534; }
        .rider-board-head.on-delivery { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color: #92400e; }
        .rider-board-head.off-duty { background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); color: #b91c1c; }

        .rider-board-body {
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            min-height: 120px;
        }

        .rider-pill {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 0.85rem 0.95rem;
            background: #f8fafc;
        }

        .rider-pill-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 0.45rem;
        }

        .rider-pill-name {
            font-weight: 700;
            color: #0f172a;
            font-size: 0.95rem;
        }

        .rider-pill-meta {
            font-size: 0.78rem;
            color: #64748b;
        }

        .rider-status-tag {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border-radius: 999px;
            padding: 0.25rem 0.65rem;
            font-size: 0.72rem;
            font-weight: 700;
        }

        .rider-status-tag.available { background: #dcfce7; color: #166534; }
        .rider-status-tag.on-delivery { background: #fef3c7; color: #92400e; }
        .rider-status-tag.off-duty { background: #fee2e2; color: #b91c1c; }

        .rider-pill-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 0.65rem;
        }

        .rider-inline-form {
            margin: 0;
        }

        .btn-rider-state {
            border: none;
            border-radius: 10px;
            padding: 0.5rem 0.8rem;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-rider-state.available {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .btn-rider-state.off-duty {
            background: #fee2e2;
            color: #b91c1c;
        }

        .btn-rider-state:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }

        .stat-card {
            background: white;
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
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.15);
        }

        .stat-card.active {
            border-color: #6366f1;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.2);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .stat-icon.all { background: linear-gradient(135deg, #6366f1 0%, #7c3aed 100%); color: white; }
        .stat-icon.scheduled { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); color: #1d4ed8; }
        .stat-icon.transit { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color: #b45309; }
        .stat-icon.delivered { background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%); color: #15803d; }
        .stat-icon.returning { background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%); color: #0369a1; }
        .stat-icon.completed { background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%); color: #4338ca; }
        .stat-icon.cancelled { background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); color: #b91c1c; }

        .stat-content h4 {
            font-size: 0.75rem;
            font-weight: 600;
            color: #64748b;
            margin: 0 0 0.125rem 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-content p {
            font-size: 1.5rem;
            font-weight: 800;
            color: #1e293b;
            margin: 0;
        }

        /* Improved Header */
        .page-header-banner {
            background: linear-gradient(135deg, #6366f1 0%, #7c3aed 100%);
            border-radius: 24px;
            padding: 2rem;
            color: white;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            box-shadow: 0 20px 40px rgba(99, 102, 241, 0.25);
            position: relative;
            overflow: hidden;
        }

        .page-header-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: rgba(255,255,255,0.08);
            border-radius: 50%;
            pointer-events: none;
        }

        .header-content h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
            z-index: 1;
        }

        .header-content p {
            font-size: 0.95rem;
            opacity: 0.9;
            margin: 0.5rem 0 0 0;
            position: relative;
            z-index: 1;
        }

        .action-bar {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #e5e7eb;
        }
        .card-header h3 {
            margin: 0;
            color: #1f2937;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        .table th, .table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        .table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
        }
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .status-Scheduled { background: #dbeafe; color: #1e40af; }
        .status-In-Transit { background: #fef3c7; color: #92400e; }
        .status-Delivered { background: #d1fae5; color: #065f46; }
        .status-Returning { background: #e0f2fe; color: #0284c7; }
        .status-Completed { background: #d1fae5; color: #047857; }
        .status-Cancelled { background: #fee2e2; color: #b91c1c; }
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
        }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-secondary { background: #6b7280; color: white; }
        .btn-success { background: #10b981; color: white; }
        .btn:hover { opacity: 0.9; }
        .alert {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
        }
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #10b981;
        }
        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #ef4444;
        }
        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 0.5rem;
            overflow-x: auto;
        }
        .filter-tab {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            color: #64748b;
            text-decoration: none;
            font-weight: 500;
            white-space: nowrap;
            transition: all 0.2s;
        }
        .filter-tab:hover {
            background: #f1f5f9;
            color: #334155;
        }
        .filter-tab.active {
            background: #3b82f6;
            color: white;
        }
        .form-select, .form-select-sm {
            padding: 0.4rem 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.875rem;
        }
        .btn-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            align-items: stretch;
            width: 100%;
            max-width: 140px;
        }
        .btn-group .btn {
            width: 100%;
            justify-content: center;
        }
        .proof-thumbnail {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 4px;
            border: 1px solid #e5e7eb;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .proof-thumbnail:hover {
            transform: scale(1.1);
            border-color: #3b82f6;
        }
        .proof-modal-img {
            width: 100%;
            max-height: 400px;
            object-fit: contain;
            border-radius: 8px;
            margin-top: 1rem;
            border: 1px solid #f1f5f9;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .no-proof-text {
            color: #94a3b8;
            font-size: 0.8rem;
            font-style: italic;
        }
        /* Lightbox Styles */
        .lightbox-overlay {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.9);
            align-items: center;
            justify-content: center;
            cursor: zoom-out;
        }
        .lightbox-content {
            max-width: 90%;
            max-height: 90%;
            object-fit: contain;
            border-radius: 8px;
            box-shadow: 0 0 20px rgba(0,0,0,0.5);
            cursor: default;
        }
        .lightbox-close {
            position: absolute;
            top: 20px;
            right: 30px;
            color: #f1f5f9;
            font-size: 40px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.3s;
            z-index: 2001;
        }
        .lightbox-close:hover {
            color: #3b82f6;
        }
    </style>
</head>
<body>
<div class="dashboard-wrapper">
    <!-- Sidebar -->
    <?php
    require_once __DIR__ . '/../../includes/sidebar.php';
    renderSidebar($conn, ['base' => '../', 'active' => 'delivery']);
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
                <a href="delivery.php" class="menu-item active">
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
                <h1><i class="fas fa-truck"></i> Delivery Management</h1>
                <p>Manage deliveries and track delivery status.</p>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_GET['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_GET['error']); ?>
            </div>
        <?php endif; ?>

        <div class="card" style="margin-bottom: 1.5rem;">
            <div class="card-header">
                <h3><i class="fas fa-users"></i> Rider Availability Board</h3>
            </div>
            <div class="card-body">
                <div class="rider-board">
                    <?php
                    $board_meta = [
                        'Available' => ['label' => 'Available', 'class' => 'available'],
                        'On Delivery' => ['label' => 'On Delivery', 'class' => 'on-delivery'],
                        'Off Duty' => ['label' => 'Off Duty', 'class' => 'off-duty'],
                    ];
                    ?>
                    <?php foreach ($board_meta as $status_name => $meta): ?>
                        <?php $group_rows = $rider_groups[$status_name] ?? []; ?>
                        <div class="rider-board-column">
                            <div class="rider-board-head <?php echo $meta['class']; ?>">
                                <span><?php echo htmlspecialchars($meta['label']); ?></span>
                                <span><?php echo count($group_rows); ?></span>
                            </div>
                            <div class="rider-board-body">
                                <?php if (!empty($group_rows)): ?>
                                    <?php foreach ($group_rows as $rider_row): ?>
                                        <?php
                                        $row_status = (string)($rider_row['rider_availability_status'] ?? 'Available');
                                        $status_class = strtolower(str_replace(' ', '-', $row_status));
                                        $active_count = (int)($rider_row['active_delivery_count'] ?? 0);
                                        $scheduled_count = (int)($rider_row['scheduled_delivery_count'] ?? 0);
                                        $in_progress_count = (int)($rider_row['in_progress_delivery_count'] ?? 0);
                                        $returning_count = (int)($rider_row['returning_delivery_count'] ?? 0);
                                        $is_busy = $row_status === 'On Delivery';
                                        ?>
                                        <div class="rider-pill">
                                            <div class="rider-pill-top">
                                                <div>
                                                    <div class="rider-pill-name"><?php echo htmlspecialchars($rider_row['name'] ?? 'Rider'); ?></div>
                                                    <div class="rider-pill-meta">
                                                        <?php
                                                        $load_bits = [];
                                                        $load_bits[] = $active_count . ' assigned';
                                                        if ($scheduled_count > 0) $load_bits[] = $scheduled_count . ' scheduled';
                                                        if ($in_progress_count > 0) $load_bits[] = $in_progress_count . ' in progress';
                                                        if ($returning_count > 0) $load_bits[] = $returning_count . ' returning';
                                                        echo htmlspecialchars(implode(' · ', $load_bits));
                                                        ?>
                                                    </div>
                                                </div>
                                                <span class="rider-status-tag <?php echo $status_class; ?>">
                                                    <?php echo htmlspecialchars($row_status); ?>
                                                </span>
                                            </div>
                                            <?php if (isset($vehicle_issue_riders[(int)$rider_row['User_ID']])): ?>
                                            <div class="rider-pill-actions">
                                                <button type="button" class="btn-rider-state" 
                                                        style="background:#fef3c7;color:#92400e;"
                                                        onclick='openBulkTransferModal(<?php echo (int)$rider_row["User_ID"]; ?>, <?php echo json_encode($rider_row["name"] ?? "Rider", JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                                    <i class="fas fa-exchange-alt"></i> Transfer All (Vehicle Issue)
                                                </button>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="rider-pill-meta">No riders in this status.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Delivery Stats Cards -->
        <div class="stats-grid">
            <a href="delivery.php?status=all" class="stat-card <?php echo empty($status_filter) || $status_filter === 'all' ? 'active' : ''; ?>">
                <div class="stat-icon all">
                    <i class="fas fa-truck"></i>
                </div>
                <div class="stat-content">
                    <h4>All Deliveries</h4>
                    <p><?php echo $delivery_stats['total'] ?? 0; ?></p>
                </div>
            </a>
            <a href="delivery.php?status=Scheduled" class="stat-card <?php echo $status_filter === 'Scheduled' ? 'active' : ''; ?>">
                <div class="stat-icon scheduled">
                    <i class="fas fa-calendar"></i>
                </div>
                <div class="stat-content">
                    <h4>Scheduled</h4>
                    <p><?php echo $delivery_stats['scheduled'] ?? 0; ?></p>
                </div>
            </a>
            <a href="delivery.php?status=In Transit" class="stat-card <?php echo $status_filter === 'In Transit' ? 'active' : ''; ?>">
                <div class="stat-icon transit">
                    <i class="fas fa-shipping-fast"></i>
                </div>
                <div class="stat-content">
                    <h4>In Transit</h4>
                    <p><?php echo $delivery_stats['transit'] ?? 0; ?></p>
                </div>
            </a>
            <a href="delivery.php?status=missed" class="stat-card <?php echo $status_filter === 'missed' ? 'active' : ''; ?>">
                <div class="stat-icon" style="background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); color: white; box-shadow: 0 8px 20px rgba(220, 38, 38, 0.3);">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-content">
                    <h4>Missed</h4>
                    <p><?php echo $dlvOverdue; ?></p>
                </div>
            </a>
            <a href="delivery.php?status=Delivered" class="stat-card <?php echo $status_filter === 'Delivered' ? 'active' : ''; ?>">
                <div class="stat-icon delivered">
                    <i class="fas fa-check"></i>
                </div>
                <div class="stat-content">
                    <h4>Delivered</h4>
                    <p><?php echo $delivery_stats['delivered'] ?? 0; ?></p>
                </div>
            </a>
            <a href="delivery.php?status=Returning" class="stat-card <?php echo $status_filter === 'Returning' ? 'active' : ''; ?>">
                <div class="stat-icon returning">
                    <i class="fas fa-rotate-left"></i>
                </div>
                <div class="stat-content">
                    <h4>Returning</h4>
                    <p><?php echo $delivery_stats['returning'] ?? 0; ?></p>
                </div>
            </a>
            <a href="delivery.php?status=Completed" class="stat-card <?php echo $status_filter === 'Completed' ? 'active' : ''; ?>">
                <div class="stat-icon completed">
                    <i class="fas fa-check-double"></i>
                </div>
                <div class="stat-content">
                    <h4>Completed</h4>
                    <p><?php echo $delivery_stats['completed'] ?? 0; ?></p>
                </div>
            </a>
            <a href="delivery.php?status=Cancelled" class="stat-card <?php echo $status_filter === 'Cancelled' ? 'active' : ''; ?>">
                <div class="stat-icon cancelled">
                    <i class="fas fa-ban"></i>
                </div>
                <div class="stat-content">
                    <h4>Cancelled</h4>
                    <p><?php echo $delivery_stats['cancelled'] ?? 0; ?></p>
                </div>
            </a>
        </div>

        <!-- Delivery Deadline Notification Cards -->
        <?php if ($dlvOverdue > 0 || $dlvDueToday > 0 || $dlvDueWeek > 0): ?>
        <div style="display:flex;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
            <?php if ($dlvOverdue > 0): ?>
            <a href="delivery.php?status=missed" style="flex:1;min-width:200px;background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:1rem;display:flex;align-items:center;gap:0.75rem;text-decoration:none;cursor:pointer;transition:box-shadow .2s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(220,38,38,0.2)'" onmouseout="this.style.boxShadow='none'">
                <span style="width:40px;height:40px;border-radius:10px;background:#fee2e2;display:flex;align-items:center;justify-content:center;color:#dc2626;font-size:1.25rem;flex-shrink:0;"><i class="fas fa-exclamation-triangle"></i></span>
                <div>
                    <strong style="font-size:0.875rem;color:#991b1b;"><?php echo $dlvOverdue; ?> Overdue Deliver<?php echo $dlvOverdue === 1 ? 'y' : 'ies'; ?></strong>
                    <p style="font-size:0.75rem;color:#b91c1c;margin:0;">Past scheduled date</p>
                </div>
            </a>
            <?php endif; ?>
            <?php if ($dlvDueToday > 0): ?>
            <a href="delivery.php?status=due_today" style="flex:1;min-width:200px;background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:1rem;display:flex;align-items:center;gap:0.75rem;text-decoration:none;cursor:pointer;transition:box-shadow .2s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(217,119,6,0.2)'" onmouseout="this.style.boxShadow='none'">
                <span style="width:40px;height:40px;border-radius:10px;background:#fef3c7;display:flex;align-items:center;justify-content:center;color:#d97706;font-size:1.25rem;flex-shrink:0;"><i class="fas fa-clock"></i></span>
                <div>
                    <strong style="font-size:0.875rem;color:#92400e;"><?php echo $dlvDueToday; ?> Scheduled Today</strong>
                    <p style="font-size:0.75rem;color:#a16207;margin:0;">Needs dispatch</p>
                </div>
            </a>
            <?php endif; ?>
            <?php if ($dlvDueWeek > 0): ?>
            <a href="delivery.php?status=due_week" style="flex:1;min-width:200px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:1rem;display:flex;align-items:center;gap:0.75rem;text-decoration:none;cursor:pointer;transition:box-shadow .2s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(37,99,235,0.2)'" onmouseout="this.style.boxShadow='none'">
                <span style="width:40px;height:40px;border-radius:10px;background:#dbeafe;display:flex;align-items:center;justify-content:center;color:#2563eb;font-size:1.25rem;flex-shrink:0;"><i class="fas fa-calendar-alt"></i></span>
                <div>
                    <strong style="font-size:0.875rem;color:#1e40af;"><?php echo $dlvDueWeek; ?> Due This Week</strong>
                    <p style="font-size:0.75rem;color:#1d4ed8;margin:0;">Scheduled within 7 days</p>
                </div>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Search by Delivery ID -->
        <form method="GET" style="display:flex;gap:0.75rem;margin-bottom:1.25rem;align-items:center;">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
            <input type="number" name="search_id" placeholder="Search by Delivery #..." min="1"
                   value="<?php echo $del_search_id > 0 ? $del_search_id : ''; ?>"
                   style="padding:0.5rem 1rem;border:1px solid #e2e8f0;border-radius:10px;font-size:0.875rem;width:220px;outline:none;">
            <button type="submit" style="padding:0.5rem 1rem;border:none;border-radius:10px;background:#6366f1;color:white;font-weight:600;font-size:0.8125rem;cursor:pointer;display:inline-flex;align-items:center;gap:0.375rem;">
                <i class="fas fa-search"></i> Search
            </button>
            <?php if ($del_search_id > 0): ?>
                <a href="?status=<?php echo urlencode($status_filter); ?>" style="padding:0.5rem 1rem;border-radius:10px;font-size:0.8125rem;font-weight:600;color:#64748b;text-decoration:none;display:inline-flex;align-items:center;gap:0.375rem;">
                    <i class="fas fa-times"></i> Clear
                </a>
            <?php endif; ?>
        </form>

            <!-- Deliveries List -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-list"></i> Deliveries</h3>
                </div>
                <div class="card-body">
                    <?php if ($deliveries_result && $deliveries_result->rowCount() > 0): ?>
                        <div class="table-scrollable">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Delivery #</th>
                                    <th>Order #</th>
                                    <th>Customer</th>
                                    <th style="width: 50px; text-align: center;">AR</th>
                                    <?php if ($show_assign_column): ?><th>Delivery Person</th><?php endif; ?>
                                    <th>Delivered By</th>
                                    <th>Scheduled Date</th>
                                    <th>Preparation</th>
                                    <th>Status</th>
                                    <th>Reason</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($delivery = $deliveries_result->fetch(PDO::FETCH_ASSOC)): ?>
                                    <?php
                                    $delivery_status = (string)($delivery['delivery_status'] ?? '');
                                    $display_status = $delivery_status;
                                    $assignment_locked = in_array($delivery_status, ['In Transit', 'Delivered', 'Returning', 'Completed', 'Cancelled', 'Remitted'], true);
                                    $manual_status_locked = ($delivery_status !== 'Scheduled');
                                    $reason_bits = [];
                                    if (!empty($delivery['cancellation_reason'])) $reason_bits[] = (string)$delivery['cancellation_reason'];
                                    if (!empty($delivery['cancellation_remarks'])) $reason_bits[] = (string)$delivery['cancellation_remarks'];
                                    // returned_to_store_at column removed — no extra reason bit needed
                                    $reason_text = implode(' - ', $reason_bits);
                                    $prep_label = prepTasksStatusLabel((string)($delivery['prep_status'] ?? 'not_started'));
                                    // When the delivery is in transit (rider picked up), show "Picked Up"
                                    $in_transit_statuses = ['In Transit'];
                                    $finished_statuses = ['Completed', 'Delivered'];
                                    if (in_array($delivery_status, $in_transit_statuses, true)) {
                                        $prep_label = ['label' => 'Picked Up', 'icon' => 'fa-box', 'class' => 'status-picked-up'];
                                    } elseif (in_array($delivery_status, $finished_statuses, true) && $prep_label['class'] === 'status-ready') {
                                        $prep_label = ['label' => 'Done', 'icon' => 'fa-flag-checkered', 'class' => 'status-done'];
                                    }
                                    $prep_colors = [];
                                    foreach (prepTasksGetValidStatuses($conn) as $s) {
                                        $k = 'status-' . str_replace('_', '-', $s);
                                        $prep_colors[$k] = ['bg' => '#f1f5f9', 'color' => '#475569', 'border' => '#e2e8f0'];
                                    }
                                    $prep_colors['status-not-started'] = ['bg' => '#f1f5f9', 'color' => '#475569', 'border' => '#e2e8f0'];
                                    $prep_colors['status-preparing'] = ['bg' => '#fef3c7', 'color' => '#92400e', 'border' => '#fde68a'];
                                    $prep_colors['status-ready'] = ['bg' => '#dcfce7', 'color' => '#166534', 'border' => '#86efac'];
                                    $prep_colors['status-short-stock'] = ['bg' => '#fee2e2', 'color' => '#991b1b', 'border' => '#fecaca'];
                                    $prep_colors['status-picked-up'] = ['bg' => '#e0f2fe', 'color' => '#0369a1', 'border' => '#7dd3fc'];
                                    $prep_colors['status-done'] = ['bg' => '#ede9fe', 'color' => '#5b21b6', 'border' => '#c4b5fd'];
                                    $prep_color = $prep_colors[$prep_label['class']] ?? $prep_colors['status-not-started'];
                                    // Overdue flag
                                    $dlv_is_overdue = false;
                                    if (!empty($delivery['schedule_date']) && strtotime($delivery['schedule_date']) < strtotime('today')) {
                                        if (!in_array($delivery_status, ['Delivered', 'Completed', 'Cancelled'], true)) {
                                            $dlv_is_overdue = true;
                                        }
                                    }
                                    ?>
                                    <tr <?php echo $dlv_is_overdue ? 'style="background:#fef2f2;border-left:4px solid #dc2626;"' : ''; ?>>
                                                                                <td><strong>#<?php echo $delivery['Delivery_ID']; ?></strong>
                                            <?php
                                            $dlv_transfers = $transfer_map[(int)$delivery['Delivery_ID']] ?? [];
                                            if (!empty($dlv_transfers)):
                                                $latest = $dlv_transfers[0];
                                            ?>
                                                <br>
                                                <span onclick='openTransferTimeline(<?php echo (int)$delivery["Delivery_ID"]; ?>)' style="display:inline-flex;align-items:center;gap:0.25rem;margin-top:0.25rem;font-size:0.62rem;font-weight:600;color:#92400e;background:#fef3c7;padding:0.15rem 0.45rem;border-radius:4px;cursor:pointer;border:1px solid #fcd34d;">
                                                    <i class="fas fa-exchange-alt"></i> Transferred from <?php echo htmlspecialchars($latest['from_rider_name']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $delivery['Order_ID'] ? 'Order #' . $delivery['Order_ID'] : 'N/A'; ?></td>
                                        <td><?php echo htmlspecialchars($delivery['customer_name'] ?? $delivery['delivered_to'] ?? 'N/A'); ?></td>
                                        <td style="text-align: center;">
                                            <?php if (!empty($delivery['is_ar'])): ?>
                                                <span style="display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; border-radius: 999px; font-size: 0.7rem; font-weight: 700; background: #e0f2fe; color: #0369a1; border: 1px solid #7dd3fc;">
                                                    <i class="fas fa-file-invoice-dollar"></i> AR
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($show_assign_column): ?>
                                        <td>
                                            <form method="POST" action="delivery.php" class="d-inline assign-rider-form">
                                                <?php echo csrfTokenField(); ?>
                                                <input type="hidden" name="action" value="assign_rider">
                                                <input type="hidden" name="delivery_id" value="<?php echo (int)$delivery['Delivery_ID']; ?>">
                                                <?php
                                                $assigned_rider_id = riderGetUserIdByDeliveryId($conn, (int)$delivery['Delivery_ID']);
                                                $assigned_rider_row = ($assigned_rider_id > 0 && isset($rider_lookup[$assigned_rider_id])) ? $rider_lookup[$assigned_rider_id] : null;
                                                $assigned_in_riders = false;
                                                if ($assigned_rider_id > 0) {
                                                    foreach ($riders as $r) {
                                                        if ((int)($r['User_ID'] ?? 0) === $assigned_rider_id) {
                                                            $assigned_in_riders = true;
                                                            break;
                                                        }
                                                    }
                                                }
                                                ?>
                                                <select name="rider_id" class="form-select form-select-sm" style="width:auto;min-width:160px;" onchange="this.form.submit()" title="Assign delivery person" <?php echo ($is_owner_view_only || $assignment_locked) ? 'disabled' : ''; ?>>
                                                    <option value="">— Select rider —</option>
                                                    <?php if ($assigned_rider_row && !$assigned_in_riders): ?>
                                                    <option value="<?php echo (int)$assigned_rider_row['User_ID']; ?>" selected>
                                                        <?php
                                                        $assigned_load = (int)($assigned_rider_row['active_delivery_count'] ?? 0);
                                                        echo htmlspecialchars(($assigned_rider_row['name'] ?? 'User #' . $assigned_rider_row['User_ID']) . ' (' . ($assigned_rider_row['rider_availability_status'] ?? 'Assigned') . ', load: ' . $assigned_load . ')');
                                                        ?>
                                                    </option>
                                                    <?php endif; ?>
                                                    <?php foreach ($riders as $r): ?>
                                                    <option value="<?php echo (int)$r['User_ID']; ?>" <?php echo ($assigned_rider_id === (int)$r['User_ID']) ? 'selected' : ''; ?>><?php echo htmlspecialchars(($r['name'] ?? 'User #' . $r['User_ID']) . ' - load ' . (int)($r['active_delivery_count'] ?? 0)); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </form>
                                            <?php if (empty($riders)): ?><small class="text-muted d-block">No assignable riders</small><?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                        <td><?php echo htmlspecialchars($delivery['delivered_by'] ?? $delivery['assigned_rider_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo $delivery['schedule_date'] ? date('M d, Y', strtotime($delivery['schedule_date'])) : 'N/A'; ?><?php if ($dlv_is_overdue): ?><br><span style="display:inline-flex;align-items:center;gap:0.25rem;margin-top:0.25rem;font-size:0.65rem;font-weight:700;color:#dc2626;background:#fee2e2;padding:0.15rem 0.45rem;border-radius:4px;"><i class="fas fa-exclamation-circle"></i> MISSED</span><?php endif; ?></td>
                                        <td>
                                            <span style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.45rem 0.7rem;border-radius:9999px;font-size:0.72rem;font-weight:700;background:<?php echo $prep_color['bg']; ?>;color:<?php echo $prep_color['color']; ?>;border:1px solid <?php echo $prep_color['border']; ?>;">
                                                <i class="fas <?php echo htmlspecialchars($prep_label['icon']); ?>"></i>
                                                <?php echo htmlspecialchars($prep_label['label']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $deliveryStatusColors = [
                                                'Scheduled' => ['bg' => '#dbeafe', 'color' => '#1d4ed8', 'border' => '#93c5fd', 'icon' => 'fa-calendar'],
                                                'In Transit' => ['bg' => '#fef3c7', 'color' => '#b45309', 'border' => '#fcd34d', 'icon' => 'fa-truck'],
                                                'Delivered' => ['bg' => '#dcfce7', 'color' => '#15803d', 'border' => '#86efac', 'icon' => 'fa-check'],
                                                'Returning' => ['bg' => '#e0f2fe', 'color' => '#0369a1', 'border' => '#bae6fd', 'icon' => 'fa-rotate-left'],
                                                'Completed' => ['bg' => '#e0e7ff', 'color' => '#4338ca', 'border' => '#a5b4fc', 'icon' => 'fa-check-double'],
                                                'Cancelled' => ['bg' => '#fee2e2', 'color' => '#b91c1c', 'border' => '#fecaca', 'icon' => 'fa-ban']
                                            ];
                                            $deliveryStatus = $display_status ?: 'Scheduled';
                                            $deliveryColors = $deliveryStatusColors[$deliveryStatus] ?? $deliveryStatusColors['Scheduled'];
                                            ?>
                                            <span style="display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.5rem 0.875rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.025em; background: <?php echo $deliveryColors['bg']; ?>; color: <?php echo $deliveryColors['color']; ?>; border: 1px solid <?php echo $deliveryColors['border']; ?>;">
                                                <i class="fas <?php echo $deliveryColors['icon']; ?>"></i> <?php echo htmlspecialchars($deliveryStatus); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($reason_text !== ''): ?>
                                                <span style="font-size:0.82rem; color:#475569;"><?php echo htmlspecialchars($reason_text); ?></span>
                                            <?php else: ?>
                                                <span class="no-proof-text">No reason</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="white-space: nowrap;">
                                            <div class="action-group">
                                                <button onclick="viewDeliveryDetails(<?php echo $delivery['Delivery_ID']; ?>)" title="View Details" class="table-action-btn table-action-btn-label table-action-btn-view">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                                <?php if (!$is_owner_view_only && $delivery_status === 'Scheduled'): ?>
                                                    <button
                                                        type="button"
                                                        onclick='openCancelDeliveryModal(<?php echo json_encode([
                                                            'deliveryId' => (int)$delivery["Delivery_ID"],
                                                            'orderId' => (int)($delivery["Order_ID"] ?? 0),
                                                            'customerName' => (string)($delivery['customer_name'] ?? $delivery['delivered_to'] ?? 'Customer')
                                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                                        class="table-action-btn table-action-btn-label table-action-btn-cancel">
                                                        <i class="fas fa-ban"></i> Cancel
                                                    </button>
                                                <?php endif; ?>
                                                <?php if (!$is_owner_view_only && $delivery_status === 'Returning' && (string)($delivery['cancellation_reason'] ?? '') === 'Vehicle issue'): ?>
                                                    <button
                                                        type="button"
                                                        onclick='openTransferDeliveryModal(<?php echo json_encode([
                                                            'deliveryId' => (int)$delivery["Delivery_ID"],
                                                            'orderId' => (int)($delivery["Order_ID"] ?? 0),
                                                            'customerName' => (string)($delivery['customer_name'] ?? $delivery['delivered_to'] ?? 'Customer'),
                                                                                                                'currentRider' => $delivery['assigned_rider_name'] ?? $delivery['delivered_by'] ?? 'Unknown',
                                                    'currentRiderId' => riderGetUserIdByDeliveryId($conn, (int)$delivery['Delivery_ID'])
                                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                                        class="table-action-btn table-action-btn-label table-action-btn-transfer">
                                                        <i class="fas fa-exchange-alt"></i> Transfer
                                                    </button>
                                                <?php endif; ?>
                                                <?php if (!$is_owner_view_only && $display_status === 'Cancelled' && (int)($delivery['Order_ID'] ?? 0) > 0 && in_array((string)($delivery['cancellation_reason'] ?? ''), ['Customer unavailable', 'Reschedule'], true)): ?>
                                                    <button
                                                        type="button"
                                                        onclick='openRescheduleModal(<?php echo json_encode([
                                                            'deliveryId' => (int)$delivery["Delivery_ID"],
                                                            'orderId' => (int)$delivery["Order_ID"],
                                                            'customerName' => (string)($delivery['customer_name'] ?? $delivery['delivered_to'] ?? 'Customer'),
                                                            'scheduleDate' => !empty($delivery['schedule_date']) ? date('Y-m-d', strtotime((string)$delivery['schedule_date'])) : ''
                                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                                        class="table-action-btn table-action-btn-label table-action-btn-reschedule">
                                                        <i class="fas fa-calendar-plus"></i> Reschedule
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>

                        <!-- Pagination for Deliveries -->
                        <?php if ($total_pages > 1): ?>
                        <div style="display: flex; justify-content: center; align-items: center; gap: 0.5rem; padding: 1.5rem; border-top: 1px solid #e2e8f0; background: #f8fafc;">
                            <a href="?status=<?php echo urlencode($status_filter); ?>&page=<?php echo max(1, $current_page - 1); ?>" class="btn btn-secondary btn-sm" <?php echo $current_page <= 1 ? 'style="pointer-events:none;opacity:0.5;"' : ''; ?>>
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                            <span style="font-size: 0.875rem; color: #475569; font-weight: 600;">
                                Page <?php echo $current_page; ?> of <?php echo $total_pages; ?> (<?php echo $total_items; ?> total)
                            </span>
                            <a href="?status=<?php echo urlencode($status_filter); ?>&page=<?php echo min($total_pages, $current_page + 1); ?>" class="btn btn-secondary btn-sm" <?php echo $current_page >= $total_pages ? 'style="pointer-events:none;opacity:0.5;"' : ''; ?>>
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        </div>
                        <?php endif; ?>

                        <style>
                            .table-scrollable {
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
                            .table-action-btn-transfer {
                                background: #fef3c7;
                                color: #92400e;
                                border: 1px solid #fde68a;
                            }
                            .table-action-btn-transfer:hover {
                                background: #fde68a;
                                color: #78350f;
                            }
                        </style>
                    <?php else: ?>
                        <p style="text-align: center; color: #6b7280; padding: 2rem;">No deliveries found.</p>
                    <?php endif; ?>
                </div>
            </div>
    </main>
</div>

<!-- Delivery Details Modal -->
<div class="modal" id="deliveryDetailsModal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color:rgba(15,23,42,0.65); align-items:center; justify-content:center; padding:1rem; backdrop-filter:blur(4px);">
    <div class="modal-content" style="background:#f8fafc; border-radius:20px; width:100%; max-width:720px; max-height:90vh; overflow:hidden; box-shadow:0 25px 50px -12px rgba(0,0,0,0.35), 0 0 0 1px rgba(255,255,255,0.1); animation:modalSlideIn 0.3s ease-out;">
        <div style="background:linear-gradient(135deg, #1e293b 0%, #334155 100%); padding:1.5rem 2rem; display:flex; justify-content:space-between; align-items:center; position:relative; overflow:hidden;">
            <div style="position:absolute; top:-50%; right:-10%; width:200px; height:200px; background:rgba(255,255,255,0.03); border-radius:50%;"></div>
            <div style="display:flex; align-items:center; gap:1rem; position:relative; z-index:1;">
                <div style="width:44px; height:44px; border-radius:12px; background:linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); display:flex; align-items:center; justify-content:center; color:white; font-size:1.25rem; box-shadow:0 8px 16px -4px rgba(99,102,241,0.4);">
                    <i class="fas fa-truck-fast"></i>
                </div>
                <div>
                    <h2 id="modalDeliveryTitle" style="font-weight:800; color:#ffffff; margin:0; font-size:1.25rem; letter-spacing:-0.025em;">Delivery Details</h2>
                    <p style="margin:0.25rem 0 0; color:#94a3b8; font-size:0.875rem;">View complete delivery information</p>
                </div>
            </div>
        </div>
        <div id="deliveryDetailsBody" style="max-height:65vh; overflow-y:auto; padding:1.5rem 2rem;">
            <div style="text-align:center; padding:2rem;">
                <div style="width:48px; height:48px; border-radius:12px; background:linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); display:flex; align-items:center; justify-content:center; margin:0 auto 1rem; animation:pulse 2s infinite;">
                    <i class="fas fa-spinner fa-spin" style="color:white; font-size:1.25rem;"></i>
                </div>
                <p style="color:#64748b; font-weight:500; margin:0;">Loading delivery details...</p>
            </div>
        </div>
        <div style="padding:1.25rem 2rem; background:#ffffff; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end;">
            <button onclick="closeDeliveryModal()" style="background:#f1f5f9; color:#475569; border:none; padding:0.75rem 1.5rem; border-radius:12px; font-weight:600; font-size:0.875rem; cursor:pointer; display:inline-flex; align-items:center; gap:0.5rem; transition:all 0.2s;" onmouseover="this.style.background='#e2e8f0'; this.style.color='#1e293b';" onmouseout="this.style.background='#f1f5f9'; this.style.color='#475569';">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
</div>

<style>
@keyframes modalSlideIn {
    from { opacity:0; transform:translateY(-20px) scale(0.98); }
    to { opacity:1; transform:translateY(0) scale(1); }
}
@keyframes pulse {
    0%, 100% { opacity:1; }
    50% { opacity:0.7; }
}
</style>

<!-- Reschedule Delivery Modal -->
<div class="modal" id="rescheduleDeliveryModal" style="display:none; position:fixed; z-index:1100; left:0; top:0; width:100%; height:100%; background-color:rgba(15,23,42,0.55); align-items:center; justify-content:center; padding:1rem;">
    <div class="modal-content" style="background:#fff; padding:2rem; border-radius:1rem; width:100%; max-width:560px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; margin-bottom:1.5rem; border-bottom:1px solid #e2e8f0; padding-bottom:1rem;">
            <div>
                <h2 id="rescheduleModalTitle" style="font-weight:700; color:#0f172a; margin:0 0 0.35rem;"><i class="fas fa-calendar-plus" style="color:#0f766e;"></i> Reschedule Delivery</h2>
                <p id="rescheduleModalSubtitle" style="margin:0; color:#64748b; font-size:0.92rem;">Create a new scheduled delivery while keeping the old attempt in history.</p>
            </div>
            <button type="button" onclick="closeRescheduleModal()" style="border:none; background:none; font-size:1.5rem; cursor:pointer; color:#94a3b8;">&times;</button>
        </div>

        <form method="POST" action="delivery.php">
            <?php echo csrfTokenField(); ?>
            <input type="hidden" name="action" value="reschedule_delivery">
            <input type="hidden" name="delivery_id" id="rescheduleDeliveryId" value="">

            <div style="display:grid; gap:1rem;">
                <label style="display:grid; gap:0.45rem;">
                    <span style="font-size:0.78rem; font-weight:700; letter-spacing:0.05em; color:#475569; text-transform:uppercase;">New Delivery Date</span>
                    <input
                        type="date"
                        id="rescheduleDate"
                        name="schedule_date"
                        required
                        min="<?php echo date('Y-m-d'); ?>"
                        style="width:100%; border:1px solid #cbd5e1; border-radius:12px; padding:0.85rem 1rem; font-size:0.95rem; color:#0f172a; background:#fff;">
                </label>

                <label style="display:grid; gap:0.45rem;">
                    <span style="font-size:0.78rem; font-weight:700; letter-spacing:0.05em; color:#475569; text-transform:uppercase;">Assign Available Rider (Optional)</span>
                    <select
                        id="rescheduleRiderId"
                        name="rider_id"
                        style="width:100%; border:1px solid #cbd5e1; border-radius:12px; padding:0.85rem 1rem; font-size:0.95rem; color:#0f172a; background:#fff;">
                        <option value="">Assign later</option>
                        <?php foreach ($riders as $r): ?>
                            <option value="<?php echo (int)$r['User_ID']; ?>"><?php echo htmlspecialchars($r['name'] ?? ('User #' . (int)$r['User_ID'])); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($riders)): ?>
                        <span style="font-size:0.82rem; color:#b45309;">No riders are currently marked Available. You can still reschedule and assign later.</span>
                    <?php else: ?>
                        <span style="font-size:0.82rem; color:#64748b;">Only riders marked Available are shown here.</span>
                    <?php endif; ?>
                </label>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:0.75rem; margin-top:1.75rem;">
                <button type="button" onclick="closeRescheduleModal()" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background:linear-gradient(135deg, #14b8a6 0%, #0f766e 100%); border:none;">
                    <i class="fas fa-calendar-check"></i> Create New Delivery
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Cancel Delivery Modal -->
<div class="modal" id="cancelDeliveryModal" style="display:none; position:fixed; z-index:1100; left:0; top:0; width:100%; height:100%; background-color:rgba(15,23,42,0.55); align-items:center; justify-content:center; padding:1rem;">
    <div class="modal-content" style="background:#fff; padding:2rem; border-radius:1rem; width:100%; max-width:560px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; margin-bottom:1.5rem; border-bottom:1px solid #e2e8f0; padding-bottom:1rem;">
            <div>
                <h2 id="cancelModalTitle" style="font-weight:700; color:#0f172a; margin:0 0 0.35rem;"><i class="fas fa-ban" style="color:#dc2626;"></i> Cancel Scheduled Delivery</h2>
                <p id="cancelModalSubtitle" style="margin:0; color:#64748b; font-size:0.92rem;">This cancels the delivery attempt and sends the order back for manager review.</p>
            </div>
            <button type="button" onclick="closeCancelDeliveryModal()" style="border:none; background:none; font-size:1.5rem; cursor:pointer; color:#94a3b8;">&times;</button>
        </div>

        <form method="POST" action="delivery.php">
            <?php echo csrfTokenField(); ?>
            <input type="hidden" name="action" value="cancel_delivery">
            <input type="hidden" name="redirect_to" value="../pages/delivery.php">
            <input type="hidden" name="delivery_id" id="cancelDeliveryId" value="">

            <div style="display:grid; gap:1rem;">
                <label style="display:grid; gap:0.45rem;">
                    <span style="font-size:0.78rem; font-weight:700; letter-spacing:0.05em; color:#475569; text-transform:uppercase;">Cancellation Reason</span>
                    <select
                        id="cancelDeliveryReason"
                        name="reason"
                        required
                        style="width:100%; border:1px solid #cbd5e1; border-radius:12px; padding:0.85rem 1rem; font-size:0.95rem; color:#0f172a; background:#fff;">
                        <option value="">Select reason</option>
                        <?php foreach ($manager_cancel_reasons as $reason_option): ?>
                            <option value="<?php echo htmlspecialchars($reason_option); ?>"><?php echo htmlspecialchars($reason_option); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label style="display:grid; gap:0.45rem;">
                    <span style="font-size:0.78rem; font-weight:700; letter-spacing:0.05em; color:#475569; text-transform:uppercase;">Remarks (Optional)</span>
                    <textarea
                        id="cancelDeliveryRemarks"
                        name="remarks"
                        rows="3"
                        placeholder="Add more context if needed..."
                        style="width:100%; border:1px solid #cbd5e1; border-radius:12px; padding:0.85rem 1rem; font-size:0.95rem; color:#0f172a; background:#fff; resize:vertical;"></textarea>
                </label>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:0.75rem; margin-top:1.75rem;">
                <button type="button" onclick="closeCancelDeliveryModal()" class="btn btn-secondary">Close</button>
                <button type="submit" class="btn btn-primary" style="background:linear-gradient(135deg, #ef4444 0%, #dc2626 100%); border:none;">
                    <i class="fas fa-ban"></i> Confirm Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Transfer Delivery Modal (Vehicle Issue) -->
<div class="modal" id="transferDeliveryModal" style="display:none; position:fixed; z-index:1100; left:0; top:0; width:100%; height:100%; background-color:rgba(15,23,42,0.55); align-items:center; justify-content:center; padding:1rem;">
    <div class="modal-content" style="background:#fff; padding:2rem; border-radius:1rem; width:100%; max-width:560px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; margin-bottom:1.5rem; border-bottom:1px solid #e2e8f0; padding-bottom:1rem;">
            <div>
                <h2 id="transferModalTitle" style="font-weight:700; color:#0f172a; margin:0 0 0.35rem;"><i class="fas fa-exchange-alt" style="color:#d97706;"></i> Transfer Delivery — Vehicle Issue</h2>
                <p id="transferModalSubtitle" style="margin:0; color:#64748b; font-size:0.92rem;">The original rider reported a vehicle issue. Assign a new rider to take over the goods and continue the delivery.</p>
            </div>
            <button type="button" onclick="closeTransferModal()" style="border:none; background:none; font-size:1.5rem; cursor:pointer; color:#94a3b8;">&times;</button>
        </div>

        <form method="POST" action="delivery.php">
            <?php echo csrfTokenField(); ?>
            <input type="hidden" name="action" value="transfer_returning_delivery">
            <input type="hidden" name="redirect_to" value="../pages/delivery.php">
            <input type="hidden" name="delivery_id" id="transferDeliveryId" value="">

            <div style="display:grid; gap:1rem;">
                <!-- Vehicle Issue Info -->
                <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:12px; padding:1rem;">
                    <div style="display:flex; align-items:center; gap:0.75rem;">
                        <span style="font-size:1.5rem;">⚠️</span>
                        <div>
                            <strong style="color:#92400e; font-size:0.9rem;">Vehicle Issue Reported</strong>
                            <p id="transferInfoText" style="margin:0.25rem 0 0; color:#b45309; font-size:0.85rem;">The rider is stuck with the goods at the breakdown location. Assign a new rider to pick up and continue.</p>
                        </div>
                    </div>
                </div>

                <!-- Current Rider Display -->
                <div style="background:#f1f5f9; border-radius:12px; padding:0.85rem 1rem;">
                    <span style="font-size:0.75rem; font-weight:700; color:#64748b; text-transform:uppercase;">Current Rider</span>
                    <p id="transferCurrentRider" style="margin:0.25rem 0 0; font-weight:600; color:#0f172a;">—</p>
                </div>

                <!-- Rider Select -->
                <label style="display:grid; gap:0.45rem;">
                    <span style="font-size:0.78rem; font-weight:700; letter-spacing:0.05em; color:#475569; text-transform:uppercase;">Transfer to Available Rider</span>
                    <select
                        id="transferRiderId"
                        name="new_rider_id"
                        required
                        style="width:100%; border:1px solid #cbd5e1; border-radius:12px; padding:0.85rem 1rem; font-size:0.95rem; color:#0f172a; background:#fff;">
                        <option value="">Select a rider</option>
                        <?php foreach ($riders as $r): ?>
                            <option value="<?php echo (int)$r['User_ID']; ?>">
                                <?php echo htmlspecialchars($r['name'] ?? ('User #' . (int)$r['User_ID'])); ?>
                                (<?php echo (int)($r['active_delivery_count'] ?? 0); ?> active)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($riders)): ?>
                        <span style="font-size:0.82rem; color:#dc2626;">No available riders. Mark a rider as Available first.</span>
                    <?php else: ?>
                        <span style="font-size:0.82rem; color:#64748b;">This rider will go to the breakdown location to pick up the goods and continue the delivery.</span>
                    <?php endif; ?>
                </label>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:0.75rem; margin-top:1.75rem;">
                <button type="button" onclick="closeTransferModal()" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background:linear-gradient(135deg, #d97706 0%, #b45309 100%); border:none;" <?php echo empty($riders) ? 'disabled' : ''; ?>>
                    <i class="fas fa-exchange-alt"></i> Transfer Delivery
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Transfer Timeline Modal -->
<div class="modal" id="transferTimelineModal" style="display:none; position:fixed; z-index:1200; left:0; top:0; width:100%; height:100%; background-color:rgba(15,23,42,0.55); align-items:center; justify-content:center; padding:1rem;">
    <div class="modal-content" style="background:#fff; padding:2rem; border-radius:1rem; width:100%; max-width:560px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; margin-bottom:1.5rem;">
            <div>
                <h2 id="timelineModalTitle" style="font-weight:700; color:#0f172a; margin:0 0 0.35rem;"><i class="fas fa-exchange-alt" style="color:#d97706;"></i> Transfer History</h2>
                <p id="timelineModalSubtitle" style="margin:0; color:#64748b; font-size:0.92rem;">Delivery reassignment history.</p>
            </div>
            <button type="button" onclick="closeTransferTimeline()" style="border:none; background:none; font-size:1.5rem; cursor:pointer; color:#94a3b8;">&times;</button>
        </div>
        <div id="timelineContent" style="max-height:400px; overflow-y:auto;">
            <p style="color:#94a3b8; font-style:italic;">Loading...</p>
        </div>
        <div style="display:flex; justify-content:flex-end; margin-top:1.5rem; border-top:1px solid #e2e8f0; padding-top:1rem;">
            <button type="button" onclick="closeTransferTimeline()" class="btn btn-secondary">Close</button>
        </div>
    </div>
</div>

<!-- Bulk Transfer Modal (Transfer All) -->
<div class="modal" id="bulkTransferModal" style="display:none; position:fixed; z-index:1100; left:0; top:0; width:100%; height:100%; background-color:rgba(15,23,42,0.55); align-items:center; justify-content:center; padding:1rem;">
    <div class="modal-content" style="background:#fff; padding:2rem; border-radius:1rem; width:100%; max-width:640px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; margin-bottom:1.5rem; border-bottom:1px solid #e2e8f0; padding-bottom:1rem;">
            <div>
                <h2 id="bulkTransferModalTitle" style="font-weight:700; color:#0f172a; margin:0 0 0.35rem;"><i class="fas fa-exchange-alt" style="color:#d97706;"></i> Bulk Transfer — Vehicle Issue</h2>
                <p id="bulkTransferModalSubtitle" style="margin:0; color:#64748b; font-size:0.92rem;">Transfer all active deliveries to an available rider.</p>
            </div>
            <button type="button" onclick="closeBulkTransferModal()" style="border:none; background:none; font-size:1.5rem; cursor:pointer; color:#94a3b8;">&times;</button>
        </div>

        <form method="POST" action="delivery.php">
            <?php echo csrfTokenField(); ?>
            <input type="hidden" name="action" value="bulk_transfer">
            <input type="hidden" name="redirect_to" value="../pages/delivery.php">
            <input type="hidden" name="source_rider_id" id="bulkSourceRiderId" value="">

            <div style="display:grid; gap:1rem;">
                <!-- Vehicle Issue Info -->
                <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:12px; padding:1rem;">
                    <div style="display:flex; align-items:center; gap:0.75rem;">
                        <span style="font-size:1.5rem;">⚠️</span>
                        <div>
                            <strong style="color:#92400e; font-size:0.9rem;">Vehicle Issue Reported</strong>
                            <p id="bulkTransferInfoText" style="margin:0.25rem 0 0; color:#b45309; font-size:0.85rem;">The rider reported a vehicle issue. All their active deliveries need to be reassigned.</p>
                        </div>
                    </div>
                </div>

                <!-- Current Rider Display -->
                <div style="background:#f1f5f9; border-radius:12px; padding:0.85rem 1rem;">
                    <span style="font-size:0.75rem; font-weight:700; color:#64748b; text-transform:uppercase;">Source Rider</span>
                    <p id="bulkCurrentRider" style="margin:0.25rem 0 0; font-weight:600; color:#0f172a;">—</p>
                </div>

                <!-- Active Deliveries List -->
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden;">
                    <div style="padding:0.75rem 1rem; background:#f1f5f9; border-bottom:1px solid #e2e8f0; font-size:0.78rem; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.05em;">
                        <i class="fas fa-list"></i> Active Deliveries to Transfer
                    </div>
                    <div id="bulkDeliveryList" style="max-height:220px; overflow-y:auto; padding:0.5rem;">
                        <p style="color:#94a3b8; font-style:italic; text-align:center; padding:1rem;">Loading...</p>
                    </div>
                </div>

                <!-- Rider Select -->
                <label style="display:grid; gap:0.45rem;">
                    <span style="font-size:0.78rem; font-weight:700; letter-spacing:0.05em; color:#475569; text-transform:uppercase;">Transfer All To</span>
                    <select
                        id="bulkTransferRiderId"
                        name="new_rider_id"
                        required
                        style="width:100%; border:1px solid #cbd5e1; border-radius:12px; padding:0.85rem 1rem; font-size:0.95rem; color:#0f172a; background:#fff;">
                        <option value="">Select a rider</option>
                        <?php foreach ($riders as $r): ?>
                            <option value="<?php echo (int)$r['User_ID']; ?>">
                                <?php echo htmlspecialchars($r['name'] ?? ('User #' . (int)$r['User_ID'])); ?>
                                (<?php echo (int)($r['active_delivery_count'] ?? 0); ?> active)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($riders)): ?>
                        <span style="font-size:0.82rem; color:#dc2626;">No available riders. Mark a rider as Available first.</span>
                    <?php else: ?>
                        <span style="font-size:0.82rem; color:#64748b;">This rider will take over all deliveries from the affected rider.</span>
                    <?php endif; ?>
                </label>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:0.75rem; margin-top:1.75rem;">
                <button type="button" onclick="closeBulkTransferModal()" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background:linear-gradient(135deg, #d97706 0%, #b45309 100%); border:none;" <?php echo empty($riders) ? 'disabled' : ''; ?>>
                    <i class="fas fa-exchange-alt"></i> Transfer All Deliveries
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Lightbox Modal -->
<div id="imageLightbox" class="lightbox-overlay" onclick="closeLightbox()">
    <span class="lightbox-close" onclick="closeLightbox()">&times;</span>
    <img class="lightbox-content" id="lightboxImg" onclick="event.stopPropagation()">
</div>

<script src="../assets/js/script.js"></script>
<script>
const canConfirmDelivered = <?php echo $can_confirm_delivered ? 'true' : 'false'; ?>;
const transferData = <?php echo json_encode($transfer_map, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const bulkTransferData = <?php echo json_encode($bulk_transfer_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

function openCancelDeliveryModal(payload) {
    const modal = document.getElementById('cancelDeliveryModal');
    const deliveryIdInput = document.getElementById('cancelDeliveryId');
    const reasonSelect = document.getElementById('cancelDeliveryReason');
    const remarksInput = document.getElementById('cancelDeliveryRemarks');
    const title = document.getElementById('cancelModalTitle');
    const subtitle = document.getElementById('cancelModalSubtitle');
    if (!modal || !deliveryIdInput || !reasonSelect) {
        return;
    }

    deliveryIdInput.value = payload.deliveryId || '';
    reasonSelect.value = '';
    remarksInput.value = '';

    if (title) {
        title.innerHTML = `<i class="fas fa-ban" style="color:#dc2626;"></i> Cancel Delivery #${payload.deliveryId || 'N/A'}`;
    }
    if (subtitle) {
        const customer = payload.customerName || 'Customer';
        const orderLabel = payload.orderId ? `Order #${payload.orderId}` : 'this order';
        subtitle.textContent = `Cancel the scheduled delivery attempt for ${customer}. ${orderLabel} will go back to manager review.`;
    }

    modal.style.display = 'flex';
}

function closeCancelDeliveryModal() {
    const modal = document.getElementById('cancelDeliveryModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

function openRescheduleModal(payload) {
    const modal = document.getElementById('rescheduleDeliveryModal');
    const deliveryIdInput = document.getElementById('rescheduleDeliveryId');
    const dateInput = document.getElementById('rescheduleDate');
    const riderSelect = document.getElementById('rescheduleRiderId');
    const title = document.getElementById('rescheduleModalTitle');
    const subtitle = document.getElementById('rescheduleModalSubtitle');
    if (!modal || !deliveryIdInput || !dateInput || !riderSelect) {
        return;
    }

    const defaultDate = payload.scheduleDate && payload.scheduleDate >= dateInput.min ? payload.scheduleDate : dateInput.min;
    deliveryIdInput.value = payload.deliveryId || '';
    dateInput.value = defaultDate || '';
    riderSelect.value = '';

    if (title) {
        title.innerHTML = `<i class="fas fa-calendar-plus" style="color:#0f766e;"></i> Reschedule Order #${payload.orderId || 'N/A'}`;
    }
    if (subtitle) {
        const customer = payload.customerName || 'Customer';
        subtitle.textContent = `Create a new scheduled delivery for ${customer}. The current cancelled/returned attempt will stay in history.`;
    }

    modal.style.display = 'flex';
}

function closeRescheduleModal() {
    const modal = document.getElementById('rescheduleDeliveryModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

function openTransferDeliveryModal(payload) {
    const modal = document.getElementById('transferDeliveryModal');
    const deliveryIdInput = document.getElementById('transferDeliveryId');
    const riderSelect = document.getElementById('transferRiderId');
    const currentRider = document.getElementById('transferCurrentRider');
    const infoText = document.getElementById('transferInfoText');
    if (!modal || !deliveryIdInput || !riderSelect) return;

    deliveryIdInput.value = payload.deliveryId || '';
    riderSelect.value = '';

    // Show all options first, then hide the current rider
    for (var i = 0; i < riderSelect.options.length; i++) {
        riderSelect.options[i].style.display = '';
    }
    var currId = parseInt(payload.currentRiderId, 10);
    if (currId > 0) {
        for (var i = 0; i < riderSelect.options.length; i++) {
            if (parseInt(riderSelect.options[i].value, 10) === currId) {
                riderSelect.options[i].style.display = 'none';
                break;
            }
        }
    }

    if (currentRider) currentRider.textContent = payload.currentRider || 'Unknown';
    if (infoText) infoText.textContent = `Delivery #${payload.deliveryId} was flagged as Vehicle issue. Assign a new rider to take over the goods at the breakdown location and continue the delivery.`;

    modal.style.display = 'flex';
}

function closeTransferModal() {
    const modal = document.getElementById('transferDeliveryModal');
    if (modal) {
        modal.style.display = 'none';
    }
}

function openTransferTimeline(deliveryId) {
    const modal = document.getElementById('transferTimelineModal');
    const content = document.getElementById('timelineContent');
    const subtitle = document.getElementById('timelineModalSubtitle');
    if (!modal || !content) return;

    subtitle.textContent = 'Delivery #' + deliveryId + ' reassignment history.';
    const transfers = transferData[deliveryId] || [];
    if (transfers.length === 0) {
        content.innerHTML = '<p style="color:#94a3b8; font-style:italic;">No transfer history for this delivery.</p>';
    } else {
        var html = '<div style="position:relative; padding-left:1.75rem;">';
        html += '<div style="position:absolute; left:0.5rem; top:0.25rem; bottom:0.25rem; width:2px; background:#e2e8f0;"></div>';
        for (var i = 0; i < transfers.length; i++) {
            var t = transfers[i];
            var dateStr = t.created_at ? new Date(t.created_at).toLocaleString() : 'Unknown date';
            html += '<div style="position:relative; margin-bottom:1.25rem;">';
            html += '<div style="position:absolute; left:-1.25rem; top:0.25rem; width:12px; height:12px; border-radius:50%; background:#d97706; border:2px solid #fff; box-shadow:0 0 0 2px #fcd34d;"></div>';
            html += '<div style="background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:0.75rem 1rem;">';
            html += '<div style="font-size:0.75rem; color:#92400e; font-weight:700;">' + dateStr + '</div>';
            html += '<div style="margin-top:0.35rem; font-size:0.85rem; color:#0f172a;">';
            html += '<i class="fas fa-user" style="color:#d97706;"></i> ' + escapeHtml(t.from_rider_name || 'Unknown') + ' → ' + escapeHtml(t.to_rider_name || 'Unknown');
            html += '</div>';
            html += '<div style="margin-top:0.2rem; font-size:0.75rem; color:#64748b;">Reason: ' + escapeHtml(t.reason || 'N/A') + '</div>';
            html += '</div></div>';
        }
        html += '</div>';
        content.innerHTML = html;
    }
    modal.style.display = 'flex';
}

function closeTransferTimeline() {
    const modal = document.getElementById('transferTimelineModal');
    if (modal) modal.style.display = 'none';
}

function openBulkTransferModal(riderId, riderName) {
    const modal = document.getElementById('bulkTransferModal');
    const riderInput = document.getElementById('bulkSourceRiderId');
    const riderDisplay = document.getElementById('bulkCurrentRider');
    const riderSelect = document.getElementById('bulkTransferRiderId');
    const deliveryList = document.getElementById('bulkDeliveryList');
    if (!modal || !riderInput || !riderDisplay || !riderSelect || !deliveryList) return;

    riderInput.value = riderId;
    riderDisplay.textContent = riderName || 'Unknown';

    // Hide the source rider from the dropdown
    for (var i = 0; i < riderSelect.options.length; i++) {
        riderSelect.options[i].style.display = '';
    }
    var srcId = parseInt(riderId, 10);
    if (srcId > 0) {
        for (var i = 0; i < riderSelect.options.length; i++) {
            if (parseInt(riderSelect.options[i].value, 10) === srcId) {
                riderSelect.options[i].style.display = 'none';
                break;
            }
        }
    }
    riderSelect.value = '';

    // Render delivery list
    var data = bulkTransferData[riderId];
    var deliveries = data ? data.deliveries : [];
    if (deliveries.length === 0) {
        deliveryList.innerHTML = '<p style="color:#94a3b8; font-style:italic; text-align:center; padding:1rem;">No active deliveries found for this rider.</p>';
    } else {
        var html = '';
        for (var i = 0; i < deliveries.length; i++) {
            var d = deliveries[i];
            var statusColors = {
                'Scheduled': { bg: '#dbeafe', color: '#1d4ed8' },
                'In Transit': { bg: '#fef3c7', color: '#b45309' },
                'Returning': { bg: '#e0f2fe', color: '#0369a1' }
            };
            var sc = statusColors[d.status] || { bg: '#f1f5f9', color: '#475569' };
            var dateStr = d.schedule_date ? new Date(d.schedule_date).toLocaleDateString() : '—';
            html += '<div style="display:flex; align-items:center; gap:0.5rem; padding:0.5rem 0.75rem; border-bottom:1px solid #f1f5f9;">';
            html += '<span style="font-weight:700; color:#0f172a; font-size:0.85rem; min-width:70px;">#' + d.delivery_id + '</span>';
            html += '<span style="color:#475569; font-size:0.82rem; min-width:50px;">O#' + d.order_id + '</span>';
            html += '<span style="color:#334155; font-size:0.82rem; flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">' + escapeHtml(d.customer_name || 'Unknown') + '</span>';
            html += '<span style="display:inline-flex; align-items:center; gap:0.25rem; padding:0.2rem 0.55rem; border-radius:6px; font-size:0.7rem; font-weight:600; background:' + sc.bg + '; color:' + sc.color + ';">' + escapeHtml(d.status) + '</span>';
            html += '</div>';
        }
        var count = deliveries.length;
        var headerHtml = '<div style="display:flex; justify-content:space-between; align-items:center; padding:0.5rem 0.75rem; background:#f1f5f9; border-bottom:1px solid #e2e8f0; font-size:0.78rem; font-weight:600; color:#475569;">';
        headerHtml += '<span>' + count + ' delivery/deliveries</span>';
        headerHtml += '<span style="color:#d97706;"><i class="fas fa-exchange-alt"></i> Will be transferred</span>';
        headerHtml += '</div>';
        deliveryList.innerHTML = headerHtml + html;
    }

    modal.style.display = 'flex';
}

function closeBulkTransferModal() {
    const modal = document.getElementById('bulkTransferModal');
    if (modal) modal.style.display = 'none';
}

function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

function updateDeliveryStatus(deliveryId, currentStatus, allowDelivered) {
    if (currentStatus !== 'Scheduled') {
        alert('Only Scheduled deliveries can be updated manually from Delivery Management.');
        return;
    }
    let statuses = ['Scheduled', 'In Transit'];
    if (allowDelivered) statuses.push('Delivered');
    const currentIndex = statuses.indexOf(currentStatus);
    const nextStatus = currentIndex < statuses.length - 1 ? statuses[currentIndex + 1] : currentStatus;
    
    if (confirm(`Update delivery status from "${currentStatus}" to "${nextStatus}"?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        const pageName = (window.location.pathname || '').split('/').pop() || 'delivery.php';
        form.action = pageName;
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'update_delivery_status';
        form.appendChild(actionInput);
        
        const csrfToken = (typeof window.csrfToken === 'string' && window.csrfToken)
            || document.querySelector('meta[name="csrf-token"]')?.content
            || document.querySelector('input[name="csrf_token"]')?.value
            || '';
        if (csrfToken) {
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = csrfToken;
            form.appendChild(csrfInput);
        }
        
        const deliveryIdInput = document.createElement('input');
        deliveryIdInput.type = 'hidden';
        deliveryIdInput.name = 'delivery_id';
        deliveryIdInput.value = deliveryId;
        form.appendChild(deliveryIdInput);
        
        const statusInput = document.createElement('input');
        statusInput.type = 'hidden';
        statusInput.name = 'new_status';
        statusInput.value = nextStatus;
        form.appendChild(statusInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

function viewDeliveryDetails(deliveryId) {
    const modal = document.getElementById('deliveryDetailsModal');
    const body = document.getElementById('deliveryDetailsBody');
    if (!modal || !body) return;

    modal.style.display = 'flex';
    body.innerHTML = '<div style="text-align:center; padding: 2rem;"><i class="fas fa-spinner fa-spin" style="color:#6366f1;"></i> Loading...</div>';

    fetch(`../api/get_delivery_details.php?delivery_id=${deliveryId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                body.innerHTML = `<div class="alert alert-danger">${data.message || 'Failed to load details'}</div>`;
                return;
            }

            // Status badge color mapping
            const statusColors = {
                'scheduled': { bg: '#dbeafe', color: '#1e40af', icon: 'fa-calendar' },
                'in-transit': { bg: '#fef3c7', color: '#92400e', icon: 'fa-shipping-fast' },
                'delivered': { bg: '#d1fae5', color: '#065f46', icon: 'fa-check-circle' },
                'returning': { bg: '#e0f2fe', color: '#0284c7', icon: 'fa-rotate-left' },
                'completed': { bg: '#e0e7ff', color: '#4338ca', icon: 'fa-flag-checkered' },
                'cancelled': { bg: '#fee2e2', color: '#b91c1c', icon: 'fa-ban' },
                'remitted': { bg: '#d1fae5', color: '#047857', icon: 'fa-money-bill-wave' }
            };

            const statusKey = data.delivery.delivery_status.toLowerCase().replace(' ', '-');
            const statusStyle = statusColors[statusKey] || { bg: '#f3f4f6', color: '#374151', icon: 'fa-info-circle' };

            let itemsHtml = '';
            data.items.forEach((item, index) => {
                const isLast = index === data.items.length - 1;
                itemsHtml += `
                    <tr style="transition: background 0.2s;">
                        <td style="padding:1rem; border-bottom:${isLast ? 'none' : '1px solid #f1f5f9'}; font-weight:500; color:#1e293b;">
                            <div style="display:flex; align-items:center; gap:0.75rem;">
                                <div style="width:36px; height:36px; border-radius:8px; background:linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); display:flex; align-items:center; justify-content:center; color:white; font-size:0.875rem;">
                                    <i class="fas fa-cube"></i>
                                </div>
                                <span>${item.product_name}</span>
                            </div>
                        </td>
                        <td style="padding:1rem; border-bottom:${isLast ? 'none' : '1px solid #f1f5f9'}; text-align:center;">
                            <span style="background:#f1f5f9; color:#475569; padding:0.35rem 0.75rem; border-radius:20px; font-weight:600; font-size:0.875rem;">${item.ordered_qty} ${item.unit}</span>
                        </td>
                        <td style="padding:1rem; border-bottom:${isLast ? 'none' : '1px solid #f1f5f9'}; text-align:center;">
                            <span style="background:${item.received_qty > 0 ? '#d1fae5' : '#f1f5f9'}; color:${item.received_qty > 0 ? '#065f46' : '#475569'}; padding:0.35rem 0.75rem; border-radius:20px; font-weight:600; font-size:0.875rem;">
                                <i class="fas fa-check" style="margin-right:0.25rem; font-size:0.75rem;"></i>${item.received_qty}
                            </span>
                        </td>
                        <td style="padding:1rem; border-bottom:${isLast ? 'none' : '1px solid #f1f5f9'}; text-align:center;">
                            ${item.damage_qty > 0 ? `
                                <span style="background:#fee2e2; color:#b91c1c; padding:0.35rem 0.75rem; border-radius:20px; font-weight:600; font-size:0.875rem;">
                                    <i class="fas fa-exclamation-triangle" style="margin-right:0.25rem; font-size:0.75rem;"></i>${item.damage_qty}
                                </span>
                            ` : `
                                <span style="background:#f1f5f9; color:#64748b; padding:0.35rem 0.75rem; border-radius:20px; font-weight:500; font-size:0.875rem;">0</span>
                            `}
                        </td>
                    </tr>
                `;
            });

            const riderName = data.delivery.delivered_by || data.delivery.assigned_rider_name;

            body.innerHTML = `
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; margin-bottom:1.5rem;">
                    <div style="background:#ffffff; padding:1.5rem; border-radius:16px; border:1px solid #e2e8f0; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);">
                        <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:1rem;">
                            <div style="width:28px; height:28px; border-radius:8px; background:linear-gradient(135deg, #3b82f6 0%, #06b6d4 100%); display:flex; align-items:center; justify-content:center; color:white; font-size:0.75rem;">
                                <i class="fas fa-truck"></i>
                            </div>
                            <span style="font-size:0.75rem; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">DELIVERY INFO</span>
                        </div>
                        <div style="display:flex; flex-direction:column; gap:0.75rem;">
                            <div style="display:flex; align-items:center; gap:0.75rem;">
                                <span style="font-size:0.875rem; color:#64748b; font-weight:500; min-width:50px;">ID:</span>
                                <span style="background:#f1f5f9; color:#1e293b; padding:0.35rem 0.75rem; border-radius:8px; font-weight:700; font-size:0.875rem;">#${data.delivery.Delivery_ID}</span>
                            </div>
                            <div style="display:flex; align-items:center; gap:0.75rem;">
                                <span style="font-size:0.875rem; color:#64748b; font-weight:500; min-width:50px;">Order:</span>
                                <span style="background:#e0e7ff; color:#4338ca; padding:0.35rem 0.75rem; border-radius:8px; font-weight:600; font-size:0.875rem;">#${data.delivery.Order_ID || 'N/A'}</span>
                            </div>
                            <div style="display:flex; align-items:center; gap:0.75rem;">
                                <span style="font-size:0.875rem; color:#64748b; font-weight:500; min-width:50px;">Status:</span>
                                <span style="background:${statusStyle.bg}; color:${statusStyle.color}; padding:0.5rem 1rem; border-radius:20px; font-weight:700; font-size:0.875rem; display:inline-flex; align-items:center; gap:0.5rem; box-shadow:0 2px 4px rgba(0,0,0,0.05);">
                                    <i class="fas ${statusStyle.icon}" style="font-size:0.75rem;"></i>
                                    ${data.delivery.delivery_status}
                                </span>
                            </div>
                        </div>
                    </div>
                    <div style="background:#ffffff; padding:1.5rem; border-radius:16px; border:1px solid #e2e8f0; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);">
                        <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:1rem;">
                            <div style="width:28px; height:28px; border-radius:8px; background:linear-gradient(135deg, #8b5cf6 0%, #ec4899 100%); display:flex; align-items:center; justify-content:center; color:white; font-size:0.75rem;">
                                <i class="fas fa-user"></i>
                            </div>
                            <span style="font-size:0.75rem; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">CUSTOMER & REP</span>
                        </div>
                        <div style="display:flex; flex-direction:column; gap:0.75rem;">
                            <div style="display:flex; align-items:center; gap:0.75rem;">
                                <span style="font-size:0.875rem; color:#64748b; font-weight:500; min-width:60px;">Client:</span>
                                <span style="color:#1e293b; font-weight:600; font-size:0.875rem;">${data.delivery.customer_name || data.delivery.delivered_to || 'Walk-in'}</span>
                            </div>
                            <div style="display:flex; align-items:flex-start; gap:0.75rem;">
                                <span style="font-size:0.875rem; color:#64748b; font-weight:500; min-width:60px; margin-top:0.125rem;">Address:</span>
                                <span style="color:#475569; font-weight:500; font-size:0.875rem; line-height:1.5;">${data.delivery.order_delivery_address || data.delivery.customer_address || 'N/A'}</span>
                            </div>
                            <div style="display:flex; align-items:center; gap:0.75rem;">
                                <span style="font-size:0.875rem; color:#64748b; font-weight:500; min-width:60px;">Rider:</span>
                                ${riderName ? `
                                    <span style="background:#fef3c7; color:#92400e; padding:0.35rem 0.75rem; border-radius:20px; font-weight:600; font-size:0.875rem; display:inline-flex; align-items:center; gap:0.35rem;">
                                        <i class="fas fa-motorcycle" style="font-size:0.75rem;"></i>
                                        ${riderName}
                                    </span>
                                ` : `
                                    <span style="background:#fee2e2; color:#b91c1c; padding:0.35rem 0.75rem; border-radius:20px; font-weight:600; font-size:0.875rem; display:inline-flex; align-items:center; gap:0.35rem;">
                                        <i class="fas fa-exclamation-circle" style="font-size:0.75rem;"></i>
                                        Not Assigned
                                    </span>
                                `}
                            </div>
                        </div>
                    </div>
                </div>

                <div style="background:#ffffff; border-radius:16px; border:1px solid #e2e8f0; overflow:hidden; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);">
                    <div style="padding:1.25rem 1.5rem; background:linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-bottom:1px solid #e2e8f0;">
                        <div style="display:flex; align-items:center; gap:0.75rem;">
                            <div style="width:32px; height:32px; border-radius:10px; background:linear-gradient(135deg, #10b981 0%, #06b6d4 100%); display:flex; align-items:center; justify-content:center; color:white; font-size:0.875rem;">
                                <i class="fas fa-boxes"></i>
                            </div>
                            <span style="font-size:1rem; font-weight:700; color:#1e293b;">Items for Delivery</span>
                            <span style="margin-left:auto; background:#6366f1; color:white; padding:0.35rem 0.875rem; border-radius:20px; font-weight:600; font-size:0.8125rem;">${data.items.length} items</span>
                        </div>
                    </div>
                    <div style="overflow-x:auto;">
                        <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
                            <thead>
                                <tr>
                                    <th style="padding:1rem; text-align:left; background:#f8fafc; color:#64748b; font-weight:700; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; border-bottom:2px solid #e2e8f0;">Product</th>
                                    <th style="padding:1rem; text-align:center; background:#f8fafc; color:#64748b; font-weight:700; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; border-bottom:2px solid #e2e8f0; width:100px;">Ordered</th>
                                    <th style="padding:1rem; text-align:center; background:#f8fafc; color:#64748b; font-weight:700; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; border-bottom:2px solid #e2e8f0; width:100px;">Received</th>
                                    <th style="padding:1rem; text-align:center; background:#f8fafc; color:#64748b; font-weight:700; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.5px; border-bottom:2px solid #e2e8f0; width:100px;">Damage</th>
                                </tr>
                            </thead>
                            <tbody>${itemsHtml}</tbody>
                        </table>
                    </div>
                </div>

                ${(() => {
                    const proofs = Array.isArray(data.proofs) ? data.proofs : [];
                    const proofPaths = proofs.map(p => (p && p.file_path ? String(p.file_path).trim() : '')).filter(Boolean);
                    const fallbackPath = data.delivery.proof_of_delivery_path ? String(data.delivery.proof_of_delivery_path).trim() : '';
                    if (!proofPaths.length && fallbackPath) {
                        proofPaths.push(fallbackPath);
                    }
                    if (!proofPaths.length) return '';
                    const firstProof = proofPaths[0];
                    const thumbs = proofPaths.map((path) => `
                        <img src="../${path}" alt="Proof" style="width:100%; height:120px; object-fit:cover; border-radius:10px; border:1px solid #e2e8f0; cursor:pointer;" onclick="openLightbox('../${path}')">
                    `).join('');
                    return `
                <div style="margin-top:1.5rem; background:#ffffff; border-radius:16px; border:1px solid #e2e8f0; overflow:hidden; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);">
                    <div style="padding:1.25rem 1.5rem; background:linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-bottom:1px solid #e2e8f0;">
                        <div style="display:flex; align-items:center; gap:0.75rem;">
                            <div style="width:32px; height:32px; border-radius:10px; background:linear-gradient(135deg, #f59e0b 0%, #ef4444 100%); display:flex; align-items:center; justify-content:center; color:white; font-size:0.875rem;">
                                <i class="fas fa-camera"></i>
                            </div>
                            <span style="font-size:1rem; font-weight:700; color:#1e293b;">Proof of Delivery</span>
                            <span style="margin-left:auto; background:#10b981; color:white; padding:0.35rem 0.875rem; border-radius:20px; font-weight:600; font-size:0.8125rem; display:inline-flex; align-items:center; gap:0.35rem;">
                                <i class="fas fa-check" style="font-size:0.75rem;"></i> Verified
                            </span>
                        </div>
                    </div>
                    <div style="padding:1.5rem; text-align:center; background:#f8fafc;">
                        <div style="display:inline-block; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px -5px rgba(0,0,0,0.15); border:3px solid white;">
                            <img src="../${firstProof}" 
                                 alt="Proof of Delivery"
                                 style="max-width:100%; max-height:300px; object-fit:cover; display:block; cursor:pointer;"
                                 onclick="openLightbox(this.src)">
                        </div>
                        <div style="margin-top:1.25rem;">
                            <button onclick="openLightbox('../${firstProof}')" style="background:linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color:white; border:none; padding:0.75rem 1.5rem; border-radius:12px; font-weight:600; font-size:0.875rem; cursor:pointer; display:inline-flex; align-items:center; gap:0.5rem; box-shadow:0 4px 6px -1px rgba(99,102,241,0.3); transition:all 0.2s;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 15px -3px rgba(99,102,241,0.4)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 6px -1px rgba(99,102,241,0.3)';">
                                <i class="fas fa-search-plus"></i> View Full Image
                            </button>
                        </div>
                        ${proofPaths.length > 1 ? `<div style="margin-top:1rem; display:grid; grid-template-columns:repeat(auto-fit, minmax(90px, 1fr)); gap:0.5rem;">${thumbs}</div>` : ''}
                    </div>
                </div>
                `;
                })()}
            `;
        })
        .catch(err => {
            body.innerHTML = `<div class="alert alert-danger">Error fetching details: ${err.message}</div>`;
        });
}

function closeDeliveryModal() {
    const modal = document.getElementById('deliveryDetailsModal');
    if (modal) modal.style.display = 'none';
}

function openLightbox(src) {
    const lightbox = document.getElementById('imageLightbox');
    const img = document.getElementById('lightboxImg');
    if (lightbox && img) {
        img.src = src;
        lightbox.style.display = 'flex';
        document.body.style.overflow = 'hidden'; // Disable background scroll
    }
}

function closeLightbox() {
    const lightbox = document.getElementById('imageLightbox');
    if (lightbox) {
        lightbox.style.display = 'none';
        document.body.style.overflow = 'auto'; // Re-enable background scroll
    }
}

// Close lightbox on Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeLightbox();
        closeDeliveryModal();
        closeCancelDeliveryModal();
        closeRescheduleModal();
        closeTransferModal();
        closeBulkTransferModal();
    }
});

window.addEventListener('click', function(event) {
    const cancelModal = document.getElementById('cancelDeliveryModal');
    const rescheduleModal = document.getElementById('rescheduleDeliveryModal');
    const detailsModal = document.getElementById('deliveryDetailsModal');
    const transferModal = document.getElementById('transferDeliveryModal');
    if (event.target === cancelModal) {
        closeCancelDeliveryModal();
    }
    if (event.target === rescheduleModal) {
        closeRescheduleModal();
    }
    if (event.target === detailsModal) {
        closeDeliveryModal();
    }
    if (event.target === transferModal) {
        closeTransferModal();
    }
    const bulkTransferModal = document.getElementById('bulkTransferModal');
    if (event.target === bulkTransferModal) {
        closeBulkTransferModal();
    }
});
</script>
</body>
</html>
