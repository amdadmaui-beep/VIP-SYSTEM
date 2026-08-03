<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

if (!defined('VIP_AI_ASSISTANT_ENABLED') || !VIP_AI_ASSISTANT_ENABLED) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'VIP AI Assistant is currently disabled.']);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

function qv($conn, $sql, $params = []) {
    try {
        if ($params) { $st = $conn->prepare($sql); $st->execute($params); return $st->fetchColumn(); }
        $st = $conn->query($sql); return $st ? $st->fetchColumn() : null;
    } catch(Exception $e) { return null; }
}
function qa($conn, $sql, $params = []) {
    try {
        if ($params) { $st = $conn->prepare($sql); $st->execute($params); return $st->fetchAll(PDO::FETCH_ASSOC); }
        $st = $conn->query($sql); return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch(Exception $e) { return []; }
}

try {
    $snap = [];

    // ── 1. CUSTOMERS ────────────────────────────────────────────────────────────
    $snap['customers']['total']           = (int)qv($conn, "SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL");
    $snap['customers']['new_today']       = (int)qv($conn, "SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL AND DATE(created_at) = CURDATE()");
    $snap['customers']['new_this_month']  = (int)qv($conn, "SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())");
    $snap['customers']['overdue_list']    = qa($conn,
        "SELECT COALESCE(c.customer_name,'Unknown') AS customer_name,
                SUM(ar.amount_due)  AS total_overdue,
                MIN(ar.due_date)    AS oldest_due,
                COUNT(ar.AR_ID)     AS invoice_count
         FROM account_receivable ar
         LEFT JOIN customers c ON ar.customer_id = c.Customer_ID
         WHERE ar.due_date < CURDATE() AND ar.status NOT IN ('Paid','Closed') AND c.deleted_at IS NULL
         GROUP BY ar.customer_id, c.customer_name
         ORDER BY total_overdue DESC LIMIT 20");
    $snap['customers']['overdue_total']   = (float)qv($conn,
        "SELECT COALESCE(SUM(amount_due),0) FROM account_receivable WHERE due_date < CURDATE() AND status NOT IN ('Paid','Closed')");
    $snap['customers']['overdue_count']   = (int)qv($conn,
        "SELECT COUNT(DISTINCT customer_id) FROM account_receivable WHERE due_date < CURDATE() AND status NOT IN ('Paid','Closed')");
    $snap['customers']['total_ar_balance']= (float)qv($conn,
        "SELECT COALESCE(SUM(amount_due),0) FROM account_receivable WHERE status NOT IN ('Paid','Closed')");

    // ── 2. ORDERS ────────────────────────────────────────────────────────────────
    $snap['orders']['today']              = (int)qv($conn, "SELECT COUNT(*) FROM orders WHERE DATE(order_date)=CURDATE()");
    $snap['orders']['this_week']          = (int)qv($conn, "SELECT COUNT(*) FROM orders WHERE YEARWEEK(order_date,1)=YEARWEEK(CURDATE(),1)");
    $snap['orders']['this_month']         = (int)qv($conn, "SELECT COUNT(*) FROM orders WHERE MONTH(order_date)=MONTH(CURDATE()) AND YEAR(order_date)=YEAR(CURDATE())");
    $snap['orders']['last_month']         = (int)qv($conn, "SELECT COUNT(*) FROM orders WHERE MONTH(order_date)=MONTH(DATE_SUB(CURDATE(),INTERVAL 1 MONTH)) AND YEAR(order_date)=YEAR(DATE_SUB(CURDATE(),INTERVAL 1 MONTH))");
    $snap['orders']['this_year']          = (int)qv($conn, "SELECT COUNT(*) FROM orders WHERE YEAR(order_date)=YEAR(CURDATE())");
    $snap['orders']['total']              = (int)qv($conn, "SELECT COUNT(*) FROM orders");
    $snap['orders']['pending']            = (int)qv($conn, "SELECT COUNT(*) FROM orders WHERE order_status IN ('Requested','Confirmed','pending')");
    $snap['orders']['revenue_today']      = (float)qv($conn, "SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE DATE(order_date)=CURDATE()");
    $snap['orders']['revenue_month']      = (float)qv($conn, "SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE MONTH(order_date)=MONTH(CURDATE()) AND YEAR(order_date)=YEAR(CURDATE())");
    $snap['orders']['today_list']         = qa($conn,
        "SELECT o.Order_ID, COALESCE(c.customer_name,'Walk-in') AS customer_name,
                o.order_status, o.total_amount, o.order_date
         FROM orders o LEFT JOIN customers c ON o.Customer_ID=c.Customer_ID
         WHERE DATE(o.order_date)=CURDATE()
         ORDER BY o.order_date DESC LIMIT 20");

    // ── 3. SALES ────────────────────────────────────────────────────────────────
    $sb = "SELECT COALESCE(SUM(sd.subtotal),0) FROM sales s JOIN sale_details sd ON s.Sale_ID=sd.Sale_ID";
    $snap['sales']['today']      = (float)qv($conn, "$sb WHERE DATE(s.created_at)=CURDATE()");
    $snap['sales']['yesterday']  = (float)qv($conn, "$sb WHERE DATE(s.created_at)=DATE_SUB(CURDATE(),INTERVAL 1 DAY)");
    $snap['sales']['this_week']  = (float)qv($conn, "$sb WHERE YEARWEEK(s.created_at,1)=YEARWEEK(CURDATE(),1)");
    $snap['sales']['last_week']  = (float)qv($conn, "$sb WHERE YEARWEEK(s.created_at,1)=YEARWEEK(DATE_SUB(CURDATE(),INTERVAL 1 WEEK),1)");
    $snap['sales']['this_month'] = (float)qv($conn, "$sb WHERE MONTH(s.created_at)=MONTH(CURDATE()) AND YEAR(s.created_at)=YEAR(CURDATE())");
    $snap['sales']['last_month'] = (float)qv($conn, "$sb WHERE MONTH(s.created_at)=MONTH(DATE_SUB(CURDATE(),INTERVAL 1 MONTH)) AND YEAR(s.created_at)=YEAR(DATE_SUB(CURDATE(),INTERVAL 1 MONTH))");
    $snap['sales']['this_year']  = (float)qv($conn, "$sb WHERE YEAR(s.created_at)=YEAR(CURDATE())");
    $snap['sales']['last_year']  = (float)qv($conn, "$sb WHERE YEAR(s.created_at)=YEAR(CURDATE())-1");
    $snap['sales']['tx_today']   = (int)qv($conn,   "SELECT COUNT(*) FROM sales WHERE DATE(created_at)=CURDATE()");
    $snap['sales']['tx_month']   = (int)qv($conn,   "SELECT COUNT(*) FROM sales WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())");

    // ── 4. INVENTORY ─────────────────────────────────────────────────────────────
    $snap['inventory']['total_products']       = (int)qv($conn, "SELECT COUNT(*) FROM products WHERE is_discontinued=0");
    $snap['inventory']['discontinued']         = (int)qv($conn, "SELECT COUNT(*) FROM products WHERE is_discontinued=1");
    $snap['inventory']['top_selling_month']    = qa($conn,
        "SELECT p.product_name, SUM(sd.quantity) AS units_sold, SUM(sd.subtotal) AS revenue
         FROM sale_details sd JOIN products p ON sd.Product_ID=p.Product_ID
         JOIN sales s ON sd.Sale_ID=s.Sale_ID
         WHERE p.is_discontinued = 0 AND MONTH(s.created_at)=MONTH(CURDATE()) AND YEAR(s.created_at)=YEAR(CURDATE())
         GROUP BY sd.Product_ID, p.product_name ORDER BY revenue DESC LIMIT 10");
    $snap['inventory']['top_selling_alltime']  = qa($conn,
        "SELECT p.product_name, SUM(sd.quantity) AS units_sold, SUM(sd.subtotal) AS revenue
         FROM sale_details sd JOIN products p ON sd.Product_ID=p.Product_ID
         WHERE p.is_discontinued = 0
         GROUP BY sd.Product_ID, p.product_name ORDER BY revenue DESC LIMIT 10");
    $snap['inventory']['low_stock']            = qa($conn,
        "SELECT p.product_name, COALESCE(SUM(si.quantity),0) AS qty
         FROM products p LEFT JOIN stockin_inventory si ON p.Product_ID=si.Product_ID
         WHERE p.is_discontinued=0 GROUP BY p.Product_ID, p.product_name
         HAVING qty <= 50 ORDER BY qty ASC LIMIT 10");

    // ── 5. DAMAGE GOODS ──────────────────────────────────────────────────────────
    $snap['damage']['today']      = (int)qv($conn, "SELECT COALESCE(SUM(quantity),0) FROM damage_goods WHERE DATE(created_at)=CURDATE()");
    $snap['damage']['this_week']  = (int)qv($conn, "SELECT COALESCE(SUM(quantity),0) FROM damage_goods WHERE YEARWEEK(created_at,1)=YEARWEEK(CURDATE(),1)");
    $snap['damage']['this_month'] = (int)qv($conn, "SELECT COALESCE(SUM(quantity),0) FROM damage_goods WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())");
    $snap['damage']['last_month'] = (int)qv($conn, "SELECT COALESCE(SUM(quantity),0) FROM damage_goods WHERE MONTH(created_at)=MONTH(DATE_SUB(CURDATE(),INTERVAL 1 MONTH)) AND YEAR(created_at)=YEAR(DATE_SUB(CURDATE(),INTERVAL 1 MONTH))");
    $snap['damage']['this_year']  = (int)qv($conn, "SELECT COALESCE(SUM(quantity),0) FROM damage_goods WHERE YEAR(created_at)=YEAR(CURDATE())");
    $snap['damage']['reports_today'] = (int)qv($conn, "SELECT COUNT(*) FROM damage_goods WHERE DATE(created_at)=CURDATE()");
    $snap['damage']['by_type']    = qa($conn,
        "SELECT COALESCE(damage_type,'Unclassified') AS damage_type, SUM(quantity) AS qty
         FROM damage_goods WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())
         GROUP BY damage_type ORDER BY qty DESC");

    // ── 6. DELIVERIES ────────────────────────────────────────────────────────────
    $snap['deliveries']['today_count']      = (int)qv($conn, "SELECT COUNT(*) FROM delivery WHERE schedule_date=CURDATE()");
    $snap['deliveries']['pending']          = (int)qv($conn, "SELECT COUNT(*) FROM delivery WHERE delivery_status IN ('Scheduled','In Transit')");
    $snap['deliveries']['overdue']          = (int)qv($conn, "SELECT COUNT(*) FROM delivery WHERE schedule_date < CURDATE() AND delivery_status NOT IN ('Delivered','Completed','Cancelled')");
    $snap['deliveries']['completed_today']  = (int)qv($conn, "SELECT COUNT(*) FROM delivery WHERE DATE(updated_at)=CURDATE() AND delivery_status IN ('Delivered','Completed')");
    $snap['deliveries']['this_month']       = (int)qv($conn, "SELECT COUNT(*) FROM delivery WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())");

    $del_cols = array_column($conn->query("SHOW COLUMNS FROM delivery")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $rider_join_clause = "LEFT JOIN user u ON d.delivered_by = COALESCE(u.full_name, u.user_name)";
    if (in_array('assigned_rider_id', $del_cols)) {
        $rider_join_clause = "LEFT JOIN user u ON d.assigned_rider_id=u.User_ID";
    }

    $snap['deliveries']['today_list']       = qa($conn,
        "SELECT d.Delivery_ID, d.delivery_status, d.delivery_address,
                COALESCE(c.customer_name, d.delivered_to, 'Unknown') AS customer_name,
                COALESCE(u.full_name, u.user_name, d.delivered_by) AS rider_name
         FROM delivery d
         LEFT JOIN orders o ON d.Order_ID=o.Order_ID
         LEFT JOIN customers c ON o.Customer_ID=c.Customer_ID
         {$rider_join_clause}
         WHERE d.schedule_date=CURDATE()
         ORDER BY d.created_at DESC LIMIT 20");

    // ── 7. USERS / LOGINS ────────────────────────────────────────────────────────
    $snap['users']['total']        = (int)qv($conn, "SELECT COUNT(*) FROM user WHERE is_active=1");
    $snap['users']['by_role']      = qa($conn,
        "SELECT r.role_name, COUNT(*) AS count FROM user u
         LEFT JOIN roles r ON u.Role_ID=r.Role_ID
         WHERE u.is_active=1 GROUP BY r.role_name ORDER BY count DESC");
    $snap['users']['all_list']     = qa($conn,
        "SELECT u.user_name, COALESCE(u.full_name,u.user_name) AS full_name,
                r.role_name, u.status
         FROM user u LEFT JOIN roles r ON u.Role_ID=r.Role_ID
         WHERE u.is_active=1 ORDER BY r.role_name, u.user_name");
    $snap['users']['active_now']   = qa($conn,
        "SELECT COALESCE(u.full_name,u.user_name) AS full_name,
                r.role_name, us.last_seen_at, us.ip_address
         FROM user_sessions us
         JOIN user u ON us.User_ID=u.User_ID
         LEFT JOIN roles r ON u.Role_ID=r.Role_ID
         WHERE us.is_active=1 AND us.logout_at IS NULL
           AND us.last_seen_at >= (NOW() - INTERVAL 180 SECOND)
         ORDER BY us.last_seen_at DESC LIMIT 20");
    $snap['users']['login_history']= qa($conn,
        "SELECT COALESCE(u.full_name,u.user_name) AS full_name,
                r.role_name, us.login_at, us.logout_at,
                CASE WHEN us.logout_at IS NULL
                      AND us.last_seen_at >= (NOW()-INTERVAL 180 SECOND)
                     THEN 'online' ELSE 'offline' END AS status
         FROM user_sessions us
         JOIN user u ON us.User_ID=u.User_ID
         LEFT JOIN roles r ON u.Role_ID=r.Role_ID
         WHERE us.login_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         ORDER BY us.login_at DESC LIMIT 100");

    // ── 8. RECENT ACTIVITY LOGS ──────────────────────────────────────────────────
    $snap['activity']['recent'] = qa($conn,
        "SELECT al.Activity_Type, al.Action_Details, al.Log_Time,
                COALESCE(u.full_name, u.user_name, 'System') AS user_name,
                r.role_name
         FROM activity_logs al
         LEFT JOIN user u ON al.User_ID=u.User_ID
         LEFT JOIN roles r ON u.Role_ID=r.Role_ID
         ORDER BY al.Log_Time DESC LIMIT 20");

    echo json_encode([
        'success'    => true,
        'snapshot'   => $snap,
        'timestamp'  => date('Y-m-d H:i:s'),
        'generated'  => date('D, d M Y h:i A'),
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
