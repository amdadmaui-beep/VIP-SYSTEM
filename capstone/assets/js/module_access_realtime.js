/**
 * Polls module/role permission version; reloads when a manager changes access for this user.
 */
(function () {
    var POLL_MS = 12000;

    function snapshotUrl() {
        var p = window.location.pathname || '';
        if (p.indexOf('/pages/') !== -1) {
            return '../api/module_access_snapshot.php';
        }
        return 'api/module_access_snapshot.php';
    }

    var url = snapshotUrl();
    var baseline = null;
    var stopped = false;

    function reloadWithNotice() {
        if (stopped) {
            return;
        }
        stopped = true;
        var msg = 'Your access permissions were updated. Reloading…';
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'info',
                title: 'Permissions updated',
                text: 'Reloading the page.',
                timer: 2000,
                showConfirmButton: false,
                allowOutsideClick: false
            }).then(function () {
                window.location.reload();
            });
            return;
        }
        var bar = document.createElement('div');
        bar.setAttribute(
            'style',
            'position:fixed;left:0;right:0;top:0;z-index:999999;background:#0f172a;color:#f8fafc;padding:12px 16px;text-align:center;font:14px system-ui,sans-serif;box-shadow:0 4px 12px rgba(0,0,0,.2);'
        );
        bar.textContent = msg;
        if (document.body) {
            document.body.appendChild(bar);
        }
        setTimeout(function () {
            window.location.reload();
        }, 900);
    }

    function tick() {
        if (stopped || document.hidden) {
            return;
        }
        fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' }
        })
            .then(function (r) {
                if (r.status === 401) {
                    return null;
                }
                if (!r.ok) {
                    return null;
                }
                return r.json();
            })
            .then(function (data) {
                if (!data || !data.success || typeof data.version !== 'string') {
                    return;
                }
                if (baseline === null) {
                    baseline = data.version;
                    return;
                }
                if (data.version !== baseline) {
                    reloadWithNotice();
                }
            })
            .catch(function () {});
    }

    var intervalId = setInterval(tick, POLL_MS);
    setTimeout(tick, 3000);

    window.addEventListener('beforeunload', function () {
        clearInterval(intervalId);
    });
})();
