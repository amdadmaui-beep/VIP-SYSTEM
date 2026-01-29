<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db.php';

if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success = "Product added successfully!";
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_name = trim($_POST['product_name']);
    $form = trim($_POST['form']);
    $unit = trim($_POST['unit']);
    $wholesale_price = floatval($_POST['wholesale_price']);
    $retail_price = floatval($_POST['retail_price']);
    $is_discontinued = isset($_POST['is_discontinued']) ? 1 : 0;
    $description = trim($_POST['description']);

    // Basic validation
    $errors = [];
    if (empty($product_name)) $errors[] = "Product name is required.";
    if (empty($form)) $errors[] = "Form is required.";
    if (empty($unit)) $errors[] = "Unit is required.";
    if ($wholesale_price <= 0) $errors[] = "Wholesale price must be greater than 0.";
    if ($retail_price <= 0) $errors[] = "Retail price must be greater than 0.";

    // Check for duplicate product name and unit combination
    if (!empty($product_name) && !empty($unit)) {
        $stmt = $conn->prepare("SELECT Product_ID FROM products WHERE product_name = ? AND unit = ?");
        $stmt->bind_param("ss", $product_name, $unit);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $errors[] = "A product with this name and unit already exists. Please choose a different name or unit.";
        }
        $stmt->close();
    }

    if (empty($errors)) {
        // Insert into database
        $stmt = $conn->prepare("INSERT INTO products (product_name, form, unit, wholesale_price, retail_price, is_discontinued, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssddis", $product_name, $form, $unit, $wholesale_price, $retail_price, $is_discontinued, $description);

        if ($stmt->execute()) {
            header("Location: products_add.php?success=1");
            exit();
        } else {
            $errors[] = "Error adding product: " . $conn->error;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Product - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
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
                <a href="inventory.php" class="menu-item">
                    <i class="fas fa-cubes"></i>
                    <span>Inventory</span>
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
            <h1>Add New Product</h1>
            <p>Enter the details for the new product.</p>

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

            <div class="form-container">
                <form method="POST" class="product-form">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="product_name">
                                <i class="fas fa-tag"></i> Product Name *
                            </label>
                            <input type="text" id="product_name" name="product_name" class="form-input" required value="<?php echo isset($_POST['product_name']) ? htmlspecialchars($_POST['product_name']) : ''; ?>">
                        </div>
                        <div class="form-group">
                            <label for="form">
                                <i class="fas fa-shapes"></i> Form *
                            </label>
                            <input type="text" id="form" name="form" class="form-input" required value="<?php echo isset($_POST['form']) ? htmlspecialchars($_POST['form']) : ''; ?>">
                        </div>
                        <div class="form-group">
                            <label for="unit">
                                <i class="fas fa-weight-hanging"></i> Unit *
                            </label>
                            <input type="text" id="unit" name="unit" class="form-input" required value="<?php echo isset($_POST['unit']) ? htmlspecialchars($_POST['unit']) : ''; ?>" placeholder="e.g., kg, pcs, liters">
                        </div>
                        <div class="form-group">
                            <label for="wholesale_price">
                                <i class="fas fa-dollar-sign"></i> Wholesale Price *
                            </label>
                            <input type="number" id="wholesale_price" name="wholesale_price" class="form-input" step="0.01" min="0" required value="<?php echo isset($_POST['wholesale_price']) ? htmlspecialchars($_POST['wholesale_price']) : ''; ?>">
                        </div>
                        <div class="form-group">
                            <label for="retail_price">
                                <i class="fas fa-dollar-sign"></i> Retail Price *
                            </label>
                            <input type="number" id="retail_price" name="retail_price" class="form-input" step="0.01" min="0" required value="<?php echo isset($_POST['retail_price']) ? htmlspecialchars($_POST['retail_price']) : ''; ?>">
                        </div>
                        <div class="form-group full-width">
                            <label for="description">
                                <i class="fas fa-file-alt"></i> Description
                            </label>
                            <textarea id="description" name="description" class="form-input" rows="4"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" id="is_discontinued" name="is_discontinued" value="1" <?php echo isset($_POST['is_discontinued']) ? 'checked' : ''; ?>>
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
</body>
</html>
