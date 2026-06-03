<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/roles_helper.php';
require_once __DIR__ . '/../../includes/module_access.php';

$managementIds = getManagementRoleIds($conn);
requireRole(empty($managementIds) ? [1, 2] : $managementIds);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Session Monitoring - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="dashboard-wrapper">
        <?php
        require_once __DIR__ . '/../../includes/sidebar.php';
        renderSidebar($conn, ['base' => '../', 'active' => 'session_monitoring']);
        ?>
        <aside class="sidebar legacy-sidebar" style="display:none;">
            <div class="sidebar-header">
                <div class="sidebar-brand">
                    <div class="brand-icon"><i class="fas fa-snowflake"></i></div>
                    <div class="brand-text"><h2>Villanueva</h2><p>Ice Plant System</p></div>
                </div>
                <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar"><i class="fas fa-angles-left"></i></button>
            </div>
            <nav class="sidebar-menu">
                <div class="menu-section">
                    <div class="menu-label">Main Menu</div>
                    <a href="../index.php" class="menu-item"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
                </div>
                <div class="menu-section">
                    <div class="menu-label">System</div>
                    <a href="activity_logs.php" class="menu-item"><i class="fas fa-history"></i><span>Activity Logs</span></a>
                    <a href="user_management.php" class="menu-item"><i class="fas fa-user-shield"></i><span>User Management</span></a>
                    <a href="session_monitoring.php" class="menu-item active"><i class="fas fa-user-clock"></i><span>Session Monitoring</span></a>
                    <a href="../logout.php" class="menu-item"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
                </div>
            </nav>
        </aside>

        <main class="main-content" id="mainContent">
            <div class="topbar" style="display:flex; justify-content:flex-end; align-items:center; margin-bottom:1.5rem;">
                <button class="mobile-sidebar-toggle" id="mobileSidebarToggle" aria-label="Toggle sidebar" style="position: static; margin-right: auto;">
                    <i class="fas fa-bars"></i>
                </button>
            </div>

            <div class="welcome-banner" style="background: linear-gradient(135deg, #7132f5 0%, #5b1ecf 50%, #5741d8 100%); box-shadow: 0 20px 60px rgba(113,50,245,0.3); border-radius: 16px; margin-bottom: 2rem;">
                <div class="welcome-content">
                    <h2><i class="fas fa-user-clock"></i> Session Monitoring</h2>
                    <p>Real-time active user sessions across the system.</p>
                </div>
            </div>

            <div class="chart-container" style="border: 1px solid #dedee5; border-radius: 16px; box-shadow: rgba(0,0,0,0.03) 0px 4px 24px; margin-bottom: 2rem;">
                <h3 class="chart-title" style="justify-content: space-between; color: #101114; align-items: center;">
                    <span style="display:flex; align-items:center; gap:0.625rem;">
                        <i class="fas fa-signal" style="color:#7132f5;"></i>
                        Live Logins Monitor
                    </span>
                    <span id="activeSessionsMeta" style="font-size:0.85rem; color:#9497a9; font-weight:600;">Loading…</span>
                </h3>
                <div style="padding: 0 1.25rem 1.25rem;">
                    <div style="display:flex; gap:0.75rem; flex-wrap:wrap; margin-bottom: 0.75rem;">
                        <span class="badge badge-success" style="border-radius: 999px; padding: 0.45rem 0.75rem; font-weight: 800;">
                            <i class="fas fa-circle" style="font-size:0.6rem;"></i>
                            <span id="activeSessionsCount">0</span> online
                        </span>
                        <span class="badge badge-info" style="border-radius: 999px; padding: 0.45rem 0.75rem; font-weight: 700; background: rgba(113,50,245,0.08); color:#7132f5;">
                            refresh: 5s
                        </span>
                    </div>

                    <div style="overflow:auto; border: 1px solid #f0f0f2; border-radius: 14px;">
                        <table class="table" style="min-width: 900px;">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Login at</th>
                                    <th>Last seen</th>
                                    <th>IP</th>
                                    <th>Current page</th>
                                </tr>
                            </thead>
                            <tbody id="activeSessionsBody">
                                <tr><td colspan="6" style="text-align:center; padding: 1.75rem; color:#9497a9;"><i class="fas fa-spinner fa-spin"></i> Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="../assets/js/script.js"></script>
    <script>
        (function () {
            const sessionsBody = document.getElementById('activeSessionsBody');
            const sessionsCount = document.getElementById('activeSessionsCount');
            const sessionsMeta = document.getElementById('activeSessionsMeta');
            const esc = (s) => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
            const timeAgo = (dt) => {
                if (!dt) return '';
                const d = new Date(String(dt).replace(' ', 'T'));
                if (isNaN(d.getTime())) return '';
                const s = Math.max(0, (Date.now() - d.getTime()) / 1000);
                if (s < 60) return `${Math.floor(s)}s ago`;
                if (s < 3600) return `${Math.floor(s / 60)}m ago`;
                return `${Math.floor(s / 3600)}h ago`;
            };

            async function loadActiveSessions() {
                try {
                    const r = await fetch('../api/active_sessions.php', { credentials: 'same-origin' });
                    const j = await r.json();
                    if (!j?.success) throw new Error('failed');
                    const rows = Array.isArray(j.data) ? j.data : [];
                    sessionsCount.textContent = String(rows.length);
                    sessionsMeta.textContent = `Updated ${new Date().toLocaleTimeString()}`;
                    if (!rows.length) {
                        sessionsBody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding: 1.5rem; color:#9497a9;"><i class="fas fa-user-slash"></i> No active users</td></tr>`;
                        return;
                    }
                    sessionsBody.innerHTML = rows.map(row => `
                        <tr>
                            <td style="font-weight:700; color:#101114;">${esc(row.user_name)}</td>
                            <td style="color:#686b82; font-weight:600;">${esc(row.role_name || '')}</td>
                            <td style="color:#686b82;">${esc(row.login_at || '')}</td>
                            <td style="color:#686b82;">${esc(timeAgo(row.last_seen_at))}</td>
                            <td style="color:#686b82; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;">${esc(row.ip_address || '')}</td>
                            <td style="color:#7132f5; font-weight:600;">${esc(row.last_path || '')}</td>
                        </tr>
                    `).join('');
                } catch (e) {
                    sessionsMeta.textContent = 'Failed to load';
                    sessionsBody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding: 1.75rem; color:#ef4444;"><i class="fas fa-triangle-exclamation"></i> Unable to load active sessions</td></tr>`;
                }
            }

            loadActiveSessions();
            setInterval(loadActiveSessions, 5000);
        })();
    </script>
</body>
</html>

