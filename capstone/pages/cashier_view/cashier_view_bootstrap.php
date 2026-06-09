<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/stock_reservation_helper.php';

// Accessible to Owner and all cashier/POS roles (dynamic from DB)
require_once __DIR__ . '/../../includes/roles_helper.php';
$cashier_ids = getCashierRoleIds($conn);
requireRole(empty($cashier_ids) ? [0] : $cashier_ids);

$full_name = $_SESSION['full_name'] ?? $_SESSION['user_name'] ?? 'Cashier';
$user_id = $_SESSION['user_id'] ?? 0;
$can_sales_history = isModuleAllowedForUser($conn, (int)$user_id, 'sales_history', true);
$can_sales_report = isModuleAllowedForUser($conn, (int)$user_id, 'sales_report', true);
$can_cashier_z_read = isModuleAllowedForUser($conn, (int)$user_id, 'cashier_z_read', true);
$can_cashier_void = isModuleAllowedForUser($conn, (int)$user_id, 'cashier_void_sale', true);
$can_cashier_delivery_orders = isModuleAllowedForUser($conn, (int)$user_id, 'cashier_delivery_orders_sales', true);
$can_cashier_ar_sales = isModuleAllowedForUser($conn, (int)$user_id, 'cashier_ar_sales', true);

// Fetch products with computed available stock:
// Base product list is cached (60s TTL) to reduce DB load;
// stock data is always live since it changes with every sale.
require_once __DIR__ . '/../../includes/product_cache.php';
$products_res = getCachedProducts($conn, 60);
$productIds = array_values(array_filter(array_map(static fn ($r) => (int)($r['Product_ID'] ?? 0), $products_res), static fn ($id) => $id > 0));
$onHandMap = !empty($productIds) ? getPhysicalStockByProductIds($conn, $productIds) : [];
$reservedMap = !empty($productIds) ? getReservedStockByProductIds($conn, $productIds) : [];

foreach ($products_res as &$productRow) {
    $pid = (int)($productRow['Product_ID'] ?? 0);
    $onHand = (float)($onHandMap[$pid] ?? 0);
    $reserved = (float)($reservedMap[$pid] ?? 0);
    $productRow['on_hand_stock'] = $onHand;
    $productRow['reserved_stock'] = $reserved;
    $productRow['available_stock'] = max(0.0, $onHand - $reserved);
}
unset($productRow);

// Hide out-of-stock products from POS
$products_res = array_values(array_filter($products_res, static fn ($r) => (float)($r['available_stock'] ?? 0) > 0));
