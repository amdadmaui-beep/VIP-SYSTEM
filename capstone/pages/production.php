<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db.php';

if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success = "Production recorded successfully!";
}

// Include backend for POST handling
require_once '../api/production_backend.php';

// Get today's date from database server
$date_result = $conn->query("SELECT CURDATE() as today");
$today_row = $date_result->fetch_assoc();
$today_date = $today_row['today'];
$date_result->free();

// Fetch products for dropdown
$products_query = "SELECT Product_ID, product_name, form, unit FROM products WHERE is_discontinued = 0 ORDER BY form, unit, product_name";
$products_result = $conn->query($products_query);

// Fetch orders for dropdown with customer info (exclude cancelled orders)
$orders_query = "SELECT o.Order_ID, o.order_date, c.customer_name 
                 FROM orders o 
                 LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID 
                 WHERE o.order_status != 'Cancelled' AND o.order_status != 'cancelled'
                 ORDER BY o.order_date DESC, o.Order_ID DESC 
                 LIMIT 100";
$orders_result = $conn->query($orders_query);

// Fetch production history
$history_query = "SELECT 
    p.Production_ID,
    p.production_date,
    p.production_type,
    p.produced_qty,
    p.bag_size,
    p.number_of_bags,
    p.Order_ID,
    pr.product_name,
    pr.form,
    pr.unit,
    u.user_name as handled_by,
    o.Order_ID as order_id_display,
    o.order_date as order_date_display
FROM productions p
INNER JOIN products pr ON p.Product_ID = pr.Product_ID
LEFT JOIN app_users u ON p.created_by = u.User_ID
LEFT JOIN orders o ON p.Order_ID = o.Order_ID
ORDER BY p.production_date DESC, p.Production_ID DESC
LIMIT 100";
$history_result = $conn->query($history_query);
if (!$history_result) {
    error_log("Error fetching production history: " . $conn->error);
    $history_result = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Production - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/production.css">
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
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <div class="container">
            <h1>Production Management</h1>
            <p>Record production quantities for products.</p>

            <div class="action-bar">
                <button type="button" class="btn btn-primary" onclick="openModal()">
                    <i class="fas fa-plus"></i> Add Production
                </button>
            </div>

            <?php if (isset($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo $error; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Modal -->
            <div id="productionModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2><i class="fas fa-industry"></i> Record Production</h2>
                        <span class="close" onclick="closeModal()">&times;</span>
                    </div>
                    <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                        <form method="POST" class="form" id="productionForm">
                            <div class="form-group">
                                <label for="production_type"><i class="fas fa-tags"></i> Production Type *</label>
                                <select id="production_type" name="production_type" required>
                                    <option value="">Select production type</option>
                                    <option value="stockin" <?php echo (isset($_POST['production_type']) && $_POST['production_type'] == 'stockin') ? 'selected' : ''; ?>>For Stock</option>
                                    <option value="orders" <?php echo (isset($_POST['production_type']) && $_POST['production_type'] == 'orders') ? 'selected' : ''; ?>>For Customer Order</option>
                                </select>
                            </div>

                            <div class="form-group" id="order_group" style="display: none;">
                                <label for="order_id"><i class="fas fa-shopping-cart"></i> Order # *</label>
                                <select id="order_id" name="order_id">
                                    <option value="">Select an order</option>
                                    <?php
                                    if ($orders_result) {
                                        $orders_result->data_seek(0);
                                        while ($order = $orders_result->fetch_assoc()):
                                            $order_display = 'Order #' . $order['Order_ID'];
                                            if (!empty($order['customer_name'])) {
                                                $order_display .= ' – ' . htmlspecialchars($order['customer_name']);
                                            }
                                            $order_display .= ' – ' . date('M d, Y', strtotime($order['order_date']));
                                    ?>
                                        <option value="<?php echo $order['Order_ID']; ?>" <?php echo (isset($_POST['order_id']) && $_POST['order_id'] == $order['Order_ID']) ? 'selected' : ''; ?>>
                                            <?php echo $order_display; ?>
                                        </option>
                                    <?php 
                                        endwhile;
                                    }
                                    ?>
                                </select>
                            </div>

                            <!-- Order Details Section -->
                            <div id="order_details_section" class="form-group" style="display: none; margin-top: 1rem; padding: 1rem; background: #f8f9fa; border-radius: 8px; border: 1px solid #dee2e6;">
                                <h4 style="margin: 0 0 0.75rem 0; color: #495057; font-size: 1rem; font-weight: 600;">
                                    <i class="fas fa-info-circle"></i> Order Details:
                                </h4>
                                <div id="order_details_content" style="color: #6c757d; font-size: 0.9rem; line-height: 1.6;">
                                    <!-- Order details will be loaded here via AJAX -->
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="product_id"><i class="fas fa-box"></i> Product *</label>
                                <select id="product_id" name="product_id" required>
                                    <option value="">Select a product</option>
                                    <?php
                                    $products_result->data_seek(0); // Reset pointer
                                    while ($product = $products_result->fetch_assoc()):
                                        $product_display = htmlspecialchars($product['product_name']);
                                        if (!empty($product['form'])) {
                                            $product_display .= ' – ' . htmlspecialchars($product['form']);
                                        }
                                        if (!empty($product['unit'])) {
                                            $product_display .= ' (' . htmlspecialchars($product['unit']) . ')';
                                        }
                                    ?>
                                        <option value="<?php echo $product['Product_ID']; ?>" 
                                                data-unit="<?php echo htmlspecialchars($product['unit'] ?? ''); ?>"
                                                data-form="<?php echo htmlspecialchars($product['form'] ?? ''); ?>"
                                                <?php echo (isset($_POST['product_id']) && $_POST['product_id'] == $product['Product_ID']) ? 'selected' : ''; ?>>
                                            <?php echo $product_display; ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>

                            <div class="form-group" id="quantity_group">
                                <label for="produced_qty"><i class="fas fa-plus"></i> Quantity *</label>
                                <div style="display: flex; gap: 0.5rem; align-items: center;">
                                    <input type="number" id="produced_qty" name="produced_qty" min="0.01" step="0.01" required value="<?php echo isset($_POST['produced_qty']) ? htmlspecialchars($_POST['produced_qty']) : ''; ?>" placeholder="Final usable quantity" style="flex: 1;">
                                    <select id="quantity_unit" name="quantity_unit" style="width: 120px;">
                                        <option value="kg" <?php echo (isset($_POST['quantity_unit']) && $_POST['quantity_unit'] == 'kg') ? 'selected' : 'selected'; ?>>kg</option>
                                        <option value="grams" <?php echo (isset($_POST['quantity_unit']) && $_POST['quantity_unit'] == 'grams') ? 'selected' : ''; ?>>grams</option>
                                        <option value="blocks" <?php echo (isset($_POST['quantity_unit']) && $_POST['quantity_unit'] == 'blocks') ? 'selected' : ''; ?>>blocks</option>
                                    </select>
                                </div>
                                <small style="color: #666; font-size: 0.85rem; margin-top: 0.25rem; display: block;">(Final usable quantity)</small>
                            </div>

                            <div class="form-group" id="pack_size_group">
                                <label for="pack_size"><i class="fas fa-weight"></i> Pack Size</label>
                                <input type="text" id="pack_size" name="pack_size" readonly value="" style="background-color: #f5f5f5; cursor: not-allowed;">
                                <!-- Hidden fields to store bag_size and bag_size_unit for backend -->
                                <input type="hidden" id="bag_size_hidden" name="bag_size" value="">
                                <input type="hidden" id="bag_size_unit_hidden" name="bag_size_unit" value="">
                            </div>

                            <div class="form-group" id="bag_size_group" style="display: none;">
                                <label for="bag_size"><i class="fas fa-weight"></i> Bag Size</label>
                                <div style="display: flex; gap: 0.5rem; align-items: center;">
                                    <input type="number" id="bag_size" name="bag_size" min="0" step="0.01" value="<?php echo isset($_POST['bag_size']) ? htmlspecialchars($_POST['bag_size']) : ''; ?>" placeholder="Enter bag size" style="flex: 1;">
                                    <select id="bag_size_unit" name="bag_size_unit" style="width: 120px;">
                                        <option value="kg" <?php echo (isset($_POST['bag_size_unit']) && $_POST['bag_size_unit'] == 'kg') ? 'selected' : 'selected'; ?>>kg</option>
                                        <option value="grams" <?php echo (isset($_POST['bag_size_unit']) && $_POST['bag_size_unit'] == 'grams') ? 'selected' : ''; ?>>grams</option>
                                        <option value="blocks" <?php echo (isset($_POST['bag_size_unit']) && $_POST['bag_size_unit'] == 'blocks') ? 'selected' : ''; ?>>blocks</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="number_of_bags"><i class="fas fa-shopping-bag"></i> Number of Packs to Produce *</label>
                                <input type="number" id="number_of_bags" name="number_of_bags" min="0" step="1" required value="<?php echo isset($_POST['number_of_bags']) ? htmlspecialchars($_POST['number_of_bags']) : ''; ?>">
                            </div>

                            <div class="form-group">
                                <label for="production_date"><i class="fas fa-calendar"></i> Production Date *</label>
                                <input type="date" id="production_date" name="production_date" required value="<?php echo isset($_POST['production_date']) ? htmlspecialchars($_POST['production_date']) : $today_date; ?>">
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Record Production
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="closeModal()">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Production History Table -->
            <div class="card" style="margin-top: 2rem;">
                <div class="card-header">
                    <h3><i class="fas fa-history"></i> Production History</h3>
                </div>
                <div class="card-body">
                    <?php if ($history_result && $history_result->num_rows > 0): ?>
<div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Production Date</th>
                                        <th>Product</th>
                                        <th>Production Type</th>
                                        <th >Bag Produced Qty</th>
                                        <th>Unit Size</th>
                                        <th>Order</th>
                                        <th>Handled By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($row = $history_result->fetch_assoc()): 
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
                                        
                                        // Format production type display
                                        $production_type_display = 'N/A';
                                        if ($row['production_type'] === 'stockin') {
                                            $production_type_display = 'For Stock';
                                        } elseif ($row['production_type'] === 'orders') {
                                            $production_type_display = 'For Customer Order';
                                        }
                                        
                                        // Format order display
                                        $order_display = 'N/A';
                                        if (!empty($row['order_id_display'])) {
                                            $order_display = 'Order #' . $row['order_id_display'];
                                            if (!empty($row['order_date_display'])) {
                                                $order_display .= ' - ' . date('M d, Y', strtotime($row['order_date_display']));
                                            }
                                        }
                                    ?>
                                        <tr>
                                            <td><?php echo !empty($row['production_date']) ? date('M d, Y', strtotime($row['production_date'])) : 'N/A'; ?></td>
                                            <td><?php echo $product_display; ?></td>
                                            <td><?php echo $production_type_display; ?></td>
                                            <td style="text-align: right;">
                                                <?php 
                                                // Format produced_qty: show whole number without decimals if it's a whole number
                                                $produced_qty = floatval($row['produced_qty']);
                                                if ($produced_qty == intval($produced_qty)) {
                                                    echo '+' . number_format($produced_qty, 0);
                                                } else {
                                                    echo '+' . number_format($produced_qty, 2);
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                // Display product unit to identify what kind of unit was produced
                                                if (isset($row['unit']) && $row['unit'] !== null && $row['unit'] !== '') {
                                                    echo htmlspecialchars($row['unit']);
                                                } else {
                                                    // Fallback to bag_size if unit is not available
                                                    if (isset($row['bag_size']) && $row['bag_size'] !== null && $row['bag_size'] !== '') {
                                                        $bag_size = floatval($row['bag_size']);
                                                        if ($bag_size > 0) {
                                                            // If it's a whole number, show without decimals
                                                            if ($bag_size == intval($bag_size)) {
                                                                echo number_format($bag_size, 0) . ' kg';
                                                            } else {
                                                                echo number_format($bag_size, 2) . ' kg';
                                                            }
                                                        } else {
                                                            echo 'N/A';
                                                        }
                                                    } else {
                                                        echo 'N/A';
                                                    }
                                                }
                                                ?>
                                            </td>
                                            <td><?php echo $order_display; ?></td>
                                            <td><?php echo htmlspecialchars($row['handled_by'] ?? 'N/A'); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <p>No production history found.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="../assets/js/script.js"></script>
<script>
// Handle production type change - show/hide order dropdown
document.addEventListener('DOMContentLoaded', function() {
    const productionType = document.getElementById('production_type');
    const orderGroup = document.getElementById('order_group');
    const orderSelect = document.getElementById('order_id');
    
    // Order details section
    const orderDetailsSection = document.getElementById('order_details_section');
    const orderDetailsContent = document.getElementById('order_details_content');
    const productSelect = document.getElementById('product_id');
    const packSizeInput = document.getElementById('pack_size');
    const packSizeGroup = document.getElementById('pack_size_group');
    const bagSizeGroup = document.getElementById('bag_size_group');
    const quantityGroup = document.getElementById('quantity_group');
    let currentOrderItems = [];
    
    function toggleOrderGroup() {
        if (productionType.value === 'orders') {
            orderGroup.style.display = 'block';
            orderSelect.required = true;
            packSizeGroup.style.display = 'block';
            bagSizeGroup.style.display = 'none';
            quantityGroup.style.display = 'none';
            // Remove required attribute from quantity field for orders
            const producedQty = document.getElementById('produced_qty');
            if (producedQty) {
                producedQty.removeAttribute('required');
            }
            // If an order is already selected, show its details
            if (orderSelect.value) {
                loadOrderDetails(orderSelect.value);
            }
        } else if (productionType.value === 'stockin') {
            // For Stock - use same fields as orders (Pack Size and Number of Packs)
            orderGroup.style.display = 'none';
            orderSelect.required = false;
            orderSelect.value = '';
            orderDetailsSection.style.display = 'none';
            packSizeGroup.style.display = 'block';
            bagSizeGroup.style.display = 'none';
            quantityGroup.style.display = 'none';
            // Remove required attribute from quantity field for stock
            const producedQty = document.getElementById('produced_qty');
            if (producedQty) {
                producedQty.removeAttribute('required');
            }
            currentOrderItems = [];
            // Reset product dropdown to show all products
            filterProductsByOrder([]);
        } else {
            orderGroup.style.display = 'none';
            orderSelect.required = false;
            orderSelect.value = '';
            orderDetailsSection.style.display = 'none';
            packSizeGroup.style.display = 'none';
            bagSizeGroup.style.display = 'block';
            quantityGroup.style.display = 'block';
            // Add required attribute back to quantity field
            const producedQty = document.getElementById('produced_qty');
            if (producedQty) {
                producedQty.setAttribute('required', 'required');
            }
            currentOrderItems = [];
            // Reset product dropdown to show all products
            filterProductsByOrder([]);
        }
    }
    
    productionType.addEventListener('change', toggleOrderGroup);
    
    // Load order details when order is selected
    orderSelect.addEventListener('change', function() {
        const orderId = this.value;
        if (orderId && productionType.value === 'orders') {
            loadOrderDetails(orderId);
        } else {
            orderDetailsSection.style.display = 'none';
        }
    });
    
    function loadOrderDetails(orderId) {
        if (!orderId) {
            console.log('No order ID provided');
            if (orderDetailsSection) orderDetailsSection.style.display = 'none';
            return;
        }
        
        if (!orderDetailsSection || !orderDetailsContent) {
            console.error('Order details elements not found');
            return;
        }
        
        console.log('Loading order details for order ID:', orderId);
        
        // Show loading state
        orderDetailsContent.innerHTML = '<div style="color: #6c757d;"><i class="fas fa-spinner fa-spin"></i> Loading order details...</div>';
        orderDetailsSection.style.display = 'block';
        
        fetch(`../api/get_order_details.php?order_id=${orderId}`, {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            }
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                console.log('Order details response:', data);
                if (data.success) {
                    let html = `<div style="margin-bottom: 0.5rem;"><strong>Order:</strong><br>`;
                    html += `<span style="color: #495057;">[ ${data.order_info} ]</span></div>`;
                    
                    // Store order items for product filtering
                    currentOrderItems = data.items || [];
                    
                    // Display order items with form and unit
                    if (data.items && data.items.length > 0) {
                        html += `<div style="margin-top: 0.75rem;"><strong>Order Items:</strong><br>`;
                        html += `<ul style="margin: 0.5rem 0 0 1.5rem; padding: 0; list-style-type: disc;">`;
                        data.items.forEach(item => {
                            html += `<li style="margin-bottom: 0.25rem;">`;
                            html += `${item.product_name} (${item.form}) – ${item.quantity} bags (${item.unit} each)`;
                            html += `</li>`;
                        });
                        html += `</ul></div>`;
                    }
                    
                    html += `<div style="margin-top: 0.75rem;"><strong>Production Summary:</strong><br>`;
                    html += `<ul style="margin: 0.5rem 0 0 1.5rem; padding: 0; list-style-type: disc;">`;
                    html += `<li>Required Quantity: <strong>${data.required_bags} bags</strong></li>`;
                    html += `<li>Already Produced: <strong>${data.produced_bags} bags</strong></li>`;
                    html += `<li>Remaining to Produce: <strong>${data.remaining_bags} bags</strong></li>`;
                    html += `</ul></div>`;
                    orderDetailsContent.innerHTML = html;
                    orderDetailsSection.style.display = 'block';
                    
                    // Filter products to show only those in the order
                    filterProductsByOrder(currentOrderItems);
                } else {
                    orderDetailsContent.innerHTML = `<div style="color: #dc3545;">Error: ${data.message || 'Failed to load order details'}</div>`;
                    orderDetailsSection.style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Error loading order details:', error);
                orderDetailsContent.innerHTML = `<div style="color: #dc3545;">Error loading order details. Please check the console for details.</div>`;
                orderDetailsSection.style.display = 'block';
            });
    }
    
    // Filter products based on order items
    function filterProductsByOrder(orderItems) {
        if (!productSelect) return;
        
        const allOptions = Array.from(productSelect.options);
        const orderProductIds = orderItems.map(item => String(item.product_id));
        
        allOptions.forEach(option => {
            if (option.value === '') {
                option.style.display = 'block';
                return;
            }
            
            if (productionType.value === 'orders' && orderItems.length > 0) {
                // Show only products that match order items by Product_ID
                option.style.display = orderProductIds.includes(option.value) ? 'block' : 'none';
            } else {
                // Show all products for non-order production
                option.style.display = 'block';
            }
        });
    }
    
    // Handle product selection to populate pack size
    if (productSelect) {
        productSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption && selectedOption.value && (productionType.value === 'orders' || productionType.value === 'stockin')) {
                const unit = selectedOption.getAttribute('data-unit') || '';
                const bagSizeHidden = document.getElementById('bag_size_hidden');
                const bagSizeUnitHidden = document.getElementById('bag_size_unit_hidden');
                
                if (packSizeInput) {
                    // Extract numeric value and unit from strings like "70g", "70G", "70 grams", "Block", etc.
                    if (unit) {
                        // Try to extract number and unit separately (case-insensitive)
                        // Match patterns like: "70g", "70G", "70g each", "70 grams", "70.5kg", "Block", etc.
                        const unitMatch = unit.match(/(\d+(?:\.\d+)?)\s*([a-zA-Z]+)/i);
                        if (unitMatch) {
                            // Found number and unit: format as "70 grams (read-only)"
                            const number = unitMatch[1];
                            const unitName = unitMatch[2].toLowerCase();
                            // Normalize unit name
                            let normalizedUnit = unitName;
                            let bagSizeValue = parseFloat(number);
                            let bagSizeUnitValue = 'kg';
                            
                            if (unitName === 'g' || unitName === 'gram') {
                                normalizedUnit = 'grams';
                                bagSizeUnitValue = 'grams';
                            } else if (unitName === 'kg' || unitName === 'kgs' || unitName === 'kilogram' || unitName === 'kilograms') {
                                normalizedUnit = 'kg';
                                bagSizeUnitValue = 'kg';
                            } else if (unitName === 'block' || unitName === 'blocks') {
                                normalizedUnit = 'blocks';
                                bagSizeUnitValue = 'blocks';
                                bagSizeValue = 1; // 1 block
                            }
                            
                            packSizeInput.value = number + ' ' + normalizedUnit + ' (read-only)';
                            
                            // Store values in hidden fields for backend
                            if (bagSizeHidden) bagSizeHidden.value = bagSizeValue;
                            if (bagSizeUnitHidden) bagSizeUnitHidden.value = bagSizeUnitValue;
                        } else {
                            // No number found, check if it's just a unit name (like "Block", "G", etc.)
                            const unitLower = unit.toLowerCase().trim();
                            let normalizedUnit = unit;
                            let bagSizeValue = 1;
                            let bagSizeUnitValue = 'kg';
                            
                            if (unitLower === 'g' || unitLower === 'gram' || unitLower === 'grams') {
                                normalizedUnit = 'grams';
                                bagSizeUnitValue = 'grams';
                            } else if (unitLower === 'kg' || unitLower === 'kilogram' || unitLower === 'kilograms') {
                                normalizedUnit = 'kg';
                                bagSizeUnitValue = 'kg';
                            } else if (unitLower === 'block' || unitLower === 'blocks') {
                                normalizedUnit = 'blocks';
                                bagSizeUnitValue = 'blocks';
                                bagSizeValue = 1;
                            }
                            
                            packSizeInput.value = normalizedUnit + ' (read-only)';
                            
                            // Store values in hidden fields for backend
                            if (bagSizeHidden) bagSizeHidden.value = bagSizeValue;
                            if (bagSizeUnitHidden) bagSizeUnitHidden.value = bagSizeUnitValue;
                        }
                    } else {
                        packSizeInput.value = '';
                        if (bagSizeHidden) bagSizeHidden.value = '';
                        if (bagSizeUnitHidden) bagSizeUnitHidden.value = '';
                    }
                }
            } else if (packSizeInput) {
                packSizeInput.value = '';
                const bagSizeHidden = document.getElementById('bag_size_hidden');
                const bagSizeUnitHidden = document.getElementById('bag_size_unit_hidden');
                if (bagSizeHidden) bagSizeHidden.value = '';
                if (bagSizeUnitHidden) bagSizeUnitHidden.value = '';
            }
        });
    }
    
    
    // Initialize on page load
    toggleOrderGroup();
    if (productionType.value === 'orders' && orderSelect.value) {
        loadOrderDetails(orderSelect.value);
    }
    
    // Auto-calculate number of bags
    const producedQty = document.getElementById('produced_qty');
    const quantityUnit = document.getElementById('quantity_unit');
    const bagSize = document.getElementById('bag_size');
    const bagSizeUnit = document.getElementById('bag_size_unit');
    const numberOfBags = document.getElementById('number_of_bags');
    
    function convertToKg(value, unit) {
        if (unit === 'kg') return value;
        if (unit === 'grams') return value / 1000;
        if (unit === 'blocks') return value; // Assuming blocks are already in equivalent kg or same unit
        return value;
    }
    
    function calculateBags() {
        const qty = parseFloat(producedQty.value) || 0;
        const qtyUnit = quantityUnit.value;
        const size = parseFloat(bagSize.value) || 0;
        const sizeUnit = bagSizeUnit.value;
        
        if (qty > 0 && size > 0) {
            // Convert both to same unit for calculation
            // If units match, calculate directly
            if (qtyUnit === sizeUnit) {
                const bags = Math.ceil(qty / size);
                numberOfBags.value = bags;
            } else {
                // Convert both to kg for calculation
                const qtyInKg = convertToKg(qty, qtyUnit);
                const sizeInKg = convertToKg(size, sizeUnit);
                if (sizeInKg > 0) {
                    const bags = Math.ceil(qtyInKg / sizeInKg);
                    numberOfBags.value = bags;
                } else {
                    numberOfBags.value = '';
                }
            }
        } else {
            numberOfBags.value = '';
        }
    }
    
    producedQty.addEventListener('input', calculateBags);
    quantityUnit.addEventListener('change', calculateBags);
    bagSize.addEventListener('input', calculateBags);
    bagSizeUnit.addEventListener('change', calculateBags);
    
    // Initialize calculation if values exist
    calculateBags();
});
</script>
</body>
</html>
<?php
$products_result->free();
if (isset($orders_result)) {
    $orders_result->free();
}
if (isset($history_result)) {
    $history_result->free();
}
$conn->close();
?>
