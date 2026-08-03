<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/product_form_categories.php';

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

$replenishment_level = (float)($product['safety_stock'] ?? 0);
if ($replenishment_level <= 0) $replenishment_level = 50;

// Fetch units from database
$units_query = "SELECT unit_id, unit_name FROM units ORDER BY unit_name";
$units_result = $conn->query($units_query);
$units = [];
if ($units_result) {
    while ($row = $units_result->fetch(PDO::FETCH_ASSOC)) {
        $units[] = $row;
    }
}

$has_category_column = productsTableHasCategoryId($conn);
$categories = $has_category_column ? fetchAssignableProductCategories($conn) : [];

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
    $product['category_id'] = $old_input['category_id'] ?? $product['category_id'];
    $product['wholesale_price'] = $old_input['wholesale_price'] ?? $product['wholesale_price'];
    $product['retail_price'] = $old_input['retail_price'] ?? $product['retail_price'];
    $product['description'] = $old_input['description'] ?? $product['description'];
    $product['is_discontinued'] = isset($old_input['is_discontinued']) ? 1 : 0;
    if (isset($old_input['storage_limit'])) {
        $storage_limit = floatval($old_input['storage_limit']);
    }
    if (isset($old_input['replenishment_level'])) {
        $replenishment_level = floatval($old_input['replenishment_level']);
    }
}

// Image upload path helper
$upload_dir = __DIR__ . '/../../uploads/products/';
$product_image_url = $product['product_image'] ? '../uploads/products/' . htmlspecialchars($product['product_image']) : null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken(false)) {
        $_SESSION['products_edit_errors'] = ['Invalid or expired security token. Please refresh and try again.'];
        header("Location: products_edit.php?id=" . urlencode((string)$product_id));
        exit;
    }
    $product_name = trim($_POST['product_name']);
    $unit_id = intval($_POST['unit_id']);
    $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
    $wholesale_price = floatval($_POST['wholesale_price']);
    $retail_price = floatval($_POST['retail_price']);
    $storage_limit = floatval($_POST['storage_limit'] ?? 0);
    $replenishment_level = floatval($_POST['replenishment_level'] ?? 50);
    $is_discontinued = isset($_POST['is_discontinued']) ? 1 : 0;
    $description = trim($_POST['description']);

    // Image upload/remove handling
$remove_image = isset($_POST['remove_image']) ? true : false;
$product_image = $product['product_image'];
$pending_image = null;

    if ($remove_image) {
        // Delete old file
        if ($product_image && file_exists($upload_dir . $product_image)) {
            unlink($upload_dir . $product_image);
        }
        $product_image = null;
    }

    if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
        $file_info = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($file_info, $_FILES['product_image']['tmp_name']);
        finfo_close($file_info);

        if (!in_array($mime_type, $allowed_types)) {
            $errors[] = "Product image must be JPG, PNG, or WebP.";
        } elseif ($_FILES['product_image']['size'] > 2 * 1024 * 1024) {
            $errors[] = "Product image must be 2MB or smaller.";
        } else {
            $new_filename = uniqid('prod_') . '.' . pathinfo($_FILES['product_image']['name'], PATHINFO_EXTENSION);
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            if (move_uploaded_file($_FILES['product_image']['tmp_name'], $upload_dir . $new_filename)) {
                $pending_image = $new_filename;
            } else {
                $errors[] = "Failed to upload product image.";
            }
        }
    }

    if (empty($product_name)) $errors[] = "Product name is required.";
    if ($unit_id <= 0) $errors[] = "Unit is required.";
    if ($has_category_column) {
        $category_error = validateProductCategoryId($conn, $category_id);
        if ($category_error !== null) {
            $errors[] = $category_error;
        }
    }
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
        $new_image = $pending_image ? $pending_image : ($remove_image ? null : $product_image);
        if ($has_category_column) {
            $update_sql = "UPDATE products SET product_name = ?, unit_id = ?, category_id = ?, wholesale_price = ?, retail_price = ?, safety_stock = ?, is_discontinued = ?, description = ?, product_image = ? WHERE Product_ID = ?";
            $update_params = [$product_name, $unit_id, $category_id, $wholesale_price, $retail_price, $replenishment_level, $is_discontinued, $description, $new_image, $product_id];
        } else {
            $update_sql = "UPDATE products SET product_name = ?, unit_id = ?, wholesale_price = ?, retail_price = ?, safety_stock = ?, is_discontinued = ?, description = ?, product_image = ? WHERE Product_ID = ?";
            $update_params = [$product_name, $unit_id, $wholesale_price, $retail_price, $replenishment_level, $is_discontinued, $description, $new_image, $product_id];
        }
        $update_stmt = $conn->prepare($update_sql);
        if ($update_stmt->execute($update_params)) {
            require_once __DIR__ . '/../../includes/product_cache.php';
            clearProductCache();
            // Delete old file only after successful DB update
            if ($pending_image && $product_image && file_exists($upload_dir . $product_image)) {
                unlink($upload_dir . $product_image);
            }
            if ($remove_image && !$pending_image && $product_image && file_exists($upload_dir . $product_image)) {
                unlink($upload_dir . $product_image);
            }
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
            // Clean up orphaned upload if update fails
            if ($pending_image && file_exists($upload_dir . $pending_image)) {
                unlink($upload_dir . $pending_image);
            }
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
            <style>
                .premium-page-banner {
                    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
                    border-radius: 20px;
                    padding: 2.5rem 3rem;
                    color: white;
                    margin-bottom: 2rem;
                    box-shadow: 0 15px 30px rgba(99, 102, 241, 0.2);
                    display: flex;
                    align-items: center;
                    gap: 1.5rem;
                    position: relative;
                    overflow: hidden;
                }
                .premium-page-banner::before {
                    content: '';
                    position: absolute;
                    top: -50%;
                    right: -10%;
                    width: 300px;
                    height: 300px;
                    background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, rgba(255,255,255,0) 70%);
                    border-radius: 50%;
                    pointer-events: none;
                }
                .premium-page-banner::after {
                    content: '';
                    position: absolute;
                    bottom: -30%;
                    right: 15%;
                    width: 200px;
                    height: 200px;
                    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%);
                    border-radius: 50%;
                    pointer-events: none;
                }
                .banner-icon {
                    background: rgba(255, 255, 255, 0.2);
                    backdrop-filter: blur(10px);
                    -webkit-backdrop-filter: blur(10px);
                    width: 80px;
                    height: 80px;
                    border-radius: 20px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 2.5rem;
                    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
                    border: 1px solid rgba(255, 255, 255, 0.3);
                    z-index: 1;
                }
                .banner-content {
                    z-index: 1;
                }
                .banner-content h1 {
                    font-size: 2rem;
                    font-weight: 800;
                    margin: 0 0 0.5rem 0;
                    letter-spacing: -0.5px;
                }
                .banner-content p {
                    font-size: 1.1rem;
                    opacity: 0.9;
                    margin: 0;
                    font-weight: 400;
                }
                @media (max-width: 768px) {
                    .premium-page-banner {
                        padding: 1.5rem;
                        flex-direction: column;
                        text-align: center;
                    }
                    .banner-icon {
                        width: 60px;
                        height: 60px;
                        font-size: 1.8rem;
                    }
                    .banner-content h1 { font-size: 1.5rem; }
                    .banner-content p { font-size: 0.95rem; }
                }
            </style>
            <div class="premium-page-banner">
                <div class="banner-icon">
                    <i class="fas fa-pen-to-square"></i>
                </div>
                <div class="banner-content">
                    <h1>Edit Product</h1>
                    <p>Update and manage the details for this product.</p>
                </div>
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

            <style>
.product-layout { display: grid; grid-template-columns: 2fr 1fr; gap: 2rem; align-items: start; }
@media (max-width: 992px) { .product-layout { grid-template-columns: 1fr; } }
.premium-card { background: #ffffff; border-radius: 20px; padding: 2rem; box-shadow: 0 10px 40px rgba(0, 0, 0, 0.04); border: 1px solid rgba(226, 232, 240, 0.8); margin-bottom: 2rem; transition: all 0.3s ease; }
.premium-card:hover { box-shadow: 0 15px 50px rgba(99, 102, 241, 0.08); border-color: rgba(99, 102, 241, 0.2); }
.card-header-premium { margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid #f1f5f9; }
.card-header-premium h3 { font-size: 1.25rem; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 0.75rem; margin: 0; }
.card-header-premium h3 i { color: #6366f1; background: #eef2ff; padding: 0.5rem; border-radius: 10px; font-size: 1rem; }
.custom-file-upload { border: 2px dashed #cbd5e1; border-radius: 16px; padding: 2rem; text-align: center; cursor: pointer; transition: all 0.3s ease; background: #f8fafc; position: relative; }
.custom-file-upload:hover { border-color: #6366f1; background: #f1f5f9; }
.custom-file-upload i { font-size: 2.5rem; color: #94a3b8; margin-bottom: 1rem; transition: color 0.3s ease; }
.custom-file-upload:hover i { color: #6366f1; }
.file-input-hidden { position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
.image-preview-container { margin-top: 1.5rem; border-radius: 12px; overflow: hidden; position: relative; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
.image-preview-container img { width: 100%; height: auto; display: block; object-fit: cover; }
.toggle-switch { position: relative; display: inline-block; width: 50px; height: 28px; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .4s; border-radius: 34px; }
.slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 4px; bottom: 4px; background-color: white; transition: .4s; border-radius: 50%; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
input:checked + .slider { background-color: #6366f1; }
input:checked + .slider:before { transform: translateX(22px); }
.status-label { display: flex; align-items: center; justify-content: space-between; font-weight: 600; color: #475569; padding: 0.5rem 0; }
.price-input-wrapper input { width: 100%; }
.btn-action-primary { background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: white; border: none; padding: 1rem; border-radius: 12px; font-size: 1.05rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; gap: 0.5rem; box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3); width: 100%; }
.btn-action-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(99, 102, 241, 0.4); background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); }
.btn-action-secondary { background: white; color: #475569; border: 2px solid #e2e8f0; padding: 1rem; border-radius: 12px; font-size: 1.05rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease; display: flex; align-items: center; justify-content: center; gap: 0.5rem; text-decoration: none; width: 100%; }
.btn-action-secondary:hover { border-color: #ef4444; color: #ef4444; background: #fef2f2; transform: translateY(-2px); }
.category-picker-empty { padding: 1.25rem; border-radius: 12px; background: #fffbeb; border: 1px dashed #fcd34d; color: #92400e; font-size: 0.9rem; text-align: center; }
.category-picker-empty i { display: block; font-size: 1.5rem; margin-bottom: 0.5rem; }
.category-picker-empty a { color: #4f46e5; font-weight: 600; }
.category-migration-note { padding: 1rem 1.25rem; border-radius: 12px; background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; font-size: 0.875rem; }

.current-image-wrapper { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1rem; margin-bottom: 1.5rem; text-align: center; }
.current-image-wrapper img { max-width: 150px; max-height: 150px; border-radius: 8px; object-fit: cover; box-shadow: 0 4px 10px rgba(0,0,0,0.05); margin: 0 auto; display: block; }
.remove-image-label { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; margin-top: 1rem; font-size: 0.875rem; color: #ef4444; font-weight: 500; cursor: pointer; background: white; padding: 0.5rem 1rem; border-radius: 8px; border: 1px solid #fca5a5; transition: all 0.3s ease; }
.remove-image-label:hover { background: #fee2e2; }
</style>

            <form method="POST" class="product-form" enctype="multipart/form-data">
                <?php echo csrfTokenField(); ?>
                <div class="product-layout">
                    <!-- Main Column -->
                    <div class="main-column">
                        <div class="premium-card">
                            <div class="card-header-premium">
                                <h3><i class="fas fa-box"></i> Basic Information</h3>
                            </div>
                            <div class="form-group" style="margin-bottom: 1.5rem;">
                                <label for="product_name">Product Name *</label>
                                <input type="text" id="product_name" name="product_name" class="form-input" required value="<?php echo htmlspecialchars($product['product_name']); ?>" placeholder="e.g. Premium Ice Blocks">
                            </div>
                            
                            <div class="form-group" style="margin-bottom: 1.5rem;">
                                <label for="unit_id">Unit *</label>
                                <select id="unit_id" name="unit_id" class="form-input" required>
                                    <option value="">Select Unit</option>
                                    <?php foreach ($units as $unit): ?>
                                        <option value="<?php echo $unit['unit_id']; ?>" <?php echo ($product['unit_id'] == $unit['unit_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($unit['unit_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <?php if ($has_category_column): ?>
                            <div class="form-group" style="margin-bottom: 1.5rem;">
                                <label for="category_id">Category *</label>
                                <?php renderProductCategoryPicker($categories, (int)($product['category_id'] ?? 0)); ?>
                            </div>
                            <?php else: ?>
                            <div class="category-migration-note" style="margin-bottom: 1.5rem;">
                                <i class="fas fa-info-circle"></i>
                                Product categories are not enabled on this database yet. Run the product categories migration to assign categories.
                            </div>
                            <?php endif; ?>

                            <div class="form-group">
                                <label for="description">Description</label>
                                <textarea id="description" name="description" class="form-input" rows="4" placeholder="Enter detailed product description here..."><?php echo htmlspecialchars($product['description']); ?></textarea>
                            </div>
                        </div>

                        <div class="premium-card">
                            <div class="card-header-premium">
                                <h3><i class="fas fa-tags"></i> Pricing & Storage</h3>
                            </div>
                            <div class="form-grid" style="margin-bottom: 1.5rem;">
                                <div class="form-group">
                                    <label for="wholesale_price">Wholesale Price *</label>
                                    <div class="price-input-wrapper">
                                        <input type="number" id="wholesale_price" name="wholesale_price" class="form-input" step="0.01" min="0" required value="<?php echo htmlspecialchars($product['wholesale_price']); ?>" placeholder="0.00">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="retail_price">Retail Price *</label>
                                    <div class="price-input-wrapper">
                                        <input type="number" id="retail_price" name="retail_price" class="form-input" step="0.01" min="0" required value="<?php echo htmlspecialchars($product['retail_price']); ?>" placeholder="0.00">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="replenishment_level">Replenishment Level *</label>
                                <input type="number" id="replenishment_level" name="replenishment_level" class="form-input" step="1" min="1" value="<?php echo htmlspecialchars((string)(int)$replenishment_level); ?>">
                                <small style="color:#64748b; display:block; margin-top:4px; font-size:0.75rem;">Triggers low-stock alert when current stock is at or below this level.</small>
                            </div>
                            <div class="form-group">
                                <label for="storage_limit">Storage Limit *</label>
                                <input type="number" id="storage_limit" name="storage_limit" class="form-input" step="1" min="1" required value="<?php echo htmlspecialchars((string)$storage_limit); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar Column -->
                    <div class="sidebar-column">
                        <div class="premium-card">
                            <div class="card-header-premium">
                                <h3><i class="fas fa-image"></i> Product Image</h3>
                            </div>
                            
                            <?php if ($product_image_url): ?>
                                <div class="current-image-wrapper">
                                    <img src="<?php echo $product_image_url; ?>" alt="Current product image">
                                    <label class="remove-image-label">
                                        <input type="checkbox" name="remove_image" value="1">
                                        <span>Remove current image</span>
                                    </label>
                                </div>
                            <?php endif; ?>
                            
                            <div class="custom-file-upload">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <p style="font-weight: 600; color: #1e293b; margin-bottom: 0.5rem;"><?php echo $product_image_url ? 'Replace Image' : 'Click to upload'; ?></p>
                                <p style="font-size: 0.8rem; color: #64748b;">JPG, PNG, or WebP (max. 2MB)</p>
                                <input type="file" id="product_image" name="product_image" class="file-input-hidden" accept="image/jpeg,image/png,image/webp" onchange="previewImage(this)">
                            </div>
                            <div id="imagePreview" class="image-preview-container" style="display: none;">
                                <img id="preview" src="#" alt="Preview">
                                <button type="button" onclick="clearImage()" style="position: absolute; top: 0.5rem; right: 0.5rem; background: rgba(255,255,255,0.9); border: none; border-radius: 50%; width: 30px; height: 30px; cursor: pointer; color: #ef4444; box-shadow: 0 2px 5px rgba(0,0,0,0.2);"><i class="fas fa-times"></i></button>
                            </div>
                        </div>

                        <div class="premium-card">
                            <div class="card-header-premium">
                                <h3><i class="fas fa-cog"></i> Settings</h3>
                            </div>
                            <div class="status-label">
                                <span>
                                    <span style="display: block; font-size: 0.95rem; color: #1e293b;">Discontinued</span>
                                    <span style="font-size: 0.8rem; color: #64748b; font-weight: 400;">No longer available for sale</span>
                                </span>
                                <label class="toggle-switch">
                                    <input type="checkbox" id="is_discontinued" name="is_discontinued" value="1" <?php echo $product['is_discontinued'] ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 1rem;">
                            <button type="submit" class="btn-action-primary">
                                <i class="fas fa-save"></i> Update Product
                            </button>
                            <a href="inventory.php" class="btn-action-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Inventory
                            </a>
                        </div>
                    </div>
                </div>
            </form>
            
            <script>
            function previewImage(input) {
                if (input.files && input.files[0]) {
                    var reader = new FileReader();
                    reader.onload = function(e) {
                        document.getElementById('preview').src = e.target.result;
                        document.getElementById('imagePreview').style.display = 'block';
                    }
                    reader.readAsDataURL(input.files[0]);
                }
            }

            function clearImage() {
                document.getElementById('product_image').value = '';
                document.getElementById('imagePreview').style.display = 'none';
                document.getElementById('preview').src = '#';
            }

            </script>
        </div>
    </main>
</div>

<script src="../assets/js/script.js"></script>
</body>
</html>


