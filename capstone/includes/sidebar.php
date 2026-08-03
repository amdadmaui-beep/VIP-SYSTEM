<?php
if (!function_exists('renderSidebar')) {
    function renderSidebar(PDO $conn, array $opts = []): void
    {
        $base = isset($opts['base']) ? htmlspecialchars((string)$opts['base'], ENT_QUOTES, 'UTF-8') : '';
        $active = isset($opts['active']) ? htmlspecialchars((string)$opts['active'], ENT_QUOTES, 'UTF-8') : '';
        $roleId = (int)($_SESSION['user_role'] ?? 0);

        if (!function_exists('getManagementRoleIds')) {
            require_once __DIR__ . '/roles_helper.php';
        }
        require_once __DIR__ . '/delivery_damage_ui_helper.php';
        require_once __DIR__ . '/manager_notifications.php';

        $ddrPendingBadge = countPendingDeliveryDamageReports($conn);
        $showDeliveryDamageQueue = ddr_table_exists($conn)
            && userCanAccessDeliveryDamageQueue($conn, $roleId)
            && !isInventoryStaffRole($conn, $roleId);

        $managementIds = getManagementRoleIds($conn);
        $canManageSystem = in_array($roleId, $managementIds, true);

        $arCountBadge = 0;
        try {
            $arCheck = $conn->query("SHOW TABLES LIKE 'account_receivable'");
            if ($arCheck && $arCheck->rowCount() > 0) {
                $arCountResult = $conn->query("SELECT COUNT(*) as cnt FROM account_receivable WHERE status IN ('Open', 'Partial', 'Overdue', 'Pending') AND amount_due > 0");
                if ($arCountResult) {
                    $arRow = $arCountResult->fetch(PDO::FETCH_ASSOC);
                    $arCountBadge = $arRow ? intval($arRow['cnt']) : 0;
                }
            }
        } catch (Throwable $e) {
            $arCountBadge = 0;
        }

        // Overdue Orders & Deliveries badge counts
        $overdueOrdersBadge = 0;
        $overdueDeliveriesBadge = 0;
        try {
            $tablesCheck = $conn->query("SHOW TABLES LIKE 'orders'");
            if ($tablesCheck && $tablesCheck->rowCount() > 0) {
                $oc = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
                $osc = $oc && $oc->rowCount() > 0 ? $oc->fetch(PDO::FETCH_ASSOC)['Field'] : 'order_status';
                $overdueStmt = $conn->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN delivery d ON o.Order_ID = d.Order_ID WHERE COALESCE(d.schedule_date, o.delivery_date, o.order_date) < CURDATE() AND LOWER(o.$osc) NOT IN ('completed','cancelled','delivered (pending cash turnover)')");
                $overdueStmt->execute();
                $overdueOrdersBadge = (int)$overdueStmt->fetchColumn();
            }
        } catch (Throwable $e) { $overdueOrdersBadge = 0; }
        try {
            $dc = $conn->query("SHOW TABLES LIKE 'delivery'");
            if ($dc && $dc->rowCount() > 0) {
                $overdueDelStmt = $conn->prepare("SELECT COUNT(*) FROM delivery WHERE schedule_date < CURDATE() AND LOWER(delivery_status) NOT IN ('delivered','completed','cancelled')");
                $overdueDelStmt->execute();
                $overdueDeliveriesBadge = (int)$overdueDelStmt->fetchColumn();
            }
        } catch (Throwable $e) { $overdueDeliveriesBadge = 0; }

        $isActive = static function (string $key) use ($active): string {
            return $active === $key ? ' active' : '';
        };

        $hideMgrNotifications = !empty($opts['hide_mgr_notifications']);
        ?>
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-brand">
                    <div class="brand-icon">
                        <img src="<?= $base ?>assets/img/VIP_lOGO.png" alt="Logo" style="width: 44px; height: 44px; object-fit: contain; border-radius: 10px;">
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
                    <a href="<?= $base ?>index.php" class="menu-item<?= $isActive('dashboard') ?>">
                        <i class="fas fa-th-large"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="<?= $base ?>pages/sales.php" class="menu-item<?= $isActive('sales') ?>">
                        <i class="fas fa-receipt"></i>
                        <span>Sales</span>
                    </a>
                    <a href="<?= $base ?>pages/inventory.php" class="menu-item<?= $isActive('inventory') ?>">
                        <i class="fas fa-cubes"></i>
                        <span>Inventory</span>
                    </a>
                    <a href="<?= $base ?>pages/damage_goods.php" class="menu-item<?= $isActive('damage_goods') ?>">
                        <i class="fas fa-heart-broken"></i>
                        <span>Damage Goods</span>
                    </a>
                    <?php if ($showDeliveryDamageQueue): ?>
                    <a href="<?= $base ?>pages/delivery_damage_queue.php" class="menu-item<?= $isActive('delivery_damage_queue') ?>">
                        <i class="fas fa-clipboard-check"></i>
                        <span>Damage Reports</span>
                        <?php if ($ddrPendingBadge > 0): ?>
                            <span class="menu-item-badge"><?= $ddrPendingBadge ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>
                    <a href="<?= $base ?>pages/stock_ledger.php" class="menu-item<?= $isActive('stock_ledger') ?>">
                        <i class="fas fa-file-invoice"></i>
                        <span>Stock Ledger</span>
                    </a>
                    <a href="<?= $base ?>pages/categories.php" class="menu-item<?= $isActive('categories') ?>">
                        <i class="fas fa-tags"></i>
                        <span>Categories</span>
                    </a>
                    <a href="<?= $base ?>pages/users.php" class="menu-item<?= $isActive('customers') ?>">
                        <i class="fas fa-users"></i>
                        <span>Customers</span>
                    </a>
                    <a href="<?= $base ?>pages/orders.php" class="menu-item<?= $isActive('orders') ?>">
                        <i class="fas fa-shopping-cart"></i>
                        <span>Orders</span>
                        <?php if ($overdueOrdersBadge > 0): ?>
                            <span class="menu-item-badge" style="background:#ef4444;"><?= $overdueOrdersBadge ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="<?= $base ?>pages/delivery.php" class="menu-item<?= $isActive('delivery') ?>">
                        <i class="fas fa-truck"></i>
                        <span>Delivery</span>
                        <?php if ($overdueDeliveriesBadge > 0): ?>
                            <span class="menu-item-badge" style="background:#ef4444;"><?= $overdueDeliveriesBadge ?></span>
                        <?php endif; ?>
                    </a>
                </div>

                <div class="menu-section">
                    <div class="menu-label">Accounting</div>
                    <a href="<?= $base ?>pages/accounts_receivable.php" class="menu-item<?= $isActive('accounts_receivable') ?>">
                        <i class="fas fa-file-invoice-dollar"></i>
                        <span>Accounts Receivable</span>
                        <?php if ($arCountBadge > 0): ?>
                            <span class="menu-item-badge"><?= $arCountBadge ?></span>
                        <?php endif; ?>
                    </a>
                </div>

                <div class="menu-section">
                    <div class="menu-label">System</div>
                    <a href="<?= $base ?>pages/activity_logs.php" class="menu-item<?= $isActive('activity_logs') ?>">
                        <i class="fas fa-history"></i>
                        <span>Activity Logs</span>
                    </a>
                    <?php if ($canManageSystem): ?>
                        <a href="<?= $base ?>pages/user_management.php" class="menu-item<?= $isActive('user_management') ?>">
                            <i class="fas fa-user-shield"></i>
                            <span>User Management</span>
                        </a>
                        <a href="<?= $base ?>pages/session_monitoring.php" class="menu-item<?= $isActive('session_monitoring') ?>">
                            <i class="fas fa-user-clock"></i>
                            <span>Session Monitoring</span>
                        </a>
                    <?php endif; ?>
                    <a href="<?= $base ?>logout.php" class="menu-item">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </nav>
        </aside>
        <?php
        if (!$hideMgrNotifications) {
            renderManagerNotificationWidget($conn, $base);
        }
    }
}

