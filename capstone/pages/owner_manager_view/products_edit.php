<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

// Accessible to Owner (1) and Manager (2, 4)
requireRole([1, 2, 4]);

$product_id = $_GET['id'] ?? null;
if (!$product_id) {
    header("Location: inventory.php");
    exit();
}

// Fetch product details with unit join
$stmt = $conn->prepare("SELECT p.*, u.unit_name FROM products p LEFT JOIN units u ON p.unit_id = u.unit_id WHERE p.Product_ID = ?");
$stmt->execute([$product_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    header("Location: inventory.php");
    exit();
}

$storage_limit = 100;
try {
    $stmt_storage = $conn->prepare("SELECT storage_limit FROM stockin_inventory WHERE Product_ID = ? ORDER BY updated_at DESC, Inventory_ID DESC LIMIT 1");
    $stmt_storage->execute([$product_id]);
    $storage_row = $stmt_storage->fetch(PDO::FETCH_ASSOC);
    if ($storage_row && isset($storage_row['storage_limit']) && (float)$storage_row['storage_limit'] > 0) {
        $storage_limit = (float)$storage_row['storage_limit'];
    }
} catch (Throwable $e) {
    $storage_limit = 100;
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

$success = $_SESSION['products_edit_success'] ?? null;
unset($_SESSION['products_edit_success']);

$errors = $_SESSION['products_edit_errors'] ?? [];
unset($_SESSION['products_edit_errors']);

$old_input = $_SESSION['products_edit_old_input'] ?? [];
unset($_SESSION['products_edit_old_input']);

if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success = "Product updated successfully!";
}

if (!empty($old_input)) {
    $product['product_name'] = $old_input['product_name'] ?? $product['product_name'];
    $product['unit_id'] = $old_input['unit_id'] ?? $product['unit_id'];
    $product['wholesale_price'] = $old_input['wholesale_price'] ?? $product['wholesale_price'];
    $product['retail_price'] = $old_input['retail_price'] ?? $product['retail_price'];
    $product['description'] = $old_input['description'] ?? $product['description'];
    $product['is_discontinued'] = isset($old_input['is_discontinued']) ? 1 : 0;
    if (isset($old_input['storage_limit'])) {
        $storage_limit = floatval($old_input['storage_limit']);
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_name = trim($_POST['product_name']);
    $unit_id = intval($_POST['unit_id']);
    $wholesale_price = floatval($_POST['wholesale_price']);
    $retail_price = floatval($_POST['retail_price']);
    $storage_limit = floatval($_POST['storage_limit'] ?? 0);
    $is_discontinued = isset($_POST['is_discontinued']) ? 1 : 0;
    $description = trim($_POST['description']);

    // Basic validation
    $errors = [];
    if (empty($product_name)) $errors[] = "Product name is required.";
    if ($unit_id <= 0) $errors[] = "Unit is required.";
    if ($wholesale_price <= 0) $errors[] = "Wholesale price must be greater than 0.";
    if ($retail_price <= 0) $errors[] = "Retail price must be greater than 0.";
    if ($storage_limit <= 0) $errors[] = "Storage limit must be greater than 0.";
    
    // Validate price relationship: wholesale price must be less than retail price
    if ($wholesale_price > 0 && $retail_price > 0) {
        if ($wholesale_price >= $retail_price) {
            $errors[] = "Wholesale price must be less than retail price. Wholesale: ₱" . number_format($wholesale_price, 2) . ", Retail: ₱" . number_format($retail_price, 2);
        }
    }

    // Check for duplicate product name and unit combination (excluding current product)
    if (!empty($product_name) && $unit_id > 0) {
        $stmt = $conn->prepare("SELECT Product_ID FROM products WHERE product_name = ? AND unit_id = ? AND Product_ID != ?");
        $stmt->execute([$product_name, $unit_id, $product_id]);
        if ($stmt->rowCount() > 0) {
            $errors[] = "A product with this name and unit already exists. Please choose a different name or unit.";
        }
    }

    if (empty($errors)) {
        // Update database - form column is no longer edited from UI
        $update_sql = "UPDATE products SET product_name = ?, unit_id = ?, wholesale_price = ?, retail_price = ?, is_discontinued = ?, description = ? WHERE Product_ID = ?";
        $update_stmt = $conn->prepare($update_sql);
        if ($update_stmt->execute([$product_name, $unit_id, $wholesale_price, $retail_price, $is_discontinued, $description, $product_id])) {
            $check_stmt = $conn->prepare("SELECT Inventory_ID FROM stockin_inventory WHERE Product_ID = ? ORDER BY updated_at DESC, Inventory_ID DESC LIMIT 1");
            $check_stmt->execute([$product_id]);
            $inv_row = $check_stmt->fetch(PDO::FETCH_ASSOC);
            $handled_by = (int)($_SESSION['user_id'] ?? 0);

            if ($inv_row && !empty($inv_row['Inventory_ID'])) {
                $upd_storage_stmt = $conn->prepare("UPDATE stockin_inventory SET storage_limit = ?, updated_at = NOW() WHERE Inventory_ID = ?");
                $upd_storage_stmt->execute([$storage_limit, (int)$inv_row['Inventory_ID']]);
            } else {
                $ins_storage_stmt = $conn->prepare("INSERT INTO stockin_inventory (Product_ID, date_in, handled_by, quantity, storage_limit) VALUES (?, CURDATE(), ?, 0, ?)");
                $ins_storage_stmt->execute([$product_id, $handled_by, $storage_limit]);
            }

            $_SESSION['products_edit_success'] = "Product updated successfully!";
            header("Location: products_edit.php?id=$product_id&success=1");
            exit();
        } else {
            $errors[] = "Error updating product.";
        }
    }

    $_SESSION['products_edit_errors'] = $errors;
    $_SESSION['products_edit_old_input'] = $_POST;
    header("Location: products_edit.php?id=$product_id");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Product - VIP Villanueva Ice Plant</title>
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
            <h1>Edit Product</h1>
            <p>Update the details for the product.</p>

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
                            window.location.href = window.location.pathname + '?id=<?php echo $product_id; ?>';
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
                            <input type="text" id="product_name" name="product_name" class="form-input" required value="<?php echo htmlspecialchars($product['product_name']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="unit_id">
                                <i class="fas fa-weight-hanging"></i> Unit *
                            </label>
                            <select id="unit_id" name="unit_id" class="form-input" required>
                                <option value="">Select Unit</option>
                                <?php foreach ($units as $unit): ?>
                                    <option value="<?php echo $unit['unit_id']; ?>" <?php echo ($product['unit_id'] == $unit['unit_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($unit['unit_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="wholesale_price">
                                <i class="fas fa-dollar-sign"></i> Wholesale Price *
                            </label>
                            <input type="number" id="wholesale_price" name="wholesale_price" class="form-input" step="0.01" min="0" required value="<?php echo htmlspecialchars($product['wholesale_price']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="retail_price">
                                <i class="fas fa-dollar-sign"></i> Retail Price *
                            </label>
                            <input type="number" id="retail_price" name="retail_price" class="form-input" step="0.01" min="0" required value="<?php echo htmlspecialchars($product['retail_price']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="storage_limit">
                                <i class="fas fa-warehouse"></i> Storage Limit *
                            </label>
                            <input type="number" id="storage_limit" name="storage_limit" class="form-input" step="1" min="1" required value="<?php echo htmlspecialchars((string)$storage_limit); ?>">
                        </div>
                        <div class="form-group full-width">
                            <label for="description">
                                <i class="fas fa-file-alt"></i> Description
                            </label>
                            <textarea id="description" name="description" class="form-input" rows="4"><?php echo htmlspecialchars($product['description']); ?></textarea>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" id="is_discontinued" name="is_discontinued" value="1" <?php echo $product['is_discontinued'] ? 'checked' : ''; ?>>
                            <span class="checkmark"></span>
                            Is Discontinued
                        </label>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-save"></i> Update Product
                        </button>
                        <a href="inventory.php" class="btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Inventory
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
