<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/roles_helper.php';

// Accessible to Owner (1), Manager (2), Inventory Staff, and Delivery Riders
$management_roles = [1, 2];
$staff_roles = getInventoryStaffRoleIds($conn);
$rider_roles = getRiderRoleIds($conn);
$allowed_roles = array_unique(array_merge($management_roles, $staff_roles, $rider_roles));

if (!isset($_SESSION['user_role']) || !in_array((int)$_SESSION['user_role'], $allowed_roles, true)) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized',
        'logs' => [],
        'pk' => 'Log_ID',
    ]);
    exit;
}

$last_id = isset($_GET['last_id']) ? max(0, (int)$_GET['last_id']) : 0;
$filter = isset($_GET['filter']) ? trim($_GET['filter']) : null;
$limit = 10;

try {
    // Resolve PK for activity_logs dynamically.
    $stmt = $conn->query("SHOW COLUMNS FROM activity_logs");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pk = 'Log_ID';
    foreach ($columns as $col) {
        if (($col['Key'] ?? '') === 'PRI') {
            $pk = $col['Field'];
            break;
        }
    }

    // Prefer canonical `user` table, fallback to app_users for legacy installs.
    $tableCheck = $conn->query("SHOW TABLES");
    $tables = $tableCheck ? $tableCheck->fetchAll(PDO::FETCH_COLUMN) : [];
    $userTable = in_array('user', $tables, true) ? 'user' : (in_array('app_users', $tables, true) ? 'app_users' : null);

    $userJoin = '';
    $userSelect = "'System' AS user_name";
    if ($userTable !== null) {
        $hasUserProfiles = in_array('user_profiles', $tables, true);
        if ($hasUserProfiles) {
            $userJoin = "LEFT JOIN `$userTable` u ON al.User_ID = u.User_ID
                         LEFT JOIN `user_profiles` up ON u.User_ID = up.User_ID";
            $userSelect = "COALESCE(TRIM(CONCAT(up.first_name, ' ', up.last_name)), u.user_name, 'System') AS user_name";
        } else {
            $userJoin = "LEFT JOIN `$userTable` u ON al.User_ID = u.User_ID";
            $userSelect = "COALESCE(u.user_name, 'System') AS user_name";
        }
    }

    $where = "al.$pk > ?";
    $params = [$last_id];

    $userRole = (int)$_SESSION['user_role'];
    if (!in_array($userRole, $management_roles, true)) {
        $isRider = in_array($userRole, $rider_roles, true);
        if ($isRider) {
            // Riders see only their own notifications and delivery activity
            $userId = (int)($_SESSION['user_id'] ?? 0);
            $where .= " AND al.User_ID = ? AND al.Activity_Type IN ('NOTIFICATION', 'DELIVERY')";
            $params[] = $userId;
        } else {
            // Strict filters for Inventory Staff:
            // 1. Rider damage reports (DELIVERY/INVENTORY containing "damage" / "Damage")
            // 2. Manager order notifications (ORDER containing "Created new order" / "Scheduled delivery")
            $where .= " AND (
                (al.Activity_Type = 'DELIVERY' AND (al.Action_Details LIKE '%damage%' OR al.Action_Details LIKE '%Damage%'))
                OR
                (al.Activity_Type = 'INVENTORY' AND (al.Action_Details LIKE '%damage%' OR al.Action_Details LIKE '%Damage%'))
                OR
                (al.Activity_Type = 'ORDER' AND (al.Action_Details LIKE '%Created new order%' OR al.Action_Details LIKE '%Scheduled delivery%'))
                OR
                (al.Activity_Type = 'NOTIFICATION' AND al.User_ID = ?)
            )";
            $params[] = (int)$_SESSION['user_id'];
        }
    }

    if ($filter) {
        $where .= " AND (al.Activity_Type LIKE ? OR al.Action_Details LIKE ?)";
        $params[] = '%' . $filter . '%';
        $params[] = '%' . $filter . '%';
    }

    $query = "SELECT al.*, $userSelect
              FROM activity_logs al
              $userJoin
              WHERE $where
              ORDER BY al.Log_Time DESC
              LIMIT ?";
    $params[] = $limit;

    $stmt = $conn->prepare($query);
    foreach ($params as $i => $val) {
        $stmt->bindValue($i + 1, $val, is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();

    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Dynamically replace customer IDs with customer names to satisfy requirements
    try {
        $cust_map = [];
        $cust_stmt = $conn->query("SELECT Customer_ID, customer_name FROM customers WHERE deleted_at IS NULL");
        if ($cust_stmt) {
            while ($c_row = $cust_stmt->fetch(PDO::FETCH_ASSOC)) {
                $cust_map[(int)$c_row['Customer_ID']] = $c_row['customer_name'];
            }
        }
        foreach ($logs as &$log) {
            if (preg_match('/customer\s*_?\s*(?:id|ID)?\s*(?::|=)?\s*(\d+)/i', $log['Action_Details'], $matches)) {
                $cid = (int)$matches[1];
                if (isset($cust_map[$cid])) {
                    $log['Action_Details'] = preg_replace('/customer\s*_?\s*(?:id|ID)?\s*(?::|=)?\s*\d+/i', 'customer: ' . $cust_map[$cid], $log['Action_Details']);
                }
            }
        }
        unset($log);
    } catch (Throwable $ex) {}

    // Dynamic injection of "Missed Delivery Day" / overdue alerts for Inventory Staff
    $overdue_logs = [];
    if (!in_array((int)$_SESSION['user_role'], $management_roles, true) && !in_array((int)$_SESSION['user_role'], $rider_roles, true) && $last_id === 0) {
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
                SELECT o.Order_ID, $scheduleDateCol AS delivery_date_effective
                FROM orders o
                $scheduleDateJoin
                LEFT JOIN order_preparation_tasks opt ON o.Order_ID = opt.Order_ID
                WHERE LOWER(COALESCE(o.{$orderStatusCol}, '')) NOT IN ('completed', 'cancelled', 'canceled', 'out for delivery', 'delivered', 'delivered (pending cash turnover)')
                  AND COALESCE(opt.status, 'not_started') != 'ready'
                  AND $scheduleDateCol < CURRENT_DATE()
                ORDER BY delivery_date_effective ASC
            ";
            
            $overdueStmt = $conn->query($overdueQuery);
            if ($overdueStmt) {
                $overdueOrders = $overdueStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($overdueOrders as $o) {
                    $formattedDate = date('M j, Y', strtotime($o['delivery_date_effective']));
                    $overdue_logs[] = [
                        $pk => 'overdue_' . $o['Order_ID'],
                        'User_ID' => 0,
                        'Activity_Type' => 'OVERDUE',
                        'Action_Details' => "This order is overdue. Delivery date ({$formattedDate}) has already passed and it is not yet marked ready.",
                        'Activity' => 'Missed Delivery Day',
                        'Log_Time' => date('Y-m-d H:i:s'), // Keep it fresh at the top!
                        'user_name' => 'System',
                        'is_overdue' => true
                    ];
                }
            }
        } catch (Throwable $ex) {
            // Silently ignore to maintain robustness
        }
    }

    if (!empty($overdue_logs)) {
        $logs = array_merge($overdue_logs, $logs);
    }

    // Sort ascending for incremental polling.
    if ($last_id > 0) {
        $logs = array_reverse($logs);
    }

    echo json_encode([
        'status' => 'success',
        'logs' => $logs,
        'pk' => $pk,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : 'Failed to load activities',
        'logs' => [],
        'pk' => 'Log_ID',
    ]);
}
