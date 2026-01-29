<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db.php';

// Fetch customers for dropdown
$customers = [];
$customers_query = "SELECT Customer_ID, customer_name, phone_number FROM customers ORDER BY customer_name";
$customers_result = $conn->query($customers_query);
if ($customers_result) {
    while ($row = $customers_result->fetch_assoc()) {
        $customers[] = $row;
    }
}

// Get AR summary using your existing table structure
// account_receivable: AR_ID, Sale_ID, Customer_ID, amount_due, due_date, status, invoice_date, invoice_amount, opening_balance
$summary = [
    'total_outstanding' => 0,
    'total_overdue' => 0,
    'open_count' => 0,
    'overdue_count' => 0,
    'collected_this_month' => 0
];

// Check if account_receivable table exists
$ar_table_exists = false;
$table_check = $conn->query("SHOW TABLES LIKE 'account_receivable'");
if ($table_check && $table_check->num_rows > 0) {
    $ar_table_exists = true;
    
    // Total outstanding (amount_due is the remaining balance in your schema)
    $outstanding_query = $conn->query("SELECT COALESCE(SUM(amount_due), 0) as total 
        FROM account_receivable WHERE status IN ('Open', 'Partial', 'Overdue', 'Pending') AND amount_due > 0");
    if ($outstanding_query) {
        $summary['total_outstanding'] = floatval($outstanding_query->fetch_assoc()['total']);
    }
    
    // Total overdue
    $overdue_query = $conn->query("SELECT COALESCE(SUM(amount_due), 0) as total, COUNT(*) as count
        FROM account_receivable WHERE due_date < CURDATE() AND status NOT IN ('Paid', 'Closed') AND amount_due > 0");
    if ($overdue_query) {
        $row = $overdue_query->fetch_assoc();
        $summary['total_overdue'] = floatval($row['total']);
        $summary['overdue_count'] = intval($row['count']);
    }
    
    // Open count
    $open_query = $conn->query("SELECT COUNT(*) as count FROM account_receivable WHERE status NOT IN ('Paid', 'Closed') AND amount_due > 0");
    if ($open_query) {
        $summary['open_count'] = intval($open_query->fetch_assoc()['count']);
    }
}

// Check if ar_payment table exists for collected this month
$payment_table_check = $conn->query("SHOW TABLES LIKE 'ar_payment'");
if ($payment_table_check && $payment_table_check->num_rows > 0) {
    $month_start = date('Y-m-01');
    $collected_query = $conn->query("SELECT COALESCE(SUM(amount_paid), 0) as total FROM ar_payment WHERE payment_date >= '$month_start'");
    if ($collected_query) {
        $summary['collected_this_month'] = floatval($collected_query->fetch_assoc()['total']);
    }
}

// Get all open AR records with customer info
$ar_records = [];
if ($ar_table_exists) {
    $ar_query = "SELECT ar.*, c.customer_name, c.phone_number,
                        DATEDIFF(CURDATE(), ar.due_date) as days_overdue
                 FROM account_receivable ar
                 LEFT JOIN customers c ON ar.Customer_ID = c.Customer_ID
                 WHERE ar.status NOT IN ('Paid', 'Closed') AND ar.amount_due > 0
                 ORDER BY ar.due_date ASC";
    $ar_result = $conn->query($ar_query);
    if ($ar_result) {
        while ($row = $ar_result->fetch_assoc()) {
            $ar_records[] = $row;
        }
    }
}

function formatPeso($amount) {
    return '₱' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounts Receivable - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .ar-header {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            padding: 2rem;
            border-radius: 20px;
            margin-bottom: 2rem;
        }
        .ar-header h1 {
            margin: 0 0 0.5rem 0;
            font-size: 1.75rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .ar-header p {
            margin: 0;
            opacity: 0.9;
        }
        .ar-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        .ar-stat-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .ar-stat-card .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1e293b;
        }
        .ar-stat-card .stat-label {
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 0.5rem;
        }
        .ar-stat-card.danger .stat-value { color: #dc2626; }
        .ar-stat-card.warning .stat-value { color: #f59e0b; }
        .ar-stat-card.success .stat-value { color: #16a34a; }
        
        .ar-actions {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }
        .ar-btn {
            padding: 0.875rem 1.5rem;
            border-radius: 12px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }
        .ar-btn-primary {
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            color: white;
        }
        .ar-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);
        }
        .ar-btn-success {
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: white;
        }
        .ar-btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.4);
        }
        
        .ar-table-container {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            overflow-x: auto;
        }
        .ar-table {
            width: 100%;
            border-collapse: collapse;
        }
        .ar-table th {
            background: #f8fafc;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #475569;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .ar-table td {
            padding: 1rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .ar-table tr:hover {
            background: #f8fafc;
        }
        
        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-open { background: #dbeafe; color: #1d4ed8; }
        .status-partial { background: #fef3c7; color: #92400e; }
        .status-overdue { background: #fee2e2; color: #991b1b; }
        .status-paid { background: #dcfce7; color: #166534; }
        .status-pending { background: #e0e7ff; color: #4338ca; }
        
        .days-overdue {
            color: #dc2626;
            font-weight: 600;
            font-size: 0.85rem;
        }
        
        .action-btns {
            display: flex;
            gap: 0.5rem;
        }
        .action-btn {
            padding: 0.5rem 0.75rem;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 0.8rem;
            transition: all 0.2s ease;
        }
        .action-btn-pay {
            background: #dcfce7;
            color: #166534;
        }
        .action-btn-pay:hover { background: #bbf7d0; }
        .action-btn-retry {
            background: #fef3c7;
            color: #92400e;
        }
        .action-btn-retry:hover { background: #fde68a; }
        .action-btn-view {
            background: #e0e7ff;
            color: #4338ca;
        }
        .action-btn-view:hover { background: #c7d2fe; }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        .modal.active { display: flex; }
        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        .modal-header h2 {
            margin: 0;
            font-size: 1.25rem;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #64748b;
        }
        .form-group {
            margin-bottom: 1.25rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #374151;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 1rem;
            transition: border-color 0.2s ease;
            box-sizing: border-box;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #6366f1;
        }
        .form-submit {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .form-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);
        }
        
        .customer-balance {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
        }
        .customer-balance .balance-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }
        .customer-balance .balance-total {
            font-weight: 700;
            font-size: 1.25rem;
            color: #dc2626;
            border-top: 2px solid #e5e7eb;
            padding-top: 0.5rem;
            margin-top: 0.5rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #64748b;
        }
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        .filter-tab {
            padding: 0.625rem 1.25rem;
            border-radius: 10px;
            border: 2px solid #e5e7eb;
            background: white;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        .filter-tab:hover { border-color: #6366f1; }
        .filter-tab.active {
            background: #6366f1;
            color: white;
            border-color: #6366f1;
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
                <a href="orders.php" class="menu-item">
                    <i class="fas fa-shopping-cart"></i>
                    <span>Orders</span>
                </a>
            </div>

            <div class="menu-section">
                <div class="menu-label">Accounting</div>
                <a href="accounts_receivable.php" class="menu-item active">
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
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <button class="mobile-sidebar-toggle" id="mobileSidebarToggle" aria-label="Toggle sidebar">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Header -->
        <div class="ar-header">
            <h1><i class="fas fa-file-invoice-dollar"></i> Accounts Receivable</h1>
            <p>Manage customer balances, payments, and collections</p>
        </div>

        <!-- Stats Cards -->
        <div class="ar-stats">
            <div class="ar-stat-card danger">
                <div class="stat-label">Total Outstanding</div>
                <div class="stat-value"><?php echo formatPeso($summary['total_outstanding']); ?></div>
            </div>
            <div class="ar-stat-card warning">
                <div class="stat-label">Overdue Amount</div>
                <div class="stat-value"><?php echo formatPeso($summary['total_overdue']); ?></div>
                <small><?php echo $summary['overdue_count']; ?> overdue invoices</small>
            </div>
            <div class="ar-stat-card">
                <div class="stat-label">Open Accounts</div>
                <div class="stat-value"><?php echo $summary['open_count']; ?></div>
            </div>
            <div class="ar-stat-card success">
                <div class="stat-label">Collected This Month</div>
                <div class="stat-value"><?php echo formatPeso($summary['collected_this_month']); ?></div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="ar-actions">
            <button class="ar-btn ar-btn-primary" onclick="openCreateARModal()">
                <i class="fas fa-plus"></i> New AR Record
            </button>
            <button class="ar-btn ar-btn-success" onclick="openPaymentModal()">
                <i class="fas fa-money-bill-wave"></i> Record Payment
            </button>
        </div>

        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <button class="filter-tab active" data-filter="all">All Open</button>
            <button class="filter-tab" data-filter="overdue">Overdue</button>
            <button class="filter-tab" data-filter="partial">Partial Payments</button>
        </div>

        <!-- AR Table -->
        <div class="ar-table-container">
            <?php if (empty($ar_records)): ?>
            <div class="empty-state">
                <i class="fas fa-file-invoice-dollar"></i>
                <h3>No Open Accounts Receivable</h3>
                <p>All accounts are paid or no AR records exist yet.</p>
            </div>
            <?php else: ?>
            <table class="ar-table">
                <thead>
                    <tr>
                        <th>AR ID</th>
                        <th>Customer</th>
                        <th>Invoice Date</th>
                        <th>Due Date</th>
                        <th>Original Amount</th>
                        <th>Balance Due</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="arTableBody">
                    <?php foreach ($ar_records as $ar): 
                        $is_overdue = strtotime($ar['due_date']) < strtotime('today') && !in_array($ar['status'], ['Paid', 'Closed']);
                        $status_lower = strtolower($ar['status']);
                        $status_class = $is_overdue ? 'overdue' : $status_lower;
                    ?>
                    <tr data-status="<?php echo $is_overdue ? 'overdue' : $status_lower; ?>">
                        <td><strong>AR-<?php echo $ar['AR_ID']; ?></strong></td>
                        <td>
                            <?php echo htmlspecialchars($ar['customer_name'] ?? 'Unknown'); ?>
                            <?php if (!empty($ar['phone_number'])): ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($ar['phone_number']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo date('M j, Y', strtotime($ar['invoice_date'])); ?></td>
                        <td>
                            <?php echo date('M j, Y', strtotime($ar['due_date'])); ?>
                            <?php if ($is_overdue && $ar['days_overdue'] > 0): ?>
                            <br><span class="days-overdue"><?php echo $ar['days_overdue']; ?> days overdue</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo formatPeso($ar['invoice_amount']); ?></td>
                        <td><strong><?php echo formatPeso($ar['amount_due']); ?></strong></td>
                        <td>
                            <span class="status-badge status-<?php echo $status_class; ?>">
                                <?php echo $is_overdue ? 'Overdue' : ucfirst($ar['status']); ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-btns">
                                <button class="action-btn action-btn-pay" onclick="openPaymentModal(<?php echo $ar['Customer_ID']; ?>, '<?php echo htmlspecialchars(addslashes($ar['customer_name'] ?? 'Unknown')); ?>', <?php echo $ar['AR_ID']; ?>)" title="Record Payment">
                                    <i class="fas fa-money-bill"></i>
                                </button>
                                <button class="action-btn action-btn-retry" onclick="openRetryModal(<?php echo $ar['AR_ID']; ?>)" title="Log Collection Attempt">
                                    <i class="fas fa-phone"></i>
                                </button>
                                <button class="action-btn action-btn-view" onclick="viewARDetails(<?php echo $ar['AR_ID']; ?>)" title="View Details">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Create AR Modal -->
<div class="modal" id="createARModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-plus-circle"></i> Create AR Record</h2>
            <button class="modal-close" onclick="closeModal('createARModal')">&times;</button>
        </div>
        <form id="createARForm" onsubmit="submitCreateAR(event)">
            <div class="form-group">
                <label>Customer *</label>
                <select name="customer_id" id="arCustomerId" required>
                    <option value="">Select Customer</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?php echo $c['Customer_ID']; ?>"><?php echo htmlspecialchars($c['customer_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Invoice Amount *</label>
                <input type="number" name="invoice_amount" step="0.01" min="0.01" required placeholder="0.00">
            </div>
            <div class="form-group">
                <label>Amount Due (Balance) *</label>
                <input type="number" name="amount_due" step="0.01" min="0.01" required placeholder="0.00">
            </div>
            <div class="form-group">
                <label>Invoice Date</label>
                <input type="date" name="invoice_date" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="form-group">
                <label>Due Date *</label>
                <input type="date" name="due_date" required value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
            </div>
            <button type="submit" class="form-submit">
                <i class="fas fa-save"></i> Create AR Record
            </button>
        </form>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal" id="paymentModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-money-bill-wave"></i> Record Payment</h2>
            <button class="modal-close" onclick="closeModal('paymentModal')">&times;</button>
        </div>
        <form id="paymentForm" onsubmit="submitPayment(event)">
            <input type="hidden" name="ar_id" id="paymentArId">
            <div class="form-group">
                <label>Customer *</label>
                <select name="customer_id" id="paymentCustomerId" required onchange="loadCustomerBalance(this.value)">
                    <option value="">Select Customer</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?php echo $c['Customer_ID']; ?>"><?php echo htmlspecialchars($c['customer_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="customer-balance" id="customerBalanceInfo" style="display: none;">
                <div class="balance-row">
                    <span>Open Invoices:</span>
                    <span id="openInvoicesCount">0</span>
                </div>
                <div class="balance-row balance-total">
                    <span>Total Outstanding:</span>
                    <span id="totalOutstanding">₱0.00</span>
                </div>
            </div>
            <div class="form-group">
                <label>Payment Amount *</label>
                <input type="number" name="amount_paid" step="0.01" min="0.01" required placeholder="0.00">
            </div>
            <div class="form-group">
                <label>Payment Date</label>
                <input type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <button type="submit" class="form-submit">
                <i class="fas fa-check"></i> Record Payment (FIFO)
            </button>
            <p style="font-size: 0.8rem; color: #64748b; margin-top: 0.75rem; text-align: center;">
                Payment will be applied to oldest invoices first
            </p>
        </form>
    </div>
</div>

<!-- Retry Attempt Modal -->
<div class="modal" id="retryModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-phone"></i> Log Collection Attempt</h2>
            <button class="modal-close" onclick="closeModal('retryModal')">&times;</button>
        </div>
        <form id="retryForm" onsubmit="submitRetry(event)">
            <input type="hidden" name="ar_id" id="retryArId">
            <div class="form-group">
                <label>Status *</label>
                <select name="status" required>
                    <option value="Contacted">Contacted Successfully</option>
                    <option value="No Answer">No Answer</option>
                    <option value="Promise to Pay">Promise to Pay</option>
                    <option value="Refused">Refused Payment</option>
                    <option value="Rescheduled">Rescheduled</option>
                </select>
            </div>
            <div class="form-group">
                <label>Remarks</label>
                <textarea name="remarks" rows="3" placeholder="Notes about this attempt..."></textarea>
            </div>
            <button type="submit" class="form-submit">
                <i class="fas fa-save"></i> Log Attempt
            </button>
        </form>
    </div>
</div>

<!-- AR Details Modal -->
<div class="modal" id="arDetailsModal">
    <div class="modal-content" style="max-width: 700px;">
        <div class="modal-header">
            <h2><i class="fas fa-file-invoice"></i> AR Details</h2>
            <button class="modal-close" onclick="closeModal('arDetailsModal')">&times;</button>
        </div>
        <div id="arDetailsContent">
            Loading...
        </div>
    </div>
</div>

<script src="../assets/js/script.js"></script>
<script>
// Modal functions
function openModal(modalId) {
    document.getElementById(modalId).classList.add('active');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

function openCreateARModal() {
    document.getElementById('createARForm').reset();
    openModal('createARModal');
}

function openPaymentModal(customerId = null, customerName = null, arId = null) {
    document.getElementById('paymentForm').reset();
    if (customerId) {
        document.getElementById('paymentCustomerId').value = customerId;
        loadCustomerBalance(customerId);
    }
    if (arId) {
        document.getElementById('paymentArId').value = arId;
    }
    openModal('paymentModal');
}

function openRetryModal(arId) {
    document.getElementById('retryForm').reset();
    document.getElementById('retryArId').value = arId;
    openModal('retryModal');
}

// Load customer balance
async function loadCustomerBalance(customerId) {
    if (!customerId) {
        document.getElementById('customerBalanceInfo').style.display = 'none';
        return;
    }
    
    try {
        const response = await fetch(`../api/ar_backend.php?action=get_customer_ar&customer_id=${customerId}`);
        const data = await response.json();
        
        if (data.success) {
            document.getElementById('openInvoicesCount').textContent = data.open_count || 0;
            document.getElementById('totalOutstanding').textContent = '₱' + parseFloat(data.total_outstanding || 0).toLocaleString('en-US', {minimumFractionDigits: 2});
            document.getElementById('customerBalanceInfo').style.display = 'block';
        }
    } catch (error) {
        console.error('Error loading customer balance:', error);
    }
}

// Submit Create AR
async function submitCreateAR(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    formData.append('action', 'create_ar');
    
    try {
        const response = await fetch('../api/ar_backend.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            alert('AR Record created successfully!');
            closeModal('createARModal');
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        alert('Error creating AR record');
        console.error(error);
    }
}

// Submit Payment
async function submitPayment(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    formData.append('action', 'record_payment');
    
    try {
        const response = await fetch('../api/ar_backend.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            let message = 'Payment recorded successfully!';
            if (data.applications && data.applications.length > 0) {
                message += '\n\nApplied to:';
                data.applications.forEach(app => {
                    message += `\n- AR-${app.ar_id}: ₱${parseFloat(app.applied).toLocaleString('en-US', {minimumFractionDigits: 2})}`;
                });
            }
            if (data.credit_balance > 0) {
                message += `\n\nCredit balance: ₱${parseFloat(data.credit_balance).toLocaleString('en-US', {minimumFractionDigits: 2})}`;
            }
            alert(message);
            closeModal('paymentModal');
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        alert('Error recording payment');
        console.error(error);
    }
}

// Submit Retry Attempt
async function submitRetry(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    formData.append('action', 'add_retry_attempt');
    
    try {
        const response = await fetch('../api/ar_backend.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            alert('Collection attempt logged successfully!');
            closeModal('retryModal');
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        alert('Error logging attempt');
        console.error(error);
    }
}

// View AR Details
async function viewARDetails(arId) {
    openModal('arDetailsModal');
    document.getElementById('arDetailsContent').innerHTML = '<p style="text-align:center;"><i class="fas fa-spinner fa-spin"></i> Loading...</p>';
    
    try {
        const response = await fetch(`../api/ar_backend.php?action=get_ar_details&ar_id=${arId}`);
        const data = await response.json();
        
        if (data.success) {
            const ar = data.ar;
            let html = `
                <div style="margin-bottom: 1.5rem;">
                    <h3 style="margin: 0 0 0.5rem 0;">AR-${ar.AR_ID}</h3>
                    <p style="margin: 0; color: #64748b;">${ar.customer_name || 'Unknown'} - ${ar.phone_number || 'No phone'}</p>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                    <div style="background: #f8fafc; padding: 1rem; border-radius: 10px;">
                        <small style="color: #64748b;">Invoice Amount</small>
                        <p style="font-size: 1.25rem; font-weight: 700; margin: 0;">₱${parseFloat(ar.invoice_amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</p>
                    </div>
                    <div style="background: #f8fafc; padding: 1rem; border-radius: 10px;">
                        <small style="color: #64748b;">Amount Due</small>
                        <p style="font-size: 1.25rem; font-weight: 700; margin: 0; color: #dc2626;">₱${parseFloat(ar.amount_due).toLocaleString('en-US', {minimumFractionDigits: 2})}</p>
                    </div>
                    <div style="background: #f8fafc; padding: 1rem; border-radius: 10px;">
                        <small style="color: #64748b;">Invoice Date</small>
                        <p style="font-weight: 600; margin: 0;">${new Date(ar.invoice_date).toLocaleDateString()}</p>
                    </div>
                    <div style="background: #f8fafc; padding: 1rem; border-radius: 10px;">
                        <small style="color: #64748b;">Due Date</small>
                        <p style="font-weight: 600; margin: 0;">${new Date(ar.due_date).toLocaleDateString()}</p>
                    </div>
                </div>
            `;
            
            // Payment History
            if (data.payments && data.payments.length > 0) {
                html += `<h4 style="margin: 1.5rem 0 1rem 0;"><i class="fas fa-money-bill"></i> Payment History</h4>`;
                html += `<table style="width: 100%; font-size: 0.85rem; border-collapse: collapse;">
                    <tr style="background: #f8fafc;"><th style="padding: 0.5rem; text-align: left;">Date</th><th style="padding: 0.5rem; text-align: left;">Amount</th><th style="padding: 0.5rem; text-align: left;">Balance After</th></tr>`;
                data.payments.forEach(p => {
                    html += `<tr><td style="padding: 0.5rem; border-bottom: 1px solid #e5e7eb;">${new Date(p.payment_date).toLocaleDateString()}</td>
                        <td style="padding: 0.5rem; border-bottom: 1px solid #e5e7eb;">₱${parseFloat(p.amount_paid).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                        <td style="padding: 0.5rem; border-bottom: 1px solid #e5e7eb;">₱${parseFloat(p.remaining_balance).toLocaleString('en-US', {minimumFractionDigits: 2})}</td></tr>`;
                });
                html += `</table>`;
            }
            
            // Retry Attempts
            if (data.retries && data.retries.length > 0) {
                html += `<h4 style="margin: 1.5rem 0 1rem 0;"><i class="fas fa-phone"></i> Collection Attempts</h4>`;
                data.retries.forEach(r => {
                    html += `<div style="background: #f8fafc; padding: 0.75rem; border-radius: 8px; margin-bottom: 0.5rem;">
                        <strong>#${r.attempt_no}</strong> - ${new Date(r.created_at).toLocaleDateString()} - ${r.status}
                        ${r.remarks ? `<br><small style="color: #64748b;">${r.remarks}</small>` : ''}
                    </div>`;
                });
            }
            
            document.getElementById('arDetailsContent').innerHTML = html;
        } else {
            document.getElementById('arDetailsContent').innerHTML = '<p style="color: #dc2626;">Error loading details: ' + (data.error || 'Unknown error') + '</p>';
        }
    } catch (error) {
        document.getElementById('arDetailsContent').innerHTML = '<p style="color: #dc2626;">Error loading details</p>';
        console.error(error);
    }
}

// Filter tabs
document.querySelectorAll('.filter-tab').forEach(tab => {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        
        const filter = this.dataset.filter;
        const rows = document.querySelectorAll('#arTableBody tr');
        
        rows.forEach(row => {
            const status = row.dataset.status;
            if (filter === 'all') {
                row.style.display = '';
            } else if (filter === 'overdue' && status === 'overdue') {
                row.style.display = '';
            } else if (filter === 'partial' && status === 'partial') {
                row.style.display = '';
            } else if (filter !== 'all') {
                row.style.display = 'none';
            }
        });
    });
});

// Close modal on outside click
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('active');
        }
    });
});
</script>
</body>
</html>
<?php $conn->close(); ?>
