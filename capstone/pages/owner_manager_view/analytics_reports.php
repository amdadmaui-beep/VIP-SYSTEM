<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

// Same access as Reports / forecasting (owner, manager roles)
requireRole([1, 2, 4]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics reports - VIP Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <link rel="stylesheet" href="../assets/css/style.css">
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
        <div class="container" style="padding: 2rem;">
            <h1 style="margin-bottom: 0.35rem;"><i class="fas fa-chart-pie" style="color: #2563eb;"></i> Analytics reports</h1>
            <p style="color: #64748b; margin-bottom: 2rem; max-width: 720px;">Central place for analytics: forecasting, operational reports, and related insights. More tools can be linked here as you add them (seasonality, events, inventory analytics, etc.).</p>

            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem;">
                <div style="background: white; border-radius: 16px; border: 1px solid #e5e7eb; padding: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.08);">
                    <div style="width: 48px; height: 48px; background: #eff6ff; color: #2563eb; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-bottom: 1rem;">
                        <i class="fas fa-chart-area"></i>
                    </div>
                    <h3 style="margin: 0 0 0.5rem; font-size: 1.2rem;">Demand forecast (Prophet)</h3>
                    <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 1.25rem;">Weekly and monthly revenue projections from recent sales. Uses Python (Holt smoothing) when available; otherwise a built-in PHP trend fallback.</p>
                    <a href="forecast_analytics.php" style="display: inline-block; padding: 0.75rem 1rem; background: #2563eb; color: white; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 0.875rem;">
                        <i class="fas fa-arrow-right"></i> Open forecast
                    </a>
                </div>

                <div style="background: white; border-radius: 16px; border: 1px solid #e5e7eb; padding: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.08);">
                    <div style="width: 48px; height: 48px; background: #f0fdf4; color: #16a34a; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-bottom: 1rem;">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <h3 style="margin: 0 0 0.5rem; font-size: 1.2rem;">Reports &amp; summaries</h3>
                    <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 1.25rem;">Aging, AR shortcuts, and other printable or operational reports.</p>
                    <a href="reports.php" style="display: inline-block; padding: 0.75rem 1rem; background: #16a34a; color: white; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 0.875rem;">
                        <i class="fas fa-arrow-right"></i> Go to reports
                    </a>
                </div>

                <div style="background: white; border-radius: 16px; border: 1px solid #e5e7eb; padding: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.08);">
                    <div style="width: 48px; height: 48px; background: #faf5ff; color: #7c3aed; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-bottom: 1rem;">
                        <i class="fas fa-th-large"></i>
                    </div>
                    <h3 style="margin: 0 0 0.5rem; font-size: 1.2rem;">Dashboard KPIs</h3>
                    <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 1.25rem;">Live charts, DSS alerts, and key metrics on the main dashboard.</p>
                    <a href="../index.php" style="display: inline-block; padding: 0.75rem 1rem; background: #7c3aed; color: white; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 0.875rem;">
                        <i class="fas fa-arrow-right"></i> Open dashboard
                    </a>
                </div>

                <div style="background: white; border-radius: 16px; border: 1px solid #e5e7eb; padding: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.08);">
                    <div style="width: 48px; height: 48px; background: #fff7ed; color: #ea580c; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-bottom: 1rem;">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <h3 style="margin: 0 0 0.5rem; font-size: 1.2rem;">Receivables analytics</h3>
                    <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 1.25rem;">Outstanding balances, aging views, and collection-focused reports.</p>
                    <a href="accounts_receivable.php" style="display: inline-block; padding: 0.75rem 1rem; background: #ea580c; color: white; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 0.875rem;">
                        <i class="fas fa-arrow-right"></i> Accounts receivable
                    </a>
                </div>
            </div>
        </div>
    </main>
</div>
<script src="../assets/js/script.js"></script>
</body>
</html>
