<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db.php';

// Include backend for POST handling
require_once '../api/manual_adjustment_backend.php';

// Fetch products for dropdown with current quantities
$products_query = "SELECT 
    p.Product_ID, 
    p.product_name, 
    p.form, 
    p.unit, 
    COALESCE(
        (SELECT si.quantity 
         FROM stockin_inventory si 
         WHERE si.Product_ID = p.Product_ID 
         ORDER BY si.updated_at DESC, si.Inventory_ID DESC 
         LIMIT 1), 
        0
    ) as current_quantity 
FROM products p 
WHERE p.is_discontinued = 0 
ORDER BY p.form, p.unit, p.product_name";
$products_result = $conn->query($products_query);

// Store products data for JavaScript
$products_data = [];
while ($product = $products_result->fetch_assoc()) {
    $products_data[] = $product;
}
$products_result->data_seek(0); // Reset pointer

// Define reasons
$reasons = [
    'Damaged',
    'Expired',
    'Lost',
    'Stolen',
    'Correction',
    'Other'
];

// Fetch adjustment history
$history_query = "SELECT 
    ma.adjustment_date,
    p.product_name,
    p.form,
    p.unit,
    ad.old_quantity,
    ad.new_quantity,
    ad.reason,
    u.user_name as handled_by
FROM adjustment_details ad
INNER JOIN manual_adjustment ma ON ad.Adjustment_ID = ma.Adjustment_ID
INNER JOIN products p ON ad.Product_ID = p.Product_ID
LEFT JOIN app_users u ON ma.created_by = u.User_ID
ORDER BY ma.adjustment_date DESC, ma.Adjustment_ID DESC, ad.Adjustmentdetail_ID DESC
LIMIT 100";
$history_result = $conn->query($history_query);
if (!$history_result) {
    error_log("Error fetching adjustment history: " . $conn->error);
    $history_result = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manual Adjustment - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<div class="dashboard-wrapper">
    <    <aside class="sidebar" id="sidebar">
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
                <a href="production.php" class="menu-item active">
                    <i class="fas fa-industry"></i>
                    <span>Production</span>
                </a>
                <a href="users.php" class="menu-item">
                    <i class="fas fa-users"></i>
                    <span>Customers</span>
                </a>
                <a href="orders.php" class="menu-item">
                    <i class="fas fa-shopping-cart"></i>
                    <span>Orders</span>
                </a>
            </div>

            <div class="menu-section">
                <div class="menu-label">Accounting</div>
                <a href="#" class="menu-item">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <span>Accounts Receivable</span>
                    <span class="menu-item-badge">3</span>
                </a>
                <a href="#" class="menu-item">
                    <i class="fas fa-money-check-alt"></i>
                    <span>Payments</span>
                </a>
                <a href="reports.php" class="menu-item">
                    <i class="fas fa-chart-line"></i>
                    <span>Reports</span>
                </a>
            </div>

            <div class="menu-section">
                <div class="menu-label">System</div>
                <a href="#" class="menu-item">
                    <i class="fas fa-cog"></i>
                    <span>Settings</span>
                </a>
                <a href="#" class="menu-item">
                    <i class="fas fa-user-circle"></i>
                    <span>Profile</span>
                </a>
            </div>
        </nav>
    </aside>!-- Sidebar -->
    
    <!-- Main Content -->
    <main class="main-content">
        <div class="container">
            <div>
                <h1>Manual Inventory Adjustment</h1>
                <p>Adjust inventory quantities manually with reasons.</p>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-adjust"></i> New Adjustment</h3>
                </div>
                <div class="card-body">
                    <form method="post" class="form">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="product_id">Product *</label>
                                <select id="product_id" name="product_id" required onchange="updateCurrentQuantity()">
                                    <option value="">Select Product</option>
                                    <?php
                                    $current_form = '';
                                    while ($product = $products_result->fetch_assoc()):
                                        if ($current_form !== $product['form']):
                                            if ($current_form !== '') echo '</optgroup>';
                                            echo '<optgroup label="' . htmlspecialchars($product['form'] ?? 'No Form') . ' - ' . htmlspecialchars($product['unit']) . '">';
                                            $current_form = $product['form'];
                                        endif;
                                    ?>
                                        <option value="<?php echo $product['Product_ID']; ?>" data-quantity="<?php echo $product['current_quantity']; ?>">
                                            <?php echo htmlspecialchars($product['product_name'] . ' - ' . ($product['form'] ?? 'No Form') . ' - ' . $product['unit']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                    <?php if ($current_form !== '') echo '</optgroup>'; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="current_quantity">Current System Quantity</label>
                                <input type="text" id="current_quantity" readonly value="0">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="adjustment_value">Adjustment Amount *</label>
                                <input type="number" id="adjustment_value" name="adjustment_value" step="0.01" required placeholder="e.g., -5 for 5 damaged items, +10 for found stock" oninput="updateResultingQuantity()">
                                <small>Enter positive number to add stock, negative number to deduct stock</small>
                            </div>

                            <div class="form-group">
                                <label for="resulting_quantity">Resulting Quantity (Preview)</label>
                                <input type="text" id="resulting_quantity" readonly value="0">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="reason">Reason *</label>
                                <select id="reason" name="reason" required>
                                    <option value="">Select Reason</option>
                                    <?php foreach ($reasons as $reason): ?>
                                        <option value="<?php echo htmlspecialchars($reason); ?>"><?php echo htmlspecialchars($reason); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" name="save_adjustment" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Adjustment
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Adjustment History Table -->
            <div class="card" style="margin-top: 2rem;">
                <div class="card-header">
                    <h3><i class="fas fa-history"></i> Adjustment History</h3>
                </div>
                <div class="card-body">
                    <?php if ($history_result && $history_result->num_rows > 0): ?>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Product</th>
                                        <th style="text-align: right;">Old Qty</th>
                                        <th style="text-align: right;">New Qty</th>
                                        <th style="text-align: right;">Change</th>
                                        <th>Reason</th>
                                        <th>Handled By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($row = $history_result->fetch_assoc()): 
                                        $change = $row['new_quantity'] - $row['old_quantity'];
                                        $change_formatted = ($change >= 0 ? '+' : '') . number_format($change, 0);
                                        $change_color = $change >= 0 ? '#10b981' : '#ef4444';
                                        $product_display = htmlspecialchars($row['product_name']);
                                        if (!empty($row['form'])) {
                                            $product_display .= ' (' . htmlspecialchars($row['form']);
                                            if (!empty($row['unit'])) {
                                                $product_display .= ' - ' . htmlspecialchars($row['unit']);
                                            }
                                            $product_display .= ')';
                                        } elseif (!empty($row['unit'])) {
                                            $product_display .= ' (' . htmlspecialchars($row['unit']) . ')';
                                        }
                                    ?>
                                        <tr>
                                            <td><?php echo date('M d, Y', strtotime($row['adjustment_date'])); ?></td>
                                            <td><?php echo $product_display; ?></td>
                                            <td style="text-align: right;"><?php echo number_format($row['old_quantity'], 0); ?></td>
                                            <td style="text-align: right;"><?php echo number_format($row['new_quantity'], 0); ?></td>
                                            <td style="text-align: right; font-weight: 700; color: <?php echo $change_color; ?>;"><?php echo $change_formatted; ?></td>
                                            <td>
                                                <span style="display: inline-block; padding: 0.375rem 0.75rem; background-color: #f1f5f9; border-radius: 8px; font-size: 0.875rem; color: #475569;">
                                                    <?php echo htmlspecialchars($row['reason']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['handled_by'] ?? 'N/A'); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 3rem; color: #64748b;">
                            <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.3;"></i>
                            <p style="margin-top: 1rem;">No adjustment history found.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="../assets/js/script.js"></script>
<script src="../assets/js/manual_adjustment.js"></script>
</body>
</html>
<?php
$products_result->free();
if (isset($history_result)) {
    $history_result->free();
}
$conn->close();
?>
