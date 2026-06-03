<?php
/**
 * Per-user module access overrides.
 * Default behavior: allowed unless explicitly denied.
 */

function getManagedModuleDefinitions(): array {
    return [
        'dashboard' => 'Dashboard',
        'customers' => 'Customers',
        'orders' => 'Orders',
        'delivery' => 'Delivery',
        'inventory' => 'Inventory',
        'production' => 'Production',
        'accounts_receivable' => 'Accounts Receivable',
        'reports' => 'Reports',
        'activity_logs' => 'Activity Logs',
        'product_management' => 'Product Management',
        'sale_view' => 'Sale View',
        'sales_management' => 'Sales Management',
        'sales_history' => 'Sales History',
        'sales_report' => 'Sales Report',
        'cashier_pos' => 'Cashier POS',
        'rider_dashboard' => 'Rider Dashboard',
        'inventory_staff_dashboard' => 'Inventory Staff Dashboard',
        'manual_adjustment' => 'Manual Adjustment',
        'inv_record_production' => 'Inventory: Record Production',
        'inv_manual_adjustment' => 'Inventory: Record Manual Adjustment',
        'inv_production_history' => 'Inventory: Production History',
        'inv_adjustment_history' => 'Inventory: Adjustment History',
        'rider_dashboard_tab' => 'Rider: Dashboard Tab',
        'rider_delivery_queue' => 'Rider: Delivery Queue',
        'rider_delivery_history' => 'Rider: Delivery History',
        'cashier_z_read' => 'Cashier: Z-Read',
        'cashier_void_sale' => 'Cashier: Void Transactions',
        'cashier_delivery_orders_sales' => 'Cashier: Delivery Orders for Sales',
        'cashier_ar_sales' => 'Cashier: AR in Sales',
        'user_management' => 'User Management',
        'session_monitoring' => 'Session Monitoring',
    ];
}

function getRoleDefaultModuleKeys(string $roleName): array {
    $r = strtolower(trim($roleName));

    if (strpos($r, 'owner') !== false || strpos($r, 'manager') !== false) {
        return [
            'dashboard',
            'sales_management',
            'sales_history',
            'sales_report',
            'customers',
            'orders',
            'delivery',
            'inventory',
            'production',
            'accounts_receivable',
            'reports',
            'activity_logs',
            'product_management',
            'sale_view',
            'user_management',
            'session_monitoring',
        ];
    }

    if (strpos($r, 'cashier') !== false) {
        return [
            'cashier_pos',
            'sales_history',
            'sales_report',
            'cashier_z_read',
            'cashier_void_sale',
            'cashier_delivery_orders_sales',
            'cashier_ar_sales',
            'orders',
            'delivery',
            'customers',
            'accounts_receivable',
        ];
    }

    if (strpos($r, 'rider') !== false) {
        return [
            'rider_dashboard',
            'rider_dashboard_tab',
            'rider_delivery_queue',
            'rider_delivery_history',
            'delivery',
        ];
    }

    if (strpos($r, 'inventory') !== false) {
        return [
            'inventory_staff_dashboard',
            'manual_adjustment',
            'inv_manual_adjustment',
            'inv_record_production',
            'inv_production_history',
            'inv_adjustment_history',
            'inventory',
            'production',
        ];
    }

    return [];
}

function getRoleDefaultModuleKeysByRoleId(PDO $conn, int $roleId): array {
    try {
        $stmt = $conn->prepare("SELECT role_name FROM roles WHERE Role_ID = ? LIMIT 1");
        $stmt->execute([$roleId]);
        $name = (string)($stmt->fetchColumn() ?: '');
        return getRoleDefaultModuleKeys($name);
    } catch (Exception $e) {
        return [];
    }
}

function ensureUserModuleAccessTable(PDO $conn): void {
    $sql = "CREATE TABLE IF NOT EXISTS user_module_access (
        Access_ID INT AUTO_INCREMENT PRIMARY KEY,
        User_ID INT NOT NULL,
        module_key VARCHAR(80) NOT NULL,
        is_allowed TINYINT(1) NOT NULL DEFAULT 1,
        updated_by INT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_module (User_ID, module_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->exec($sql);
}

function getUserModuleAccessMap(PDO $conn, int $userId): array {
    ensureUserModuleAccessTable($conn);
    $stmt = $conn->prepare("SELECT module_key, is_allowed FROM user_module_access WHERE User_ID = ?");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $row) {
        $map[(string)$row['module_key']] = ((int)$row['is_allowed'] === 1);
    }
    return $map;
}

/**
 * Stable hash for the current user's role + per-module overrides (for realtime permission sync).
 */
function getModuleAccessVersionForUser(PDO $conn, int $userId): string {
    ensureUserModuleAccessTable($conn);
    $stmt = $conn->prepare("SELECT Role_ID FROM user WHERE User_ID = ? LIMIT 1");
    $stmt->execute([$userId]);
    $roleId = (int)($stmt->fetchColumn() ?: 0);
    $stmt = $conn->prepare("SELECT module_key, is_allowed FROM user_module_access WHERE User_ID = ? ORDER BY module_key ASC");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $row) {
        $map[(string)$row['module_key']] = (int)$row['is_allowed'];
    }
    return hash('sha256', $roleId . '|' . json_encode($map));
}

function isModuleAllowedForUser(PDO $conn, int $userId, string $moduleKey, bool $default = true): bool {
    $defs = getManagedModuleDefinitions();
    if (!isset($defs[$moduleKey])) {
        return $default;
    }
    $map = getUserModuleAccessMap($conn, $userId);
    return array_key_exists($moduleKey, $map) ? (bool)$map[$moduleKey] : $default;
}

function resolveCurrentModuleKey(): ?string {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = strtolower(basename($script));

    $pageMap = [
        'index.php' => 'dashboard',
        'sales.php' => 'sales_management',
        'cashier_view.php' => 'cashier_pos',
        'rider_view.php' => 'rider_dashboard',
        'inventory_staff_view.php' => 'inventory_staff_dashboard',
        'inventory_staff.php' => 'inventory_staff_dashboard',
        'manual_adjustment.php' => 'manual_adjustment',
        'inventory.php' => 'inventory',
        'orders.php' => 'orders',
        'delivery.php' => 'delivery',
        'production.php' => 'production',
        'users.php' => 'customers',
        'reports.php' => 'reports',
        'analytics_reports.php' => 'reports',
        'forecast_analytics.php' => 'reports',
        'accounts_receivable.php' => 'accounts_receivable',
        'activity_logs.php' => 'activity_logs',
        'user_management.php' => 'user_management',
        'session_monitoring.php' => 'session_monitoring',
        'products_add.php' => 'product_management',
        'products_edit.php' => 'product_management',
        'sale_view.php' => 'sale_view',
    ];

    if (isset($pageMap[$base])) {
        return $pageMap[$base];
    }

    $apiMap = [
        'sales_backend.php' => 'cashier_pos',
        'export_sales.php' => 'sales_report',
        'get_sale_details.php' => 'sales_history',
        'orders_backend.php' => 'orders',
        'get_order_details.php' => 'orders',
        'delivery_backend.php' => 'delivery',
        'get_delivery_details.php' => 'delivery',
        'inventory_backend.php' => 'inventory',
        'inventory_stats.php' => 'inventory',
        'manual_adjustment_backend.php' => 'manual_adjustment',
        'production_backend.php' => 'production',
        'get_production_history.php' => 'production',
        'get_production_history_all.php' => 'production',
        'export_production_today.php' => 'production',
        'users_backend.php' => 'customers',
        'ar_backend.php' => 'accounts_receivable',
        'rider_dashboard_backend.php' => 'rider_dashboard',
        'pos_order_search.php' => 'cashier_pos',
        'user_management_backend.php' => 'user_management',
        'active_sessions.php' => 'session_monitoring',
        'dashboard_charts.php' => 'dashboard',
        'dashboard_stats.php' => 'dashboard',
        'dss_backend.php' => 'dashboard',
        'forecast_sales.php' => 'reports',
        'forecast_training_export.php' => 'reports',
        'forecast_bundle_export.php' => 'reports',
        'forecast_import.php' => 'reports',
    ];

    if (strpos($script, '/api/') !== false && isset($apiMap[$base])) {
        return $apiMap[$base];
    }

    return null;
}

function enforceCurrentRequestModuleAccess(PDO $conn): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return;
    }

    $moduleKey = resolveCurrentModuleKey();
    if ($moduleKey === null) {
        return;
    }

    if (isModuleAllowedForUser($conn, $userId, $moduleKey, true)) {
        return;
    }

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $isApi = strpos($script, '/api/') !== false;
    if ($isApi) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'Module access is currently restricted for your account.',
        ]);
        exit;
    }

    $isSubpage = !file_exists('login.php');
    $target = $isSubpage ? '../access_denied.php' : 'access_denied.php';
    header('Location: ' . $target . '?module=' . urlencode($moduleKey));
    exit;
}

