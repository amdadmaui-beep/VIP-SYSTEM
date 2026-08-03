<?php
/**
 * Shared header chrome for inventory staff mobile shell (bell + nav + drawer).
 *
 * Expected keys in $INV_CHROME:
 * - display_name (string)
 * - display_role (string)
 * - session_label (string) e.g. "Inventory Session" or "Delivery damage"
 * - nav_active: 'preparation'|'dashboard'|'inventory'|'delivery'|'history'
 * - preparation_href (string, default inventory_staff_view.php)
 * - inventory_href (string)
 * - ddr_queue_show (bool)
 * - ddr_nav_href (string)
 * - ddr_pending_n (int)
 * - dashboard_href (string, default manual_adjustment.php)
 * - history_href (string)
 * - history_nav_id (string, optional) id attribute on History nav link for ?tab=history JS hooks
 */
declare(strict_types=1);

function inv_chrome_nav_classes(string $active, string $key): string
{
    $base = 'flex-shrink-0 px-4 py-2.5 rounded-xl text-xs font-bold transition-all flex items-center gap-2 whitespace-nowrap';
    if ($active === $key) {
        return $base . ' nav-tab active bg-indigo-600 text-white shadow-md shadow-indigo-200';
    }
    return $base . ' nav-tab text-slate-500 hover:bg-slate-100';
}

function inv_chrome_drawer_link_classes(string $active, string $key): string
{
    $base = 'box-border flex w-full max-w-full min-w-0 items-center justify-start gap-3 rounded-xl px-4 py-3.5 text-left text-sm font-bold transition-colors';
    if ($active === $key) {
        return $base . ' bg-indigo-600 text-white shadow-md';
    }
    return $base . ' text-slate-700 hover:bg-slate-100';
}

/**
 * Echo <link> and <script> for chrome assets (safe to call once per page).
 */
function inv_chrome_head_assets(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    echo '<link rel="stylesheet" href="../assets/css/inventory-staff-chrome.css">' . "\n";
}

/**
 * Profile row (with mobile menu) + bell + logout + desktop nav only.
 * Call inv_chrome_render_mobile_drawer() after </header> so the drawer is not clipped
 * by sticky header / backdrop-filter containing blocks.
 *
 * @param array<string, mixed> $c
 */
function inv_chrome_render_header_main(array $c): void
{
    $display_name = htmlspecialchars((string)($c['display_name'] ?? 'User'), ENT_QUOTES, 'UTF-8');
    $display_role = htmlspecialchars((string)($c['display_role'] ?? 'Staff'), ENT_QUOTES, 'UTF-8');
    $session_label = htmlspecialchars((string)($c['session_label'] ?? 'Inventory Session'), ENT_QUOTES, 'UTF-8');
    $nav_active = (string)($c['nav_active'] ?? 'dashboard');
    $preparation_href = htmlspecialchars((string)($c['preparation_href'] ?? 'inventory_staff_view.php'), ENT_QUOTES, 'UTF-8');
    $inventory_href = htmlspecialchars((string)($c['inventory_href'] ?? 'inventory_staff.php'), ENT_QUOTES, 'UTF-8');
    $dashboard_href = htmlspecialchars((string)($c['dashboard_href'] ?? 'manual_adjustment.php'), ENT_QUOTES, 'UTF-8');
    $history_href = htmlspecialchars((string)($c['history_href'] ?? 'manual_adjustment.php?tab=history'), ENT_QUOTES, 'UTF-8');
    $profile_href = htmlspecialchars((string)($c['profile_href'] ?? 'profile_settings.php'), ENT_QUOTES, 'UTF-8');
    $ddr_queue_show = !empty($c['ddr_queue_show']);
    $ddr_nav_href = htmlspecialchars((string)($c['ddr_nav_href'] ?? ''), ENT_QUOTES, 'UTF-8');
    $ddr_pending_n = (int)($c['ddr_pending_n'] ?? 0);
    $initial = strtoupper(substr((string)($c['display_name'] ?? 'U'), 0, 1));
    $history_nav_id = isset($c['history_nav_id']) && (string)$c['history_nav_id'] !== ''
        ? ' id="' . htmlspecialchars((string)$c['history_nav_id'], ENT_QUOTES, 'UTF-8') . '"'
        : '';
    $profilePicture = (string)($c['profile_picture'] ?? '');
    $hasProfilePic = $profilePicture !== '' && file_exists(dirname(__DIR__) . '/' . $profilePicture);
    $profilePicSrc = $hasProfilePic ? '../' . $profilePicture . '?v=' . time() : '';
    $total_notifications_n = (int)($c['total_notifications_n'] ?? 0);
    $inv_user_id = (int)($_SESSION['user_id'] ?? 0);
    $inv_last_seen_log_id = (int)($_SESSION['last_seen_log_id'] ?? 0);
    $badge_label = $total_notifications_n > 99 ? '99+' : (string)$total_notifications_n;

    ?>
    <div class="flex justify-between items-center mb-4 gap-2">
        <div class="flex items-center gap-2 min-w-0 flex-1">
            <button type="button" id="invStaffMenuToggle" class="md:hidden flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 shadow-sm hover:bg-slate-50 hover:border-slate-300 transition-colors" aria-label="Open navigation menu" aria-expanded="false" aria-controls="invStaffDrawerPanel">
                <i class="fas fa-bars text-base" aria-hidden="true"></i>
            </button>
            <div class="flex items-center gap-3 min-w-0">
                <?php if ($hasProfilePic): ?>
                    <img src="<?php echo htmlspecialchars($profilePicSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile" class="w-12 h-12 shrink-0 rounded-2xl object-cover border-2 border-indigo-200 shadow-md">
                <?php else: ?>
                    <div class="w-12 h-12 shrink-0 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-500 text-white flex items-center justify-center text-xl font-bold shadow-md">
                        <?php echo $initial; ?>
                    </div>
                <?php endif; ?>
                <div class="min-w-0">
                    <span class="text-[10px] font-bold tracking-wider text-indigo-500 uppercase block truncate"><?php echo $session_label; ?></span>
                    <h2 class="text-base font-bold leading-tight truncate"><?php echo $display_name; ?></h2>
                    <p class="text-xs text-slate-500 font-medium truncate"><?php echo $display_role; ?></p>
                </div>
            </div>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <div class="inv-bell-wrap relative" id="invStaffNotifRoot" data-user-id="<?php echo $inv_user_id; ?>" data-unread-count="<?php echo $total_notifications_n; ?>" data-last-seen-id="<?php echo $inv_last_seen_log_id; ?>">
                <button type="button" id="invStaffNotifToggle" class="inv-bell-btn group relative flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 shadow-sm transition-all duration-200 hover:border-slate-300 hover:bg-slate-50 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-300 focus-visible:ring-offset-2" aria-expanded="false" aria-haspopup="true" aria-controls="invStaffBellPanel" title="Notifications">
                    <i class="fas fa-bell inv-bell-icon text-lg text-slate-500 group-hover:text-slate-600" aria-hidden="true"></i>
                    <span id="invStaffNotifBadge" class="pointer-events-none absolute -top-1 -right-1 flex h-[1.125rem] min-w-[1.125rem] items-center justify-center rounded-full bg-red-500 px-0.5 text-[9px] font-black leading-none text-white shadow-sm ring-2 ring-white<?php echo $total_notifications_n > 0 ? '' : ' hidden'; ?>"><?php echo htmlspecialchars($badge_label, ENT_QUOTES, 'UTF-8'); ?></span>
                </button>
                <div id="invStaffBellPanel" class="inv-bell-dd-panel z-[80] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl shadow-slate-200/80" role="region" aria-label="Notifications panel">
                    <div class="border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white px-4 py-3">
                        <p class="text-[11px] font-black uppercase tracking-wider text-slate-800">Notifications</p>
                        <p class="text-[10px] font-medium text-slate-500">Delivery damage &amp; recent activity</p>
                    </div>
                    <?php if ($ddr_queue_show && $ddr_nav_href !== ''): ?>
                    <a href="<?php echo $ddr_nav_href; ?>" class="flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-3 text-sm font-bold text-indigo-700 transition-colors hover:bg-indigo-50">
                        <span class="flex items-center gap-2"><i class="fas fa-clipboard-check text-indigo-500"></i> Delivery damage queue</span>
                        <?php if ($ddr_pending_n > 0): ?>
                        <span class="rounded-full bg-red-500 px-2 py-0.5 text-[10px] font-black text-white"><?php echo $ddr_pending_n > 99 ? '99+' : $ddr_pending_n; ?> pending</span>
                        <?php else: ?>
                        <span class="text-[10px] font-bold uppercase tracking-wide text-slate-400">No pending</span>
                        <?php endif; ?>
                    </a>
                    <?php else: ?>
                    <div class="border-b border-slate-100 px-4 py-3 text-xs font-medium text-slate-500">Delivery damage queue is not available for your account.</div>
                    <?php endif; ?>
                    <div class="max-h-64 overflow-y-auto overscroll-contain">
                        <p class="px-4 pt-3 text-[10px] font-black uppercase tracking-wider text-slate-400">Recent activity</p>
                        <div id="invStaffBellList" class="pb-2" data-loaded="0"></div>
                    </div>
                </div>
            </div>
            <a href="<?php echo $profile_href; ?>" class="flex h-11 w-11 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 shadow-sm transition-colors hover:bg-slate-50 hover:text-indigo-600" title="Profile settings" aria-label="Profile settings">
                <i class="fas fa-user-cog text-sm" aria-hidden="true"></i>
            </a>
            <button type="button" onclick="location.href='../logout.php'" class="flex h-11 flex-col items-center justify-center rounded-xl bg-slate-100 px-3 text-slate-500 transition-colors hover:bg-slate-200 hover:text-red-500">
                <i class="fas fa-sign-out-alt text-sm" aria-hidden="true"></i>
                <span class="mt-0.5 text-[9px] font-bold">Logout</span>
            </button>
        </div>
    </div>

    <!-- Shared nav -->
    <div class="flex gap-2 overflow-x-auto hide-scroll pb-1">
        <a href="<?php echo $dashboard_href; ?>" class="<?php echo inv_chrome_nav_classes($nav_active, 'dashboard'); ?>">
            <i class="fas fa-chart-pie"></i> Dashboard
        </a>
        <a href="<?php echo $preparation_href; ?>" class="<?php echo inv_chrome_nav_classes($nav_active, 'preparation'); ?>">
            <i class="fas fa-clipboard-list"></i> Preparations
        </a>
        <a href="<?php echo $inventory_href; ?>" class="<?php echo inv_chrome_nav_classes($nav_active, 'inventory'); ?>">
            <i class="fas fa-warehouse"></i> Inventory
        </a>
        <?php if ($ddr_queue_show && $ddr_nav_href !== ''): ?>
        <a href="<?php echo $ddr_nav_href; ?>" class="<?php echo inv_chrome_nav_classes($nav_active, 'delivery'); ?>">
            <i class="fas fa-exclamation-triangle"></i> Delivery Damage Report
        </a>
        <?php endif; ?>
        <a href="<?php echo $history_href; ?>"<?php echo $history_nav_id; ?> class="<?php echo inv_chrome_nav_classes($nav_active, 'history'); ?>">
            <i class="fas fa-clock-rotate-left"></i> History
        </a>
        <a href="<?php echo $profile_href; ?>" class="<?php echo inv_chrome_nav_classes($nav_active, 'profile'); ?>">
            <i class="fas fa-user-cog"></i> Profile
        </a>
    </div>
    <?php
}

/**
 * Mobile drawer only — output immediately after </header> (sibling of header, inside body).
 *
 * @param array<string, mixed> $c
 */
function inv_chrome_render_mobile_drawer(array $c): void
{
    $nav_active = (string)($c['nav_active'] ?? 'dashboard');
    $preparation_href = htmlspecialchars((string)($c['preparation_href'] ?? 'inventory_staff_view.php'), ENT_QUOTES, 'UTF-8');
    $inventory_href = htmlspecialchars((string)($c['inventory_href'] ?? 'inventory_staff.php'), ENT_QUOTES, 'UTF-8');
    $dashboard_href = htmlspecialchars((string)($c['dashboard_href'] ?? 'manual_adjustment.php'), ENT_QUOTES, 'UTF-8');
    $history_href = htmlspecialchars((string)($c['history_href'] ?? 'manual_adjustment.php?tab=history'), ENT_QUOTES, 'UTF-8');
    $profile_href = htmlspecialchars((string)($c['profile_href'] ?? 'profile_settings.php'), ENT_QUOTES, 'UTF-8');
    $ddr_queue_show = !empty($c['ddr_queue_show']);
    $ddr_nav_href = htmlspecialchars((string)($c['ddr_nav_href'] ?? ''), ENT_QUOTES, 'UTF-8');
    $profilePictureDrawer = (string)($c['profile_picture'] ?? '');
    $hasProfilePicDrawer = $profilePictureDrawer !== '' && file_exists(dirname(__DIR__) . '/' . $profilePictureDrawer);
    $profilePicSrcDrawer = $hasProfilePicDrawer ? '../' . $profilePictureDrawer . '?v=' . time() : '';
    $initialDrawer = strtoupper(substr((string)($c['display_name'] ?? 'U'), 0, 1));

    ?>
    <div id="invStaffDrawerBackdrop" class="inv-drawer-backdrop fixed inset-0 z-[100] bg-slate-900/40 md:hidden" aria-hidden="true"></div>
    <div id="invStaffDrawerPanel" class="inv-drawer-panel is-closed fixed left-0 top-0 z-[110] flex h-full min-h-0 w-[min(20rem,calc(100vw-1rem))] max-w-[calc(100vw-1rem)] flex-col overflow-hidden border-r border-slate-200 bg-white shadow-2xl md:hidden" role="dialog" aria-modal="true" aria-label="Navigation menu">
        <div class="flex shrink-0 items-center justify-between border-b border-slate-100 px-4 py-4 bg-gradient-to-r from-indigo-50 to-white">
            <div class="flex items-center gap-3">
                <?php if ($hasProfilePicDrawer): ?>
                    <img src="<?php echo htmlspecialchars($profilePicSrcDrawer, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile" class="w-10 h-10 rounded-xl object-cover border-2 border-indigo-200">
                <?php else: ?>
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-500 text-white flex items-center justify-center font-bold">
                        <?php echo $initialDrawer; ?>
                    </div>
                <?php endif; ?>
                <div>
                    <p class="text-sm font-bold text-slate-800 truncate"><?php echo htmlspecialchars((string)($c['display_name'] ?? 'User'), ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="text-xs text-slate-500"><?php echo htmlspecialchars((string)($c['display_role'] ?? 'Staff'), ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            </div>
            <button type="button" id="invStaffDrawerClose" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-800" aria-label="Close menu">
                <i class="fas fa-times text-lg" aria-hidden="true"></i>
            </button>
        </div>
        <nav class="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto overflow-x-hidden overscroll-contain p-3 pb-10" aria-label="Main navigation">
            <a data-inv-drawer-link href="<?php echo $dashboard_href; ?>" class="<?php echo inv_chrome_drawer_link_classes($nav_active, 'dashboard'); ?>">
                <i class="fas fa-chart-pie w-5 shrink-0 text-center opacity-90"></i><span class="min-w-0 truncate">Dashboard</span>
            </a>
            <a data-inv-drawer-link href="<?php echo $preparation_href; ?>" class="<?php echo inv_chrome_drawer_link_classes($nav_active, 'preparation'); ?>">
                <i class="fas fa-clipboard-list w-5 shrink-0 text-center opacity-90"></i><span class="min-w-0 truncate">Preparations</span>
            </a>
            <a data-inv-drawer-link href="<?php echo $inventory_href; ?>" class="<?php echo inv_chrome_drawer_link_classes($nav_active, 'inventory'); ?>">
                <i class="fas fa-warehouse w-5 shrink-0 text-center opacity-90"></i><span class="min-w-0 truncate">Inventory</span>
            </a>
            <?php if ($ddr_queue_show && $ddr_nav_href !== ''): ?>
            <a data-inv-drawer-link href="<?php echo $ddr_nav_href; ?>" class="<?php echo inv_chrome_drawer_link_classes($nav_active, 'delivery'); ?>">
                <i class="fas fa-exclamation-triangle w-5 shrink-0 text-center opacity-90"></i><span class="min-w-0 truncate">Delivery Damage Report</span>
            </a>
            <?php endif; ?>
            <a data-inv-drawer-link href="<?php echo $history_href; ?>" class="<?php echo inv_chrome_drawer_link_classes($nav_active, 'history'); ?>">
                <i class="fas fa-clock-rotate-left w-5 shrink-0 text-center opacity-90"></i><span class="min-w-0 truncate">History</span>
            </a>
            <a data-inv-drawer-link href="<?php echo $profile_href; ?>" class="<?php echo inv_chrome_drawer_link_classes($nav_active, 'profile'); ?>">
                <i class="fas fa-user-cog w-5 shrink-0 text-center opacity-90"></i><span class="min-w-0 truncate">Profile</span>
            </a>
        </nav>
    </div>
    <?php
}

/**
 * Header chrome without mobile drawer (drawer must follow </header> via inv_chrome_render_mobile_drawer).
 *
 * @param array<string, mixed> $c
 */
function inv_chrome_render_header_block(array $c): void
{
    inv_chrome_render_header_main($c);
}

/**
 * Get products whose current quantity is below safety threshold.
 * Returns array of [Product_ID, product_name, current_quantity, safety_stock, storage_limit].
 */
function getLowStockProducts(PDO $conn): array
{
    $productsCols = [];
    $pc = $conn->query("SHOW COLUMNS FROM products");
    if ($pc) {
        while ($c = $pc->fetch(PDO::FETCH_ASSOC)) {
            $productsCols[] = $c['Field'];
        }
    }
    $hasSafetyStock = in_array('safety_stock', $productsCols, true);
    $hasDiscontinued = in_array('is_discontinued', $productsCols, true);
    $discontinuedWhere = $hasDiscontinued ? 'WHERE p.is_discontinued = 0' : '';

    $sql = "SELECT p.Product_ID, p.product_name,
                   COALESCE((
                       SELECT quantity FROM stockin_inventory
                       WHERE Product_ID = p.Product_ID
                       ORDER BY COALESCE(updated_at, created_at, date_in) DESC, Inventory_ID DESC
                       LIMIT 1
                   ), 0) AS current_quantity,
                   " . ($hasSafetyStock ? 'COALESCE(p.safety_stock, 20)' : '20') . " AS safety_stock,
                   COALESCE((
                       SELECT storage_limit FROM stockin_inventory
                       WHERE Product_ID = p.Product_ID
                       ORDER BY COALESCE(updated_at, created_at, date_in) DESC, Inventory_ID DESC
                       LIMIT 1
                   ), 100) AS storage_limit
            FROM products p
            {$discontinuedWhere}
            HAVING current_quantity > 0 AND current_quantity <= safety_stock
            ORDER BY (safety_stock - current_quantity) DESC
            LIMIT 50";
    $stmt = $conn->query($sql);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

/**
 * Get email addresses of users with given role IDs.
 * Returns array of [email, full_name].
 */
function getStaffEmailsByRoleIds(PDO $conn, array $roleIds): array
{
    if (empty($roleIds)) return [];
    $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
    $sql = "SELECT DISTINCT u.user_name, up.email, u.full_name
            FROM user u
            LEFT JOIN User_Profile up ON u.User_ID = up.User_ID
            WHERE u.Role_ID IN ({$placeholders})
              AND u.is_active = 1
              AND up.email IS NOT NULL
              AND TRIM(up.email) <> ''";
    $stmt = $conn->prepare($sql);
    $stmt->execute($roleIds);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Ensure the low_stock_email_log tracking table exists.
 */
function ensureLowStockEmailLogTable(PDO $conn): void
{
    $conn->exec("CREATE TABLE IF NOT EXISTS low_stock_email_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        product_count INT NOT NULL DEFAULT 0,
        recipient_count INT NOT NULL DEFAULT 0
    )");
}

/**
 * Get number of low-stock alert emails sent today.
 */
function getLowStockEmailTodayCount(PDO $conn): int
{
    $stmt = $conn->query("SELECT COUNT(*) AS cnt FROM low_stock_email_log WHERE DATE(sent_at) = CURDATE()");
    if ($stmt && $row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        return (int)$row['cnt'];
    }
    return 0;
}

/**
 * Log a low-stock alert email send.
 */
function logLowStockEmailSent(PDO $conn, int $productCount, int $recipientCount): void
{
    $stmt = $conn->prepare("INSERT INTO low_stock_email_log (product_count, recipient_count) VALUES (?, ?)");
    $stmt->execute([$productCount, $recipientCount]);
}

/**
 * Send low stock alert emails to relevant staff.
 * Call after stock-in, adjustment, or on dashboard load.
 * Returns array with 'sent' count and 'errors'.
 */
function notifyLowStockToStaff(PDO $conn): array
{
    require_once __DIR__ . '/mailer.php';
    require_once __DIR__ . '/roles_helper.php';

    ensureLowStockEmailLogTable($conn);

    $todayCount = getLowStockEmailTodayCount($conn);
    if ($todayCount >= 3) {
        return ['sent' => 0, 'errors' => [], 'reason' => 'daily limit reached (3/3)'];
    }

    $lowProducts = getLowStockProducts($conn);
    if (empty($lowProducts)) {
        return ['sent' => 0, 'errors' => [], 'reason' => 'no low stock products'];
    }

    $managerIds = getManagerRoleIds($conn);
    $invIds = getInventoryStaffRoleIds($conn);
    $allIds = array_values(array_unique(array_merge($managerIds, $invIds)));
    $recipients = getStaffEmailsByRoleIds($conn, $allIds);

    if (empty($recipients)) {
        return ['sent' => 0, 'errors' => [], 'reason' => 'no staff emails found'];
    }

    $result = sendLowStockAlertEmail($recipients, $lowProducts);
    if (!empty($result['sent'])) {
        logLowStockEmailSent($conn, count($lowProducts), (int)$result['sent']);
    }
    return $result;
}
