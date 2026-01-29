<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db.php';

if (isset($_GET['success'])) {
    if ($_GET['success'] == '1') {
        $success = "Customer added successfully!";
    } elseif ($_GET['success'] == '2') {
        $success = "Customer updated successfully!";
    }
}

// Include backend for POST handling
require_once '../api/users_backend.php';

// Fetch customers list
$customers_query = "SELECT Customer_ID, customer_name, phone_number, address, type, created_at FROM customers ORDER BY created_at DESC";
$customers_result = $conn->query($customers_query);
if (!$customers_result) {
    // Table doesn't exist yet, create empty result
    $customers_result = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/customer.css">
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
               
                <a href="users.php" class="menu-item active">
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
            <div class="customer-header">
                <h1><i class="fas fa-users"></i> Customers Management</h1>
                <p>Add and manage customer information.</p>
            </div>

            <?php if (isset($success)): ?>
                <div class="alert-message success">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert-message error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo $error; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="customer-card">
                <div class="customer-card-header">
                    <h3><i class="fas fa-user-plus"></i> Add New Customer</h3>
                </div>
                <div class="card-body">
                    <button id="addCustomerBtn" class="customer-btn-primary">
                        <i class="fas fa-plus"></i> Add New Customer
                    </button>
                </div>
            </div>

            <!-- Customers List Table -->
            <div class="customer-table-container">
                <div class="customer-card-header">
                    <h3><i class="fas fa-list"></i> Customers List</h3>
                </div>
                <div class="card-body">
                    <?php if ($customers_result && $customers_result->num_rows > 0): ?>
                        <table class="customer-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Customer Name</th>
                                    <th>Phone Number</th>
                                    <th>Address</th>
                                    <th>Type</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($customer = $customers_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo $customer['Customer_ID']; ?></td>
                                        <td><?php echo htmlspecialchars($customer['customer_name']); ?></td>
                                        <td><?php echo htmlspecialchars($customer['phone_number']); ?></td>
                                        <td><?php echo htmlspecialchars($customer['address'] ?? 'N/A'); ?></td>
                                        <td>
                                            <span class="customer-badge">
                                                <?php echo htmlspecialchars($customer['type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button type="button" class="action-btn" onclick="editCustomer(<?php echo $customer['Customer_ID']; ?>, '<?php echo addslashes($customer['customer_name']); ?>', '<?php echo addslashes($customer['phone_number']); ?>', '<?php echo addslashes($customer['address'] ?? ''); ?>', '<?php echo addslashes($customer['type']); ?>')">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="customer-empty-state">
                            <i class="fas fa-inbox"></i>
                            <p>No customers found. Add your first customer above.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Add Customer Modal -->
<div id="addCustomerModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-user-plus"></i> Add New Customer</h3>
            <span class="close">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="modal-body">
                <div class="customer-form-grid">
                    <div class="customer-form-group">
                        <label for="modal_customer_name">Customer Name *</label>
                        <input type="text" id="modal_customer_name" name="customer_name" class="customer-form-input" required placeholder="Enter customer name">
                    </div>

                    <div class="customer-form-group">
                        <label for="modal_phone_number">Phone Number *</label>
                        <input type="text" id="modal_phone_number" name="phone_number" class="customer-form-input" required placeholder="Enter phone number">
                    </div>
                </div>

                <div class="customer-form-grid">
                    <div class="customer-form-group">
                        <label for="modal_type">Customer Type</label>
                        <select id="modal_type" name="type" class="customer-form-select">
                            <option value="Regular">Regular</option>
                            <option value="Wholesale">Wholesale</option>
                            <option value="Retail">Retail</option>
                            <option value="VIP">VIP</option>
                        </select>
                    </div>

                    <div class="customer-form-group">
                        <label for="modal_address">Address</label>
                        <input type="text" id="modal_address" name="address" class="customer-form-input" placeholder="Enter customer address">
                    </div>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="customer-btn-secondary" onclick="closeModal('addCustomerModal')">Cancel</button>
                <button type="submit" class="customer-btn-primary">
                    <i class="fas fa-save"></i> Add Customer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Customer Modal -->
<div id="editCustomerModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Edit Customer</h3>
            <span class="close">&times;</span>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" id="edit_customer_id" name="customer_id">
            <div class="modal-body">
                <div class="customer-form-grid">
                    <div class="customer-form-group">
                        <label for="edit_customer_name">Customer Name *</label>
                        <input type="text" id="edit_customer_name" name="customer_name" class="customer-form-input" required placeholder="Enter customer name">
                    </div>

                    <div class="customer-form-group">
                        <label for="edit_phone_number">Phone Number *</label>
                        <input type="text" id="edit_phone_number" name="phone_number" class="customer-form-input" required placeholder="Enter phone number">
                    </div>
                </div>

                <div class="customer-form-grid">
                    <div class="customer-form-group">
                        <label for="edit_type">Customer Type</label>
                        <select id="edit_type" name="type" class="customer-form-select">
                            <option value="Regular">Regular</option>
                            <option value="Wholesale">Wholesale</option>
                            <option value="Retail">Retail</option>
                            <option value="VIP">VIP</option>
                        </select>
                    </div>

                    <div class="customer-form-group">
                        <label for="edit_address">Address</label>
                        <input type="text" id="edit_address" name="address" class="customer-form-input" placeholder="Enter customer address">
                    </div>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="customer-btn-secondary" onclick="closeModal('editCustomerModal')">Cancel</button>
                <button type="submit" class="customer-btn-primary">
                    <i class="fas fa-save"></i> Update Customer
                </button>
            </div>
        </form>
    </div>
</div>

<script src="../assets/js/script.js"></script>
<script>
// Modal functionality
function openModal(modalId) {
    document.getElementById(modalId).style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Close modal when clicking outside or on close button
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Add Customer Modal
    const addCustomerBtn = document.getElementById('addCustomerBtn');
    if (addCustomerBtn) {
        addCustomerBtn.addEventListener('click', function() {
            openModal('addCustomerModal');
        });
    }

    // Close buttons
    const closeButtons = document.querySelectorAll('.close');
    closeButtons.forEach(button => {
        button.addEventListener('click', function() {
            const modal = this.closest('.modal');
            closeModal(modal.id);
        });
    });
});

// Edit Customer function
function editCustomer(id, name, phone, address, type) {
    document.getElementById('edit_customer_id').value = id;
    document.getElementById('edit_customer_name').value = name;
    document.getElementById('edit_phone_number').value = phone;
    document.getElementById('edit_address').value = address;
    document.getElementById('edit_type').value = type;
    openModal('editCustomerModal');
}
</script>
</body>
</html>
<?php
if ($customers_result) {
    $customers_result->free();
}
$conn->close();
?>
