<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db.php';

// Include backend for POST handling
require_once '../api/sales_backend.php';

// Check if we're filtering by a specific order
$filter_order_id = intval($_GET['delivery_order_id'] ?? 0);
$delivery_where = "d.delivery_status = 'Delivered' AND (o.order_status IS NULL OR o.order_status != 'Completed')";
if ($filter_order_id > 0) {
    $delivery_where .= " AND o.Order_ID = $filter_order_id";
}

// Fetch deliveries that are ready for sale (status = 'Delivered')
$deliveries_query = "SELECT d.Delivery_ID, d.Order_ID, d.delivery_status, d.delivered_by, d.delivered_to,
                     o.order_date, o.order_status, c.customer_name
                     FROM delivery d
                     LEFT JOIN orders o ON d.Order_ID = o.Order_ID
                     LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
                     WHERE $delivery_where
                     ORDER BY d.actual_date_arrived DESC, d.Delivery_ID DESC
                     LIMIT 50";
$deliveries_result = $conn->query($deliveries_query);

// Check if status column exists in sales table
$check_status_col = $conn->query("SHOW COLUMNS FROM sales LIKE 'status'");
$has_status_col = $check_status_col && $check_status_col->num_rows > 0;
if ($check_status_col) $check_status_col->close();

// Detect which column stores the cashier/recorder in `sales` (if any)
$sales_cols_res = $conn->query("SHOW COLUMNS FROM sales");
$sales_cols = [];
if ($sales_cols_res) {
    while ($r = $sales_cols_res->fetch_assoc()) {
        $sales_cols[] = $r['Field'];
    }
    $sales_cols_res->close();
}
$sales_user_col = null;
if (in_array('created_by', $sales_cols, true)) {
    $sales_user_col = 'created_by';
} elseif (in_array('User_ID', $sales_cols, true)) {
    $sales_user_col = 'User_ID';
} elseif (in_array('user_id', $sales_cols, true)) {
    $sales_user_col = 'user_id';
}
$recorded_by_select = $sales_user_col ? "u.user_name as recorded_by," : "'N/A' as recorded_by,";
$recorded_by_join = $sales_user_col ? "LEFT JOIN app_users u ON u.User_ID = s.$sales_user_col" : "";

// Fetch sales history
$status_select = $has_status_col ? "s.status," : "'Completed' as status,";
$status_group_by = $has_status_col ? ", s.status" : "";
$sales_query = "SELECT 
                    s.Sale_ID, 
                    s.created_at, 
                    $status_select
                    $recorded_by_select
                    d.Delivery_ID, 
                    d.delivered_to, 
                    o.Order_ID,
                    ss.Delivery_ID as has_delivery,
                    COALESCE(SUM(sd.quantity), 0) as total_qty,
                    COALESCE(SUM(sd.subtotal), 0) as total_amount,
                    COALESCE(
                        GROUP_CONCAT(
                            CONCAT(
                                p.product_name,
                                IF(p.form IS NULL OR p.form = '', '', CONCAT(' (', p.form, ')')),
                                IF(p.unit IS NULL OR p.unit = '', '', CONCAT(' ', p.unit)),
                                ' x', CAST(ROUND(sd.quantity, 0) AS UNSIGNED)
                            )
                            SEPARATOR ', '
                        ),
                        ''
                    ) as items_sold
                FROM sales s
                $recorded_by_join
                LEFT JOIN sale_source ss ON s.Sale_ID = ss.Sale_ID
                LEFT JOIN delivery d ON ss.Delivery_ID = d.Delivery_ID
                LEFT JOIN orders o ON d.Order_ID = o.Order_ID
                LEFT JOIN sale_details sd ON sd.Sale_ID = s.Sale_ID
                LEFT JOIN products p ON p.Product_ID = sd.Product_ID
                GROUP BY s.Sale_ID, s.created_at{$status_group_by}, recorded_by, d.Delivery_ID, d.delivered_to, o.Order_ID, ss.Delivery_ID
                ORDER BY s.created_at DESC
                LIMIT 100";
$sales_result = $conn->query($sales_query);

// Fetch products for walk-in sales
$products_query = "SELECT Product_ID, product_name, form, unit, retail_price 
                   FROM products WHERE is_discontinued = 0 ORDER BY form, unit, product_name";
$products_result = $conn->query($products_query);
$products_data = [];
while ($product = $products_result->fetch_assoc()) {
    $products_data[] = $product;
}
$products_result->data_seek(0);

// Fetch customers for walk-in sales
$customers_query = "SELECT Customer_ID, customer_name FROM customers ORDER BY customer_name";
$customers_result = $conn->query($customers_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .action-bar {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #e5e7eb;
        }
        .card-header h3 {
            margin: 0;
            color: #1f2937;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        .table th, .table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        .table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
        }
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .status-Completed { background: #d1fae5; color: #065f46; }
        .status-Pending { background: #fef3c7; color: #92400e; }
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-secondary { background: #6b7280; color: white; }
        .btn-success { background: #10b981; color: white; }
        .btn:hover { opacity: 0.9; }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            overflow-y: auto;
        }
        .modal-content {
            max-width: 800px;
            margin: 2rem auto;
            background: white;
            border-radius: 12px;
            padding: 2rem;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        .close {
            font-size: 2rem;
            cursor: pointer;
            color: #6b7280;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #374151;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.875rem;
        }
        .alert {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
        }
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #10b981;
        }
        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #ef4444;
        }
    </style>
</head>
<body>
<div class="dashboard-wrapper">
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
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
                <a href="sales.php" class="menu-item active">
                    <i class="fas fa-receipt"></i>
                    <span>Sales</span>
                </a>
                <a href="inventory.php" class="menu-item">
                    <i class="fas fa-cubes"></i>
                    <span>Inventory</span>
                </a>
                <a href="production.php" class="menu-item">
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
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <div class="container">
            <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <div>
                    <h1><i class="fas fa-receipt"></i> Sales Management</h1>
                    <p style="color: #6b7280; margin-top: 0.5rem;">
                        <strong>Important:</strong> Sales are recorded ONLY when the delivery rider returns with payment and hands it over to the cashier. 
                        Inventory is reduced only at this point, not during delivery.
                    </p>
                </div>
            </div>

            <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_GET['success']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_GET['error']); ?>
                </div>
            <?php endif; ?>

            <div class="action-bar">
                <button onclick="showCreateWalkinSaleModal()" class="btn btn-success">
                    <i class="fas fa-walking"></i> Record Walk-in Sale (Payment Received)
                </button>
            </div>

            <!-- Deliveries Ready for Sale -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-truck"></i> Deliveries Ready for Sale</h3>
                    <small style="color: #6b7280;">(Only record sale when delivery rider has returned with payment)</small>
                </div>
                <div class="card-body">
                    <?php if ($deliveries_result && $deliveries_result->num_rows > 0): ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Delivery #</th>
                                    <th>Order #</th>
                                    <th>Customer</th>
                                    <th>Delivered By</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($delivery = $deliveries_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong>#<?php echo $delivery['Delivery_ID']; ?></strong></td>
                                        <td><?php echo $delivery['Order_ID'] ? 'Order #' . $delivery['Order_ID'] : 'N/A'; ?></td>
                                        <td><?php echo htmlspecialchars($delivery['customer_name'] ?? $delivery['delivered_to'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($delivery['delivered_by'] ?? 'N/A'); ?></td>
                                        <td><span class="status-badge status-<?php echo $delivery['delivery_status']; ?>"><?php echo $delivery['delivery_status']; ?></span></td>
                                        <td>
                                            <button onclick="createSaleFromDelivery(<?php echo $delivery['Delivery_ID']; ?>)" class="btn btn-primary btn-sm" title="Record sale when payment is received from delivery rider">
                                                <i class="fas fa-money-bill-wave"></i> Record Sale (Payment Received)
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="text-align: center; color: #6b7280; padding: 2rem;">No deliveries ready for sale.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sales History -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-history"></i> Sales History</h3>
                </div>
                <div class="card-body">
                    <?php if ($sales_result && $sales_result->num_rows > 0): ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Sale #</th>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Customer/Order</th>
                                    <th>Recorded By</th>
                                    <th>Items Sold</th>
                                    <th>Total Qty</th>
                                    <th>Total Amount</th>
                                    <th>Status</th>
                                    <th>View</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($sale = $sales_result->fetch_assoc()): 
                                    $sale_type = $sale['has_delivery'] ? 'Pre-Order (Wholesale)' : 'Walk-in (Retail)';
                                    $customer_info = $sale['delivered_to'] ?? ($sale['Order_ID'] ? 'Order #' . $sale['Order_ID'] : 'N/A');
                                ?>
                                    <tr>
                                        <td><strong>#<?php echo $sale['Sale_ID']; ?></strong></td>
                                        <td><?php echo date('M d, Y H:i', strtotime($sale['created_at'])); ?></td>
                                    <td><?php echo $sale_type; ?></td>
                                    <td><?php echo htmlspecialchars($customer_info); ?></td>
                                    <td><?php echo htmlspecialchars($sale['recorded_by'] ?? 'N/A'); ?></td>
                                    <td style="max-width: 320px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <?php echo htmlspecialchars($sale['items_sold'] ?? ''); ?>
                                    </td>
                                    <td><strong><?php echo number_format((float)round(floatval($sale['total_qty'] ?? 0), 0), 0); ?></strong></td>
                                    <td><strong>₱<?php echo number_format($sale['total_amount'] ?? 0, 2); ?></strong></td>
                                    <td>
                                        <?php if ($has_status_col): ?>
                                            <span class="status-badge status-<?php echo $sale['status']; ?>"><?php echo $sale['status']; ?></span>
                                        <?php else: ?>
                                            <span class="status-badge status-Completed">Completed</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-secondary btn-sm" onclick="openSaleViewModal(<?php echo intval($sale['Sale_ID']); ?>)" title="View full sale details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="text-align: center; color: #6b7280; padding: 2rem;">No sales recorded yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Create Sale from Delivery Modal -->
<div id="saleFromDeliveryModal" class="modal">
    <div class="modal-content" style="max-height: 85vh; overflow-y: auto;">
        <div class="modal-header">
            <h2><i class="fas fa-truck"></i> Record Sale from Delivery</h2>
            <span class="close" onclick="closeSaleFromDeliveryModal()">&times;</span>
        </div>
        <div style="background: #fef3c7; padding: 1rem; border-radius: 6px; margin-bottom: 1rem; border-left: 4px solid #f59e0b;">
            <strong><i class="fas fa-exclamation-triangle"></i> Confirm Payment Received:</strong>
            <p style="margin: 0.5rem 0 0 0; color: #92400e;">
                Only record this sale if the delivery rider has returned and handed over the payment to the cashier. 
                Inventory will be reduced when you confirm.
            </p>
        </div>
        <form id="saleFromDeliveryForm" method="POST">
            <input type="hidden" name="action" value="create_sale_from_delivery">
            <input type="hidden" name="delivery_id" id="delivery_id">
            <div id="delivery_details_container"></div>
            <div class="form-group">
                <label for="remarks">Remarks</label>
                <textarea id="remarks" name="remarks" rows="3" placeholder="Payment received from delivery rider..."></textarea>
            </div>
            <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem;">
                <button type="button" onclick="closeSaleFromDeliveryModal()" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Confirm Payment Received & Record Sale
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Create Walk-in Sale Modal -->
<div id="walkinSaleModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-cash-register"></i> Walk-in POS (Retail)</h2>
            <span class="close" onclick="closeWalkinSaleModal()">&times;</span>
        </div>
        <div style="background: #d1fae5; padding: 1rem; border-radius: 6px; margin-bottom: 1rem; border-left: 4px solid #10b981;">
            <strong><i class="fas fa-info-circle"></i> Walk-in Sale:</strong>
            <p style="margin: 0.5rem 0 0 0; color: #065f46;">
                Customer pays immediately. Record sale and reduce inventory now.
            </p>
        </div>
        <form id="walkinSaleForm" method="POST">
            <input type="hidden" name="action" value="create_walkin_sale">
            <div class="form-group">
                <label>Items *</label>
                <div id="walkinItems">
                    <div class="order-item-row" style="display: flex; gap: 0.5rem; margin-bottom: 0.5rem;">
                        <select name="items[]" class="product-select" required style="flex: 2;">
                            <option value="">Select Product</option>
                            <?php foreach ($products_data as $product):
                                $product_display = htmlspecialchars($product['product_name']);
                                if (!empty($product['form'])) {
                                    $product_display .= ' - ' . htmlspecialchars($product['form']);
                                }
                                if (!empty($product['unit'])) {
                                    $product_display .= ' (' . htmlspecialchars($product['unit']) . ')';
                                }
                            ?>
                                <option value="<?php echo $product['Product_ID']; ?>"
                                    data-retail="<?php echo $product['retail_price']; ?>">
                                    <?php echo $product_display; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" name="quantities[]" placeholder="Qty" min="0.01" step="0.01" required style="width: 100px;">
                        <input type="text" name="unit_prices[]" placeholder="Price" readonly style="width: 120px;">
                        <button type="button" onclick="removeWalkinItem(this)" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <button type="button" onclick="addWalkinItem()" class="btn btn-secondary">
                    <i class="fas fa-plus"></i> Add Item
                </button>
            </div>

            <div class="card" style="margin-top: 1rem; background:#f9fafb;">
                <div style="display:flex; justify-content:space-between; align-items:center; gap: 1rem;">
                    <div style="font-size: 1.1rem;">
                        <strong>Total: ₱<span id="walkin_total_amount">0.00</span></strong>
                    </div>
                    <div style="display:flex; gap: 1rem; align-items:center;">
                        <div>
                            <label style="display:block; font-weight:600; margin-bottom:4px;">Cash</label>
                            <input type="number" id="walkin_cash" name="cash_received" min="0" step="0.01" value="0" style="width: 160px;">
                        </div>
                        <div>
                            <label style="display:block; font-weight:600; margin-bottom:4px;">Change</label>
                            <input type="text" id="walkin_change" name="change_given" readonly value="₱0.00" style="width: 160px; background:#f3f4f6;">
                        </div>
                    </div>
                </div>
                <small style="color:#6b7280; display:block; margin-top:8px;">
                    Enter cash amount to compute change. Sale will only be saved when cash ≥ total.
                </small>
            </div>

            <div class="form-group">
                <label for="walkin_remarks">Remarks</label>
                <textarea id="walkin_remarks" name="remarks" rows="3"></textarea>
            </div>
            <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem;">
                <button type="button" onclick="closeWalkinSaleModal()" class="btn btn-secondary">Cancel</button>
                <button type="submit" id="walkin_submit_btn" class="btn btn-success">
                    <i class="fas fa-save"></i> Confirm Payment & Record Sale
                </button>
            </div>
        </form>
    </div>
</div>

<!-- View Sale Modal -->
<div id="saleViewModal" class="modal">
    <div class="modal-content" style="max-height: 85vh; overflow-y: auto;">
        <div class="modal-header">
            <h2><i class="fas fa-receipt"></i> Sale Details</h2>
            <span class="close" onclick="closeSaleViewModal()">&times;</span>
        </div>
        <div id="sale_view_body">
            <div style="text-align:center; padding: 2rem;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
        </div>
    </div>
</div>

<script src="../assets/js/script.js"></script>
<script>
// Sale from Delivery functions
function createSaleFromDelivery(deliveryId) {
    if (!deliveryId) {
        alert('Invalid delivery ID');
        return;
    }
    
    document.getElementById('delivery_id').value = deliveryId;
    
    // Show loading state
    document.getElementById('delivery_details_container').innerHTML = '<div style="text-align: center; padding: 2rem;"><i class="fas fa-spinner fa-spin"></i> Loading delivery details...</div>';
    document.getElementById('saleFromDeliveryModal').style.display = 'block';
    
    // Fetch delivery details via AJAX
    fetch(`../api/get_delivery_details.php?delivery_id=${deliveryId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.items && data.items.length > 0) {
                let html = '<div class="form-group"><label>Delivery Items:</label>';
                html += '<table class="table" style="margin-top: 0.5rem;">';
                html += '<thead><tr><th>Product</th><th>Ordered Qty</th><th>Received Qty</th><th>Damage Qty</th><th>Sold Qty</th><th>Unit Price (Wholesale)</th><th>Subtotal</th></tr></thead><tbody>';
                
                data.items.forEach(item => {
                    html += `<tr>
                        <td>${item.product_name} ${item.form ? '(' + item.form + ')' : ''}</td>
                        <td>${item.ordered_qty}</td>
                        <td><input type="number" class="js-received" name="received_qty[]" value="${item.received_qty || item.ordered_qty}" min="0" step="0.01" required></td>
                        <td><input type="number" class="js-damage" name="damage_qty[]" value="${item.damage_qty || 0}" min="0" step="0.01"></td>
                        <td><span class="js-sold">0</span></td>
                        <td>₱<span class="js-unit-price">${parseFloat(item.unit_price).toFixed(2)}</span></td>
                        <td>₱<span class="js-subtotal">0.00</span></td>
                        <input type="hidden" name="delivery_detail_id[]" value="${item.delivery_detail_id}">
                        <input type="hidden" name="order_detail_id[]" value="${item.order_detail_id}">
                        <input type="hidden" name="product_id[]" value="${item.product_id}">
                    </tr>`;
                });
                
                html += '</tbody></table>';
                html += `
                    <div style="display:flex; justify-content:flex-end; margin-top:12px; font-size: 1rem;">
                        <strong>Total Amount: ₱<span id="js-total-amount">0.00</span></strong>
                    </div>
                `;
                html += '</div>';
                document.getElementById('delivery_details_container').innerHTML = html;
                initDeliverySaleAutoCalc();
            } else {
                document.getElementById('delivery_details_container').innerHTML = 
                    '<div class="alert alert-danger">No delivery details found. Please ensure the delivery has been properly recorded with items.</div>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('delivery_details_container').innerHTML = 
                '<div class="alert alert-danger">Error loading delivery details: ' + error.message + '</div>';
        });
}

function initDeliverySaleAutoCalc() {
    const container = document.getElementById('delivery_details_container');
    const rows = container.querySelectorAll('tbody tr');
    const totalEl = document.getElementById('js-total-amount');
    if (!rows.length || !totalEl) return;

    function recalc() {
        let total = 0;

        rows.forEach(row => {
            const receivedInput = row.querySelector('.js-received');
            const damageInput = row.querySelector('.js-damage');
            const soldEl = row.querySelector('.js-sold');
            const unitPriceEl = row.querySelector('.js-unit-price');
            const subtotalEl = row.querySelector('.js-subtotal');

            const received = Math.max(0, parseFloat(receivedInput?.value || '0') || 0);
            let damage = Math.max(0, parseFloat(damageInput?.value || '0') || 0);

            // prevent damage > received
            if (damage > received) {
                damage = received;
                if (damageInput) damageInput.value = String(received);
            }

            const sold = Math.max(0, received - damage);
            const unitPrice = parseFloat(unitPriceEl?.textContent || '0') || 0;
            const subtotal = sold * unitPrice;

            if (soldEl) soldEl.textContent = sold.toFixed(2).replace(/\.00$/, '');
            if (subtotalEl) subtotalEl.textContent = subtotal.toFixed(2);
            total += subtotal;
        });

        totalEl.textContent = total.toFixed(2);
    }

    rows.forEach(row => {
        row.querySelectorAll('.js-received, .js-damage').forEach(inp => {
            inp.addEventListener('input', recalc);
            inp.addEventListener('change', recalc);
        });
    });

    recalc();
}

function closeSaleFromDeliveryModal() {
    document.getElementById('saleFromDeliveryModal').style.display = 'none';
}

function openSaleViewModal(saleId) {
    const modal = document.getElementById('saleViewModal');
    const body = document.getElementById('sale_view_body');
    if (!modal || !body) return;
    modal.style.display = 'block';
    body.innerHTML = '<div style="text-align:center; padding: 2rem;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';

    fetch(`../api/get_sale_details.php?id=${saleId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                body.innerHTML = `<div class="alert alert-danger">${data.message || 'Failed to load sale details'}</div>`;
                return;
            }

            let html = `
                <div class="card" style="background:#f9fafb;">
                    <div style="display:flex; flex-wrap:wrap; gap: 1rem; justify-content:space-between;">
                        <div><strong>Sale #</strong> ${data.sale.sale_id}</div>
                        <div><strong>Date</strong> ${data.sale.created_at}</div>
                        <div><strong>Type</strong> ${data.sale.type}</div>
                        <div><strong>Customer</strong> ${data.sale.customer}</div>
                        <div><strong>Recorded By</strong> ${data.sale.recorded_by || 'N/A'}</div>
                    </div>
                </div>
                <div class="card">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Form</th>
                                <th>Unit</th>
                                <th>Qty</th>
                                <th>Unit Price</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            data.items.forEach(it => {
                html += `
                    <tr>
                        <td>${it.product_name}</td>
                        <td>${it.form || ''}</td>
                        <td>${it.unit || ''}</td>
                        <td><strong>${it.quantity}</strong></td>
                        <td>₱${it.unit_price}</td>
                        <td><strong>₱${it.subtotal}</strong></td>
                    </tr>
                `;
            });

            html += `
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" style="text-align:right;"><strong>Totals</strong></td>
                                <td><strong>${data.totals.total_qty}</strong></td>
                                <td></td>
                                <td><strong>₱${data.totals.total_amount}</strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            `;

            body.innerHTML = html;
        })
        .catch(err => {
            body.innerHTML = `<div class="alert alert-danger">Error: ${err.message}</div>`;
        });
}

function closeSaleViewModal() {
    const modal = document.getElementById('saleViewModal');
    if (modal) modal.style.display = 'none';
}

// Walk-in Sale functions
function showCreateWalkinSaleModal() {
    document.getElementById('walkinSaleModal').style.display = 'block';
    initWalkinPOS();
}

function closeWalkinSaleModal() {
    document.getElementById('walkinSaleModal').style.display = 'none';
}

function addWalkinItem() {
    const container = document.getElementById('walkinItems');
    const newItem = container.firstElementChild.cloneNode(true);
    newItem.querySelector('select').value = '';
    newItem.querySelector('input[type="number"]').value = '';
    newItem.querySelector('input[type="text"]').value = '';
    container.appendChild(newItem);
    attachWalkinItemListeners(newItem);
    initWalkinPOS();
}

function removeWalkinItem(button) {
    if (document.getElementById('walkinItems').children.length > 1) {
        button.closest('.order-item-row').remove();
        initWalkinPOS();
    }
}

function attachWalkinItemListeners(itemRow) {
    const select = itemRow.querySelector('.product-select');
    const priceInput = itemRow.querySelector('input[type="text"]');
    const qtyInput = itemRow.querySelector('input[name="quantities[]"]');
    
    select.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if (selectedOption && selectedOption.value) {
            const retailPrice = selectedOption.getAttribute('data-retail');
            priceInput.value = '₱' + parseFloat(retailPrice).toFixed(2);
        } else {
            priceInput.value = '';
        }
        initWalkinPOS();
    });

    if (qtyInput) {
        qtyInput.addEventListener('input', initWalkinPOS);
        qtyInput.addEventListener('change', initWalkinPOS);
    }
}

// Attach listeners to existing items
document.querySelectorAll('#walkinItems .order-item-row').forEach(item => {
    attachWalkinItemListeners(item);
});

function initWalkinPOS() {
    const form = document.getElementById('walkinSaleForm');
    const totalEl = document.getElementById('walkin_total_amount');
    const cashEl = document.getElementById('walkin_cash');
    const changeEl = document.getElementById('walkin_change');
    const submitBtn = document.getElementById('walkin_submit_btn');
    if (!form || !totalEl || !cashEl || !changeEl || !submitBtn) return;

    const selects = form.querySelectorAll('.product-select');
    const quantities = form.querySelectorAll('input[name="quantities[]"]');
    const prices = form.querySelectorAll('input[name="unit_prices[]"]');

    let total = 0;
    for (let i = 0; i < selects.length; i++) {
        if (!selects[i].value) continue;
        const qty = Math.max(0, parseFloat(quantities[i]?.value || '0') || 0);
        const priceStr = (prices[i]?.value || '').replace('₱', '').trim();
        const price = Math.max(0, parseFloat(priceStr || '0') || 0);
        total += qty * price;
    }

    totalEl.textContent = total.toFixed(2);

    const cash = Math.max(0, parseFloat(cashEl.value || '0') || 0);
    const change = cash - total;
    changeEl.value = '₱' + (change > 0 ? change.toFixed(2) : '0.00');

    // enable submit only if cash covers total AND total > 0
    const canPay = total > 0 && cash >= total;
    submitBtn.disabled = !canPay;
    submitBtn.style.opacity = canPay ? '1' : '0.6';
    submitBtn.style.cursor = canPay ? 'pointer' : 'not-allowed';
}

// Update change whenever cash changes
document.getElementById('walkin_cash')?.addEventListener('input', initWalkinPOS);
document.getElementById('walkin_cash')?.addEventListener('change', initWalkinPOS);

// Update walk-in sale form submission
document.getElementById('walkinSaleForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const items = [];
    const selects = this.querySelectorAll('.product-select');
    const quantities = this.querySelectorAll('input[name="quantities[]"]');
    const prices = this.querySelectorAll('input[name="unit_prices[]"]');
    
    for (let i = 0; i < selects.length; i++) {
        if (selects[i].value && quantities[i].value) {
            items.push({
                product_id: selects[i].value,
                quantity: quantities[i].value,
                unit_price: prices[i].value.replace('₱', '').trim()
            });
        }
    }
    
    if (items.length === 0) {
        alert('Please add at least one item');
        return;
    }
    
    // Create hidden input for items JSON
    const itemsInput = document.createElement('input');
    itemsInput.type = 'hidden';
    itemsInput.name = 'items';
    itemsInput.value = JSON.stringify(items);
    this.appendChild(itemsInput);
    
    // Remove the items[] and quantities[] inputs to avoid confusion
    this.querySelectorAll('select[name="items[]"], input[name="quantities[]"], input[name="unit_prices[]"]').forEach(el => {
        el.remove();
    });
    
    // Submit the form normally
    this.submit();
});

// Update sale from delivery form submission
document.getElementById('saleFromDeliveryForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const deliveryDetails = [];
    
    const deliveryDetailIds = this.querySelectorAll('input[name="delivery_detail_id[]"]');
    const orderDetailIds = this.querySelectorAll('input[name="order_detail_id[]"]');
    const productIds = this.querySelectorAll('input[name="product_id[]"]');
    const receivedQtys = this.querySelectorAll('input[name="received_qty[]"]');
    const damageQtys = this.querySelectorAll('input[name="damage_qty[]"]');
    
    for (let i = 0; i < deliveryDetailIds.length; i++) {
        deliveryDetails.push({
            delivery_detail_id: deliveryDetailIds[i].value,
            order_detail_id: orderDetailIds[i].value,
            product_id: productIds[i].value,
            received_qty: receivedQtys[i].value,
            damage_qty: damageQtys[i].value || 0
        });
    }
    
    if (deliveryDetails.length === 0) {
        alert('No delivery items found');
        return;
    }
    
    // Create hidden input for delivery_details JSON
    const detailsInput = document.createElement('input');
    detailsInput.type = 'hidden';
    detailsInput.name = 'delivery_details';
    detailsInput.value = JSON.stringify(deliveryDetails);
    this.appendChild(detailsInput);
    
    // Submit the form normally
    this.submit();
});

// Close modals when clicking outside
window.onclick = function(event) {
    const saleModal = document.getElementById('saleFromDeliveryModal');
    const walkinModal = document.getElementById('walkinSaleModal');
    if (event.target == saleModal) {
        saleModal.style.display = 'none';
    }
    if (event.target == walkinModal) {
        walkinModal.style.display = 'none';
    }
}
</script>
</body>
</html>
<?php
if ($deliveries_result) $deliveries_result->free();
if ($sales_result) $sales_result->free();
if ($products_result) $products_result->free();
if ($customers_result) $customers_result->free();
$conn->close();
?>