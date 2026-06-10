/**
 * Manager/owner notification polling — badge, panel, audio, toasts.
 * Skips init when dashboard banner bell (#notificationToggle) is present.
 */
(function () {
    'use strict';

    if (document.getElementById('notificationToggle')) {
        return;
    }

    var root = document.getElementById('mgrNotifRoot');
    if (!root) {
        return;
    }

    var apiUrl = root.getAttribute('data-api-url') || '../api/get_recent_activities.php';
    var userId = parseInt(root.getAttribute('data-user-id') || '0', 10);
    var toggle = document.getElementById('mgrNotifToggle');
    var panel = document.getElementById('mgrNotifPanel');
    var badge = document.getElementById('mgrNotifBadge');
    var list = document.getElementById('mgrNotifList');
    var toastHost = document.getElementById('mgrNotifToastHost');

    var notifKey = 'mgr_notif_lastId_' + userId;
    var countKey = 'mgr_notif_count_' + userId;
    var shownKey = 'mgr_notif_shown_' + userId;
    var lastLogId = parseInt(localStorage.getItem(notifKey) || '0', 10);
    var unreadCount = parseInt(localStorage.getItem(countKey) || '0', 10);
    var shownSet = {};

    try {
        JSON.parse(localStorage.getItem(shownKey) || '[]').forEach(function (id) {
            shownSet[id] = true;
        });
    } catch (e) {
        shownSet = {};
    }

    function persistShown() {
        localStorage.setItem(shownKey, JSON.stringify(Object.keys(shownSet)));
    }

    function escapeHtml(str) {
        if (typeof window.escapeHtml === 'function') {
            return window.escapeHtml(str);
        }
        var div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    function formatTimeAgo(dateString) {
        if (!dateString) return '';
        var date = new Date(String(dateString).replace(' ', 'T'));
        if (isNaN(date.getTime())) return '';
        var secondsPast = (Date.now() - date.getTime()) / 1000;
        if (secondsPast < 60) return Math.max(0, parseInt(secondsPast, 10)) + 's ago';
        if (secondsPast < 3600) return parseInt(secondsPast / 60, 10) + 'm ago';
        if (secondsPast <= 86400) return parseInt(secondsPast / 3600, 10) + 'h ago';
        return parseInt(secondsPast / 86400, 10) + 'd ago';
    }

    function iconClassForType(type, details) {
        var t = String(type || '').toUpperCase();
        var d = String(details || '').toLowerCase();
        if (t.includes('DELETE') || t.includes('DAMAGE') || t.includes('ERROR') || t.includes('CANCEL') || t.includes('OVERDUE')) {
            return 'warn';
        }
        if (t.includes('ADD') || t.includes('CREATE') || t.includes('NEW') || t.includes('COMPLET')) {
            return 'ok';
        }
        if (d.includes('overdue') || d.includes('missed')) {
            return 'warn';
        }
        return 'info';
    }

    function updateBadge() {
        if (!badge) return;
        if (unreadCount > 0) {
            badge.textContent = unreadCount > 9 ? '9+' : String(unreadCount);
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }
    }

    function playSoundSafe() {
        if (typeof playNotificationSound === 'function') {
            try {
                playNotificationSound();
            } catch (e) {
                console.warn('Notification sound failed', e);
            }
        }
    }

    function showToast(title, message) {
        if (!toastHost) return;
        var toast = document.createElement('div');
        toast.className = 'mgr-notif-toast';
        toast.innerHTML = '<strong>' + escapeHtml(title) + '</strong><span>' + escapeHtml(message) + '</span>';
        toastHost.appendChild(toast);
        setTimeout(function () {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(12px)';
            toast.style.transition = 'opacity 0.25s ease, transform 0.25s ease';
            setTimeout(function () { toast.remove(); }, 260);
        }, 5200);
    }

    function renderItem(log, pkField) {
        var logId = String(log[pkField] ?? log.Log_ID ?? '');
        var activityType = (log.Activity_Type || 'ACTIVITY').toUpperCase();
        var details = log.Action_Details || log.Activity || '';
        var iconCls = iconClassForType(activityType, details);
        var item = document.createElement('div');
        item.className = 'mgr-notif-item';
        item.innerHTML =
            '<div class="mgr-notif-item-icon ' + iconCls + '"><i class="fas fa-bell"></i></div>' +
            '<div class="mgr-notif-item-body">' +
                '<div class="mgr-notif-item-title">' + escapeHtml(log.user_name || 'System') + ' · ' + escapeHtml(activityType) + '</div>' +
                '<div class="mgr-notif-item-msg">' + escapeHtml(details) + '</div>' +
                '<div class="mgr-notif-item-time">' + escapeHtml(formatTimeAgo(log.Log_Time || log.Time)) + '</div>' +
            '</div>';
        item.dataset.logId = logId;
        return item;
    }

    function fetchNotifications(isPolling) {
        var url = apiUrl + '?last_id=' + (isPolling ? lastLogId : 0) + '&limit=12';
        fetch(url, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 'success') {
                    if (!isPolling && list) {
                        list.innerHTML = '<div class="mgr-notif-empty">' + escapeHtml((data && data.message) || 'Unable to load notifications.') + '</div>';
                    }
                    return;
                }

                var logs = Array.isArray(data.logs) ? data.logs : [];
                var pkField = data.pk || 'Log_ID';

                if (!isPolling) {
                    list.innerHTML = '';
                }

                if (logs.length === 0) {
                    if (!isPolling && list && !list.children.length) {
                        list.innerHTML = '<div class="mgr-notif-empty"><i class="fas fa-check-circle"></i> You are caught up!</div>';
                    }
                    return;
                }

                logs.forEach(function (log) {
                    var rawId = log[pkField] ?? log.Log_ID ?? 0;
                    var logIdNum = parseInt(rawId, 10);
                    var logKey = String(rawId);

                    if (!isNaN(logIdNum) && logIdNum > lastLogId) {
                        lastLogId = logIdNum;
                        localStorage.setItem(notifKey, String(lastLogId));
                    }

                    if (isPolling && !shownSet[logKey]) {
                        shownSet[logKey] = true;
                        persistShown();
                        unreadCount++;
                        localStorage.setItem(countKey, String(unreadCount));
                        playSoundSafe();
                        showToast(log.Activity_Type || 'Activity', log.Action_Details || log.Activity || 'New activity');
                    }

                    var item = renderItem(log, pkField);
                    if (isPolling) {
                        list.insertBefore(item, list.firstChild);
                    } else {
                        list.appendChild(item);
                    }
                });

                updateBadge();
            })
            .catch(function (err) {
                console.error('Manager notifications fetch failed:', err);
                if (!isPolling && list) {
                    list.innerHTML = '<div class="mgr-notif-empty">Could not reach notification service.</div>';
                }
            });
    }

    function openPanel() {
        panel.classList.remove('hidden');
        toggle.setAttribute('aria-expanded', 'true');
        unreadCount = 0;
        localStorage.setItem(countKey, '0');
        shownSet = {};
        localStorage.removeItem(shownKey);
        updateBadge();
        if (list && list.querySelector('.mgr-notif-loading')) {
            fetchNotifications(false);
        }
    }

    function closePanel() {
        panel.classList.add('hidden');
        toggle.setAttribute('aria-expanded', 'false');
    }

    toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        if (panel.classList.contains('hidden')) {
            openPanel();
        } else {
            closePanel();
        }
    });

    document.addEventListener('click', function (e) {
        if (!panel || panel.classList.contains('hidden')) return;
        if (!root.contains(e.target)) {
            closePanel();
        }
    });

    updateBadge();
    fetchNotifications(false);
    setInterval(function () { fetchNotifications(true); }, 5000);
})();
