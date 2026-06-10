<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/roles_helper.php';

// Accessible to management and inventory staff (role IDs from DB)
$management_roles = getManagementRoleIds($conn);
$staff_roles = getInventoryStaffRoleIds($conn);
$allowed_roles = array_values(array_unique(array_merge($management_roles, $staff_roles)));

if (!isset($_SESSION['user_role']) || !in_array((int)$_SESSION['user_role'], $allowed_roles, true)) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized'
    ]);
    exit;
}

try {
    // 1. Get max Log_ID from activity_logs to use as the seen watermark
    $stmt = $conn->query("SHOW COLUMNS FROM activity_logs");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pk = 'Log_ID';
    foreach ($columns as $col) {
        if (($col['Key'] ?? '') === 'PRI') {
            $pk = $col['Field'];
            break;
        }
    }
    
    $stmt = $conn->query("SELECT MAX($pk) FROM activity_logs");
    $max_log_id = $stmt ? (int)$stmt->fetchColumn() : 0;
    
    $_SESSION['last_seen_log_id'] = $max_log_id;
    
    // 2. Fetch currently overdue orders and save their IDs to the seen session array
    $seen_overdue_ids = [];
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
        $scheduleDateJoin = $hasScheduleDate ? "LEFT JOIN delivery d ON d.Delivery_ID = (
            SELECT d2.Delivery_ID
            FROM delivery d2
            WHERE d2.Order_ID = o.Order_ID
            ORDER BY d2.Delivery_ID DESC
            LIMIT 1
        )" : "";
        $scheduleDateCol = $hasScheduleDate ? "COALESCE(d.schedule_date, $deliveryDateSelect)" : $deliveryDateSelect;
        
        $overdueQuery = "
            SELECT o.Order_ID
            FROM orders o
            $scheduleDateJoin
            LEFT JOIN order_preparation_tasks opt ON o.Order_ID = opt.Order_ID
            WHERE LOWER(COALESCE(o.{$orderStatusCol}, '')) NOT IN ('completed', 'cancelled', 'canceled', 'out for delivery', 'delivered', 'delivered (pending cash turnover)')
              AND COALESCE(opt.status, 'not_started') != 'ready'
              AND $scheduleDateCol < CURRENT_DATE()
        ";
        
        $overdueStmt = $conn->query($overdueQuery);
        if ($overdueStmt) {
            $seen_overdue_ids = $overdueStmt->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (Throwable $e) {}
    
    $_SESSION['seen_overdue_order_ids'] = array_map('intval', $seen_overdue_ids);
    
    echo json_encode([
        'status' => 'success'
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
