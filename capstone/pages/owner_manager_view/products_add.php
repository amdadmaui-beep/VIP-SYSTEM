<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

// Accessible to Owner (1) and Manager (2, 4)
requireRole([1, 2, 4]);

$success = $_SESSION['products_add_success'] ?? null;
unset($_SESSION['products_add_success']);

$errors = $_SESSION['products_add_errors'] ?? [];
unset($_SESSION['products_add_errors']);

$old_input = $_SESSION['products_add_old_input'] ?? [];
unset($_SESSION['products_add_old_input']);

if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success = "Product added successfully!";
}

// Fetch units from database
$units_query = "SELECT unit_id, unit_name FROM units ORDER BY unit_name";
$units_result = $conn->query($units_query);
$units = [];
if ($units_result) {
    while ($row = $units_result->fetch(PDO::FETCH_ASSOC)) {
        $units[] = $row;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_name = trim($_POST['product_name']);
    $unit_id = intval($_POST['unit_id']);
    $wholesale_price = floatval($_POST['wholesale_price']);
    $retail_price = floatval($_POST['retail_price']);
    $is_discontinued = isset($_POST['is_discontinued']) ? 1 : 0;
    $description = trim($_POST['description']);

    // Basic validation
    $errors = [];
    if (empty($product_name)) $errors[] = "Product name is required.";
    if ($unit_id <= 0) $errors[] = "Unit is required.";
    if ($wholesale_price <= 0) $errors[] = "Wholesale price must be greater than 0.";
    if ($retail_price <= 0) $errors[] = "Retail price must be greater than 0.";
    
    // Validate price relationship: wholesale price must be less than retail price
    if ($wholesale_price > 0 && $retail_price > 0) {
        if ($wholesale_price >= $retail_price) {
            $errors[] = "Wholesale price must be less than retail price. Wholesale: ₱" . number_format($wholesale_price, 2) . ", Retail: ₱" . number_format($retail_price, 2);
        }
    }

    // Check for duplicate product name and unit combination
    if (!empty($product_name) && $unit_id > 0 && empty($errors)) {
        $stmt = $conn->prepare("SELECT Product_ID FROM products WHERE product_name = ? AND unit_id = ?");
        $stmt->execute([$product_name, $unit_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $errors[] = "A product with this name and unit already exists. Please choose a different name or unit.";
        }
    }

    if (empty($errors)) {
        // Insert into database (no more 'form' column in products table)
        $stmt = $conn->prepare("INSERT INTO products (product_name, unit_id, wholesale_price, retail_price, is_discontinued, description) VALUES (?, ?, ?, ?, ?, ?)");

        if ($stmt->execute([$product_name, $unit_id, $wholesale_price, $retail_price, $is_discontinued, $description])) {
            $_SESSION['products_add_success'] = "Product added successfully!";
            header("Location: products_add.php?success=1");
            exit();
        } else {
            $errors[] = "Error adding product: " . implode(', ', $stmt->errorInfo());
        }
    }

    $_SESSION['products_add_errors'] = $errors;
    $_SESSION['products_add_old_input'] = $_POST;
    header("Location: products_add.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Product - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<div class="dashboard-wrapper">
    <!-- Sidebar -->
    <?php
    require_once __DIR__ . '/../../includes/sidebar.php';
    renderSidebar($conn, ['base' => '../', 'active' => 'inventory']);
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
                <a href="#" class="menu-item">
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
        <div class="container">
            <h1>Add New Product</h1>
            <p>Enter the details for the new product.</p>

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
                            // Optionally redirect or clear form
                            window.location.href = window.location.pathname;
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

            <div class="form-container">
                <form method="POST" class="product-form">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="product_name">
                                <i class="fas fa-tag"></i> Product Name *
                            </label>
                            <input type="text" id="product_name" name="product_name" class="form-input" required value="<?php echo isset($old_input['product_name']) ? htmlspecialchars($old_input['product_name']) : ''; ?>">
                        </div>
                        <div class="form-group">
                            <label for="unit_id">
                                <i class="fas fa-weight-hanging"></i> Unit *
                            </label>
                            <select id="unit_id" name="unit_id" class="form-input" required>
                                <option value="">Select Unit</option>
                                <?php foreach ($units as $unit): ?>
                                    <option value="<?php echo $unit['unit_id']; ?>" <?php echo (isset($old_input['unit_id']) && $old_input['unit_id'] == $unit['unit_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($unit['unit_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="wholesale_price">
                                <i class="fas fa-dollar-sign"></i> Wholesale Price *
                            </label>
                            <input type="number" id="wholesale_price" name="wholesale_price" class="form-input" step="0.01" min="0" required value="<?php echo isset($old_input['wholesale_price']) ? htmlspecialchars($old_input['wholesale_price']) : ''; ?>">
                        </div>
                        <div class="form-group">
                            <label for="retail_price">
                                <i class="fas fa-dollar-sign"></i> Retail Price *
                            </label>
                            <input type="number" id="retail_price" name="retail_price" class="form-input" step="0.01" min="0" required value="<?php echo isset($old_input['retail_price']) ? htmlspecialchars($old_input['retail_price']) : ''; ?>">
                        </div>
                        <div class="form-group full-width">
                            <label for="description">
                                <i class="fas fa-file-alt"></i> Description
                            </label>
                            <textarea id="description" name="description" class="form-input" rows="4"><?php echo isset($old_input['description']) ? htmlspecialchars($old_input['description']) : ''; ?></textarea>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" id="is_discontinued" name="is_discontinued" value="1" <?php echo isset($old_input['is_discontinued']) ? 'checked' : ''; ?>>
                            <span class="checkmark"></span>
                            Is Discontinued
                        </label>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-plus"></i> Add Product
                        </button>
                        <a href="../index.php" class="btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Dashboard
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>

<script src="../assets/js/script.js"></script>
<script>
// Product form validation
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('.product-form');
    const wholesalePrice = document.getElementById('wholesale_price');
    const retailPrice = document.getElementById('retail_price');
    
    if (form && wholesalePrice && retailPrice) {
        // Real-time price validation
        function validatePrices() {
            const wholesale = parseFloat(wholesalePrice.value) || 0;
            const retail = parseFloat(retailPrice.value) || 0;
            
            // Remove previous error styling
            wholesalePrice.style.borderColor = '';
            retailPrice.style.borderColor = '';
            
            // Remove previous error message
            const existingError = document.getElementById('price-error-message');
            if (existingError) {
                existingError.remove();
            }
            
            if (wholesale > 0 && retail > 0 && wholesale >= retail) {
                // Add error styling
                wholesalePrice.style.borderColor = '#ef4444';
                retailPrice.style.borderColor = '#ef4444';
                
                // Add error message
                const errorMsg = document.createElement('div');
                errorMsg.id = 'price-error-message';
                errorMsg.style.color = '#ef4444';
                errorMsg.style.fontSize = '0.875rem';
                errorMsg.style.marginTop = '0.5rem';
                errorMsg.style.display = 'flex';
                errorMsg.style.alignItems = 'center';
                errorMsg.style.gap = '0.5rem';
                errorMsg.innerHTML = '<i class="fas fa-exclamation-circle"></i> Wholesale price must be less than retail price.';
                
                retailPrice.parentElement.appendChild(errorMsg);
                return false;
            }
            return true;
        }
        
        // Validate on input
        wholesalePrice.addEventListener('input', validatePrices);
        retailPrice.addEventListener('input', validatePrices);
        
        // Validate on form submit
        form.addEventListener('submit', function(e) {
            if (!validatePrices()) {
                e.preventDefault();
                retailPrice.focus();
                return false;
            }
        });
    }
});
</script>
</body>
</html>
