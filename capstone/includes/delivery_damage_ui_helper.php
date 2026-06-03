<?php
/**
 * Shared UI helpers for delivery damage reports (pending count, reviewer access).
 */
if (!function_exists('ddr_table_exists')) {
    function ddr_table_exists(PDO $conn): bool
    {
        try {
            $t = $conn->query("SHOW TABLES LIKE 'delivery_damage_report'");
            return $t && $t->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('getDeliveryDamageReviewRoleIds')) {
    /**
     * Inventory staff ∪ owner/manager-style roles (matches delivery_damage_backend / sidebar).
     */
    function getDeliveryDamageReviewRoleIds(PDO $conn): array
    {
        if (!function_exists('getInventoryStaffRoleIds')) {
            require_once __DIR__ . '/roles_helper.php';
        }
        $staff = getInventoryStaffRoleIds($conn);
        return array_values(array_unique(array_merge([1, 2, 4], $staff)));
    }
}

if (!function_exists('userCanAccessDeliveryDamageQueue')) {
    function userCanAccessDeliveryDamageQueue(PDO $conn, int $roleId): bool
    {
        return in_array($roleId, getDeliveryDamageReviewRoleIds($conn), true);
    }
}

if (!function_exists('countPendingDeliveryDamageReports')) {
    function countPendingDeliveryDamageReports(PDO $conn): int
    {
        if (!ddr_table_exists($conn)) {
            return 0;
        }
        try {
            return (int) $conn->query(
                "SELECT COUNT(*) 
                 FROM delivery_damage_report r
                 LEFT JOIN damage_report_reviews rev ON rev.report_id = r.report_id
                 WHERE COALESCE(rev.status, 'pending_review') = 'pending_review'"
            )->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('isInventoryStaffRole')) {
    /** True if this role is an inventory-staff role (from DB). */
    function isInventoryStaffRole(PDO $conn, int $roleId): bool
    {
        if (!function_exists('getInventoryStaffRoleIds')) {
            require_once __DIR__ . '/roles_helper.php';
        }
        return in_array($roleId, getInventoryStaffRoleIds($conn), true);
    }
}

if (!function_exists('deliveryDamageQueueHrefForUser')) {
    /**
     * Inventory staff use the mobile staff queue page; owners/managers use the main dashboard queue.
     */
    function deliveryDamageQueueHrefForUser(PDO $conn, int $roleId): string
    {
        return isInventoryStaffRole($conn, $roleId)
            ? 'inventory_staff_delivery_damage.php'
            : 'delivery_damage_queue.php';
    }
}

if (!function_exists('getUnreadNotificationsCount')) {
    function getUnreadNotificationsCount(PDO $conn): int
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $role_id = isset($_SESSION['user_role']) ? (int)$_SESSION['user_role'] : 0;
        
        // Define management and staff roles
        if (!function_exists('getInventoryStaffRoleIds')) {
            require_once __DIR__ . '/roles_helper.php';
        }
        $management_roles = [1, 2];
        $staff_roles = getInventoryStaffRoleIds($conn);
        $allowed_roles = array_unique(array_merge($management_roles, $staff_roles));
        
        if (!in_array($role_id, $allowed_roles, true)) {
            return 0;
        }
        
        $last_seen_log_id = isset($_SESSION['last_seen_log_id']) ? (int)$_SESSION['last_seen_log_id'] : 0;
        
        // 1. Count unread activity logs
        $unread_logs_count = 0;
        try {
            $stmt = $conn->query("SHOW COLUMNS FROM activity_logs");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $pk = 'Log_ID';
            foreach ($columns as $col) {
                if (($col['Key'] ?? '') === 'PRI') {
                    $pk = $col['Field'];
                    break;
                }
            }
            
            $where = "al.$pk > :last_seen";
            $params = [':last_seen' => $last_seen_log_id];
            
            if (!in_array($role_id, $management_roles, true)) {
                $where .= " AND (
                    (al.Activity_Type = 'DELIVERY' AND (al.Action_Details LIKE '%damage%' OR al.Action_Details LIKE '%Damage%'))
                    OR
                    (al.Activity_Type = 'INVENTORY' AND (al.Action_Details LIKE '%damage%' OR al.Action_Details LIKE '%Damage%'))
                    OR
                    (al.Activity_Type = 'ORDER' AND (al.Action_Details LIKE '%Created new order%' OR al.Action_Details LIKE '%Scheduled delivery%'))
                )";
            }
            
            $query = "SELECT COUNT(*) FROM activity_logs al WHERE $where";
            $stmt = $conn->prepare($query);
            $stmt->execute($params);
            $unread_logs_count = (int)$stmt->fetchColumn();
        } catch (Throwable $e) { error_log('Failed to count unread delivery logs: ' . $e->getMessage()); }
        
        // 2. Count unread overdue orders
        $unread_overdue_count = 0;
        try {
            $seen_overdue_order_ids = isset($_SESSION['seen_overdue_order_ids']) ? $_SESSION['seen_overdue_order_ids'] : [];
            
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
                $current_overdue_ids = $overdueStmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($current_overdue_ids as $oid) {
                    if (!in_array((int)$oid, $seen_overdue_order_ids, true)) {
                        $unread_overdue_count++;
                    }
                }
            }
        } catch (Throwable $e) {}
        
        return $unread_logs_count + $unread_overdue_count;
    }
}
