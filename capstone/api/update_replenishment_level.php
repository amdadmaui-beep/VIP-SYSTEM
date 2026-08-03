<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/roles_helper.php';
require_once __DIR__ . '/../includes/csrf.php';

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
$new_level = isset($_POST['replenishment_level']) ? (float)$_POST['replenishment_level'] : 0;

if ($product_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product ID.']);
    exit;
}
if ($new_level < 0) {
    echo json_encode(['success' => false, 'message' => 'Replenishment level must be 0 or greater.']);
    exit;
}

$product_stmt = $conn->prepare("SELECT Product_ID, product_name FROM products WHERE Product_ID = ?");
$product_stmt->execute([$product_id]);
$product = $product_stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    echo json_encode(['success' => false, 'message' => 'Product not found.']);
    exit;
}

try {
    $upd_stmt = $conn->prepare("UPDATE products SET safety_stock = ? WHERE Product_ID = ?");
    $upd_stmt->execute([$new_level, $product_id]);

    logActivity('INVENTORY', "Updated replenishment level for {$product['product_name']} to {$new_level}", $product_id);

    echo json_encode([
        'success' => true,
        'message' => 'Replenishment level updated successfully.',
        'product_name' => $product['product_name'],
        'replenishment_level' => $new_level
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error updating replenishment level: ' . $e->getMessage()]);
}
