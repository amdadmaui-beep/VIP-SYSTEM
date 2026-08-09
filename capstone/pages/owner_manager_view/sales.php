<?php
session_start();
ob_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/roles_helper.php';

$management_ids = getManagementRoleIds($conn);
requireRole(empty($management_ids) ? [1] : $management_ids);

// Treat only true 'owner' accounts as view-only (by Role_ID)
$is_owner_view_only = ((int)($_SESSION['user_role'] ?? 0) === 1);

// Include backend for POST handling
require_once __DIR__ . '/../../api/sales_backend.php';

// Detect orders table status column (order_status vs status)
$order_status_col = 'order_status';
$cols_res = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
if ($cols_res && $cols_res->rowCount() > 0) {
    $row = $cols_res->fetch(PDO::FETCH_ASSOC);
    $order_status_col = $row['Field'];
}

// Check if we're filtering by a specific order
$filter_order_id = intval($_GET['delivery_order_id'] ?? 0);
// Deliveries Ready for Sale: delivery_status IN ('Delivered', 'Returning') AND no sale recorded yet
// Exclude deliveries that already have a sale (in sale_source)
$delivery_where = "d.delivery_status IN ('Delivered', 'Returning')";
$delivery_where .= " AND NOT EXISTS (SELECT 1 FROM sale_source ss WHERE ss.Delivery_ID = d.Delivery_ID)";
$delivery_where .= " AND (o.{$order_status_col} IS NULL OR o.{$order_status_col} != 'Completed')";
if ($filter_order_id > 0) {
    $delivery_where .= " AND o.Order_ID = " . intval($filter_order_id);
}

// Fetch deliveries that are ready for sale (delivery_status = 'Delivered', not yet sold)
$deliveries_query = "SELECT d.Delivery_ID, d.Order_ID, d.delivery_status, d.delivered_by, d.delivered_to,
                     o.order_date, o.{$order_status_col} as order_status, c.customer_name
                     FROM delivery d
                     LEFT JOIN orders o ON d.Order_ID = o.Order_ID
                     LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
                     WHERE $delivery_where
                     ORDER BY COALESCE(d.actual_date_arrived, d.created_at) DESC, d.Delivery_ID DESC
                     LIMIT 50";
$deliveries_result = $conn->query($deliveries_query);
$deliveries_list = [];
if ($deliveries_result) {
    $deliveries_list = $deliveries_result->fetchAll(PDO::FETCH_ASSOC);
}

// Check if status column exists in sales table
$check_status_col = $conn->query("SHOW COLUMNS FROM sales LIKE 'status'");
$has_status_col = $check_status_col && $check_status_col->rowCount() > 0;

// Detect which column stores the cashier/recorder in `sales` (if any)
$sales_cols_res = $conn->query("SHOW COLUMNS FROM sales");
$sales_cols = [];
if ($sales_cols_res) {
    while ($r = $sales_cols_res->fetch(PDO::FETCH_ASSOC)) {
        $sales_cols[] = $r['Field'];
    }
}
$sales_user_col = null;
if (in_array('created_by', $sales_cols, true)) {
    $sales_user_col = 'created_by';
} elseif (in_array('User_ID', $sales_cols, true)) {
    $sales_user_col = 'User_ID';
} elseif (in_array('user_id', $sales_cols, true)) {
    $sales_user_col = 'user_id';
}
$recorded_by_select = $sales_user_col ? "u.user_name as recorded_by, s.$sales_user_col as cashier_user_id," : "'N/A' as recorded_by, NULL as cashier_user_id,";
$recorded_by_join = $sales_user_col ? "LEFT JOIN user u ON u.User_ID = s.$sales_user_col" : "";

// ── Selected filters from GET ─────────────────────────────────────────────
$filter_cashier_id = intval($_GET['cashier_id'] ?? 0);
$filter_date = $_GET['filter_date'] ?? '';
$cashier_where = '';
if ($filter_cashier_id > 0 && $sales_user_col) {
    $cashier_where = " AND s.$sales_user_col = " . $filter_cashier_id;
}
$date_where = '';
if (!empty($filter_date)) {
    $date_where = " AND DATE(s.created_at) = " . $conn->quote($filter_date);
}

// ── Fetch cashiers & managers with sale stats (for card filter) ──
$cashier_filter_list = [];
$cashier_stats_all = ['sale_count' => 0, 'total_revenue' => 0];
if ($sales_user_col) {
    $cashier_date_clause = '';
    if (!empty($filter_date)) {
        $cashier_date_clause = ' AND DATE(s.created_at) = ' . $conn->quote($filter_date);
    }
    $recorder_role_ids = array_values(array_unique(array_merge(
        getCashierRoleIds($conn),
        getManagerRoleIds($conn)
    )));
    if (empty($recorder_role_ids)) {
        $recorder_role_ids = [3];
    }
    $recorder_role_in = implode(',', array_map('intval', $recorder_role_ids));
    $cashier_list_sql = "SELECT u.User_ID, u.user_name, r.role_name,
            COUNT(DISTINCT s.Sale_ID) AS sale_count,
            COALESCE(SUM(sd.subtotal), 0) AS total_revenue
        FROM sales s
        INNER JOIN user u ON u.User_ID = s.$sales_user_col
        LEFT JOIN roles r ON r.Role_ID = u.Role_ID
        LEFT JOIN sale_details sd ON sd.Sale_ID = s.Sale_ID
        WHERE u.Role_ID IN ($recorder_role_in) $cashier_date_clause
        GROUP BY u.User_ID, u.user_name, r.role_name
        ORDER BY u.user_name";
    $cl_res = $conn->query($cashier_list_sql);
    if ($cl_res) {
        $cashier_filter_list = $cl_res->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cashier_filter_list as $cf) {
            $cashier_stats_all['sale_count'] += (int)($cf['sale_count'] ?? 0);
            $cashier_stats_all['total_revenue'] += (float)($cf['total_revenue'] ?? 0);
        }
    }
}

// ── Pagination settings ─────────────────────────────────────────────────────
$sales_per_page = 20;
$sales_page = max(1, intval($_GET['sales_page'] ?? 1));
$sales_query_error = null;
$sales_total_items = 0;
$sales_total_pages = 1;
$sales_by_cashier = [];

if (!function_exists('formatSaleDate')) {
    function formatSaleDate(?string $datetime): string {
        if (empty($datetime)) {
            return '—';
        }
        $ts = strtotime($datetime);
        return ($ts !== false) ? date('M d, Y H:i', $ts) : '—';
    }
}

// Fetch sales history
$status_select = $has_status_col ? "s.status," : "'Completed' as status,";
$status_group_by = $has_status_col ? ", s.status" : "";

$count_query = "SELECT COUNT(DISTINCT s.Sale_ID) as total FROM sales s
                $recorded_by_join
                LEFT JOIN sale_source ss ON s.Sale_ID = ss.Sale_ID
                LEFT JOIN delivery d ON ss.Delivery_ID = d.Delivery_ID
                LEFT JOIN orders o ON d.Order_ID = o.Order_ID
                WHERE 1=1 $cashier_where $date_where";

try {
    $count_result = $conn->query($count_query);
    $sales_total_items = $count_result ? (int)($count_result->fetch(PDO::FETCH_ASSOC)['total'] ?? 0) : 0;
    $sales_total_pages = max(1, (int)ceil($sales_total_items / $sales_per_page));
    $sales_page = min(max(1, $sales_page), $sales_total_pages);
    $sales_offset = ($sales_page - 1) * $sales_per_page;

    $sales_query = "SELECT 
                        s.Sale_ID, 
                        s.created_at, 
                        $status_select
                        $recorded_by_select
                        d.Delivery_ID, 
                        d.delivered_to, 
                        o.Order_ID,
                        COALESCE(c.customer_name, d.delivered_to) as customer_display,
                        ss.Delivery_ID as has_delivery,
                        (SELECT COALESCE(ar2.amount_due, 0) FROM account_receivable ar2 WHERE ar2.Sale_ID = s.Sale_ID LIMIT 1) as ar_balance_due,
                        COALESCE(SUM(sd.quantity), 0) as total_qty,
                        COALESCE(SUM(sd.subtotal), 0) as total_amount,
                        COALESCE(
                            GROUP_CONCAT(
                                CONCAT(
                                    p.product_name,
                                    IF(u_units.unit_name IS NULL OR u_units.unit_name = '', '', CONCAT(' ', u_units.unit_name)),
                                    ' x', CAST(ROUND(sd.quantity, 0) AS UNSIGNED)
                                )
                                SEPARATOR ', '
                            ),
                            ''
                        ) as items_sold
                    FROM sales s
                    $recorded_by_join
                    LEFT JOIN sale_source ss ON s.Sale_ID = ss.Sale_ID
                    LEFT JOIN delivery d ON ss.Delivery_ID = d.Delivery_ID
                    LEFT JOIN orders o ON d.Order_ID = o.Order_ID
                    LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
                    LEFT JOIN sale_details sd ON sd.Sale_ID = s.Sale_ID
                    LEFT JOIN products p ON p.Product_ID = sd.Product_ID
                    LEFT JOIN units u_units ON p.unit_id = u_units.unit_id
                    WHERE 1=1 $cashier_where $date_where
                    GROUP BY s.Sale_ID, s.created_at{$status_group_by}, recorded_by, cashier_user_id, d.Delivery_ID, d.delivered_to, o.Order_ID, c.customer_name, ss.Delivery_ID
                    ORDER BY recorded_by ASC, s.created_at DESC
                    LIMIT $sales_per_page OFFSET $sales_offset";

    $sales_result = $conn->query($sales_query);
    if ($sales_result && $sales_result->rowCount() > 0) {
        while ($row = $sales_result->fetch(PDO::FETCH_ASSOC)) {
            $cname = $row['recorded_by'] ?? 'Unknown';
            $sales_by_cashier[$cname][] = $row;
        }
    }
} catch (Throwable $e) {
    error_log('Sales history query failed: ' . $e->getMessage());
    $sales_query_error = 'Unable to load sales history. Please try again or contact the administrator.';
    $sales_by_cashier = [];
}

// Get sales statistics
$sales_stats = ['total_sales' => 0, 'today_sales' => 0, 'total_revenue' => 0, 'today_revenue' => 0];
try {
    $stats_query = "SELECT 
        COUNT(*) as total_sales,
        SUM(CASE WHEN DATE(s.created_at) = CURDATE() THEN 1 ELSE 0 END) as today_sales,
        COALESCE(SUM(sd.subtotal), 0) as total_revenue,
        COALESCE(SUM(CASE WHEN DATE(s.created_at) = CURDATE() THEN sd.subtotal ELSE 0 END), 0) as today_revenue
    FROM sales s
    LEFT JOIN sale_details sd ON sd.Sale_ID = s.Sale_ID";
    $stats_result = $conn->query($stats_query);
    if ($stats_result) {
        $sales_stats = $stats_result->fetch(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    // Stats not critical
}

// Fetch products for walk-in sales
$products_query = "SELECT p.Product_ID, p.product_name, u.unit_name, p.retail_price 
                   FROM products p 
                   LEFT JOIN units u ON p.unit_id = u.unit_id 
                   WHERE p.is_discontinued = 0 
                   ORDER BY u.unit_name, p.product_name";
$products_result = $conn->query($products_query);
$products_data = [];
if ($products_result) {
    while ($product = $products_result->fetch(PDO::FETCH_ASSOC)) {
        $products_data[] = $product;
    }
}
// PDO doesn't support data_seek - products_data array is already populated

// Fetch customers for walk-in sales
$customers_query = "SELECT Customer_ID, customer_name FROM customers WHERE deleted_at IS NULL ORDER BY customer_name";
$customers_result = $conn->query($customers_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <link rel="stylesheet" href="../assets/css/style.css">
    <script>
        window.csrfToken = '<?php echo getCsrfToken(); ?>';
    </script>
    <style>
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
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
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.15);
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

        .stat-icon.total { background: linear-gradient(135deg, #6366f1 0%, #7c3aed 100%); color: white; }
        .stat-icon.today { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); color: #1d4ed8; }
        .stat-icon.revenue { background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%); color: #15803d; }
        .stat-icon.today-rev { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color: #b45309; }

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
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid #f1f5f9;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .card-header h3 {
            margin: 0;
            color: #1e293b;
            font-size: 1.125rem;
            font-weight: 600;
        }

        .sales-filter-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.25rem;
        }
        .sales-date-filter {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .sales-date-filter label {
            font-size: 0.8rem;
            font-weight: 600;
            color: #64748b;
            margin: 0;
        }
        .cashier-cards-section {
            margin-bottom: 1.5rem;
        }
        .cashier-cards-label {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #64748b;
            margin: 0 0 0.75rem 0;
        }
        .cashier-card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
        }
        .cashier-card {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            padding: 1.125rem 1.25rem;
            background: #ffffff;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            text-decoration: none;
            color: inherit;
            transition: all 0.25s ease;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.04);
            cursor: pointer;
        }
        .cashier-card:hover {
            border-color: #a5b4fc;
            transform: translateY(-3px);
            box-shadow: 0 12px 28px rgba(99, 102, 241, 0.15);
        }
        .cashier-card.is-active {
            border-color: #6366f1;
            background: linear-gradient(135deg, #f5f3ff 0%, #eef2ff 100%);
            box-shadow: 0 8px 24px rgba(99, 102, 241, 0.2);
        }
        .cashier-card-top {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .cashier-card-avatar {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        .cashier-card-avatar.all {
            background: linear-gradient(135deg, #6366f1 0%, #7c3aed 100%);
        }
        .cashier-card-avatar.c0 { background: linear-gradient(135deg, #6366f1, #7c3aed); }
        .cashier-card-avatar.c1 { background: linear-gradient(135deg, #0ea5e9, #0284c7); }
        .cashier-card-avatar.c2 { background: linear-gradient(135deg, #10b981, #059669); }
        .cashier-card-avatar.c3 { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .cashier-card-avatar.c4 { background: linear-gradient(135deg, #ec4899, #db2777); }
        .cashier-card-avatar.c5 { background: linear-gradient(135deg, #8b5cf6, #6d28d9); }
        .cashier-card-name {
            font-weight: 700;
            font-size: 0.95rem;
            color: #1e293b;
            line-height: 1.3;
        }
        .cashier-card-role {
            font-size: 0.7rem;
            color: #64748b;
            font-weight: 500;
        }
        .cashier-card-stats {
            display: flex;
            justify-content: space-between;
            gap: 0.5rem;
            padding-top: 0.75rem;
            border-top: 1px solid #e2e8f0;
        }
        .cashier-card.is-active .cashier-card-stats {
            border-top-color: #c7d2fe;
        }
        .cashier-card-stat {
            text-align: center;
            flex: 1;
        }
        .cashier-card-stat span {
            display: block;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #94a3b8;
        }
        .cashier-card-stat strong {
            display: block;
            font-size: 0.9rem;
            font-weight: 700;
            color: #1e293b;
            margin-top: 0.15rem;
        }
        .cashier-card-stat strong.revenue {
            color: #059669;
        }
        @media (max-width: 640px) {
            .cashier-card-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        @media (max-width: 420px) {
            .cashier-card-grid {
                grid-template-columns: 1fr;
            }
        }

        .table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        .table th {
            background: #f8fafc;
            padding: 0.875rem 1rem;
            text-align: left;
            font-weight: 600;
            color: #64748b;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid #f1f5f9;
        }
        .table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            font-size: 0.9375rem;
        }
        .table tr:last-child td {
            border-bottom: none;
        }
        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }
        .status-Completed { background: #dcfce7; color: #166534; }
        .status-Pending { background: #fef9c3; color: #854d0e; }
        .status-Delivered { background: #dbeafe; color: #1e40af; }
        
        .btn {
            padding: 0.625rem 1.25rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.875rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s;
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
            font-weight: 600;
            font-size: 0.875rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s;
        }
        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.8125rem;
        }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-primary:hover { background: #2563eb; }
        .btn-secondary { background: #f1f5f9; color: #475569; }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-success { background: #10b981; color: white; }
        .btn-success:hover { background: #059669; }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 1000;
            overflow-y: auto;
            padding: 1.5rem;
        }
        .modal-content {
            max-width: 850px;
            margin: 2rem auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            padding: 0;
            overflow: hidden;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.25rem 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid #f1f5f9;
        }
        .modal-header h2 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 700;
            color: #1e293b;
        }
        .modal-body {
            padding: 1.5rem;
        }
        .close {
            font-size: 1.5rem;
            cursor: pointer;
            color: #94a3b8;
            transition: color 0.2s;
        }
        .close:hover { color: #64748b; }
        
        .form-group {
            margin-bottom: 1.25rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            font-size: 0.875rem;
            color: #334155;
        }
        .form-control {
            width: 100%;
            padding: 0.625rem 0.875rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.9375rem;
            transition: border-color 0.2s, box-shadow 0.2s;
            color: #1e293b;
        }
        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .ar-section {
            background: #f8fafc;
            border-radius: 12px;
            padding: 1.25rem;
            margin: 1.5rem 0;
            border: 1px solid #e2e8f0;
        }
        .ar-toggle-container {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
            padding: 0.5rem;
            background: white;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
        }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #cbd5e1;
            transition: .3s;
            border-radius: 24px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 18px; width: 18px;
            left: 3px; bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }
        input:checked + .slider { background-color: #3b82f6; }
        input:checked + .slider:before { transform: translateX(20px); }
        
        .payment-input-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .alert {
            padding: 1rem 1.25rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 500;
            font-size: 0.9375rem;
        }
        .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .btn-ar-post {
            padding: 0.75rem 1.5rem;
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            color: white !important;
            border: 2px solid #4f46e5;
            border-radius: 10px;
            font-weight: 700;
            font-size: 1rem;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.35);
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-ar-post:hover {
            background: linear-gradient(135deg, #4f46e5 0%, #4338ca 100%);
            box-shadow: 0 6px 16px rgba(99, 102, 241, 0.45);
            transform: translateY(-1px);
        }
        .btn-ar-post.active {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            border-color: #16a34a;
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.35);
        }
    </style>
</head>
<body>
<div class="dashboard-wrapper">
    <!-- Sidebar -->
    <?php
    require_once __DIR__ . '/../../includes/sidebar.php';
    renderSidebar($conn, ['base' => '../', 'active' => 'sales']);
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
                <a href="sales.php" class="menu-item active">
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
                <a href="../pickup_orders.php" class="menu-item">
                    <i class="fas fa-shopping-basket"></i>
                    <span>Pickup Orders</span>
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
                <h1><i class="fas fa-receipt"></i> Sales Management</h1>
                <p>Sales are recorded when the delivery rider returns with payment. Inventory is reduced at this point, not during delivery.</p>
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

        <!-- Sales Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon total">
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <div class="stat-content">
                    <h4>Total Sales</h4>
                    <p><?php echo number_format($sales_stats['total_sales'] ?? 0); ?></p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon today">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div class="stat-content">
                    <h4>Today's Sales</h4>
                    <p><?php echo number_format($sales_stats['today_sales'] ?? 0); ?></p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon revenue">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-content">
                    <h4>Total Revenue</h4>
                    <p>₱<?php echo number_format($sales_stats['total_revenue'] ?? 0, 2); ?></p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon today-rev">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="stat-content">
                    <h4>Today's Revenue</h4>
                    <p>₱<?php echo number_format($sales_stats['today_revenue'] ?? 0, 2); ?></p>
                </div>
            </div>
        </div>

        <!-- Sales Report Export -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-file-export"></i> Sales Report Export</h3>
                </div>
                <div class="card-body">
                    <div style="display:flex; flex-wrap:wrap; gap:1rem; align-items:flex-end;">
                        <div class="form-group" style="margin-bottom:0; min-width:160px;">
                            <label for="sales_export_start">Start Date</label>
                            <input type="date" id="sales_export_start" class="form-control">
                        </div>
                        <div class="form-group" style="margin-bottom:0; min-width:160px;">
                            <label for="sales_export_end">End Date</label>
                            <input type="date" id="sales_export_end" class="form-control">
                        </div>
                        <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                            <button type="button" class="btn btn-secondary btn-sm" onclick="exportSalesRange()">
                                <i class="fas fa-file-export"></i> Export Range
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="exportSalesToday()">
                                <i class="fas fa-calendar-day"></i> Export Today
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sales History (grouped by cashier for Owner/Manager) -->
            <div class="card">
                <div class="card-header" style="flex-wrap:wrap; gap:1rem; border-bottom:none; margin-bottom:0; padding-bottom:0;">
                    <h3><i class="fas fa-history"></i> Sales History</h3>
                </div>

                <?php if (!empty($cashier_filter_list)): ?>
                <div class="sales-filter-toolbar">
                    <form method="GET" id="salesDateFilterForm" class="sales-date-filter">
                        <?php if ($filter_cashier_id > 0): ?>
                            <input type="hidden" name="cashier_id" value="<?= (int)$filter_cashier_id ?>">
                        <?php endif; ?>
                        <label for="filter_date"><i class="fas fa-calendar-alt"></i> Date</label>
                        <input type="date" id="filter_date" name="filter_date" class="form-control" style="width:auto; padding:0.375rem 0.75rem; font-size:0.875rem; height:auto;" value="<?= htmlspecialchars($filter_date) ?>" onchange="this.form.submit()">
                        <?php if ($filter_cashier_id > 0 || !empty($filter_date)): ?>
                            <a href="?" class="btn btn-secondary btn-sm" style="display:inline-flex; align-items:center; gap:0.25rem;"><i class="fas fa-times"></i> Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="cashier-cards-section">
                    <p class="cashier-cards-label"><i class="fas fa-users"></i> Select a cashier or manager to view their sales</p>
                    <div class="cashier-card-grid" id="cashierCardGrid">
                        <?php
                        $all_active = ($filter_cashier_id === 0);
                        $all_href = '?' . http_build_query(array_filter([
                            'filter_date' => $filter_date ?: null,
                            'sales_page' => null,
                        ]));
                        ?>
                        <a href="<?= htmlspecialchars($all_href) ?>" class="cashier-card<?= $all_active ? ' is-active' : '' ?>" id="cashier-card-all" aria-pressed="<?= $all_active ? 'true' : 'false' ?>">
                            <div class="cashier-card-top">
                                <div class="cashier-card-avatar all"><i class="fas fa-users"></i></div>
                                <div>
                                    <div class="cashier-card-name">All Staff</div>
                                    <div class="cashier-card-role">Cashiers &amp; Managers</div>
                                </div>
                            </div>
                            <div class="cashier-card-stats">
                                <div class="cashier-card-stat">
                                    <span>Sales</span>
                                    <strong><?= number_format($cashier_stats_all['sale_count']) ?></strong>
                                </div>
                                <div class="cashier-card-stat">
                                    <span>Revenue</span>
                                    <strong class="revenue">₱<?= number_format($cashier_stats_all['total_revenue'], 2) ?></strong>
                                </div>
                            </div>
                        </a>
                        <?php foreach ($cashier_filter_list as $idx => $cf):
                            $cid = (int)$cf['User_ID'];
                            $is_active = ($filter_cashier_id === $cid);
                            $card_href = '?' . http_build_query(array_filter([
                                'cashier_id' => $cid,
                                'filter_date' => $filter_date ?: null,
                            ]));
                            $avatar_class = 'c' . ($idx % 6);
                            $initial = strtoupper(substr($cf['user_name'], 0, 1));
                            $role_name_raw = strtolower((string)($cf['role_name'] ?? ''));
                            if (strpos($role_name_raw, 'manager') !== false) {
                                $role_label = 'Manager';
                            } elseif (strpos($role_name_raw, 'cashier') !== false) {
                                $role_label = 'Cashier';
                            } else {
                                $role_label = ucwords(str_replace('_', ' ', $cf['role_name'] ?? 'Staff'));
                            }
                        ?>
                        <a href="<?= htmlspecialchars($card_href) ?>" class="cashier-card<?= $is_active ? ' is-active' : '' ?>" id="cashier-card-<?= $cid ?>" aria-pressed="<?= $is_active ? 'true' : 'false' ?>">
                            <div class="cashier-card-top">
                                <div class="cashier-card-avatar <?= $avatar_class ?>"><?= htmlspecialchars($initial) ?></div>
                                <div>
                                    <div class="cashier-card-name"><?= htmlspecialchars($cf['user_name']) ?></div>
                                    <div class="cashier-card-role"><?= htmlspecialchars($role_label) ?></div>
                                </div>
                            </div>
                            <div class="cashier-card-stats">
                                <div class="cashier-card-stat">
                                    <span>Sales</span>
                                    <strong><?= number_format((int)($cf['sale_count'] ?? 0)) ?></strong>
                                </div>
                                <div class="cashier-card-stat">
                                    <span>Revenue</span>
                                    <strong class="revenue">₱<?= number_format((float)($cf['total_revenue'] ?? 0), 2) ?></strong>
                                </div>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card-body" style="padding:0; border-top:1px solid #f1f5f9;">
                    <?php if ($filter_cashier_id > 0 && !empty($cashier_filter_list)):
                        $selected_cashier_name = '';
                        foreach ($cashier_filter_list as $cf) {
                            if ((int)$cf['User_ID'] === $filter_cashier_id) {
                                $selected_cashier_name = $cf['user_name'];
                                break;
                            }
                        }
                        if ($selected_cashier_name !== ''): ?>
                    <div style="padding:0.875rem 1.5rem; background:#eef2ff; border-bottom:1px solid #c7d2fe; display:flex; align-items:center; gap:0.5rem; font-size:0.875rem; color:#4338ca;">
                        <i class="fas fa-user-check"></i>
                        Showing sales recorded by <strong><?= htmlspecialchars($selected_cashier_name) ?></strong>
                        <?php if (!empty($filter_date)): ?>
                            on <strong><?= htmlspecialchars(date('M d, Y', strtotime($filter_date))) ?></strong>
                        <?php endif; ?>
                    </div>
                    <?php endif; endif; ?>
                    <?php if ($sales_query_error): ?>
                        <div style="margin:1.5rem; padding:1rem 1.25rem; background:#fef2f2; border:1px solid #fecaca; border-radius:12px; color:#991b1b; font-size:0.9rem;">
                            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($sales_query_error) ?>
                        </div>
                    <?php elseif (!empty($sales_by_cashier)): ?>
                        <?php foreach ($sales_by_cashier as $cashier_name => $cashier_sales):
                            $cashier_total_amt = array_sum(array_column($cashier_sales, 'total_amount'));
                            $cashier_total_qty = array_sum(array_column($cashier_sales, 'total_qty'));
                        ?>
                        <!-- Cashier section header -->
                        <div style="display:flex; justify-content:space-between; align-items:center; padding:0.75rem 1.5rem; background: linear-gradient(135deg,#f8fafc,#f1f5f9); border-top:2px solid #e2e8f0; border-bottom:1px solid #e2e8f0;">
                            <div style="display:flex; align-items:center; gap:0.625rem;">
                                <div style="width:32px; height:32px; border-radius:50%; background:linear-gradient(135deg,#6366f1,#7c3aed); display:flex; align-items:center; justify-content:center; color:white; font-size:0.75rem; font-weight:700;">
                                    <?= strtoupper(substr($cashier_name, 0, 1)) ?>
                                </div>
                                <div>
                                    <div style="font-weight:700; color:#1e293b; font-size:0.95rem;"><?= htmlspecialchars($cashier_name) ?></div>
                                    <div style="font-size:0.75rem; color:#64748b;"><?= count($cashier_sales) ?> transaction<?= count($cashier_sales) !== 1 ? 's' : '' ?></div>
                                </div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-size:0.75rem; color:#64748b;">Total Qty: <strong><?= number_format((int)$cashier_total_qty) ?></strong></div>
                                <div style="font-size:0.875rem; font-weight:700; color:#059669;">₱<?= number_format($cashier_total_amt, 2) ?></div>
                            </div>
                        </div>
                        <!-- Cashier sales table -->
                        <div class="table-scrollable">
                        <table class="table" style="margin:0;">
                            <thead>
                                <tr>
                                    <th>Sale #</th>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Customer/Order</th>
                                    <th>Items Sold</th>
                                    <th>Total Qty</th>
                                    <th>Total Amount</th>
                                    <th>Payment</th>
                                    <th>Status</th>
                                    <th>View</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cashier_sales as $sale):
                                    $sale_type = $sale['has_delivery'] ? 'Pre-Order' : 'Walk-in';
                                    $customer_info = $sale['customer_display'] ?? $sale['delivered_to'] ?? ($sale['Order_ID'] ? 'Order #' . $sale['Order_ID'] : 'N/A');
                                    $ar_bal = floatval($sale['ar_balance_due'] ?? 0);
                                ?>
                                <tr>
                                    <td><strong>#<?= $sale['Sale_ID'] ?></strong></td>
                                    <td><?= formatSaleDate($sale['created_at'] ?? null) ?></td>
                                    <td>
                                        <span style="font-size:0.75rem; font-weight:600; padding:0.25rem 0.625rem; border-radius:9999px; background:<?= $sale['has_delivery'] ? '#dbeafe' : '#f0fdf4' ?>; color:<?= $sale['has_delivery'] ? '#1d4ed8' : '#15803d' ?>;">
                                            <?= $sale_type ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($customer_info) ?></td>
                                    <td style="max-width:280px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars($sale['items_sold'] ?? '') ?></td>
                                    <td><strong><?= number_format((int)round(floatval($sale['total_qty'] ?? 0))) ?></strong></td>
                                    <td><strong>₱<?= number_format($sale['total_amount'] ?? 0, 2) ?></strong></td>
                                    <td>
                                        <?php if ($ar_bal > 0): ?>
                                            <span class="status-badge" style="background:#fef3c7;color:#92400e;" title="AR Balance: ₱<?= number_format($ar_bal, 2) ?>">
                                                <i class="fas fa-clock"></i> Partial (AR)
                                            </span>
                                        <?php else: ?>
                                            <span class="status-badge" style="background:#dcfce7;color:#166534;">
                                                <i class="fas fa-check"></i> Fully Paid
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($has_status_col): ?>
                                            <span class="status-badge status-<?= $sale['status'] ?>"><?= $sale['status'] ?></span>
                                        <?php else: ?>
                                            <span class="status-badge status-Completed">Completed</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="openSaleViewModal(<?= intval($sale['Sale_ID']) ?>)" title="View full sale details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="text-align:center; color:#6b7280; padding:2rem;">No sales recorded yet.</p>
                    <?php endif; ?>

                    <?php if ($sales_total_pages > 1): ?>
                    <div style="display: flex; justify-content: center; align-items: center; gap: 0.5rem; padding: 1.5rem; border-top: 1px solid #e2e8f0; background: #f8fafc;">
                        <a href="?cashier_id=<?php echo $filter_cashier_id; ?>&filter_date=<?php echo urlencode($filter_date); ?>&sales_page=<?php echo max(1, $sales_page - 1); ?>" class="btn btn-secondary btn-sm" <?php echo $sales_page <= 1 ? 'style="pointer-events:none;opacity:0.5;"' : ''; ?>>
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                        <span style="font-size: 0.875rem; color: #475569; font-weight: 600;">
                            Page <?php echo $sales_page; ?> of <?php echo $sales_total_pages; ?> (<?php echo $sales_total_items; ?> total)
                        </span>
                        <a href="?cashier_id=<?php echo $filter_cashier_id; ?>&filter_date=<?php echo urlencode($filter_date); ?>&sales_page=<?php echo min($sales_total_pages, $sales_page + 1); ?>" class="btn btn-secondary btn-sm" <?php echo $sales_page >= $sales_total_pages ? 'style="pointer-events:none;opacity:0.5;"' : ''; ?>>
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                    <?php endif; ?>

                    <style>
                        .table-scrollable {
                            max-height: 500px;
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
            </div>
        </div>
    </main>
</div>

<!-- Create Sale from Delivery Modal -->
<div id="saleFromDeliveryModal" class="modal">
    <div class="modal-content" style="max-width: 1100px; width: 95vw;">
        <div class="modal-header">
            <h2><i class="fas fa-truck"></i> Record Sale from Delivery</h2>
            <span class="close" onclick="closeSaleFromDeliveryModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div style="background: #fef3c7; padding: 1rem; border-radius: 8px; margin-bottom: 1.25rem; border-left: 4px solid #f59e0b; font-size: 0.875rem; color: #92400e;">
                <strong><i class="fas fa-info-circle"></i> Delivery Confirmation:</strong>
                <p style="margin: 0.25rem 0 0 0;">
                    Update received/damage quantities as reported by the rider. Inventory will be reduced upon confirmation.
                </p>
            </div>
            <form id="saleFromDeliveryForm" method="POST">
                <?php echo csrfTokenField(); ?>
                <input type="hidden" name="action" value="create_sale_from_delivery">
                <input type="hidden" name="delivery_id" id="delivery_id">
                <input type="hidden" name="post_to_ar" id="post_to_ar_hidden" value="0">
                
                <div id="delivery_details_container"></div>

                <div class="ar-section" style="margin-top: 1.5rem;">
                    <button type="button" id="post_to_ar_btn" class="btn btn-ar-post" onclick="togglePostToAR()">
                        <i class="fas fa-file-invoice-dollar"></i> Post to Accounts Receivable (AR)
                    </button>
                    <p style="font-size: 0.8125rem; color: #64748b; margin: 0.5rem 0 0 0;">
                        Click if customer pays partially. The balance will be recorded in their AR account.
                    </p>
                    
                    <div id="ar_payment_details" style="display: none; margin-top: 1.25rem; padding: 1.25rem; background: white; border-radius: 8px; border: 1px solid #e2e8f0;">
                        <h4 style="margin: 0 0 1rem 0; font-size: 1rem; color: #1e293b;"><i class="fas fa-money-bill-wave"></i> Partial Payment Details</h4>
                        <div class="payment-input-group" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="amount_paid">Amount Paid Now (₱) *</label>
                                <input type="number" id="amount_paid" name="amount_paid" class="form-control" step="0.01" min="0" value="0" placeholder="0.00">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Balance to AR (₱)</label>
                                <input type="text" id="ar_balance" class="form-control" readonly value="0.00" style="background: #f1f5f9; font-weight: 600;">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="due_date">Due Date for Balance</label>
                                <input type="date" id="due_date" name="due_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-top: 1.5rem;">
                    <label for="remarks">Remarks</label>
                    <textarea id="remarks" name="remarks" class="form-control" rows="2" placeholder="e.g., Partial payment received..."></textarea>
                </div>
                
                <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid #f1f5f9;">
                    <button type="button" onclick="closeSaleFromDeliveryModal()" class="btn btn-secondary">Cancel</button>
                    <?php if (hasRole(1)): ?>
                        <span class="badge bg-light text-dark">View Only</span>
                    <?php else: ?>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Confirm & Record Sale
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Create Walk-in Sale Modal -->
<div id="walkinSaleModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-cash-register"></i> Walk-in POS (Retail)</h2>
            <span class="close" onclick="closeWalkinSaleModal()">&times;</span>
        </div>
        <div class="modal-body">
            <div style="background: #d1fae5; padding: 1rem; border-radius: 8px; margin-bottom: 1.25rem; border-left: 4px solid #10b981; font-size: 0.875rem; color: #065f46;">
                <strong><i class="fas fa-info-circle"></i> Walk-in Sale:</strong>
                <p style="margin: 0.25rem 0 0 0;">
                    Customer pays immediately. Record sale and reduce inventory now.
                </p>
            </div>
            <form id="walkinSaleForm" method="POST">
                <?php echo csrfTokenField(); ?>
                <input type="hidden" name="action" value="create_walkin_sale">
                <div class="form-group">
                    <label>Items *</label>
                    <div id="walkinItems">
                        <div class="order-item-row" style="display: flex; gap: 0.75rem; margin-bottom: 0.75rem; align-items: flex-start;">
                            <select name="items[]" class="product-select form-control" required style="flex: 2;">
                                <option value="">Select Product</option>
                                <?php foreach ($products_data as $product):
                                    $product_display = htmlspecialchars($product['product_name']);
                                    if (!empty($product['unit_name'])) {
                                        $product_display .= ' (' . htmlspecialchars($product['unit_name']) . ')';
                                    }
                                ?>
                                    <option value="<?php echo $product['Product_ID']; ?>"
                                        data-retail="<?php echo $product['retail_price']; ?>">
                                        <?php echo $product_display; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" name="quantities[]" class="form-control" placeholder="Qty" min="0.01" step="0.01" required style="width: 100px;">
                            <input type="text" name="unit_prices[]" class="form-control" placeholder="Price" readonly style="width: 120px; background: #f8fafc;">
                            <button type="button" onclick="removeWalkinItem(this)" class="btn btn-secondary btn-sm" style="height: 42px; width: 42px; padding: 0;">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <button type="button" onclick="addWalkinItem()" class="btn btn-secondary btn-sm">
                        <i class="fas fa-plus"></i> Add Item
                    </button>
                </div>

                <div class="card" style="margin-top: 1.5rem; background: #f8fafc; border: 1px solid #e2e8f0; padding: 1.25rem;">
                    <div style="display: flex; flex-direction: column; gap: 1rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-weight: 600; color: #64748b;">Total Amount</span>
                            <span style="font-size: 1.25rem; font-weight: 700; color: #1e293b;">₱<span id="walkin_total_amount">0.00</span></span>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Cash Received (₱)</label>
                                <input type="number" id="walkin_cash" name="cash_received" class="form-control" min="0" step="0.01" value="0">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label>Change (₱)</label>
                                <input type="text" id="walkin_change" name="change_given" class="form-control" readonly value="₱0.00" style="background: #f1f5f9;">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="walkin_remarks">Remarks</label>
                    <textarea id="walkin_remarks" name="remarks" class="form-control" rows="2"></textarea>
                </div>
                
                <div style="display: flex; gap: 0.75rem; justify-content: flex-end; margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid #f1f5f9;">
                    <button type="button" onclick="closeWalkinSaleModal()" class="btn btn-secondary">Cancel</button>
                    <?php if (hasRole(1)): ?>
                        <span class="badge bg-light text-dark">View Only</span>
                    <?php else: ?>
                        <button type="submit" id="walkin_submit_btn" class="btn btn-success">
                            <i class="fas fa-save"></i> Confirm & Record Sale
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Deliveries Ready for Sale Modal -->
<div id="deliveriesReadyModal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background:rgba(15,23,42,0.6); backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:1rem;">
    <div class="modal-content" style="background:#fff; border-radius:20px; width:100%; max-width:900px; max-height:90vh; overflow-y:auto; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); animation:modalSlideUp 0.3s ease-out;">
        <div style="background:linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); color:#fff; padding:1.5rem 2rem; display:flex; justify-content:space-between; align-items:center;">
            <h2 style="margin:0; font-size:1.25rem; font-weight:700; display:flex; align-items:center; gap:0.75rem;">
                <i class="fas fa-truck" style="font-size:1rem;"></i> Deliveries Ready for Sale
            </h2>
            <button onclick="closeDeliveriesReadyModal()" style="background:rgba(255,255,255,0.2); border:none; color:#fff; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:1.25rem; transition:all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.3)'" onmouseout="this.style.background='rgba(255,255,255,0.2)'">&times;</button>
        </div>
        <div class="modal-body" style="padding: 2rem;">
            <div style="background: #e0e7ff; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border-left: 4px solid #6366f1; font-size: 0.875rem; color: #3730a3;">
                <strong><i class="fas fa-info-circle"></i> Complete Delivery Sales:</strong>
                <p style="margin: 0.25rem 0 0 0;">
                    Only record a sale when the delivery rider has returned with the payment from the customer.
                </p>
            </div>
            
            <?php if (!empty($deliveries_list)): ?>
                <div style="overflow-x:auto;">
                    <table class="table" style="width:100%; border-collapse:collapse; margin-bottom:1rem;">
                        <thead>
                            <tr style="border-bottom:2px solid #cbd5e1; text-align:left;">
                                <th style="padding:0.75rem;">Delivery #</th>
                                <th style="padding:0.75rem;">Order #</th>
                                <th style="padding:0.75rem;">Customer</th>
                                <th style="padding:0.75rem;">Delivered By</th>
                                <th style="padding:0.75rem;">Status</th>
                                <th style="padding:0.75rem;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deliveries_list as $delivery): ?>
                                <tr style="border-bottom:1px solid #cbd5e1;">
                                    <td style="padding:0.75rem;"><strong>#<?php echo $delivery['Delivery_ID']; ?></strong></td>
                                    <td style="padding:0.75rem;"><?php echo $delivery['Order_ID'] ? 'Order #' . $delivery['Order_ID'] : 'N/A'; ?></td>
                                    <td style="padding:0.75rem;"><?php echo htmlspecialchars($delivery['customer_name'] ?? $delivery['delivered_to'] ?? 'N/A'); ?></td>
                                    <td style="padding:0.75rem;"><?php echo htmlspecialchars($delivery['delivered_by'] ?? 'N/A'); ?></td>
                                    <td style="padding:0.75rem;">
                                        <?php
                                        $statusColors = [
                                            'Delivered' => ['bg' => '#dbeafe', 'color' => '#1d4ed8', 'border' => '#93c5fd'],
                                            'Returning' => ['bg' => '#fef3c7', 'color' => '#b45309', 'border' => '#fcd34d'],
                                            'Completed' => ['bg' => '#dcfce7', 'color' => '#15803d', 'border' => '#86efac'],
                                            'Scheduled' => ['bg' => '#e0e7ff', 'color' => '#4338ca', 'border' => '#a5b4fc'],
                                            'In Transit' => ['bg' => '#f1f5f9', 'color' => '#475569', 'border' => '#cbd5e1']
                                        ];
                                        $status = $delivery['delivery_status'] ?? 'Unknown';
                                        $colors = $statusColors[$status] ?? ['bg' => '#f1f5f9', 'color' => '#64748b', 'border' => '#e2e8f0'];
                                        ?>
                                        <span style="display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.25rem 0.625rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.025em; background: <?php echo $colors['bg']; ?>; color: <?php echo $colors['color']; ?>; border: 1px solid <?php echo $colors['border']; ?>;">
                                            <?php echo htmlspecialchars($status); ?>
                                        </span>
                                    </td>
                                    <td style="padding:0.75rem;">
                                        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                            <button onclick="closeDeliveriesReadyModal(); viewDeliveryDetails(<?php echo $delivery['Delivery_ID']; ?>)" title="View Details" style="display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.4rem 0.75rem; border-radius: 8px; font-size: 0.8125rem; font-weight: 600; color: #475569; background: #f1f5f9; border: 1px solid #e2e8f0; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#e2e8f0';" onmouseout="this.style.background='#f1f5f9';">
                                                <i class="fas fa-eye" style="color: #6366f1;"></i> View
                                            </button>
                                            <?php if (!$is_owner_view_only): ?>
                                                <button onclick="closeDeliveriesReadyModal(); createSaleFromDelivery(<?php echo $delivery['Delivery_ID']; ?>)" title="Record sale when payment is received from delivery rider" style="display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.4rem 0.75rem; border-radius: 8px; font-size: 0.8125rem; font-weight: 600; color: white; background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); border: none; cursor: pointer; transition: all 0.2s; box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(59, 130, 246, 0.4)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(59, 130, 246, 0.3)';">
                                                    <i class="fas fa-money-bill-wave"></i> Record Sale
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p style="text-align: center; color: #6b7280; padding: 2rem;">No deliveries ready for sale.</p>
            <?php endif; ?>
        </div>
        <div style="display: flex; justify-content: flex-end; padding: 1.5rem 2rem; border-top: 1px solid #f1f5f9; background: #fafafa; border-bottom-left-radius: 20px; border-bottom-right-radius: 20px;">
            <button type="button" onclick="closeDeliveriesReadyModal()" class="btn btn-secondary" style="padding: 0.5rem 1.25rem; border-radius: 8px; font-weight: 600; cursor: pointer;">Close</button>
        </div>
    </div>
</div>

<!-- View Sale Modal -->
<div id="saleViewModal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background:rgba(15,23,42,0.6); backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:1rem;">
    <div class="modal-content" style="background:#fff; border-radius:20px; width:100%; max-width:800px; max-height:90vh; overflow-y:auto; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); animation:modalSlideUp 0.3s ease-out;">
        <div style="background:linear-gradient(135deg, #6366f1 0%, #7c3aed 100%); color:#fff; padding:1.5rem 2rem; display:flex; justify-content:space-between; align-items:center;">
            <h2 style="margin:0; font-size:1.25rem; font-weight:700; display:flex; align-items:center; gap:0.75rem;">
                <i class="fas fa-receipt" style="font-size:1rem;"></i> Sale Details
            </h2>
            <button onclick="closeSaleViewModal()" style="background:rgba(255,255,255,0.2); border:none; color:#fff; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:1.25rem; transition:all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.3)'" onmouseout="this.style.background='rgba(255,255,255,0.2)'">&times;</button>
        </div>
        <div id="sale_view_body" style="padding:2rem;">
            <div style="text-align:center; padding: 2rem; color:#64748b;">
                <i class="fas fa-spinner fa-spin" style="font-size:1.5rem; color:#6366f1;"></i>
                <p style="margin-top:0.5rem; font-size:0.875rem;">Loading sale details...</p>
            </div>
        </div>
    </div>
</div>

<style>
    @keyframes modalSlideUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
</style>

<script src="../assets/js/script.js"></script>
<!-- Delivery Details Modal -->
<div class="modal" id="deliveryDetailsModal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div class="modal-content" style="background:#fff; padding:2rem; border-radius:1rem; width:100%; max-width:700px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; border-bottom:1px solid #f1f5f9; padding-bottom:1rem;">
            <h2 style="font-weight:700; color:#0f172a; margin:0;"><i class="fas fa-truck text-primary"></i> Delivery Details</h2>
        </div>
        <div id="deliveryDetailsBody" style="max-height:60vh; overflow-y:auto;">
            <p style="text-align:center;"><i class="fas fa-spinner fa-spin"></i> Loading details...</p>
        </div>
        <div style="margin-top:2rem; text-align:right;">
            <button onclick="closeDeliveryModal()" class="btn btn-secondary">Close</button>
        </div>
    </div>
</div>

<script>
function getLocalDateStringForSales() {
    const d = new Date();
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

function exportSalesRange() {
    const startInput = document.getElementById('sales_export_start');
    const endInput = document.getElementById('sales_export_end');
    if (!startInput) return;

    let start = startInput.value;
    let end = endInput && endInput.value ? endInput.value : start;

    if (!start) {
        alert('Please select at least a start date for the sales report.');
        return;
    }

    if (end && start > end) {
        const tmp = start;
        start = end;
        end = tmp;
    }

    window.location.href = `../api/export_sales.php?start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`;
}

function exportSalesToday() {
    const today = getLocalDateStringForSales();
    window.location.href = `../api/export_sales.php?start=${encodeURIComponent(today)}&end=${encodeURIComponent(today)}`;
}

// Sale from Delivery functions
function createSaleFromDelivery(deliveryId) {
    if (!deliveryId) {
        alert('Invalid delivery ID');
        return;
    }
    
    document.getElementById('delivery_id').value = deliveryId;
    
    // Reset AR section
    const arDetails = document.getElementById('ar_payment_details');
    const arBtn = document.getElementById('post_to_ar_btn');
    const arHidden = document.getElementById('post_to_ar_hidden');
    if (arDetails) arDetails.style.display = 'none';
    if (arBtn) { arBtn.classList.remove('active'); arBtn.innerHTML = '<i class="fas fa-file-invoice-dollar"></i> Post to Accounts Receivable (AR)'; }
    if (arHidden) arHidden.value = '0';
    
    // Show loading state
    document.getElementById('delivery_details_container').innerHTML = '<div style="text-align: center; padding: 2rem;"><i class="fas fa-spinner fa-spin"></i> Loading delivery details...</div>';
    document.getElementById('saleFromDeliveryModal').style.display = 'block';
    
    // Fetch delivery details via AJAX
    fetch(`../api/get_delivery_details.php?delivery_id=${deliveryId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.items && data.items.length > 0) {
                let html = '<div class="form-group"><label>Delivery Items:</label>';
                html += '<div style="overflow-x: auto; margin-top: 0.5rem;">';
                html += '<table class="table" style="margin-top: 0; min-width: 700px;">';
                html += '<thead><tr><th>Product</th><th>Ordered</th><th>Received</th><th>Damage</th><th>Sold</th><th>Unit Price</th><th>Subtotal</th></tr></thead><tbody>';
                
                data.items.forEach(item => {
                    html += `<tr>
                        <td>${item.product_name}</td>
                        <td>${item.ordered_qty}</td>
                        <td><input type="number" class="js-received" name="received_qty[]" value="${item.received_qty || item.ordered_qty}" min="0" step="0.01" required></td>
                        <td><input type="number" class="js-damage" name="damage_qty[]" value="${item.damage_qty || 0}" min="0" step="0.01"></td>
                        <td><span class="js-sold">0</span></td>
                        <td>₱<span class="js-unit-price">${parseFloat(item.unit_price).toFixed(2)}</span></td>
                        <td>₱<span class="js-subtotal">0.00</span></td>
                        <input type="hidden" name="delivery_detail_id[]" value="${item.delivery_detail_id}">
                        <input type="hidden" name="order_detail_id[]" value="${item.order_detail_id}">
                        <input type="hidden" name="product_id[]" value="${item.product_id}">
                    </tr>`;
                });
                
                html += '</tbody></table></div>';
                html += `
                    <div style="display:flex; justify-content:flex-end; margin-top:12px; font-size: 1rem;">
                        <strong>Total Amount: ₱<span id="js-total-amount">0.00</span></strong>
                    </div>
                `;
                html += '</div>';
                document.getElementById('delivery_details_container').innerHTML = html;
                initDeliverySaleAutoCalc();
            } else {
                document.getElementById('delivery_details_container').innerHTML = 
                    '<div class="alert alert-danger">No delivery details found. Please ensure the delivery has been properly recorded with items.</div>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('delivery_details_container').innerHTML = 
                '<div class="alert alert-danger">Error loading delivery details: ' + error.message + '</div>';
        });
}

function initDeliverySaleAutoCalc() {
    const container = document.getElementById('delivery_details_container');
    const rows = container.querySelectorAll('tbody tr');
    const totalEl = document.getElementById('js-total-amount');
    if (!rows.length || !totalEl) return;

    function recalc() {
        let total = 0;

        rows.forEach(row => {
            const receivedInput = row.querySelector('.js-received');
            const damageInput = row.querySelector('.js-damage');
            const soldEl = row.querySelector('.js-sold');
            const unitPriceEl = row.querySelector('.js-unit-price');
            const subtotalEl = row.querySelector('.js-subtotal');

            const received = Math.max(0, parseFloat(receivedInput?.value || '0') || 0);
            let damage = Math.max(0, parseFloat(damageInput?.value || '0') || 0);

            // prevent damage > received
            if (damage > received) {
                damage = received;
                if (damageInput) damageInput.value = String(received);
            }

            const sold = Math.max(0, received - damage);
            const unitPrice = parseFloat(unitPriceEl?.textContent || '0') || 0;
            const subtotal = sold * unitPrice;

            if (soldEl) soldEl.textContent = sold.toFixed(2).replace(/\.00$/, '');
            if (subtotalEl) subtotalEl.textContent = subtotal.toFixed(2);
            total += subtotal;
        });

        totalEl.textContent = total.toFixed(2);
        
        // Update AR balance if Post to AR is active
        const postToArHidden = document.getElementById('post_to_ar_hidden');
        const amountPaid = document.getElementById('amount_paid');
        const arBalance = document.getElementById('ar_balance');
        
        if (postToArHidden && postToArHidden.value === '1' && amountPaid && arBalance) {
            const paid = parseFloat(amountPaid.value || 0);
            const balance = Math.max(0, total - paid);
            arBalance.value = balance.toFixed(2);
        }
    }

    rows.forEach(row => {
        row.querySelectorAll('.js-received, .js-damage').forEach(inp => {
            inp.addEventListener('input', recalc);
            inp.addEventListener('change', recalc);
        });
    });

    const amountPaid = document.getElementById('amount_paid');
    if (amountPaid) {
        amountPaid.addEventListener('input', recalc);
        amountPaid.addEventListener('change', recalc);
    }

    recalc();
}

function closeSaleFromDeliveryModal() {
    document.getElementById('saleFromDeliveryModal').style.display = 'none';
}

function togglePostToAR() {
    const btn = document.getElementById('post_to_ar_btn');
    const details = document.getElementById('ar_payment_details');
    const hidden = document.getElementById('post_to_ar_hidden');
    const isActive = details.style.display !== 'none';
    if (isActive) {
        details.style.display = 'none';
        hidden.value = '0';
        btn.classList.remove('active');
        btn.innerHTML = '<i class="fas fa-file-invoice-dollar"></i> Post to Accounts Receivable (AR)';
    } else {
        details.style.display = 'block';
        hidden.value = '1';
        btn.classList.add('active');
        btn.innerHTML = '<i class="fas fa-check"></i> Post to AR (Active)';
    }
    const recalc = typeof initDeliverySaleAutoCalc === 'function' ? function() {
        const rows = document.querySelectorAll('#delivery_details_container tbody tr');
        const totalEl = document.getElementById('js-total-amount');
        if (rows.length && totalEl) {
            let total = 0;
            rows.forEach(row => {
                const soldEl = row.querySelector('.js-sold');
                const unitPriceEl = row.querySelector('.js-unit-price');
                const sold = parseFloat(soldEl?.textContent || '0') || 0;
                const unitPrice = parseFloat(unitPriceEl?.textContent || '0') || 0;
                total += sold * unitPrice;
            });
            totalEl.textContent = total.toFixed(2);
            const arBalance = document.getElementById('ar_balance');
            const amountPaid = document.getElementById('amount_paid');
            if (arBalance && amountPaid && hidden.value === '1') {
                const paid = parseFloat(amountPaid.value || 0);
                arBalance.value = Math.max(0, total - paid).toFixed(2);
            }
        }
    } : function(){};
    recalc();
}

function openSaleViewModal(saleId) {
    const modal = document.getElementById('saleViewModal');
    const body = document.getElementById('sale_view_body');
    if (!modal || !body) return;
    modal.style.display = 'block';
    body.innerHTML = '<div style="text-align:center; padding: 2rem;"><i class="fas fa-spinner fa-spin" style="color:#6366f1;"></i></div>';

    fetch(`../api/get_sale_details.php?id=${saleId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                body.innerHTML = `<div style="padding:1rem;background:#fee2e2;border-radius:8px;color:#dc2626;">${data.message || 'Failed to load sale details'}</div>`;
                return;
            }

            const typeColors = {
                'Pre-Order (Wholesale)': { bg: '#dbeafe', text: '#1e40af', icon: 'fa-boxes' },
                'Pre-Order (Retail)': { bg: '#dbeafe', text: '#1e40af', icon: 'fa-box' },
                'Walk-in (Wholesale)': { bg: '#dcfce7', text: '#166534', icon: 'fa-store' },
                'Walk-in (Retail)': { bg: '#dcfce7', text: '#166534', icon: 'fa-store' }
            };
            const typeStyle = typeColors[data.sale.type] || { bg: '#f3f4f6', text: '#374151', icon: 'fa-receipt' };

            let html = `
                <style>
                    .sale-detail-header { display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:1rem; margin-bottom:1.5rem; }
                    .sale-detail-item { background:#f8fafc; border-radius:12px; padding:1rem; border:1px solid #e2e8f0; }
                    .sale-detail-label { font-size:0.75rem; color:#64748b; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:0.25rem; font-weight:600; }
                    .sale-detail-value { font-size:0.95rem; color:#1e293b; font-weight:600; }
                    .sale-type-badge { display:inline-flex; align-items:center; gap:0.5rem; padding:0.375rem 0.75rem; border-radius:9999px; font-size:0.75rem; font-weight:600; background:${typeStyle.bg}; color:${typeStyle.text}; }
                    .sale-ar-card { background:linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border:1px solid #fcd34d; border-radius:12px; padding:1rem; margin-bottom:1.5rem; }
                    .sale-ar-paid { background:linear-gradient(135deg, #dcfce7 0%, #86efac 100%); border:1px solid #86efac; }
                    .sale-table { width:100%; border-collapse:separate; border-spacing:0; font-size:0.875rem; }
                    .sale-table th { background:#f1f5f9; color:#475569; font-weight:600; text-transform:uppercase; font-size:0.7rem; letter-spacing:0.05em; padding:0.75rem 1rem; text-align:left; }
                    .sale-table td { padding:0.875rem 1rem; border-bottom:1px solid #e2e8f0; color:#334155; }
                    .sale-table tr:hover td { background:#f8fafc; }
                    .sale-table tfoot td { background:#f8fafc; font-weight:700; color:#1e293b; border-top:2px solid #e2e8f0; border-bottom:none; }
                    .sale-product-name { font-weight:600; color:#1e293b; }
                    .sale-price { font-family:'Poppins', monospace; color:#475569; }
                    .sale-total { font-family:'Poppins', monospace; font-size:1rem; color:#059669; }
                </style>

                <div class="sale-detail-header">
                    <div class="sale-detail-item">
                        <div class="sale-detail-label">Sale #</div>
                        <div class="sale-detail-value">${data.sale.sale_id}</div>
                    </div>
                    <div class="sale-detail-item">
                        <div class="sale-detail-label">Date</div>
                        <div class="sale-detail-value">${data.sale.created_at}</div>
                    </div>
                    <div class="sale-detail-item">
                        <div class="sale-detail-label">Type</div>
                        <div class="sale-type-badge"><i class="fas ${typeStyle.icon}"></i> ${data.sale.type}</div>
                    </div>
                    <div class="sale-detail-item">
                        <div class="sale-detail-label">Customer</div>
                        <div class="sale-detail-value">${data.sale.customer || 'Walk-in'}</div>
                    </div>
                    <div class="sale-detail-item">
                        <div class="sale-detail-label">Recorded By</div>
                        <div class="sale-detail-value"><i class="fas fa-user-circle" style="color:#6366f1;margin-right:0.25rem;"></i>${data.sale.recorded_by || 'N/A'}</div>
                    </div>
                </div>

                ${data.sale.ar ? `
                <div class="sale-ar-card ${data.sale.ar.balance_in_ar > 0 ? '' : 'sale-ar-paid'}">
                    <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.75rem; font-weight:600; color:${data.sale.ar.balance_in_ar > 0 ? '#92400e' : '#166534'};">
                        <i class="fas ${data.sale.ar.balance_in_ar > 0 ? 'fa-clock' : 'fa-check-circle'}"></i>
                        ${data.sale.ar.balance_in_ar > 0 ? 'Payment Pending' : 'Payment Complete'}
                    </div>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(100px, 1fr)); gap:1rem; font-size:0.875rem;">
                        <div>
                            <div style="color:#64748b;font-size:0.7rem;text-transform:uppercase;">Invoice Total</div>
                            <div style="font-weight:700;color:#1e293b;">₱${data.sale.ar.invoice_amount}</div>
                        </div>
                        <div>
                            <div style="color:#64748b;font-size:0.7rem;text-transform:uppercase;">Amount Paid</div>
                            <div style="font-weight:700;color:#059669;">₱${data.sale.ar.amount_paid}</div>
                        </div>
                        <div>
                            <div style="color:#64748b;font-size:0.7rem;text-transform:uppercase;">Balance</div>
                            <div style="font-weight:700;color:${data.sale.ar.balance_in_ar > 0 ? '#dc2626' : '#059669'};">₱${data.sale.ar.amount_due}</div>
                        </div>
                        ${data.sale.ar.due_date ? `
                        <div>
                            <div style="color:#64748b;font-size:0.7rem;text-transform:uppercase;">Due Date</div>
                            <div style="font-weight:700;color:#1e293b;">${data.sale.ar.due_date}</div>
                        </div>` : ''}
                    </div>
                </div>
                ` : ''}

                <div style="background:#fff; border-radius:12px; border:1px solid #e2e8f0; overflow:hidden;">
                    <table class="sale-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Unit</th>
                                <th style="text-align:center;">Qty</th>
                                <th style="text-align:right;">Unit Price</th>
                                <th style="text-align:right;">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            data.items.forEach(it => {
                html += `
                    <tr>
                        <td><div class="sale-product-name">${it.product_name}</div></td>
                        <td style="color:#64748b;">${it.unit || '-'}</td>
                        <td style="text-align:center; font-weight:600;">${it.quantity}</td>
                        <td style="text-align:right;" class="sale-price">₱${it.unit_price}</td>
                        <td style="text-align:right;" class="sale-price"><strong>₱${it.subtotal}</strong></td>
                    </tr>
                `;
            });

            html += `
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2" style="text-align:right;">Total Items</td>
                                <td style="text-align:center;">${data.totals.total_qty}</td>
                                <td style="text-align:right;">Total Amount</td>
                                <td style="text-align:right;" class="sale-total">₱${data.totals.total_amount}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            `;

            body.innerHTML = html;
        })
        .catch(err => {
            body.innerHTML = `<div style="padding:1rem;background:#fee2e2;border-radius:8px;color:#dc2626;">Error: ${err.message}</div>`;
        });
}

function closeSaleViewModal() {
    const modal = document.getElementById('saleViewModal');
    if (modal) modal.style.display = 'none';
}

// Deliveries Ready for Sale Modal functions
function showDeliveriesReadyModal() {
    const modal = document.getElementById('deliveriesReadyModal');
    if (modal) modal.style.display = 'flex';
}

function closeDeliveriesReadyModal() {
    const modal = document.getElementById('deliveriesReadyModal');
    if (modal) modal.style.display = 'none';
}

// Walk-in Sale functions
function showCreateWalkinSaleModal() {
    document.getElementById('walkinSaleModal').style.display = 'block';
    initWalkinPOS();
}

function closeWalkinSaleModal() {
    document.getElementById('walkinSaleModal').style.display = 'none';
}

function addWalkinItem() {
    const container = document.getElementById('walkinItems');
    const newItem = container.firstElementChild.cloneNode(true);
    newItem.querySelector('select').value = '';
    newItem.querySelector('input[type="number"]').value = '';
    newItem.querySelector('input[type="text"]').value = '';
    container.appendChild(newItem);
    attachWalkinItemListeners(newItem);
    initWalkinPOS();
}

function removeWalkinItem(button) {
    if (document.getElementById('walkinItems').children.length > 1) {
        button.closest('.order-item-row').remove();
        initWalkinPOS();
    }
}

function attachWalkinItemListeners(itemRow) {
    const select = itemRow.querySelector('.product-select');
    const priceInput = itemRow.querySelector('input[type="text"]');
    const qtyInput = itemRow.querySelector('input[name="quantities[]"]');
    
    select.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if (selectedOption && selectedOption.value) {
            const retailPrice = selectedOption.getAttribute('data-retail');
            priceInput.value = '₱' + parseFloat(retailPrice).toFixed(2);
        } else {
            priceInput.value = '';
        }
        initWalkinPOS();
    });

    if (qtyInput) {
        qtyInput.addEventListener('input', initWalkinPOS);
        qtyInput.addEventListener('change', initWalkinPOS);
    }
}

// Attach listeners to existing items
document.querySelectorAll('#walkinItems .order-item-row').forEach(item => {
    attachWalkinItemListeners(item);
});

function initWalkinPOS() {
    const form = document.getElementById('walkinSaleForm');
    const totalEl = document.getElementById('walkin_total_amount');
    const cashEl = document.getElementById('walkin_cash');
    const changeEl = document.getElementById('walkin_change');
    const submitBtn = document.getElementById('walkin_submit_btn');
    if (!form || !totalEl || !cashEl || !changeEl || !submitBtn) return;

    const selects = form.querySelectorAll('.product-select');
    const quantities = form.querySelectorAll('input[name="quantities[]"]');
    const prices = form.querySelectorAll('input[name="unit_prices[]"]');

    let total = 0;
    for (let i = 0; i < selects.length; i++) {
        if (!selects[i].value) continue;
        const qty = Math.max(0, parseFloat(quantities[i]?.value || '0') || 0);
        const priceStr = (prices[i]?.value || '').replace('₱', '').trim();
        const price = Math.max(0, parseFloat(priceStr || '0') || 0);
        total += qty * price;
    }

    totalEl.textContent = total.toFixed(2);

    const cash = Math.max(0, parseFloat(cashEl.value || '0') || 0);
    const change = cash - total;
    changeEl.value = '₱' + (change > 0 ? change.toFixed(2) : '0.00');

    // enable submit only if cash covers total AND total > 0
    const canPay = total > 0 && cash >= total;
    submitBtn.disabled = !canPay;
    submitBtn.style.opacity = canPay ? '1' : '0.6';
    submitBtn.style.cursor = canPay ? 'pointer' : 'not-allowed';
}

// Update change whenever cash changes
document.getElementById('walkin_cash')?.addEventListener('input', initWalkinPOS);
document.getElementById('walkin_cash')?.addEventListener('change', initWalkinPOS);

// Update walk-in sale form submission
document.getElementById('walkinSaleForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const items = [];
    const selects = this.querySelectorAll('.product-select');
    const quantities = this.querySelectorAll('input[name="quantities[]"]');
    const prices = this.querySelectorAll('input[name="unit_prices[]"]');
    
    for (let i = 0; i < selects.length; i++) {
        if (selects[i].value && quantities[i].value) {
            items.push({
                product_id: selects[i].value,
                quantity: quantities[i].value,
                unit_price: prices[i].value.replace('₱', '').trim()
            });
        }
    }
    
    if (items.length === 0) {
        alert('Please add at least one item');
        return;
    }
    
    // Create hidden input for items JSON
    const itemsInput = document.createElement('input');
    itemsInput.type = 'hidden';
    itemsInput.name = 'items';
    itemsInput.value = JSON.stringify(items);
    this.appendChild(itemsInput);
    
    // Remove the items[] and quantities[] inputs to avoid confusion
    this.querySelectorAll('select[name="items[]"], input[name="quantities[]"], input[name="unit_prices[]"]').forEach(el => {
        el.remove();
    });
    
    // Submit the form normally
    this.submit();
});

// Update sale from delivery form submission
document.getElementById('saleFromDeliveryForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const deliveryDetails = [];
    
    const deliveryDetailIds = this.querySelectorAll('input[name="delivery_detail_id[]"]');
    const orderDetailIds = this.querySelectorAll('input[name="order_detail_id[]"]');
    const productIds = this.querySelectorAll('input[name="product_id[]"]');
    const receivedQtys = this.querySelectorAll('input[name="received_qty[]"]');
    const damageQtys = this.querySelectorAll('input[name="damage_qty[]"]');
    
    for (let i = 0; i < deliveryDetailIds.length; i++) {
        deliveryDetails.push({
            delivery_detail_id: deliveryDetailIds[i].value,
            order_detail_id: orderDetailIds[i].value,
            product_id: productIds[i].value,
            received_qty: receivedQtys[i].value,
            damage_qty: damageQtys[i].value || 0
        });
    }
    
    if (deliveryDetails.length === 0) {
        alert('No delivery items found');
        return;
    }
    
    // Create hidden input for delivery_details JSON
    const detailsInput = document.createElement('input');
    detailsInput.type = 'hidden';
    detailsInput.name = 'delivery_details';
    detailsInput.value = JSON.stringify(deliveryDetails);
    this.appendChild(detailsInput);
    
    // Submit the form normally
    this.submit();
});

// Close modals when clicking outside
window.onclick = function(event) {
    const saleModal = document.getElementById('saleFromDeliveryModal');
    const walkinModal = document.getElementById('walkinSaleModal');
    const deliveriesReadyModal = document.getElementById('deliveriesReadyModal');
    if (event.target == saleModal) {
        saleModal.style.display = 'none';
    }
    if (event.target == walkinModal) {
        walkinModal.style.display = 'none';
    }
    if (event.target == deliveriesReadyModal) {
        deliveriesReadyModal.style.display = 'none';
    }
    const deliveryModal = document.getElementById('deliveryDetailsModal');
    if (event.target == deliveryModal) {
        deliveryModal.style.display = 'none';
    }
}

function viewDeliveryDetails(deliveryId) {
    const modal = document.getElementById('deliveryDetailsModal');
    const body = document.getElementById('deliveryDetailsBody');
    if (!modal || !body) return;

    modal.style.display = 'flex';
    body.innerHTML = '<div style="text-align:center; padding: 2rem;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';

    fetch(`../api/get_delivery_details.php?delivery_id=${deliveryId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                body.innerHTML = `<div class="alert alert-danger">${data.message || 'Failed to load details'}</div>`;
                return;
            }

            let itemsHtml = '';
            if (data.items && data.items.length > 0) {
                data.items.forEach(item => {
                    const unitBadge = item.unit ? `<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:9999px;font-size:0.7rem;font-weight:600;background:linear-gradient(135deg,#e0e7ff 0%,#c7d2fe 100%);color:#4338ca;border:1px solid #a5b4fc;">${item.unit}</span>` : '';
                    itemsHtml += `
                        <tr style="transition:background-color 0.15s;">
                            <td style="padding:1rem;border-bottom:1px solid #f1f5f9;">
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <span style="font-weight:600;color:#1e293b;">${item.product_name}</span>
                                    ${unitBadge}
                                </div>
                            </td>
                            <td style="padding:1rem;border-bottom:1px solid #f1f5f9;text-align:center;">
                                <span style="display:inline-flex;align-items:center;justify-content:center;min-width:36px;padding:6px 12px;border-radius:8px;font-weight:700;font-size:0.9rem;background:linear-gradient(135deg,#fef3c7 0%,#fde68a 100%);color:#b45309;border:1px solid #fcd34d;">${item.ordered_qty}</span>
                            </td>
                            <td style="padding:1rem;border-bottom:1px solid #f1f5f9;text-align:center;">
                                <span style="display:inline-flex;align-items:center;justify-content:center;min-width:36px;padding:6px 12px;border-radius:8px;font-weight:700;font-size:0.9rem;background:linear-gradient(135deg,#dcfce7 0%,#bbf7d0 100%);color:#15803d;border:1px solid #86efac;">${item.received_qty || 0}</span>
                            </td>
                        </tr>
                    `;
                });
            } else {
                itemsHtml = '<tr><td colspan="3" style="text-align:center; padding:2rem; color:#94a3b8;"><i class="fas fa-box-open" style="font-size:2rem; display:block; margin-bottom:0.5rem; opacity:0.5;"></i>No items found.</td></tr>';
            }

            const statusColors = {
                'Scheduled': { bg: '#dbeafe', color: '#1d4ed8', border: '#93c5fd', icon: '\uf017' },
                'In Transit': { bg: '#fef3c7', color: '#b45309', border: '#fcd34d', icon: '\uf0d1' },
                'Delivered': { bg: '#dcfce7', color: '#15803d', border: '#86efac', icon: '\uf00c' },
                'Completed': { bg: '#e0e7ff', color: '#4338ca', border: '#a5b4fc', icon: '\uf164' },
                'Cancelled': { bg: '#fee2e2', color: '#dc2626', border: '#fca5a5', icon: '\uf00d' }
            };
            const status = data.delivery.delivery_status || 'Scheduled';
            const statusStyle = statusColors[status] || statusColors['Scheduled'];

            body.innerHTML = `
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-bottom:1.5rem; background:linear-gradient(135deg,#f8fafc 0%,#f1f5f9 100%); padding:1.5rem; border-radius:16px; border:1px solid #e2e8f0;">
                    <div>
                        <p style="margin:0 0 0.75rem; font-size:0.7rem; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Delivery Info</p>
                        <p style="margin:0 0 0.5rem; font-size:0.95rem;"><strong style="color:#475569;">ID:</strong> <span style="color:#6366f1; font-weight:700;">#${data.delivery.Delivery_ID}</span></p>
                        <p style="margin:0 0 0.5rem; font-size:0.95rem;"><strong style="color:#475569;">Order:</strong> <span style="color:#6366f1; font-weight:700;">#${data.delivery.Order_ID || 'N/A'}</span></p>
                        <p style="margin:0; font-size:0.95rem;"><strong style="color:#475569;">Status:</strong> 
                            <span style="display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:9999px;font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;background:${statusStyle.bg};color:${statusStyle.color};border:1px solid ${statusStyle.border};">
                                <i class="fas" style="font-family:'Font Awesome 6 Free';font-weight:900;">${statusStyle.icon}</i> ${data.delivery.delivery_status}
                            </span>
                        </p>
                    </div>
                    <div>
                        <p style="margin:0 0 0.75rem; font-size:0.7rem; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Customer & Rep</p>
                        <p style="margin:0 0 0.5rem; font-size:0.95rem;"><strong style="color:#475569;">Client:</strong> <span style="color:#1e293b; font-weight:600;">${data.delivery.customer_name || data.delivery.delivered_to || 'Walk-in'}</span></p>
                        <p style="margin:0 0 0.5rem; font-size:0.95rem;"><strong style="color:#475569;">Address:</strong> <span style="color:#64748b;">${data.delivery.order_delivery_address || data.delivery.customer_address || 'N/A'}</span></p>
                        <p style="margin:0; font-size:0.95rem;"><strong style="color:#475569;">Sales Rep / Rider:</strong> <span style="display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:9999px;font-size:0.8rem;font-weight:600;background:linear-gradient(135deg,#f1f5f9 0%,#e2e8f0 100%);color:#475569;border:1px solid #e2e8f0;"><i class="fas fa-user-circle"></i> ${data.delivery.delivered_by || 'N/A'}</span></p>
                    </div>
                </div>
                <h4 style="margin:1.5rem 0 1rem; font-size:1rem; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-box" style="color:#6366f1;"></i> Items for Delivery
                </h4>
                <div style="overflow-x:auto; border-radius:12px; border:1px solid #e2e8f0;">
                    <table style="width:100%; border-collapse:separate; border-spacing:0; font-size:0.9rem;">
                        <thead>
                            <tr style="background:linear-gradient(135deg,#f8fafc 0%,#f1f5f9 100%);">
                                <th style="padding:1rem; text-align:left; font-size:0.7rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; border-bottom:2px solid #e2e8f0;">Product</th>
                                <th style="padding:1rem; text-align:center; font-size:0.7rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; border-bottom:2px solid #e2e8f0;">Ordered</th>
                                <th style="padding:1rem; text-align:center; font-size:0.7rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; border-bottom:2px solid #e2e8f0;">Received</th>
                            </tr>
                        </thead>
                        <tbody>${itemsHtml}</tbody>
                    </table>
                </div>
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
</script>
</body>
</html>
<?php
// PDO doesn't need free() or close() - resources are automatically freed
?>
