<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db.php';

// Include backend for POST handling
require_once '../api/inventory_backend.php';

// Query to fetch inventory data with product details
$query = "SELECT p.Product_ID, p.product_name, p.form, p.unit, p.wholesale_price, p.retail_price, p.description, p.created_date, (SELECT quantity FROM stockin_inventory WHERE Product_ID = p.Product_ID ORDER BY updated_at DESC LIMIT 1) as current_quantity, (SELECT updated_at FROM stockin_inventory WHERE Product_ID = p.Product_ID ORDER BY updated_at DESC LIMIT 1) as inventory_updated_at FROM products p ORDER BY p.created_at DESC";
$result = $conn->query($query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/inventory.css">
    <link rel="stylesheet" href="../assets/css/style.css">
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
                <a href="inventory.php" class="menu-item active">
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
    <main class="main-content" id="mainContent">
        <button class="mobile-sidebar-toggle" id="mobileSidebarToggle" aria-label="Toggle sidebar">
            <i class="fas fa-bars"></i>
        </button>
        <!-- Inventory Header -->
        <section class="inventory-header">
            <div class="inventory-header-content">
                <h1 class="inventory-title">
                    <i class="fas fa-cubes"></i>
                    Inventory Management
                </h1>
                <p class="inventory-subtitle">View and manage your inventory stocks efficiently</p>
            </div>
        </section>

        <!-- Search and Filter Controls -->
        <section class="inventory-controls">
            <div class="search-filter-grid">
                <div class="search-group">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" class="search-input" placeholder="Search products..." id="searchInput">
                </div>
                <select class="filter-select" id="categoryFilter">
                    <option value="">All Categories</option>
                    <option value="ice">Ice Products</option>
                    <option value="beverages">Beverages</option>
                    <option value="other">Other</option>
                </select>
                <select class="filter-select" id="stockFilter">
                    <option value="">All Stock Levels</option>
                    <option value="low">Low Stock (< 10)</option>
                    <option value="medium">Medium Stock (10-50)</option>
                    <option value="high">High Stock (> 50)</option>
                </select>
                <a href="products_add.php" class="btn-add-product">
                    <i class="fas fa-plus"></i>
                    Add Product
                </a>
            </div>
        </section>

        <!-- Inventory Stats -->
        <section class="inventory-stats">
            <div class="stat-card">
                <div class="stat-content">
                    <div class="stat-info">
                        <p>Total Products</p>
                        <h3 id="inventoryTotalProducts"><?php echo $result->num_rows; ?></h3>
                        <div class="stat-change neutral">
                            <i class="fas fa-circle"></i>
                            <span id="inventoryActiveProducts"><?php echo $result->num_rows; ?></span> active products
                        </div>
                    </div>
                    <div class="stat-icon purple">
                        <i class="fas fa-cubes"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-content">
                    <div class="stat-info">
                        <p>Low Stock Items</p>
                        <h3 id="inventoryLowStock">0</h3>
                        <div class="stat-change negative">
                            <i class="fas fa-exclamation-triangle"></i>
                            Items below threshold
                        </div>
                    </div>
                    <div class="stat-icon amber">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-content">
                    <div class="stat-info">
                        <p>Total Value</p>
                        <h3 id="inventoryTotalValue">₱0.00</h3>
                        <div class="stat-change neutral">
                            <i class="fas fa-dollar-sign"></i>
                            Inventory value
                        </div>
                    </div>
                    <div class="stat-icon green">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-content">
                    <div class="stat-info">
                        <p>Out of Stock</p>
                        <h3 id="inventoryOutOfStock">0</h3>
                        <div class="stat-change neutral">
                            <i class="fas fa-times-circle"></i>
                            Requires attention
                        </div>
                    </div>
                    <div class="stat-icon pink">
                        <i class="fas fa-times-circle"></i>
                    </div>
                </div>
            </div>
        </section>

        <!-- Inventory Table -->
        <section class="inventory-table-container">
            <?php if ($result->num_rows > 0): ?>
                <div class="table-responsive">
                    <table class="inventory-table" id="inventoryTable">
                        <thead>
                            <tr>
                                <th>Product Name</th>
                                <th>Form</th>
                                <th>Unit</th>
                                <th>Wholesale Price</th>
                                <th>Retail Price</th>
                                <th>Description</th>
                                <th>Quantity</th>
                                <th>Created Date</th>
                                <th>Last Updated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <div class="product-name">
                                            <i class="fas fa-box"></i>
                                            <?php echo htmlspecialchars($row['product_name']); ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['form'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($row['unit']); ?></td>
                                    <td class="price-cell"><?php echo htmlspecialchars($row['wholesale_price']); ?></td>
                                    <td class="price-cell"><?php echo htmlspecialchars($row['retail_price']); ?></td>
                                    <td><?php echo htmlspecialchars($row['description'] ?? '-'); ?></td>
                                    <td>
                                        <?php
                                        $quantity = $row['current_quantity'] ?? 0;
                                        $quantityClass = 'quantity-high';
                                        if ($quantity < 10) {
                                            $quantityClass = 'quantity-low';
                                        } elseif ($quantity < 50) {
                                            $quantityClass = 'quantity-medium';
                                        }
                                        ?>
                                        <span class="quantity-cell <?php echo $quantityClass; ?>">
                                            <?php echo htmlspecialchars($quantity); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['created_date'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($row['inventory_updated_at'] ?? '-'); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="products_edit.php?id=<?php echo $row['Product_ID']; ?>" class="btn-edit">
                                                <i class="fas fa-edit"></i>
                                                Edit
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-cubes"></i>
                    <h3>No Products Found</h3>
                    <p>You haven't added any products to your inventory yet. Start by adding your first product.</p>
                    <a href="products_add.php" class="btn-add-product">
                        <i class="fas fa-plus"></i>
                        Add Your First Product
                    </a>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>

<script src="../assets/js/script.js"></script>
<script src="../assets/js/inventory.js"></script>
<script>
// Load inventory statistics
document.addEventListener('DOMContentLoaded', function() {
    loadInventoryStats();
});

async function loadInventoryStats() {
    try {
        const response = await fetch('../api/inventory_stats.php');
        const result = await response.json();
        
        if (result.success) {
            const data = result.data;
            
            document.getElementById('inventoryTotalProducts').textContent = data.total_products;
            document.getElementById('inventoryActiveProducts').textContent = data.total_products;
            document.getElementById('inventoryLowStock').textContent = data.low_stock;
            document.getElementById('inventoryTotalValue').textContent = formatCurrency(data.total_value);
            document.getElementById('inventoryOutOfStock').textContent = data.out_of_stock;
        }
    } catch (error) {
        console.error('Error loading inventory stats:', error);
    }
}

function formatCurrency(amount) {
    return '₱' + parseFloat(amount).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}
</script>
</body>
</html>
<?php
$result->free();
$conn->close();
?>
