<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/csrf.php';

// Accessible to Owner (1) and Manager (2, 4)
requireRole([1, 2, 4]);

$show_trashed = isset($_GET['trashed']) && $_GET['trashed'] === '1';

if (isset($_GET['success'])) {
    if ($_GET['success'] == '1') {
        $success = "Customer added successfully!";
    } elseif ($_GET['success'] == '2') {
        $success = "Customer updated successfully!";
    } elseif ($_GET['success'] == '3') {
        $success = "Customer moved to trash successfully!";
    } elseif ($_GET['success'] == '4') {
        $success = "Customer restored successfully!";
    }
}

// Include backend for POST handling
require_once __DIR__ . '/../../api/users_backend.php';

// Fetch active customers list
$customers_query = "SELECT Customer_ID, customer_name, phone_number, address, email, credit_limit, aging_days, created_at FROM customers WHERE deleted_at IS NULL ORDER BY created_at DESC";
$customers_result = $conn->query($customers_query);
if (!$customers_result) {
    $customers_result = null;
}

// Fetch trashed customers
$trashed_query = "SELECT Customer_ID, customer_name, phone_number, address, email, credit_limit, aging_days, created_at, deleted_at FROM customers WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC";
$trashed_result = $conn->query($trashed_query);

// Get total customers count
$total_customers = $customers_result ? $customers_result->rowCount() : 0;
$total_trashed = $trashed_result ? $trashed_result->rowCount() : 0;

// Re-fetch result sets after counting
$customers_result = $conn->query($customers_query);
$trashed_result = $conn->query($trashed_query);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/customer.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 30px rgba(99, 102, 241, 0.15);
            border-color: #c7d2fe;
        }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stat-icon.total {
            background: linear-gradient(135deg, #6366f1 0%, #7c3aed 100%);
            color: white;
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.3);
        }

        .stat-content h4 {
            font-size: 0.8rem;
            font-weight: 600;
            color: #64748b;
            margin: 0 0 0.25rem 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-content p {
            font-size: 1.75rem;
            font-weight: 800;
            color: #1e293b;
            margin: 0;
        }

        /* Improved Header */
        .page-header-banner {
            background: linear-gradient(135deg, #6366f1 0%, #7c3aed 100%);
            border-radius: 24px;
            padding: 2rem;
            color: white;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            box-shadow: 0 20px 40px rgba(99, 102, 241, 0.25);
            position: relative;
            overflow: hidden;
        }

        .page-header-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: rgba(255,255,255,0.08);
            border-radius: 50%;
            pointer-events: none;
        }

        .header-content h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
            z-index: 1;
        }

        .header-content p {
            font-size: 0.95rem;
            opacity: 0.9;
            margin: 0.5rem 0 0 0;
            position: relative;
            z-index: 1;
        }

        .btn-add-new {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.875rem 1.5rem;
            background: white;
            color: #6366f1;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            position: relative;
            z-index: 1;
        }

        .btn-add-new:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
    </style>
</head>
<body>
<div class="dashboard-wrapper">
    <!-- Sidebar -->
    <?php
    require_once __DIR__ . '/../../includes/sidebar.php';
    renderSidebar($conn, ['base' => '../', 'active' => 'customers']);
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
                <a href="users.php" class="menu-item active">
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
    <main class="main-content" style="padding: 2rem;">
        <!-- Header Banner -->
        <div class="page-header-banner">
            <div class="header-content">
                <h1><i class="fas fa-users"></i> Customers Management</h1>
                <p>Add and manage customer information.</p>
            </div>
            <button id="addCustomerBtn" class="btn-add-new">
                <i class="fas fa-plus"></i> Add New Customer
            </button>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon total">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-content">
                    <h4>Total Customers</h4>
                    <p><?php echo $total_customers; ?></p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon total" style="background: linear-gradient(135deg, #f59e0b 0%, #ef4444 100%);">
                    <i class="fas fa-trash-alt"></i>
                </div>
                <div class="stat-content">
                    <h4>Trashed</h4>
                    <p><?php echo $total_trashed; ?></p>
                </div>
            </div>

        </div>

        <div style="display: flex; gap: 0.75rem; margin-bottom: 1.5rem; flex-wrap: wrap;">
            <a href="?<?php echo $show_trashed ? '' : 'trashed=1'; ?>" class="btn-add-new" style="background: <?php echo $show_trashed ? '#6366f1' : '#f59e0b'; ?>; color: white; text-decoration: none;">
                <i class="fas fa-<?php echo $show_trashed ? 'users' : 'trash-alt'; ?>"></i>
                <?php echo $show_trashed ? 'View Active Customers' : 'View Trashed (' . $total_trashed . ')'; ?>
            </a>
        </div>

            <?php if (isset($success)): ?>
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: '<?php echo $success; ?>',
                        confirmButtonColor: '#6366f1',
                        confirmButtonText: 'OK'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            // Remove success parameter from URL
                            window.history.replaceState({}, document.title, window.location.pathname);
                        }
                    });
                });
                </script>
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



            <!-- Hidden forms for delete and restore -->
            <form id="deleteForm" method="POST" style="display:none;">
                <?php echo csrfTokenField(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="customer_id" id="delete_customer_id">
            </form>
            <form id="restoreForm" method="POST" style="display:none;">
                <?php echo csrfTokenField(); ?>
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="customer_id" id="restore_customer_id">
            </form>

            <?php if ($show_trashed): ?>
            <!-- Trashed Customers Table -->
            <div class="customer-table-container">
                <div class="customer-card-header">
                    <h3><i class="fas fa-trash-alt"></i> Trashed Customers</h3>
                </div>
                <div class="card-body">
                    <?php if ($trashed_result && $trashed_result->rowCount() > 0): ?>
                        <table class="customer-table">
                            <thead>
                                <tr>
                                    <th>Customer Name</th>
                                    <th>Phone Number</th>
                                    <th>Email</th>
                                    <th>Address</th>
                                    <th>Aging Days</th>
                                    <th>Date Added</th>
                                    <th>Deleted At</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($customer = $trashed_result->fetch(PDO::FETCH_ASSOC)): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($customer['customer_name']); ?></td>
                                        <td><?php echo htmlspecialchars($customer['phone_number']); ?></td>
                                        <td><?php echo htmlspecialchars($customer['email'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($customer['address'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($customer['aging_days'] ?? 'N/A'); ?> days</td>
                                        <td><?php echo htmlspecialchars($customer['created_at'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($customer['deleted_at']); ?></td>
                                        <td>
                                            <button type="button" onclick="restoreCustomer(<?php echo $customer['Customer_ID']; ?>)" style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; border-radius: 10px; font-size: 0.8125rem; font-weight: 600; color: white; background: linear-gradient(135deg, #10b981 0%, #059669 100%); border: none; cursor: pointer; transition: all 0.2s; box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(16, 185, 129, 0.4)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(16, 185, 129, 0.3)';">
                                                <i class="fas fa-undo"></i> Restore
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="customer-empty-state">
                            <i class="fas fa-inbox"></i>
                            <p>No trashed customers.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <!-- Active Customers List Table -->
            <div class="customer-table-container">
                <div class="customer-card-header">
                    <h3><i class="fas fa-list"></i> Customers List</h3>
                </div>
                <div class="card-body">
                    <?php if ($customers_result && $customers_result->rowCount() > 0): ?>
                        <table class="customer-table">
                            <thead>
                                <tr>
                                    <th>Customer Name</th>
                                    <th>Phone Number</th>
                                    <th>Email</th>
                                    <th>Address</th>
                                    <th>Aging Days</th>
                                    <th>Credit Limit</th>
                                    <th>Date Added</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($customer = $customers_result->fetch(PDO::FETCH_ASSOC)): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($customer['customer_name']); ?></td>
                                        <td><?php echo htmlspecialchars($customer['phone_number']); ?></td>
                                        <td><?php echo htmlspecialchars($customer['email'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($customer['address'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($customer['aging_days'] ?? 'N/A'); ?> days</td>
                                        <td>₱<?php echo number_format(floatval($customer['credit_limit'] ?? 0), 2); ?></td>
                                        <td><?php echo htmlspecialchars($customer['created_at'] ?? 'N/A'); ?></td>
                                        <td style="display: flex; gap: 0.5rem;">
                                            <button type="button" onclick="editCustomer(<?php echo $customer['Customer_ID']; ?>, '<?php echo addslashes($customer['customer_name']); ?>', '<?php echo addslashes($customer['phone_number']); ?>', '<?php echo addslashes($customer['email'] ?? ''); ?>', '<?php echo addslashes($customer['address'] ?? ''); ?>', <?php echo $customer['aging_days'] ?? 0; ?>, <?php echo floatval($customer['credit_limit'] ?? 0); ?>)" style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; border-radius: 10px; font-size: 0.8125rem; font-weight: 600; color: white; background: linear-gradient(135deg, #6366f1 0%, #7c3aed 100%); border: none; cursor: pointer; transition: all 0.2s; box-shadow: 0 2px 8px rgba(99, 102, 241, 0.3);" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(99, 102, 241, 0.4)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(99, 102, 241, 0.3)';">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button type="button" onclick="deleteCustomer(<?php echo $customer['Customer_ID']; ?>, '<?php echo addslashes($customer['customer_name']); ?>')" style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; border-radius: 10px; font-size: 0.8125rem; font-weight: 600; color: white; background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); border: none; cursor: pointer; transition: all 0.2s; box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 12px rgba(239, 68, 68, 0.4)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 8px rgba(239, 68, 68, 0.3)';">
                                                <i class="fas fa-trash"></i> Delete
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
            <?php endif; ?>
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
            <?php echo csrfTokenField(); ?>
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
                        <label for="modal_address">Address</label>
                        <input type="text" id="modal_address" name="address" class="customer-form-input" placeholder="Enter customer address">
                    </div>
                </div>

                <div class="customer-form-grid">
                    <div class="customer-form-group">
                        <label for="modal_email">Email</label>
                        <input type="email" id="modal_email" name="email" class="customer-form-input" placeholder="Enter email address">
                    </div>
                </div>

                <div class="customer-form-grid">
                    <div class="customer-form-group">
                        <label for="modal_aging_days">Aging Days *</label>
                        <select id="modal_aging_days" name="aging_days" class="customer-form-select" required>
                            <option value="">Select Aging Days</option>
                            <option value="15">15 Days</option>
                            <option value="30">30 Days</option>
                            <option value="40">40 Days</option>
                            <option value="60">60 Days</option>
                        </select>
                    </div>
                </div>

                <div class="customer-form-grid">
                    <div class="customer-form-group">
                        <label for="modal_credit_limit">Credit Limit (₱)</label>
                        <input type="number" id="modal_credit_limit" name="credit_limit" class="customer-form-input" step="0.01" min="0" placeholder="Auto-calculated from aging days">
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
            <?php echo csrfTokenField(); ?>
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
                        <label for="edit_address">Address</label>
                        <input type="text" id="edit_address" name="address" class="customer-form-input" placeholder="Enter customer address">
                    </div>
                </div>

                <div class="customer-form-grid">
                    <div class="customer-form-group">
                        <label for="edit_email">Email</label>
                        <input type="email" id="edit_email" name="email" class="customer-form-input" placeholder="Enter email address">
                    </div>
                </div>

                <div class="customer-form-grid">
                    <div class="customer-form-group">
                        <label for="edit_aging_days">Aging Days *</label>
                        <select id="edit_aging_days" name="aging_days" class="customer-form-select" required>
                            <option value="">Select Aging Days</option>
                            <option value="15">15 Days</option>
                            <option value="30">30 Days</option>
                            <option value="40">40 Days</option>
                            <option value="60">60 Days</option>
                        </select>
                    </div>
                </div>

                <div class="customer-form-grid">
                    <div class="customer-form-group">
                        <label for="edit_credit_limit">Credit Limit (₱)</label>
                        <input type="number" id="edit_credit_limit" name="credit_limit" class="customer-form-input" step="0.01" min="0" placeholder="Auto-calculated from aging days">
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

// Form validation
function validateCustomerForm(form) {
    const customerName = form.querySelector('[name="customer_name"]').value.trim();
    const phoneNumber = form.querySelector('[name="phone_number"]').value.trim();
    const email = form.querySelector('[name="email"]').value.trim();
    const agingDays = form.querySelector('[name="aging_days"]').value;
    
    // Clear previous errors
    form.querySelectorAll('.error-message').forEach(el => el.remove());
    form.querySelectorAll('.customer-form-input, .customer-form-select').forEach(el => {
        el.style.borderColor = '';
    });
    
    let isValid = true;
    
    // Validate customer name
    if (customerName.length < 2) {
        showFieldError(form.querySelector('[name="customer_name"]'), 'Customer name must be at least 2 characters.');
        isValid = false;
    }
    
    // Validate phone number
    if (!phoneNumber) {
        showFieldError(form.querySelector('[name="phone_number"]'), 'Phone number is required.');
        isValid = false;
    } else if (!/^[0-9\s\-\+\(\)]+$/.test(phoneNumber)) {
        showFieldError(form.querySelector('[name="phone_number"]'), 'Phone number contains invalid characters.');
        isValid = false;
    }
    
    // Validate email
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        showFieldError(form.querySelector('[name="email"]'), 'Invalid email format.');
        isValid = false;
    }
    
    // Validate aging days
    if (!agingDays || !['15', '30', '40', '60'].includes(agingDays)) {
        showFieldError(form.querySelector('[name="aging_days"]'), 'Please select valid aging days (15, 30, 40, or 60).');
        isValid = false;
    }
    
    return isValid;
}

function showFieldError(field, message) {
    field.style.borderColor = '#ef4444';
    const errorDiv = document.createElement('div');
    errorDiv.className = 'error-message';
    errorDiv.style.color = '#ef4444';
    errorDiv.style.fontSize = '0.875rem';
    errorDiv.style.marginTop = '0.25rem';
    errorDiv.textContent = message;
    field.parentElement.appendChild(errorDiv);
}

document.addEventListener('DOMContentLoaded', function() {
    // Add Customer Modal
    const addCustomerBtn = document.getElementById('addCustomerBtn');
    if (addCustomerBtn) {
        addCustomerBtn.addEventListener('click', function() {
            // Reset form
            document.getElementById('addCustomerModal').querySelector('form').reset();
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
    
    // Setup credit limit auto-fill from aging days
    setupCreditLimitAutoFill('modal_aging_days', 'modal_credit_limit');
    setupCreditLimitAutoFill('edit_aging_days', 'edit_credit_limit');
    
    // Form validation on submit
    const addForm = document.querySelector('#addCustomerModal form');
    const editForm = document.querySelector('#editCustomerModal form');
    
    if (addForm) {
        addForm.addEventListener('submit', function(e) {
            if (!validateCustomerForm(this)) {
                e.preventDefault();
                return false;
            }
        });
    }
    
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            if (!validateCustomerForm(this)) {
                e.preventDefault();
                return false;
            }
        });
    }
});

// Edit Customer function
function editCustomer(id, name, phone, email, address, aging_days, credit_limit) {
    document.getElementById('edit_customer_id').value = id;
    document.getElementById('edit_customer_name').value = name;
    document.getElementById('edit_phone_number').value = phone;
    document.getElementById('edit_email').value = email || '';
    document.getElementById('edit_address').value = address || '';
    document.getElementById('edit_aging_days').value = aging_days || '';
    document.getElementById('edit_credit_limit').value = credit_limit || '';
    openModal('editCustomerModal');
}

// Auto-calculate credit limit from aging days
const creditLimitMap = { 15: 2000, 30: 3500, 40: 4500, 60: 6000 };

function setupCreditLimitAutoFill(agingSelectId, creditLimitInputId) {
    var agingSelect = document.getElementById(agingSelectId);
    var creditInput = document.getElementById(creditLimitInputId);
    if (!agingSelect || !creditInput) return;
    agingSelect.addEventListener('change', function() {
        var val = parseInt(this.value);
        if (val && creditLimitMap[val]) {
            // Only auto-fill if the field is empty or still at default
            if (!creditInput.value || parseFloat(creditInput.value) <= 0) {
                creditInput.value = creditLimitMap[val];
            }
        }
    });
}

// Delete Customer function
function deleteCustomer(id, name) {
    Swal.fire({
        title: 'Delete Customer?',
        html: `Are you sure you want to move <strong>${name}</strong> to trash?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#64748b',
        confirmButtonText: '<i class="fas fa-trash"></i> Yes, delete it!',
        cancelButtonText: 'Cancel',
        showLoaderOnConfirm: true,
        preConfirm: () => {
            document.getElementById('delete_customer_id').value = id;
            document.getElementById('deleteForm').submit();
        }
    });
}

// Restore Customer function
function restoreCustomer(id) {
    Swal.fire({
        title: 'Restore Customer?',
        text: 'This customer will be restored to the active list.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#10b981',
        cancelButtonColor: '#64748b',
        confirmButtonText: '<i class="fas fa-undo"></i> Yes, restore it!',
        cancelButtonText: 'Cancel',
        showLoaderOnConfirm: true,
        preConfirm: () => {
            document.getElementById('restore_customer_id').value = id;
            document.getElementById('restoreForm').submit();
        }
    });
}
</script>
</body>
</html>
<?php
// PDO doesn't need free() or close() - resources are automatically freed
?>
