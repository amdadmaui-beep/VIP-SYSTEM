<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

requireRole([1, 2, 4]);

$success = $_SESSION['categories_success'] ?? null;
unset($_SESSION['categories_success']);

$errors = $_SESSION['categories_errors'] ?? [];
unset($_SESSION['categories_errors']);

// Handle Add Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add') {
        $category_name = trim($_POST['category_name']);
        if (empty($category_name)) {
            $errors[] = "Category name is required.";
        } else {
            $stmt = $conn->prepare("SELECT category_id FROM product_categories WHERE category_name = ? AND deleted_at IS NULL");
            $stmt->execute([$category_name]);
            if ($stmt->fetch()) {
                $errors[] = "A category with this name already exists.";
            } else {
                $stmt = $conn->prepare("INSERT INTO product_categories (category_name) VALUES (?)");
                if ($stmt->execute([$category_name])) {
                    require_once __DIR__ . '/../../includes/product_cache.php';
                    clearProductCache();
                    $_SESSION['categories_success'] = "Category added successfully.";
                } else {
                    $errors[] = "Error adding category.";
                }
            }
        }
        if (!empty($errors)) {
            $_SESSION['categories_errors'] = $errors;
        }
        header("Location: categories.php");
        exit;
    }

    // Handle Edit Category
    if ($_POST['action'] === 'edit') {
        $category_id = intval($_POST['category_id']);
        $category_name = trim($_POST['category_name']);
        if (empty($category_name)) {
            $errors[] = "Category name is required.";
        } elseif ($category_id <= 0) {
            $errors[] = "Invalid category.";
        } else {
            $stmt = $conn->prepare("SELECT category_id FROM product_categories WHERE category_name = ? AND category_id != ? AND deleted_at IS NULL");
            $stmt->execute([$category_name, $category_id]);
            if ($stmt->fetch()) {
                $errors[] = "Another category with this name already exists.";
            } else {
                $stmt = $conn->prepare("UPDATE product_categories SET category_name = ? WHERE category_id = ?");
                if ($stmt->execute([$category_name, $category_id])) {
                    require_once __DIR__ . '/../../includes/product_cache.php';
                    clearProductCache();
                    $_SESSION['categories_success'] = "Category updated successfully.";
                } else {
                    $errors[] = "Error updating category.";
                }
            }
        }
        if (!empty($errors)) {
            $_SESSION['categories_errors'] = $errors;
        }
        header("Location: categories.php");
        exit;
    }

    // Handle Soft Delete Category
    if ($_POST['action'] === 'delete') {
        $category_id = intval($_POST['category_id']);
        if ($category_id <= 0) {
            $_SESSION['categories_errors'] = ["Invalid category."];
        } else {
            $stmt = $conn->prepare("UPDATE product_categories SET deleted_at = NOW() WHERE category_id = ? AND deleted_at IS NULL");
            if ($stmt->execute([$category_id]) && $stmt->rowCount() > 0) {
                require_once __DIR__ . '/../../includes/product_cache.php';
                clearProductCache();
                $_SESSION['categories_success'] = "Category moved to trash. You can restore it anytime.";
            } else {
                $_SESSION['categories_errors'] = ["Error deleting category or already deleted."];
            }
        }
        header("Location: categories.php");
        exit;
    }

    // Handle Restore Category
    if ($_POST['action'] === 'restore') {
        $category_id = intval($_POST['category_id']);
        if ($category_id <= 0) {
            $_SESSION['categories_errors'] = ["Invalid category."];
        } else {
            $stmt = $conn->prepare("UPDATE product_categories SET deleted_at = NULL WHERE category_id = ?");
            if ($stmt->execute([$category_id]) && $stmt->rowCount() > 0) {
                require_once __DIR__ . '/../../includes/product_cache.php';
                clearProductCache();
                $_SESSION['categories_success'] = "Category restored successfully.";
            } else {
                $_SESSION['categories_errors'] = ["Error restoring category."];
            }
        }
        header("Location: categories.php");
        exit;
    }
}

// Fetch active categories with product counts
$active_query = "SELECT c.*, COUNT(p.Product_ID) as product_count
          FROM product_categories c
          LEFT JOIN products p ON c.category_id = p.category_id
          WHERE c.deleted_at IS NULL
          GROUP BY c.category_id
          ORDER BY c.category_name";
$active_categories = $conn->query($active_query)->fetchAll();

// Fetch deleted categories with product counts
$deleted_query = "SELECT c.*, COUNT(p.Product_ID) as product_count
          FROM product_categories c
          LEFT JOIN products p ON c.category_id = p.category_id
          WHERE c.deleted_at IS NOT NULL
          GROUP BY c.category_id
          ORDER BY c.deleted_at DESC";
$deleted_categories = $conn->query($deleted_query)->fetchAll();

// Fetch all products with category for the product viewer modal
$products_by_cat_query = "SELECT p.Product_ID, p.product_name, u.unit_name, p.wholesale_price, p.retail_price, p.category_id
          FROM products p
          LEFT JOIN units u ON p.unit_id = u.unit_id
          WHERE p.category_id IS NOT NULL
          ORDER BY p.product_name";
$products_by_cat = $conn->query($products_by_cat_query)->fetchAll();
$products_by_category = [];
foreach ($products_by_cat as $prod) {
    $cid = $prod['category_id'];
    if (!isset($products_by_category[$cid])) {
        $products_by_category[$cid] = [];
    }
    $products_by_category[$cid][] = $prod;
}
$products_json = json_encode($products_by_category);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories - VIP Villanueva Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<div class="dashboard-wrapper">
    <?php
    require_once __DIR__ . '/../../includes/sidebar.php';
    renderSidebar($conn, ['base' => '../', 'active' => 'categories']);
    ?>

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
                .banner-content { z-index: 1; }
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
                    .premium-page-banner { padding: 1.5rem; flex-direction: column; text-align: center; }
                    .banner-icon { width: 60px; height: 60px; font-size: 1.8rem; }
                    .banner-content h1 { font-size: 1.5rem; }
                    .banner-content p { font-size: 0.95rem; }
                }
                .premium-card {
                    background: #ffffff;
                    border-radius: 20px;
                    padding: 2rem;
                    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.04);
                    border: 1px solid rgba(226, 232, 240, 0.8);
                    margin-bottom: 2rem;
                }
                .card-header-premium {
                    margin-bottom: 1.5rem;
                    padding-bottom: 1rem;
                    border-bottom: 1px solid #f1f5f9;
                }
                .card-header-premium h3 {
                    font-size: 1.25rem;
                    font-weight: 700;
                    color: #1e293b;
                    display: flex;
                    align-items: center;
                    gap: 0.75rem;
                    margin: 0;
                }
                .card-header-premium h3 i {
                    color: #6366f1;
                    background: #eef2ff;
                    padding: 0.5rem;
                    border-radius: 10px;
                    font-size: 1rem;
                }
                .btn-add-category {
                    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
                    color: white;
                    border: none;
                    padding: 0.75rem 1.5rem;
                    border-radius: 12px;
                    font-size: 0.95rem;
                    font-weight: 600;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    display: inline-flex;
                    align-items: center;
                    gap: 0.5rem;
                    box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
                }
                .btn-add-category:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 8px 25px rgba(99, 102, 241, 0.4);
                }
                .badge-count {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    background: #eef2ff;
                    color: #6366f1;
                    font-size: 0.75rem;
                    font-weight: 700;
                    padding: 0.25rem 0.65rem;
                    border-radius: 20px;
                    min-width: 24px;
                }
                .action-btns { display: flex; gap: 0.5rem; }
                .action-btns button {
                    padding: 0.4rem 0.8rem;
                    border-radius: 8px;
                    font-size: 0.8rem;
                    font-weight: 600;
                    cursor: pointer;
                    border: 1px solid #e2e8f0;
                    background: white;
                    transition: all 0.2s;
                }
                .btn-view-cat { color: #0284c7; border-color: #bae6fd; }
                .btn-view-cat:hover { background: #f0f9ff; }
                .btn-edit-cat { color: #6366f1; border-color: #c7d2fe; }
                .btn-edit-cat:hover { background: #eef2ff; }
                .btn-delete-cat { color: #ef4444; border-color: #fecaca; }
                .btn-delete-cat:hover { background: #fef2f2; }
                .btn-restore-cat { color: #10b981; border-color: #a7f3d0; }
                .btn-restore-cat:hover { background: #ecfdf5; }
                .btn-show-trash { color: #dc2626; border-color: #fecaca; background: white; }
                .btn-show-trash:hover { background: #fef2f2; }
                .deleted-row { background: #fef2f2; }
                .deleted-row td { opacity: 0.75; }
                .stat-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
                .stat-card { background: white; border-radius: 16px; padding: 1.25rem 1.5rem; box-shadow: 0 4px 12px rgba(0,0,0,0.03); border: 1px solid rgba(226,232,240,0.8); display: flex; align-items: center; gap: 1rem; }
                .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; flex-shrink: 0; }
                .stat-icon.purple { background: #eef2ff; color: #6366f1; }
                .stat-icon.green { background: #ecfdf5; color: #10b981; }
                .stat-icon.red { background: #fef2f2; color: #dc2626; }
                .stat-info h3 { margin: 0; font-size: 1.5rem; font-weight: 800; color: #1e293b; line-height: 1.2; }
                .stat-info p { margin: 0; font-size: 0.8rem; color: #64748b; font-weight: 500; }
                .scrollable-table-wrapper { max-height: 480px; overflow-y: auto; overflow-x: auto; border-radius: 12px; }
                .scrollable-table-wrapper table thead { position: sticky; top: 0; background: white; z-index: 1; }
                .scrollable-table-wrapper table thead::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; border-bottom: 2px solid #f1f5f9; }
            </style>

            <div class="premium-page-banner">
                <div class="banner-icon">
                    <i class="fas fa-tags"></i>
                </div>
                <div class="banner-content">
                    <h1>Product Categories</h1>
                    <p>Manage product categories to organize your inventory by form and type.</p>
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

            <?php
            $total_active = count($active_categories);
            $total_deleted = count($deleted_categories);
            $cat_prod_count = $conn->query("SELECT COUNT(*) as cnt FROM products WHERE category_id IS NOT NULL")->fetch()['cnt'];
            ?>

            <div class="stat-cards">
                <div class="stat-card">
                    <div class="stat-icon purple">
                        <i class="fas fa-tags"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $total_active; ?></h3>
                        <p>Active Categories</p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green">
                        <i class="fas fa-box"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo intval($cat_prod_count); ?></h3>
                        <p>Categorized Products</p>
                    </div>
                </div>
                <div class="stat-card" style="cursor: pointer; <?php echo $total_deleted > 0 ? '' : 'opacity: 0.5;'; ?>" onclick="openTrashModal()" title="<?php echo $total_deleted > 0 ? 'Click to view trash' : 'No deleted categories'; ?>">
                    <div class="stat-icon red">
                        <i class="fas fa-trash-alt"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?php echo $total_deleted; ?></h3>
                        <p>In Trash</p>
                    </div>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.5rem;">
                <div>
                    <?php if ($total_deleted > 0): ?>
                    <button class="btn-show-trash" onclick="openTrashModal()" style="padding: 0.5rem 1rem; border-radius: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer; border: 1px solid #fecaca; display: inline-flex; align-items: center; gap: 0.4rem;">
                        <i class="fas fa-trash-alt"></i>
                        View Trash (<?php echo $total_deleted; ?>)
                    </button>
                    <?php endif; ?>
                </div>
                <button class="btn-add-category" onclick="document.getElementById('addCategoryModal').style.display='flex'">
                    <i class="fas fa-plus"></i> Add Category
                </button>
            </div>

            <!-- Active Categories -->
            <div class="premium-card">
                <div class="card-header-premium">
                    <h3><i class="fas fa-list"></i> Active Categories</h3>
                </div>
                <?php if (count($active_categories) > 0): ?>
                <div class="table-responsive">
                    <div class="scrollable-table-wrapper">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="border-bottom: 2px solid #f1f5f9;">
                                <th style="padding: 1rem; text-align: left; font-size: 0.85rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Category Name</th>
                                <th style="padding: 1rem; text-align: center; font-size: 0.85rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Products</th>
                                <th style="padding: 1rem; text-align: right; font-size: 0.85rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($active_categories as $cat): ?>
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 1rem; font-weight: 600; color: #1e293b;">
                                    <i class="fas fa-tag" style="color: #6366f1; margin-right: 0.5rem;"></i>
                                    <?php echo htmlspecialchars($cat['category_name']); ?>
                                </td>
                                <td style="padding: 1rem; text-align: center;">
                                    <span class="badge-count"><?php echo intval($cat['product_count']); ?></span>
                                </td>
                                <td style="padding: 1rem; text-align: right;">
                                    <div class="action-btns" style="justify-content: flex-end;">
                                        <?php if (intval($cat['product_count']) > 0): ?>
                                        <button class="btn-view-cat" onclick="viewCategoryProducts(<?php echo intval($cat['category_id']); ?>, '<?php echo htmlspecialchars($cat['category_name'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <?php endif; ?>
                                        <button class="btn-edit-cat" onclick="editCategory(<?php echo intval($cat['category_id']); ?>, '<?php echo htmlspecialchars($cat['category_name'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <button class="btn-delete-cat" onclick="deleteCategory(<?php echo intval($cat['category_id']); ?>, '<?php echo htmlspecialchars($cat['category_name'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <?php else: ?>
                <div style="text-align: center; padding: 3rem 1rem; color: #64748b;">
                    <i class="fas fa-tags" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 1rem; display: block;"></i>
                    <h3 style="color: #1e293b; margin-bottom: 0.5rem;">No Active Categories</h3>
                    <p>Click "Add Category" to create your first product category.</p>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </main>
</div>

<!-- Add Category Modal -->
<div id="addCategoryModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 20px; padding: 2rem; width: 90%; max-width: 450px; box-shadow: 0 25px 50px rgba(0,0,0,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-plus-circle" style="color: #6366f1;"></i> Add Category
            </h3>
            <button onclick="this.closest('#addCategoryModal').style.display='none'" style="background: none; border: none; font-size: 1.5rem; color: #94a3b8; cursor: pointer;">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label for="category_name">Category Name *</label>
                <input type="text" id="category_name" name="category_name" class="form-input" required placeholder="e.g. Ice Cubes">
            </div>

            <div style="display: flex; gap: 0.75rem; justify-content: flex-end;">
                <button type="button" onclick="this.closest('#addCategoryModal').style.display='none'" style="padding: 0.75rem 1.5rem; border-radius: 10px; border: 1px solid #e2e8f0; background: white; color: #64748b; font-weight: 600; cursor: pointer;">Cancel</button>
                <button type="submit" style="padding: 0.75rem 1.5rem; border-radius: 10px; border: none; background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: white; font-weight: 600; cursor: pointer; box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);">Add Category</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Category Modal -->
<div id="editCategoryModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 20px; padding: 2rem; width: 90%; max-width: 450px; box-shadow: 0 25px 50px rgba(0,0,0,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h3 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-edit" style="color: #6366f1;"></i> Edit Category
            </h3>
            <button onclick="this.closest('#editCategoryModal').style.display='none'" style="background: none; border: none; font-size: 1.5rem; color: #94a3b8; cursor: pointer;">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="category_id" id="edit_category_id">
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label for="edit_category_name">Category Name *</label>
                <input type="text" id="edit_category_name" name="category_name" class="form-input" required>
            </div>
            <div style="display: flex; gap: 0.75rem; justify-content: flex-end;">
                <button type="button" onclick="this.closest('#editCategoryModal').style.display='none'" style="padding: 0.75rem 1.5rem; border-radius: 10px; border: 1px solid #e2e8f0; background: white; color: #64748b; font-weight: 600; cursor: pointer;">Cancel</button>
                <button type="submit" style="padding: 0.75rem 1.5rem; border-radius: 10px; border: none; background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); color: white; font-weight: 600; cursor: pointer; box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);">Update Category</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Category Form -->
<form id="deleteCategoryForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="category_id" id="delete_category_id">
</form>

<!-- Restore Category Form -->
<form id="restoreCategoryForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="restore">
    <input type="hidden" name="category_id" id="restore_category_id">
</form>

<!-- View Products Modal -->
<div id="viewProductsModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 20px; padding: 2rem; width: 90%; max-width: 650px; max-height: 80vh; display: flex; flex-direction: column; box-shadow: 0 25px 50px rgba(0,0,0,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-shrink: 0;">
            <h3 id="viewProductsTitle" style="margin: 0; font-size: 1.25rem; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-box" style="color: #6366f1; background: #eef2ff; padding: 0.5rem; border-radius: 10px; font-size: 1rem;"></i>
                <span id="viewProductsTitleText">Products</span>
            </h3>
            <button onclick="document.getElementById('viewProductsModal').style.display='none'" style="background: none; border: none; font-size: 1.5rem; color: #94a3b8; cursor: pointer;">&times;</button>
        </div>
        <div id="viewProductsBody" style="overflow-y: auto; flex: 1; border-radius: 12px; min-height: 100px;">
            <div style="text-align: center; padding: 2rem; color: #94a3b8;">
                <i class="fas fa-spinner fa-spin" style="font-size: 1.5rem;"></i>
                <p>Loading...</p>
            </div>
        </div>
        <div style="display: flex; justify-content: flex-end; margin-top: 1.5rem; flex-shrink: 0;">
            <button onclick="document.getElementById('viewProductsModal').style.display='none'" style="padding: 0.75rem 1.5rem; border-radius: 10px; border: 1px solid #e2e8f0; background: white; color: #64748b; font-weight: 600; cursor: pointer;">Close</button>
        </div>
    </div>
</div>

<!-- Trash Modal -->
<div id="trashModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 20px; padding: 2rem; width: 90%; max-width: 600px; max-height: 80vh; display: flex; flex-direction: column; box-shadow: 0 25px 50px rgba(0,0,0,0.2);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-shrink: 0;">
            <h3 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: #dc2626; display: flex; align-items: center; gap: 0.5rem;">
                <i class="fas fa-trash-alt" style="background: #fef2f2; color: #dc2626; padding: 0.5rem; border-radius: 10px; font-size: 1rem;"></i>
                Trash (<?php echo count($deleted_categories); ?>)
            </h3>
            <button onclick="closeTrashModal()" style="background: none; border: none; font-size: 1.5rem; color: #94a3b8; cursor: pointer;">&times;</button>
        </div>
        <div style="overflow-y: auto; overflow-x: auto; flex: 1; border-radius: 12px;">
            <?php if (count($deleted_categories) > 0): ?>
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 2px solid #fecaca;">
                        <th style="padding: 0.75rem 1rem; text-align: left; font-size: 0.8rem; font-weight: 700; color: #991b1b; text-transform: uppercase; letter-spacing: 0.05em; position: sticky; top: 0; background: white;">Category Name</th>
                        <th style="padding: 0.75rem 1rem; text-align: center; font-size: 0.8rem; font-weight: 700; color: #991b1b; text-transform: uppercase; letter-spacing: 0.05em; position: sticky; top: 0; background: white;">Products</th>
                        <th style="padding: 0.75rem 1rem; text-align: right; font-size: 0.8rem; font-weight: 700; color: #991b1b; text-transform: uppercase; letter-spacing: 0.05em; position: sticky; top: 0; background: white;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deleted_categories as $cat): ?>
                    <tr style="border-bottom: 1px solid #fecaca;">
                        <td style="padding: 0.75rem 1rem; font-weight: 600; color: #991b1b;">
                            <i class="fas fa-tag" style="color: #fca5a5; margin-right: 0.5rem;"></i>
                            <?php echo htmlspecialchars($cat['category_name']); ?>
                        </td>
                        <td style="padding: 0.75rem 1rem; text-align: center;">
                            <span style="display: inline-flex; align-items: center; justify-content: center; background: #fee2e2; color: #dc2626; font-size: 0.75rem; font-weight: 700; padding: 0.25rem 0.65rem; border-radius: 20px; min-width: 24px;"><?php echo intval($cat['product_count']); ?></span>
                        </td>
                        <td style="padding: 0.75rem 1rem; text-align: right;">
                            <button class="btn-restore-cat" onclick="restoreCategory(<?php echo intval($cat['category_id']); ?>, '<?php echo htmlspecialchars($cat['category_name'], ENT_QUOTES); ?>')" style="padding: 0.4rem 0.8rem; border-radius: 8px; font-size: 0.8rem; font-weight: 600; cursor: pointer; border: 1px solid #a7f3d0; background: white; color: #10b981;">
                                <i class="fas fa-undo"></i> Restore
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div style="text-align: center; padding: 2rem; color: #94a3b8;">
                <i class="fas fa-trash-alt" style="font-size: 2rem; display: block; margin-bottom: 0.5rem;"></i>
                <p>No categories in trash.</p>
            </div>
            <?php endif; ?>
        </div>
        <div style="display: flex; justify-content: flex-end; margin-top: 1.5rem; flex-shrink: 0;">
            <button onclick="closeTrashModal()" style="padding: 0.75rem 1.5rem; border-radius: 10px; border: 1px solid #e2e8f0; background: white; color: #64748b; font-weight: 600; cursor: pointer;">Close</button>
        </div>
    </div>
</div>

<script>
var productsByCategory = <?php echo $products_json; ?>;

function viewCategoryProducts(categoryId, categoryName) {
    var products = productsByCategory[categoryId] || [];
    document.getElementById('viewProductsTitleText').textContent = categoryName + ' (' + products.length + ')';
    var body = document.getElementById('viewProductsBody');
    if (products.length === 0) {
        body.innerHTML = '<div style="text-align: center; padding: 2rem; color: #94a3b8;"><i class="fas fa-box-open" style="font-size: 2rem; display: block; margin-bottom: 0.5rem;"></i><p>No products in this category.</p></div>';
    } else {
        var html = '<table style="width: 100%; border-collapse: collapse;">';
        html += '<thead><tr style="border-bottom: 2px solid #e2e8f0;">';
        html += '<th style="padding: 0.75rem 1rem; text-align: left; font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; position: sticky; top: 0; background: white;">Product Name</th>';
        html += '<th style="padding: 0.75rem 1rem; text-align: center; font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; position: sticky; top: 0; background: white;">Unit</th>';
        html += '<th style="padding: 0.75rem 1rem; text-align: right; font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; position: sticky; top: 0; background: white;">Wholesale</th>';
        html += '<th style="padding: 0.75rem 1rem; text-align: right; font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; position: sticky; top: 0; background: white;">Retail</th>';
        html += '</tr></thead><tbody>';
        for (var i = 0; i < products.length; i++) {
            var p = products[i];
            html += '<tr style="border-bottom: 1px solid #f1f5f9;">';
            html += '<td style="padding: 0.75rem 1rem; font-weight: 500; color: #1e293b;"><i class="fas fa-cube" style="color: #6366f1; margin-right: 0.5rem; font-size: 0.8rem;"></i>' + escapeHtml(p.product_name) + '</td>';
            html += '<td style="padding: 0.75rem 1rem; text-align: center; color: #64748b;">' + escapeHtml(p.unit_name || '-') + '</td>';
            html += '<td style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #059669;">₱ ' + parseFloat(p.wholesale_price).toFixed(2) + '</td>';
            html += '<td style="padding: 0.75rem 1rem; text-align: right; font-weight: 600; color: #6366f1;">₱ ' + parseFloat(p.retail_price).toFixed(2) + '</td>';
            html += '</tr>';
        }
        html += '</tbody></table>';
        body.innerHTML = html;
    }
    document.getElementById('viewProductsModal').style.display = 'flex';
}

function escapeHtml(text) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}

function editCategory(id, name) {
    document.getElementById('edit_category_id').value = id;
    document.getElementById('edit_category_name').value = name;
    document.getElementById('editCategoryModal').style.display = 'flex';
}

function deleteCategory(id, name) {
    Swal.fire({
        title: 'Delete Category?',
        text: `Are you sure you want to move "${name}" to trash? Products in this category won't be affected, and you can restore it later.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, move to trash',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('delete_category_id').value = id;
            document.getElementById('deleteCategoryForm').submit();
        }
    });
}

function openTrashModal() {
    document.getElementById('trashModal').style.display = 'flex';
}

function closeTrashModal() {
    document.getElementById('trashModal').style.display = 'none';
}

function restoreCategory(id, name) {
    Swal.fire({
        title: 'Restore Category?',
        text: `Restore "${name}" to active categories?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#10b981',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, restore it',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('restore_category_id').value = id;
            document.getElementById('restoreCategoryForm').submit();
        }
    });
}

// Close modals on backdrop click
document.querySelectorAll('#addCategoryModal, #editCategoryModal, #trashModal, #viewProductsModal').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.style.display = 'none';
        }
    });
});
</script>

<script src="../assets/js/script.js"></script>
</body>
</html>
