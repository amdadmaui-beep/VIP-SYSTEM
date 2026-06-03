<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/roles_helper.php';

// Accessible to Cashier, Owner, Manager
$cashier_ids = getCashierRoleIds($conn);
requireRole(empty($cashier_ids) ? [0] : array_merge([1, 2], $cashier_ids));

$full_name = $_SESSION['full_name'] ?? $_SESSION['user_name'] ?? 'Cashier';
$user_id = $_SESSION['user_id'] ?? 0;
$can_cashier_delivery_orders = isModuleAllowedForUser($conn, (int)$user_id, 'cashier_delivery_orders_sales', true);

// Fetch riders for dropdown
require_once __DIR__ . '/../../includes/roles_helper.php';
$rider_role_ids = getRiderRoleIds($conn);
$riders_res = [];
if (!empty($rider_role_ids)) {
    $placeholders = implode(',', array_fill(0, count($rider_role_ids), '?'));
    $rider_stmt = $conn->prepare("SELECT u.User_ID, COALESCE(u.full_name, u.user_name) as rider_name 
                                   FROM user u 
                                   WHERE u.Role_ID IN ($placeholders) AND u.is_active = 1 
                                   ORDER BY COALESCE(u.full_name, u.user_name)");
    $rider_stmt->execute($rider_role_ids);
    $riders_res = $rider_stmt->fetchAll(PDO::FETCH_ASSOC);
}
