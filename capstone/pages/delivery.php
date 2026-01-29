<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db.php';

// Include backend for POST handling
require_once '../api/delivery_backend.php';

// Fetch deliveries
$deliveries_query = "SELECT d.*, o.Order_ID, o.order_date, o.order_status,
                    c.customer_name, c.phone_number
                    FROM delivery d
                    LEFT JOIN orders o ON d.Order_ID = o.Order_ID
                    LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
                    ORDER BY d.created_at DESC
                    LIMIT 100";
$deliveries_result = $conn->query($deliveries_query);

// Fetch orders that need delivery assignment
$orders_query = "SELECT o.Order_ID, o.order_date, c.customer_name, o.delivery_address
                 FROM orders o
                 INNER JOIN customers c ON o.Customer_ID = c.Customer_ID
                 WHERE o.order_status IN ('Confirmed', 'Scheduled for Delivery')
                 AND o.Order_ID NOT IN (SELECT Order_ID FROM delivery WHERE Order_ID IS NOT NULL)
                 ORDER BY o.order_date DESC
                 LIMIT 50";
$orders_result = $conn->query($orders_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Management - VIP Villanueva Ice Plant</title>
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
        .status-Scheduled { background: #dbeafe; color: #1e40af; }
        .status-In-Transit { background: #fef3c7; color: #92400e; }
        .status-Delivered { background: #d1fae5; color: #065f46; }
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
            font-size: 0.875rem;
        }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-secondary { background: #6b7280; color: white; }
        .btn-success { background: #10b981; color: white; }
        .btn:hover { opacity: 0.9; }
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
                <a href="delivery.php" class="menu-item active">
                    <i class="fas fa-truck"></i>
                    <span>Delivery</span>
                </a>
            </div>
        </nav>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <div class="container">
            <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <div>
                    <h1><i class="fas fa-truck"></i> Delivery Management</h1>
                    <p style="color: #6b7280; margin-top: 0.5rem;">Manage deliveries and track delivery status.</p>
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

            <!-- Deliveries List -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-list"></i> Deliveries</h3>
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
                                    <th>Scheduled Date</th>
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
                                        <td><?php echo $delivery['schedule_date'] ? date('M d, Y', strtotime($delivery['schedule_date'])) : 'N/A'; ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo str_replace(' ', '-', $delivery['delivery_status']); ?>">
                                                <?php echo htmlspecialchars($delivery['delivery_status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($delivery['delivery_status'] !== 'Delivered'): ?>
                                                <button onclick="updateDeliveryStatus(<?php echo $delivery['Delivery_ID']; ?>, '<?php echo $delivery['delivery_status']; ?>')" class="btn btn-primary btn-sm">
                                                    <i class="fas fa-edit"></i> Update Status
                                                </button>
                                            <?php else: ?>
                                                <a href="sales.php" class="btn btn-success btn-sm">
                                                    <i class="fas fa-money-bill-wave"></i> Record Sale
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="text-align: center; color: #6b7280; padding: 2rem;">No deliveries found.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="../assets/js/script.js"></script>
<script>
function updateDeliveryStatus(deliveryId, currentStatus) {
    const statuses = ['Scheduled', 'In Transit', 'Delivered'];
    const currentIndex = statuses.indexOf(currentStatus);
    const nextStatus = currentIndex < statuses.length - 1 ? statuses[currentIndex + 1] : currentStatus;
    
    if (confirm(`Update delivery status from "${currentStatus}" to "${nextStatus}"?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '../api/delivery_backend.php';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'update_delivery_status';
        form.appendChild(actionInput);
        
        const deliveryIdInput = document.createElement('input');
        deliveryIdInput.type = 'hidden';
        deliveryIdInput.name = 'delivery_id';
        deliveryIdInput.value = deliveryId;
        form.appendChild(deliveryIdInput);
        
        const statusInput = document.createElement('input');
        statusInput.type = 'hidden';
        statusInput.name = 'new_status';
        statusInput.value = nextStatus;
        form.appendChild(statusInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}
</script>
</body>
</html>
<?php
if ($deliveries_result) $deliveries_result->free();
if ($orders_result) $orders_result->free();
$conn->close();
?>
