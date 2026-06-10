<?php
/**
 * Floating notification bell + polling for owner/manager pages.
 */
declare(strict_types=1);

if (!function_exists('renderManagerNotificationWidget')) {
    function renderManagerNotificationWidget(PDO $conn, string $base = '../'): void
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }

        if (!function_exists('getManagementRoleIds')) {
            require_once __DIR__ . '/roles_helper.php';
        }

        $roleId = (int)($_SESSION['user_role'] ?? 0);
        if (!in_array($roleId, getManagementRoleIds($conn), true)) {
            return;
        }

        $rendered = true;
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $apiUrl = $base . 'api/get_recent_activities.php';
        $activityLogsUrl = $base . 'pages/activity_logs.php';
        $cssPath = $base . 'assets/css/manager-notifications.css';
        $helperPath = $base . 'assets/js/notification_helper.js';
        $jsPath = $base . 'assets/js/manager-notifications.js';
        $cssVer = @filemtime(__DIR__ . '/../assets/css/manager-notifications.css') ?: time();
        $helperVer = @filemtime(__DIR__ . '/../assets/js/notification_helper.js') ?: time();
        $jsVer = @filemtime(__DIR__ . '/../assets/js/manager-notifications.js') ?: time();

        echo '<link rel="stylesheet" href="' . htmlspecialchars($cssPath, ENT_QUOTES, 'UTF-8') . '?v=' . $cssVer . '">' . "\n";
        ?>
        <div id="mgrNotifRoot" class="mgr-notif-root"
             data-api-url="<?php echo htmlspecialchars($apiUrl, ENT_QUOTES, 'UTF-8'); ?>"
             data-activity-logs-url="<?php echo htmlspecialchars($activityLogsUrl, ENT_QUOTES, 'UTF-8'); ?>"
             data-user-id="<?php echo $userId; ?>">
            <button type="button" id="mgrNotifToggle" class="mgr-notif-btn" aria-label="Open notifications" aria-expanded="false" aria-controls="mgrNotifPanel">
                <i class="fas fa-bell" aria-hidden="true"></i>
                <span id="mgrNotifBadge" class="mgr-notif-badge hidden" aria-hidden="true">0</span>
            </button>
            <div id="mgrNotifPanel" class="mgr-notif-panel hidden" role="region" aria-label="Notifications">
                <div class="mgr-notif-panel-head">
                    <strong><i class="fas fa-bell"></i> Notifications</strong>
                    <span class="mgr-notif-panel-sub">Live system activity</span>
                </div>
                <div id="mgrNotifList" class="mgr-notif-list">
                    <div class="mgr-notif-loading"><i class="fas fa-spinner fa-spin"></i> Loading…</div>
                </div>
                <div class="mgr-notif-panel-foot">
                    <a href="<?php echo htmlspecialchars($activityLogsUrl, ENT_QUOTES, 'UTF-8'); ?>">View all activity logs <i class="fas fa-arrow-right"></i></a>
                </div>
            </div>
        </div>
        <div id="mgrNotifToastHost" class="mgr-notif-toast-host" aria-live="polite"></div>
        <?php
        echo '<script src="' . htmlspecialchars($helperPath, ENT_QUOTES, 'UTF-8') . '?v=' . $helperVer . '"></script>' . "\n";
        echo '<script src="' . htmlspecialchars($jsPath, ENT_QUOTES, 'UTF-8') . '?v=' . $jsVer . '"></script>' . "\n";
    }
}
