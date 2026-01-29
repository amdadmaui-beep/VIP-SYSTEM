<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db.php';

// Include backend for POST handling
require_once '../api/orders_backend.php';

// Get today's date
$date_result = $conn->query("SELECT CURDATE() as today, CURTIME() as now");
$today_row = $date_result->fetch_assoc();
$today_date = $today_row['today'];
$now_time = substr($today_row['now'], 0, 5); // HH:MM format
$date_result->free();

// Fetch customers for dropdown
$customers_query = "SELECT Customer_ID, customer_name, phone_number, address, type FROM customers ORDER BY customer_name";
$customers_result = $conn->query($customers_query);

// Fetch products for order items
$products_query = "SELECT Product_ID, product_name, form, unit, wholesale_price, retail_price 
    FROM products WHERE is_discontinued = 0 ORDER BY form, unit, product_name";
$products_result = $conn->query($products_query);
$products_data = [];
while ($product = $products_result->fetch_assoc()) {
    $products_data[] = $product;
}
$products_result->data_seek(0);

// Fetch orders with filters
$status_filter = $_GET['status'] ?? '';
$status_where = '';

// Make filter tabs functional (whitelist + special "pending")
$allowed_statuses = [
    'Requested',
    'pending',
    'Confirmed',
    'Scheduled for Delivery',
    'Out for Delivery',
    'Delivered',
    'Delivered (Pending Cash Turnover)',
    'Completed',
    'Cancelled',
    'cancelled'
];

if (!empty($status_filter) && $status_filter !== 'all') {
    if ($status_filter === 'pending') {
        // Pending = Requested + pending (case-insensitive)
        $status_where = "WHERE (LOWER(o.order_status) = 'pending' OR LOWER(o.order_status) = 'requested')";
    } elseif (strtolower($status_filter) === 'cancelled') {
        // Cancelled tab should show both Cancelled/cancelled
        $status_where = "WHERE LOWER(o.order_status) = 'cancelled'";
    } elseif (in_array($status_filter, $allowed_statuses, true)) {
        $safe = $conn->real_escape_string($status_filter);
        $status_where = "WHERE o.order_status = '{$safe}'";
    }
}

$orders_query = "SELECT 
    o.Order_ID,
    o.order_date,
    o.order_status,
    o.total_amount,
    o.remarks,
    o.created_at,
    d.Delivery_ID,
    d.schedule_date as delivery_date,
    d.delivery_status,
    c.customer_name,
    c.phone_number,
    c.address as customer_address,
    d.delivered_by as delivery_person_name
FROM orders o
INNER JOIN customers c ON o.Customer_ID = c.Customer_ID
LEFT JOIN delivery d ON o.Order_ID = d.Order_ID
$status_where
ORDER BY o.created_at DESC
LIMIT 100";

$orders_result = $conn->query($orders_query);
if (!$orders_result) {
    error_log("Error fetching orders: " . $conn->error);
    $orders_result = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/orders.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
                <a href="production.php" class="menu-item">
                    <i class="fas fa-industry"></i>
                    <span>Production</span>
                </a>
                <a href="users.php" class="menu-item">
                    <i class="fas fa-users"></i>
                    <span>Customers</span>
                </a>
                <a href="orders.php" class="menu-item active">
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
            <div class="page-header">
                <div>
                    <h1 class="page-title"><i class="fas fa-shopping-cart"></i> Order Management</h1>
                    <p class="page-subtitle">Manage customer orders from phone calls through delivery completion.</p>
                </div>
                <button onclick="showCreateOrderModal()" class="btn btn-primary">
                    <i class="fas fa-plus"></i> New Order
                </button>
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

            <!-- Status Filter Tabs -->
            <div class="filter-tabs">
                <a href="orders.php?status=all" class="filter-tab <?php echo empty($status_filter) || $status_filter === 'all' ? 'active' : ''; ?>">
                    All Orders
                </a>
                <a href="orders.php?status=pending" class="filter-tab <?php echo $status_filter === 'pending' ? 'active' : ''; ?>">
                    Pending
                </a>
                <a href="orders.php?status=Confirmed" class="filter-tab <?php echo $status_filter === 'Confirmed' ? 'active' : ''; ?>">
                    Confirmed
                </a>
                <a href="orders.php?status=Scheduled for Delivery" class="filter-tab <?php echo $status_filter === 'Scheduled for Delivery' ? 'active' : ''; ?>">
                    Scheduled
                </a>
                <a href="orders.php?status=Out for Delivery" class="filter-tab <?php echo $status_filter === 'Out for Delivery' ? 'active' : ''; ?>">
                    Out for Delivery
                </a>
                <a href="orders.php?status=Delivered (Pending Cash Turnover)" class="filter-tab <?php echo $status_filter === 'Delivered (Pending Cash Turnover)' ? 'active' : ''; ?>">
                    Delivered
                </a>
                <a href="orders.php?status=Completed" class="filter-tab <?php echo $status_filter === 'Completed' ? 'active' : ''; ?>">
                    Completed
                </a>
                <a href="orders.php?status=Cancelled" class="filter-tab <?php echo strtolower($status_filter) === 'cancelled' ? 'active' : ''; ?>">
                    Cancelled
                </a>
            </div>

            <!-- Orders List -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-list"></i> Orders List</h3>
                </div>
                <div class="card-body">
                    <?php if ($orders_result && $orders_result->num_rows > 0): ?>
                        <div class="table-container" style="max-height: 600px; overflow-y: auto;">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Order #</th>
                                        <th>Customer</th>
                                        <th>Order Date</th>
                                        <th>Delivery Date</th>
                                        <th>Status</th>
                                        <th>Total Amount</th>
                                        <th>Delivery Person</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($order = $orders_result->fetch_assoc()): 
                                        // Generate status class - handle both old and new status formats
                                        $status_for_class = strtolower($order['order_status']);
                                        $status_for_class = str_replace([' ', '(', ')', 'pendingcash', 'turnover'], ['', '', '', '', ''], $status_for_class);
                                        $status_class = 'status-' . $status_for_class;
                                    ?>
                                        <tr>
                                            <td><strong>#<?php echo $order['Order_ID']; ?></strong></td>
                                            <td>
                                                <?php echo htmlspecialchars($order['customer_name']); ?><br>
                                                <small style="color: #64748b;"><?php echo htmlspecialchars($order['phone_number']); ?></small>
                                            </td>
                                            <td>
                                                <?php echo date('M d, Y', strtotime($order['order_date'])); ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($order['delivery_date'])): ?>
                                                    <?php echo date('M d, Y', strtotime($order['delivery_date'])); ?>
                                                <?php else: ?>
                                                    <span style="color: #94a3b8;">Not scheduled</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $status_class; ?>">
                                                    <?php echo htmlspecialchars($order['order_status']); ?>
                                                </span>
                                            </td>
                                            <td><strong>₱<?php echo number_format($order['total_amount'], 2); ?></strong></td>
                                            <td>
                                                <?php if (!empty($order['delivery_person_name'])): ?>
                                                    <span><?php echo htmlspecialchars($order['delivery_person_name']); ?></span>
                                                <?php else: ?>
                                                    <span style="color: #94a3b8;">Not assigned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="order-actions">
                                                    <button onclick="viewOrderDetails(<?php echo $order['Order_ID']; ?>)" class="btn btn-sm btn-secondary" title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php 
                                                    $is_completed = in_array(strtolower($order['order_status']), ['completed']);
                                                    $is_cancelled = in_array(strtolower($order['order_status']), ['cancelled']);
                                                    $can_assign = in_array($order['order_status'], ['Confirmed', 'Scheduled for Delivery', 'pending', 'Requested']);
                                                    $is_delivered = in_array(strtolower($order['order_status']), ['delivered (pending cash turnover)', 'delivered']);
                                                    ?>
                                                    <?php if (!$is_completed && !$is_cancelled): ?>
                                                        <button class="btn btn-sm btn-primary update-status-btn" 
                                                                title="Update Status" 
                                                                type="button"
                                                                data-order-id="<?php echo intval($order['Order_ID']); ?>"
                                                                data-status="<?php echo htmlspecialchars(trim($order['order_status']), ENT_QUOTES, 'UTF-8'); ?>">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if ($can_assign): ?>
                                                        <button onclick="assignDelivery(<?php echo $order['Order_ID']; ?>)" class="btn btn-sm btn-info" title="Assign Delivery">
                                                            <i class="fas fa-truck"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if ($is_delivered && !$is_completed): ?>
                                                        <a href="sales.php?delivery_order_id=<?php echo $order['Order_ID']; ?>" class="btn btn-sm btn-success" title="Record Sale (Payment Received)">
                                                            <i class="fas fa-money-bill-wave"></i> Record Sale
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if (!$is_completed && !$is_cancelled): ?>
                                                        <button onclick="cancelOrder(<?php echo $order['Order_ID']; ?>)" class="btn btn-sm btn-danger" title="Cancel Order">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 3rem; color: #64748b;">
                            <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.3;"></i>
                            <p style="margin-top: 1rem;">No orders found.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Create Order Modal -->
<div id="createOrderModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; overflow-y: auto;">
    <div style="max-width: 800px; margin: 2rem auto; background: white; border-radius: 12px; padding: 2rem; max-height: 85vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2><i class="fas fa-plus-circle"></i> Create New Order</h2>
            <button onclick="closeCreateOrderModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer;">&times;</button>
        </div>
        <form id="createOrderForm" method="POST">
            <input type="hidden" name="action" value="create_order">

            <div class="form-row">
                <div class="form-group">
                    <label for="customer_id">Customer *</label>
                    <select id="customer_id" name="customer_id" required>
                        <option value="">Select Customer</option>
                        <?php
                        $customers_result->data_seek(0);
                        while ($customer = $customers_result->fetch_assoc()):
                        ?>
                            <option value="<?php echo $customer['Customer_ID']; ?>">
                                <?php echo htmlspecialchars($customer['customer_name'] . ' - ' . $customer['phone_number']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="order_date">Order Date *</label>
                    <input type="date" id="order_date" name="order_date" required value="<?php echo $today_date; ?>">
                </div>
                <!-- Order Time field - only show if column exists in database -->
                <?php
                $check_time_col = $conn->query("SHOW COLUMNS FROM orders LIKE 'order_time'");
                $has_order_time_col = $check_time_col && $check_time_col->num_rows > 0;
                if ($check_time_col) $check_time_col->close();
                if ($has_order_time_col): ?>
                <div class="form-group">
                    <label for="order_time">Order Time *</label>
                    <input type="time" id="order_time" name="order_time" required value="<?php echo $now_time; ?>">
                </div>
                <?php endif; ?>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="delivery_address">Delivery Address</label>
                    <input type="text" id="delivery_address" name="delivery_address" placeholder="Enter delivery address">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="delivery_date">Delivery Date (Optional)</label>
                    <input type="date" id="delivery_date" name="delivery_date">
                </div>
                <div class="form-group">
                    <label for="delivery_person">Delivery Person (Optional)</label>
                    <input type="text" id="delivery_person" name="delivery_person" placeholder="Enter delivery person name">
                </div>
            </div>

            <div class="form-group">
                <label>Order Items *</label>
                <div id="orderItems">
                    <div class="order-item-row">
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
                                    data-wholesale="<?php echo $product['wholesale_price']; ?>"
                                    data-retail="<?php echo $product['retail_price']; ?>">
                                    <?php echo $product_display; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" name="quantities[]" placeholder="Qty" min="0.01" step="0.01" required style="width: 100px;">
                        <select name="price_types[]" class="price-type-select" style="width: 120px;">
                            <option value="wholesale">Wholesale</option>
                            <option value="retail">Retail</option>
                        </select>
                        <input type="text" name="unit_prices[]" placeholder="Price" readonly style="width: 120px;">
                        <button type="button" onclick="removeOrderItem(this)" class="btn btn-sm btn-danger">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <button type="button" onclick="addOrderItem()" class="btn btn-secondary" style="margin-top: 0.5rem;">
                    <i class="fas fa-plus"></i> Add Item
                </button>
            </div>

            <div class="form-group">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="3" placeholder="Additional notes..."></textarea>
            </div>

            <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem;">
                <button type="button" onclick="closeCreateOrderModal()" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Create Order
                </button>
            </div>
        </form>
    </div>
</div>

<script src="../assets/js/script.js"></script>
<script src="../assets/js/orders.js"></script>
<script>
    // Pass PHP data to JavaScript AFTER orders.js is loaded
    <?php 
    // Ensure products_data is always an array and properly encoded
    if (!isset($products_data) || !is_array($products_data)) {
        $products_data = [];
    }
    // Clean the data to ensure valid JSON
    $clean_products = [];
    foreach ($products_data as $product) {
        $clean_products[] = [
            'Product_ID' => intval($product['Product_ID'] ?? 0),
            'product_name' => $product['product_name'] ?? '',
            'form' => $product['form'] ?? '',
            'unit' => $product['unit'] ?? '',
            'wholesale_price' => floatval($product['wholesale_price'] ?? 0),
            'retail_price' => floatval($product['retail_price'] ?? 0)
        ];
    }
    $json_products = json_encode($clean_products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    if ($json_products === false) {
        $json_products = '[]'; // Fallback to empty array if encoding fails
        error_log("JSON encoding error: " . json_last_error_msg());
    }
    ?>
    const productsData = <?php echo $json_products; ?>;
    
    // Debug: Log if productsData is loaded correctly
    console.log('Products data loaded:', productsData.length, 'products');
    
    // Attach event listeners to update status buttons using data attributes
    // Wait for both DOM and orders.js to be loaded
    (function() {
        function attachUpdateStatusListeners() {
            if (typeof updateOrderStatus === 'function') {
                // Update Status buttons
                document.querySelectorAll('.update-status-btn').forEach(function(button) {
                    // Remove any existing listeners
                    const newButton = button.cloneNode(true);
                    button.parentNode.replaceChild(newButton, button);
                    
                    newButton.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        const orderId = parseInt(this.getAttribute('data-order-id'));
                        const status = this.getAttribute('data-status');
                        console.log('Button clicked:', { orderId, status });
                        if (orderId && status) {
                            updateOrderStatus(orderId, status);
                        } else {
                            console.error('Missing data attributes:', { orderId, status });
                            alert('Error: Missing order information. Please refresh the page.');
                        }
                    });
                });
                console.log('Update status button listeners attached');
            } else {
                // Retry after a short delay if updateOrderStatus is not yet defined
                setTimeout(attachUpdateStatusListeners, 100);
            }
        }
        
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', attachUpdateStatusListeners);
        } else {
            attachUpdateStatusListeners();
        }
    })();
</script>
<script src="../assets/js/orders.js"></script>

</body>
</html>
<?php
if ($customers_result) {
    $customers_result->free();
}
if ($products_result) {
    $products_result->free();
}
if ($orders_result) {
    $orders_result->free();
}
$conn->close();
?>
