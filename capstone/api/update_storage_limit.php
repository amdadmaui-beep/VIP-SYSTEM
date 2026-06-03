<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/logger.php';
require_once '../includes/roles_helper.php';
require_once '../includes/csrf.php';

// Allow Owner (1), Manager (2), Admin (4), and Inventory Staff roles
$staff_ids = getInventoryStaffRoleIds($conn);
$allowed_roles = array_unique(array_merge([1, 2, 4], $staff_ids));
requireRole($allowed_roles);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (!validateCsrfToken(false)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired security token. Please refresh the page and try again.']);
    exit;
}

$product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
$new_storage_limit = isset($_POST['storage_limit']) ? (float)$_POST['storage_limit'] : 0;
$user_id = (int)($_SESSION['user_id'] ?? 0);

// Validation
if ($product_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product ID.']);
    exit;
}

if ($new_storage_limit <= 0) {
    echo json_encode(['success' => false, 'message' => 'Storage limit must be greater than 0.']);
    exit;
}

// Check if product exists
$product_stmt = $conn->prepare("SELECT Product_ID, product_name FROM products WHERE Product_ID = ?");
$product_stmt->execute([$product_id]);
$product = $product_stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    echo json_encode(['success' => false, 'message' => 'Product not found.']);
    exit;
}

// Get current quantity from latest stockin_inventory record
$qty_stmt = $conn->prepare("SELECT Inventory_ID, quantity FROM stockin_inventory WHERE Product_ID = ? ORDER BY updated_at DESC, Inventory_ID DESC LIMIT 1");
$qty_stmt->execute([$product_id]);
$inv_row = $qty_stmt->fetch(PDO::FETCH_ASSOC);

$current_quantity = $inv_row ? (float)$inv_row['quantity'] : 0;

// CRITICAL: Block if new storage limit is less than current quantity
try {
    $conn->beginTransaction();

    $handled_by = (int)($_SESSION['user_id'] ?? 0);

    if ($inv_row && !empty($inv_row['Inventory_ID'])) {
        // Update existing record
        $upd_stmt = $conn->prepare("UPDATE stockin_inventory SET storage_limit = ?, updated_at = NOW() WHERE Inventory_ID = ?");
        $upd_stmt->execute([$new_storage_limit, (int)$inv_row['Inventory_ID']]);
    } else {
        // Insert new record with 0 quantity if no inventory record exists
        $ins_stmt = $conn->prepare("INSERT INTO stockin_inventory (Product_ID, date_in, handled_by, quantity, storage_limit) VALUES (?, CURDATE(), ?, 0, ?)");
        $ins_stmt->execute([$product_id, $handled_by, $new_storage_limit]);
    }

    $conn->commit();

    logActivity('INVENTORY', "Updated storage limit for {$product['product_name']} to {$new_storage_limit}", $product_id);

    echo json_encode([
        'success' => true,
        'message' => 'Storage limit updated successfully.',
        'product_name' => $product['product_name'],
        'storage_limit' => $new_storage_limit,
        'current_quantity' => $current_quantity
    ]);
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Error updating storage limit: ' . $e->getMessage()]);
}
