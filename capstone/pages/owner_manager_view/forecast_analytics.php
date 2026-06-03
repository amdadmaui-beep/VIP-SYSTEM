<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/csrf.php';

requireRole([1, 2, 4]);
$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demand forecast (Prophet) - VIP Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" integrity="sha384-9nhczxUqK87bcKHh20fSQcTGD4qq5GhayNYSYWqwBkINBhOfQLg/P5HG5lF1urn4" crossorigin="anonymous"></script>
</head>
<body>
<div class="dashboard-wrapper">
    <?php
    require_once __DIR__ . '/../../includes/sidebar.php';
    renderSidebar($conn, ['base' => '../', 'active' => 'analytics_reports']);
    ?>
    <aside class="sidebar legacy-sidebar" style="display:none;">
        <div class="sidebar-header">
            <div class="sidebar-brand">
                <div class="brand-icon"><i class="fas fa-snowflake"></i></div>
                <div class="brand-text">
                    <h2>Villanueva</h2>
                    <p>Ice Plant System</p>
                </div>
            </div>
            <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar"><i class="fas fa-angles-left"></i></button>
        </div>
        <nav class="sidebar-menu">
            <div class="menu-section">
                <div class="menu-label">Main Menu</div>
                <a href="../index.php" class="menu-item"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
                <a href="sales.php" class="menu-item"><i class="fas fa-receipt"></i><span>Sales</span></a>
                <a href="inventory.php" class="menu-item"><i class="fas fa-cubes"></i><span>Inventory</span></a>
                <a href="damage_goods.php" class="menu-item"><i class="fas fa-heart-broken"></i><span>Damage Goods</span></a>
                <a href="stock_ledger.php" class="menu-item"><i class="fas fa-file-invoice"></i><span>Stock Ledger</span></a>
                <a href="users.php" class="menu-item"><i class="fas fa-users"></i><span>Customers</span></a>
                <a href="orders.php" class="menu-item"><i class="fas fa-shopping-cart"></i><span>Orders</span></a>
                <a href="delivery.php" class="menu-item"><i class="fas fa-truck"></i><span>Delivery</span></a>
            </div>
            <div class="menu-section">
                <div class="menu-label">System</div>
                <a href="../logout.php" class="menu-item"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </nav>
    </aside>

    <main class="main-content">
        <div class="container" style="padding: 2rem; max-width: 1200px;">
            <h1 style="margin-bottom: 0.35rem;"><i class="fas fa-chart-area" style="color: #2563eb;"></i> Demand forecast</h1>
            <p style="color: #64748b; margin-bottom: 1.5rem;"><strong>Forecast:</strong> total bags/day from sales; <strong>expected revenue</strong> = bags × avg ₱/bag. <strong>Facebook Prophet</strong> (Python) only — install dependencies on the server; forecasts use a business floor so active demand is not driven to zero.</p>

            <div id="forecastErr" style="display:none; background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:1rem; border-radius:12px; margin-bottom:1rem;"></div>

            <div id="headlineBox" style="display:none; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
                <div style="background: linear-gradient(135deg, #1d4ed8, #2563eb); color: #fff; border-radius: 16px; padding: 1.25rem; box-shadow: 0 4px 14px rgba(37,99,235,0.35);">
                    <div style="font-size: 0.8rem; opacity: 0.92; margin-bottom: 0.35rem;"><i class="fas fa-box-open"></i> Forecasted demand</div>
                    <div id="hdrTotalBags" style="font-size: 2rem; font-weight: 700; line-height: 1.2;">—</div>
                    <div style="font-size: 0.85rem; opacity: 0.95;">bags (next day)</div>
                </div>
                <div style="background: linear-gradient(135deg, #0f766e, #14b8a6); color: #fff; border-radius: 16px; padding: 1.25rem; box-shadow: 0 4px 14px rgba(20,184,166,0.35);">
                    <div style="font-size: 0.8rem; opacity: 0.92; margin-bottom: 0.35rem;"><i class="fas fa-money-bill-wave"></i> Estimated revenue</div>
                    <div id="hdrRev" style="font-size: 1.75rem; font-weight: 700;">—</div>
                    <div id="hdrRevRange" style="font-size: 0.8rem; opacity: 0.95;">—</div>
                </div>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 16px; padding: 1.25rem;">
                    <div style="font-size: 0.8rem; color: #64748b; margin-bottom: 0.35rem;"><i class="fas fa-arrows-left-right"></i> Confidence range (total bags)</div>
                    <div id="hdrBagRange" style="font-size: 1.35rem; font-weight: 700; color: #0f172a;">—</div>
                    <div style="font-size: 0.8rem; color: #64748b; margin-top: 0.75rem;">Next day <strong id="hdrDate">—</strong></div>
                </div>
            </div>

            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:1rem 1.25rem; margin-bottom:1.5rem;">
                <h3 style="margin:0 0 0.5rem; font-size:1rem;">CSV import / export</h3>
                <p style="font-size:0.85rem; color:#64748b; margin:0 0 1rem;">Export: <code style="font-size:0.85em;">date,total_bags,revenue</code>. Import also accepts legacy <code style="font-size:0.85em;">date,revenue</code> (bags inferred as revenue÷25 if bags column missing).</p>
                <div style="display:flex; flex-wrap:wrap; gap:0.75rem; align-items:center;">
                    <button type="button" id="exportTrainingBtn" style="padding:0.45rem 0.9rem; background:#0f172a; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer; font-size:0.875rem;">Export training CSV (DB)</button>
                    <button type="button" id="exportBundleBtn" style="padding:0.45rem 0.9rem; background:#fff; color:#0f172a; border:1px solid #cbd5e1; border-radius:8px; font-weight:600; cursor:pointer; font-size:0.875rem;">Export forecast bundle (DB)</button>
                </div>
                <div style="margin-top:1rem; display:flex; flex-wrap:wrap; gap:0.75rem; align-items:center;">
                    <input type="file" id="csvFile" accept=".csv,text/csv" style="font-size:0.875rem;">
                    <button type="button" id="importBtn" style="padding:0.45rem 0.9rem; background:#16a34a; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer; font-size:0.875rem;">Run forecast from CSV</button>
                </div>
            </div>

            <div style="display:flex; flex-wrap:wrap; gap:1rem; align-items:center; margin-bottom:1.5rem;">
                <label style="color:#475569; font-size:0.9rem;">Training window
                    <select id="daysSel" style="margin-left:8px; padding:6px 10px; border-radius:8px; border:1px solid #e2e8f0;">
                        <option value="90" selected>90 days (~3 months)</option>
                        <option value="60">60 days</option>
                        <option value="30">30 days</option>
                    </select>
                </label>
                <button type="button" id="reloadBtn" style="padding:0.5rem 1rem; background:#2563eb; color:#fff; border:none; border-radius:8px; font-weight:600; cursor:pointer;">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
                <span id="metaLine" style="color:#64748b; font-size:0.85rem;"></span>
            </div>

            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:1.25rem; margin-bottom:1.5rem;">
                <h3 style="margin:0 0 1rem; font-size:1.05rem;">Daily bags (training history)</h3>
                <canvas id="dailyChart" height="100"></canvas>
            </div>

            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:1.5rem;">
                <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:1.25rem;">
                    <h3 style="margin:0 0 0.75rem; font-size:1.05rem;">Weekly bag totals &amp; forecast</h3>
                    <p id="weeklyMethod" style="font-size:0.8rem; color:#64748b; margin-bottom:0.75rem;"></p>
                    <div style="overflow-x:auto;">
                        <table style="width:100%; font-size:0.85rem; border-collapse:collapse;">
                            <thead><tr style="text-align:left; border-bottom:1px solid #e5e7eb;"><th>Week starting</th><th>Actual (bags)</th><th style="color:#2563eb;">Forecast</th><th>Low–high</th></tr></thead>
                            <tbody id="weeklyBody"></tbody>
                        </table>
                    </div>
                </div>
                <div style="background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:1.25rem;">
                    <h3 style="margin:0 0 0.75rem; font-size:1.05rem;">Monthly bag totals &amp; forecast</h3>
                    <p id="monthlyMethod" style="font-size:0.8rem; color:#64748b; margin-bottom:0.75rem;"></p>
                    <div style="overflow-x:auto;">
                        <table style="width:100%; font-size:0.85rem; border-collapse:collapse;">
                            <thead><tr style="text-align:left; border-bottom:1px solid #e5e7eb;"><th>Month</th><th>Actual (bags)</th><th style="color:#16a34a;">Forecast</th><th>Low–high</th></tr></thead>
                            <tbody id="monthlyBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="notesBox" style="margin-top:1.5rem; padding:1rem; background:#f8fafc; border-radius:12px; font-size:0.85rem; color:#475569;"></div>
        </div>
    </main>
</div>

<script src="../assets/js/script.js"></script>
<script>
(function () {
    const errEl = document.getElementById('forecastErr');
    const dailyChartCtx = document.getElementById('dailyChart');
    let chart;

    function showErr(msg) {
        errEl.style.display = 'block';
        errEl.textContent = msg;
    }
    function hideErr() {
        errEl.style.display = 'none';
    }

    function horizonsQuery() {
        return '&horizon_weeks=4&horizon_months=3&horizon_days=14';
    }

    function bagCell(r) {
        var v = r.bags != null ? r.bags : r.revenue;
        return Number(v || 0).toLocaleString(undefined, { maximumFractionDigits: 1 });
    }

    function renderForecast(data) {
        const meta = data.meta || {};
        var src = data.source || 'database';
        var extra = '';
        if (src === 'csv_import' && data.imported_rows) {
            extra = ' · Source: CSV import (' + data.imported_rows + ' rows)';
        } else if (src === 'database') {
            extra = ' · Source: live Sales (DB)';
        }
        var ap = meta.avg_price_per_bag != null ? ' · Avg ₱/bag: ' + Number(meta.avg_price_per_bag).toFixed(2) : '';
        document.getElementById('metaLine').textContent =
            'Training days: ' + (meta.training_days || '—') +
            ' · Weekly obs: ' + (meta.weekly_observations || '—') +
            ' · Monthly obs: ' + (meta.monthly_observations || '—') +
            (meta.engine ? ' · Engine: ' + meta.engine : '') + ap + extra;

        document.getElementById('weeklyMethod').textContent = 'Method: ' + (meta.weekly_method || '—');
        document.getElementById('monthlyMethod').textContent = 'Method: ' + (meta.monthly_method || '—');

        var hb = document.getElementById('headlineBox');
        var h = data.headline;
        if (h && h.total_bags && typeof h.total_bags.yhat === 'number') {
            hb.style.display = 'grid';
            document.getElementById('hdrDate').textContent = h.forecast_date || '—';
            document.getElementById('hdrTotalBags').textContent = Math.round(h.total_bags.yhat);
            document.getElementById('hdrBagRange').textContent =
                Math.round(h.total_bags.yhat_low) + ' – ' + Math.round(h.total_bags.yhat_high) + ' bags';
            var er = h.estimated_revenue || {};
            document.getElementById('hdrRev').textContent = '₱' + Number(er.yhat || 0).toLocaleString(undefined, { minimumFractionDigits: 2 });
            document.getElementById('hdrRevRange').textContent =
                'Range: ₱' + Number(er.yhat_low || 0).toLocaleString(undefined, { minimumFractionDigits: 2 }) +
                ' – ₱' + Number(er.yhat_high || 0).toLocaleString(undefined, { minimumFractionDigits: 2 });
        } else {
            hb.style.display = 'none';
        }

        const daily = data.daily || [];
        const labels = daily.map(function (r) { return r.date; });
        const tBags = daily.map(function (r) { return Number(r.total_bags != null ? r.total_bags : r.revenue || 0); });
        if (chart) chart.destroy();
        chart = new Chart(dailyChartCtx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Total bags (history)',
                        data: tBags,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37,99,235,0.08)',
                        fill: true,
                        tension: 0.2,
                        pointRadius: 0
                    }
                ]
            },
            options: {
                responsive: true,
                scales: {
                    x: { ticks: { maxTicksLimit: 12 } },
                    y: { beginAtZero: true, title: { display: true, text: 'Bags (qty)' } }
                }
            }
        });

        const wh = data.weekly && data.weekly.history ? data.weekly.history : [];
        const wf = data.weekly && data.weekly.forecast ? data.weekly.forecast : [];
        const wRows = document.getElementById('weeklyBody');
        wRows.innerHTML = '';
        wh.forEach(function (r) {
            const tr = document.createElement('tr');
            tr.innerHTML = '<td>' + r.period_start + '</td><td>' + bagCell(r) + '</td><td>—</td><td>—</td>';
            tr.style.borderBottom = '1px solid #f1f5f9';
            wRows.appendChild(tr);
        });
        wf.forEach(function (r) {
            const tr = document.createElement('tr');
            tr.innerHTML = '<td>' + r.period_start + '</td><td>—</td><td>' + bagCell({ bags: r.yhat }) + '</td><td>' + bagCell({ bags: r.yhat_low }) + ' – ' + bagCell({ bags: r.yhat_high }) + '</td>';
            tr.style.borderBottom = '1px solid #f1f5f9';
            tr.style.background = '#eff6ff';
            wRows.appendChild(tr);
        });

        const mh = data.monthly && data.monthly.history ? data.monthly.history : [];
        const mf = data.monthly && data.monthly.forecast ? data.monthly.forecast : [];
        const mRows = document.getElementById('monthlyBody');
        mRows.innerHTML = '';
        mh.forEach(function (r) {
            const tr = document.createElement('tr');
            tr.innerHTML = '<td>' + r.month + '</td><td>' + bagCell(r) + '</td><td>—</td><td>—</td>';
            tr.style.borderBottom = '1px solid #f1f5f9';
            mRows.appendChild(tr);
        });
        mf.forEach(function (r) {
            const tr = document.createElement('tr');
            tr.innerHTML = '<td>' + r.month + '</td><td>—</td><td>' + bagCell({ bags: r.yhat }) + '</td><td>' + bagCell({ bags: r.yhat_low }) + ' – ' + bagCell({ bags: r.yhat_high }) + '</td>';
            tr.style.borderBottom = '1px solid #f1f5f9';
            tr.style.background = '#f0fdf4';
            mRows.appendChild(tr);
        });

        const notes = data.notes || [];
        document.getElementById('notesBox').innerHTML = '<strong>Notes</strong><ul style="margin:0.5rem 0 0 1.1rem;">' + notes.map(function (n) { return '<li>' + n + '</li>'; }).join('') + '</ul>';
    }

    function failForecast(data) {
        var m = (data.error || 'Forecast failed') + (data.hint ? ' — ' + data.hint : '');
        if (data.details) m += ' (' + data.details + ')';
        showErr(m);
    }

    async function load() {
        hideErr();
        const days = document.getElementById('daysSel').value;
        const url = '../api/forecast_sales.php?days=' + encodeURIComponent(days) + horizonsQuery();
        let data;
        try {
            const res = await fetch(url, { credentials: 'same-origin' });
            data = await res.json();
        } catch (e) {
            showErr('Network error loading forecast.');
            return;
        }
        if (!data.success) {
            failForecast(data);
            return;
        }
        renderForecast(data);
    }

    document.getElementById('exportTrainingBtn').addEventListener('click', function () {
        const days = document.getElementById('daysSel').value;
        window.location = '../api/forecast_training_export.php?days=' + encodeURIComponent(days);
    });
    document.getElementById('exportBundleBtn').addEventListener('click', function () {
        const days = document.getElementById('daysSel').value;
        window.location = '../api/forecast_bundle_export.php?days=' + encodeURIComponent(days) + horizonsQuery();
    });

    document.getElementById('importBtn').addEventListener('click', async function () {
        const f = document.getElementById('csvFile').files[0];
        if (!f) {
            showErr('Choose a CSV file first.');
            return;
        }
        hideErr();
        const fd = new FormData();
        fd.append('csv', f);
        fd.append('horizon_weeks', '4');
        fd.append('horizon_months', '3');
        fd.append('horizon_days', '14');
        fd.append('csrf_token', <?php echo json_encode($csrfToken); ?>);
        let data;
        try {
            const res = await fetch('../api/forecast_import.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            data = await res.json();
        } catch (e) {
            showErr('Network error during CSV import.');
            return;
        }
        if (!data.success) {
            failForecast(data);
            return;
        }
        renderForecast(data);
    });

    document.getElementById('reloadBtn').addEventListener('click', load);
    load();
})();
</script>
</body>
</html>
