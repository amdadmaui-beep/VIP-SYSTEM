<?php
/**
 * Rider dashboard — entry URL unchanged; markup split under pages/rider_view/.
 */
require_once __DIR__ . '/rider_view/rider_bootstrap.php';

include __DIR__ . '/rider_view/Rider_head.php';
include __DIR__ . '/rider_view/Rider_header_nav.php';
?>

<main class="px-4 py-4 space-y-4">
<?php
include __DIR__ . '/rider_view/Rider_Dashboard.php';
include __DIR__ . '/rider_view/Rider_Queue.php';
include __DIR__ . '/rider_view/Rider_Delivered_history.php';
include __DIR__ . '/rider_view/Rider_Cancelled_Orders.php';
include __DIR__ . '/rider_view/Rider_modals_and_forms.php';
?>
</main>
<?php

$delivery_ids_for_js = array_values(array_map('intval', array_column($deliveries, 'Delivery_ID')));
$ready_delivery_ids_for_js = array_values(array_map('intval', array_column(array_filter($deliveries, function($d) {
    return in_array(strtolower($d['prep_status'] ?? ''), ['ready', 'ready_for_pickup', 'ready_to_pickup'], true);
}), 'Delivery_ID')));
$rider_view_config = [
    'csrfToken' => getCsrfToken(),
    'currentRiderUserId' => $user_id,
    'deliveryCancellationReasons' => array_values($delivery_cancellation_reasons),
    'deliveryIds' => $delivery_ids_for_js,
    'readyDeliveryIds' => $ready_delivery_ids_for_js,
    'mapsEnabled' => !empty($rider_maps_enabled),
    'flags' => [
        'CAN_RIDER_DASHBOARD' => (bool) $can_rider_dashboard,
        'CAN_RIDER_QUEUE' => (bool) $can_rider_queue,
        'CAN_RIDER_HISTORY' => (bool) $can_rider_history,
        'HAS_DELIVERY_DAMAGE_REPORTS' => !empty($has_delivery_damage_reports),
    ],
    'flashSuccess' => isset($_GET['success']) ? (string) $_GET['success'] : '',
    'flashError' => isset($_GET['error']) ? (string) $_GET['error'] : '',
];
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<?php if (!empty($rider_maps_enabled)): ?>
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js" integrity="sha384-cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH" crossorigin="anonymous"></script>
<script src="https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.js" integrity="sha384-SYKAG6cglRMN0RVvhNeBY0r3FYKNOJtznwA0v7B5Vp9tr31xAHsZC0DqkQ/pZDmj" crossorigin="anonymous"></script>
<?php endif; ?>
<script>
window.RIDER_VIEW_CONFIG = <?= json_encode($rider_view_config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.PREP_TASK_STATUSES = <?= json_encode(prepTasksGetValidStatuses($conn), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<script src="../assets/js/Rider_view.js?v=<?= filemtime('../assets/js/Rider_view.js') ?>"></script>
<script src="../assets/js/script.js?v=<?= filemtime('../assets/js/script.js') ?>"></script>
<script src="../assets/js/module_access_realtime.js?v=<?= filemtime('../assets/js/module_access_realtime.js') ?>"></script>
<script src="../assets/js/notification_helper.js?v=<?= filemtime('../assets/js/notification_helper.js') ?>"></script>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        if (typeof lucide !== 'undefined') lucide.createIcons();
    });
    document.addEventListener('show.bs.modal', function () {
        setTimeout(function () { if (typeof lucide !== 'undefined') lucide.createIcons(); }, 100);
    });

    // Rider notification polling with audio
    (function() {
        const badge = document.getElementById('notificationBadge');
        const list = document.getElementById('notificationList');
        const noNotifItem = document.getElementById('noNotifItem');
        var userId = (window.RIDER_VIEW_CONFIG && window.RIDER_VIEW_CONFIG.currentRiderUserId) || 0;
        var notifKey = 'notif_lastId_' + userId;
        var countKey = 'notif_count_' + userId;
        var shownKey = 'notif_shown_' + userId;
        var lastLogId = parseInt(localStorage.getItem(notifKey) || '0', 10);
        var notifCount = parseInt(localStorage.getItem(countKey) || '0', 10);
        var shownIds = JSON.parse(localStorage.getItem(shownKey) || '[]');
        var shownSet = {};
        shownIds.forEach(function(id) { shownSet[id] = true; });
        function persistShown() { localStorage.setItem(shownKey, JSON.stringify(Object.keys(shownSet))); }

        // Restore badge from previous session
        if (notifCount > 0) {
            badge.textContent = notifCount > 9 ? '9+' : (notifCount || '');
            badge.style.display = '';
        }

        function showRiderToast(title, message) {
            var container = document.getElementById('riderToastContainer');
            if (!container) {
                container = document.createElement('div');
                container.id = 'riderToastContainer';
                container.style.cssText = 'position:fixed;bottom:1rem;right:1rem;z-index:99999;display:flex;flex-direction:column;gap:0.5rem;max-width:320px;';
                document.body.appendChild(container);
            }
            var toast = document.createElement('div');
            toast.style.cssText = 'background:#fff;border-radius:12px;padding:0.75rem 1rem;box-shadow:0 8px 24px rgba(0,0,0,0.15);border-left:4px solid #6366f1;font-size:0.85rem;color:#1e293b;transform:translateX(120%);transition:transform 0.3s;';
            toast.innerHTML = '<div style="font-weight:700;margin-bottom:0.25rem;">' + escapeHtml(title) + '</div><div style="color:#64748b;">' + escapeHtml(message) + '</div>';
            container.appendChild(toast);
            setTimeout(function() { toast.style.transform = 'translateX(0)'; }, 10);
            setTimeout(function() { toast.style.transform = 'translateX(120%)'; setTimeout(function() { toast.remove(); }, 300); }, 5000);
        }

        function fetchNotifList(isPolling) {
            var url = '../api/get_recent_activities.php?limit=10';
            if (isPolling && lastLogId > 0) url += '&last_id=' + lastLogId;
            fetch(url)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.status !== 'success' || !data.logs || data.logs.length === 0) {
                        if (!isPolling) {
                            if (noNotifItem) noNotifItem.style.display = '';
                            badge.style.display = 'none';
                        }
                        return;
                    }

                    var pkField = data.pk || 'Log_ID';
                    if (noNotifItem) noNotifItem.style.display = 'none';

                    if (!isPolling) list.innerHTML = '';

                    var newUnread = 0;
                    data.logs.forEach(function(log) {
                        var logId = parseInt(log[pkField] || log.Log_ID || 0, 10);
                        if (logId > lastLogId) {
                            lastLogId = logId;
                            localStorage.setItem(notifKey, lastLogId);
                        }
                        var neverShown = isPolling && !shownSet[logId];
                        if (neverShown) {
                            shownSet[logId] = true;
                            persistShown();
                            playNotificationSound();
                            showRiderToast('Notification', log.Action_Details || log.Activity || '');
                        }

                        if (!isPolling || neverShown) {
                            var item = document.createElement('div');
                            item.className = 'p-2 border-bottom small';
                            var details = log.Action_Details || log.Activity || '';
                            var time = log.Log_Time ? new Date(log.Log_Time.replace(' ', 'T')) : new Date();
                            var timeStr = time.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
                            item.innerHTML = '<div class="fw-bold text-truncate" style="font-size:0.75rem;">' + escapeHtml(details) + '</div><div class="text-muted" style="font-size:0.65rem;">' + timeStr + '</div>';
                            if (isPolling) {
                                list.insertBefore(item, list.firstChild);
                            } else {
                                list.appendChild(item);
                            }
                        }
                        if (log.Activity_Type === 'NOTIFICATION') newUnread++;
                    });

                    notifCount = newUnread;
                    localStorage.setItem(countKey, notifCount);
                    badge.textContent = notifCount > 9 ? '9+' : (notifCount || '');
                    badge.style.display = notifCount > 0 ? '' : 'none';
                })
                .catch(function() {
                    if (!lastLogId) badge.style.display = 'none';
                });
        }

        document.querySelector('[data-bs-toggle="dropdown"]')?.addEventListener('shown.bs.dropdown', function() {
            notifCount = 0;
            localStorage.setItem(countKey, '0');
            shownSet = {}; shownIds = [];
            localStorage.removeItem(shownKey);
            badge.style.display = 'none';
            fetchNotifList(false);
        });

        fetchNotifList(false);
        setInterval(function() { fetchNotifList(true); }, 10000);
    })();
</script>
</body>
</html>
