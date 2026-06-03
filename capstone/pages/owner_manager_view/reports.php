<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

// Accessible to Owner (1) and Manager (2, 4)
requireRole([1, 2, 4]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="dashboard-wrapper">
    <!-- Sidebar -->
    <?php
    require_once __DIR__ . '/../../includes/sidebar.php';
    renderSidebar($conn, ['base' => '../', 'active' => 'analytics_reports']);
    ?>
    <aside class="sidebar legacy-sidebar" style="display:none;">
        <div class="sidebar-header">
            <div class="sidebar-brand">
                <div class="brand-icon">
                    <i class="fas fa-snowflake"></i>
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
                <a href="../index.php" class="menu-item">
                    <i class="fas fa-th-large"></i>
                    <span>Dashboard</span>
                </a>
                <a href="sales.php" class="menu-item">
                    <i class="fas fa-receipt"></i>
                    <span>Sales</span>
                </a>
                <a href="inventory.php" class="menu-item">
                    <i class="fas fa-cubes"></i>
                    <span>Inventory</span>
                </a>
                <a href="damage_goods.php" class="menu-item">
                    <i class="fas fa-heart-broken"></i>
                    <span>Damage Goods</span>
                </a>
                <a href="stock_ledger.php" class="menu-item">
                    <i class="fas fa-file-invoice"></i>
                    <span>Stock Ledger</span>
                </a>
                <a href="users.php" class="menu-item">
                    <i class="fas fa-users"></i>
                    <span>Customers</span>
                </a>
                <a href="orders.php" class="menu-item">
                    <i class="fas fa-shopping-cart"></i>
                    <span>Orders</span>
                </a>
                <a href="delivery.php" class="menu-item">
                    <i class="fas fa-truck"></i>
                    <span>Delivery</span>
                </a>
            </div>

            <div class="menu-section">
                <div class="menu-label">Accounting</div>
                <a href="accounts_receivable.php" class="menu-item">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <span>Accounts Receivable</span>
                    <span class="menu-item-badge">3</span>
                </a>
            </div>

            <div class="menu-section">
                <div class="menu-label">System</div>
                <a href="activity_logs.php" class="menu-item">
                    <i class="fas fa-history"></i>
                    <span>Activity Logs</span>
                </a>
                <?php if (in_array($_SESSION['user_role'] ?? 1, [1, 2])): ?>
                <a href="user_management.php" class="menu-item">
                    <i class="fas fa-user-shield"></i>
                    <span>User Management</span>
                </a>
                <?php endif; ?>
                <a href="../logout.php" class="menu-item">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <div class="container" style="padding: 2rem;">
            <h1 style="margin-bottom: 0.5rem;"><i class="fas fa-chart-line" style="color: var(--primary-color);"></i> Reports & Analytics</h1>
            <p style="color: #64748b; margin-bottom: 2.5rem;">Access detailed business reports and financial summaries. For forecasting and other analytics, open <a href="analytics_reports.php" style="color:#2563eb;font-weight:600;">Analytics reports</a>.</p>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem;">
                <!-- Aging Report Card -->
                <div style="background: white; border-radius: 16px; border: 1px solid #e5e7eb; padding: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                    <div style="width: 48px; height: 48px; background: #eff6ff; color: #2563eb; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-bottom: 1rem;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <h3 style="margin: 0 0 0.5rem 0; font-size: 1.25rem;">Customer Aging Report</h3>
                    <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 1.5rem;">Detailed breakdown of outstanding balances by age (0-90+ days) with credit limit alerts.</p>
                    <a href="accounts_receivable.php?view=aging" class="btn btn-primary" style="display: inline-block; padding: 0.75rem 1rem; background: #2563eb; color: white; border-radius: 8px; text-decoration: none; font-weight: 500; font-size: 0.875rem;">
                        <i class="fas fa-eye"></i> View Report
                    </a>
                </div>

                <!-- Sales forecast -->
                <div style="background: white; border-radius: 16px; border: 1px solid #e5e7eb; padding: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                    <div style="width: 48px; height: 48px; background: #f0fdf4; color: #16a34a; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-bottom: 1rem;">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <h3 style="margin: 0 0 0.5rem 0; font-size: 1.25rem;">Demand forecast (Prophet)</h3>
                    <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 1.5rem;">Weekly and monthly revenue projections from recent sales (Python). Fine for ~3 months of history; seasonal &amp; events can be layered on later.</p>
                    <a href="forecast_analytics.php" class="btn btn-primary" style="display: inline-block; padding: 0.75rem 1rem; background: #16a34a; color: white; border-radius: 8px; text-decoration: none; font-weight: 500; font-size: 0.875rem;">
                        <i class="fas fa-arrow-right"></i> Open forecast
                    </a>
                </div>

                <!-- Inventory Report Card (Placeholder) -->
                <div style="background: white; border-radius: 16px; border: 1px solid #e5e7eb; padding: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); opacity: 0.7;">
                    <div style="width: 48px; height: 48px; background: #fef2f2; color: #dc2626; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-bottom: 1rem;">
                        <i class="fas fa-boxes"></i>
                    </div>
                    <h3 style="margin: 0 0 0.5rem 0; font-size: 1.25rem;">Inventory Valuation</h3>
                    <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 1.5rem;">Current stock levels, reorder points, and total value of inventory on hand.</p>
                    <button disabled style="padding: 0.75rem 1rem; background: #f1f5f9; color: #94a3b8; border-radius: 8px; border: none; font-weight: 500; font-size: 0.875rem; cursor: not-allowed;">
                        Coming Soon
                    </button>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="../assets/js/script.js"></script>
</body>
</html>
