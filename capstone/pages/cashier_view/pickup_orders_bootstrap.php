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
$can_cashier_pickup_orders = isModuleAllowedForUser($conn, (int)$user_id, 'cashier_delivery_orders_sales', true);
$can_cashier_ar_sales = isModuleAllowedForUser($conn, (int)$user_id, 'cashier_ar_sales', true);