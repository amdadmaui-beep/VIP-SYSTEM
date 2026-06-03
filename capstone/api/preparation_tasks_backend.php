<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/roles_helper.php';
require_once '../includes/csrf.php';
require_once '../includes/logger.php';
require_once '../includes/preparation_tasks_helper.php';

$inventoryStaffIds = getInventoryStaffRoleIds($conn);
requireRole(empty($inventoryStaffIds) ? [0] : $inventoryStaffIds);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../pages/inventory_staff_view.php');
    exit();
}

if (!validateCsrfToken(false)) {
    header('Location: ../pages/inventory_staff_view.php?error=' . urlencode('Invalid or expired security token. Please refresh and try again.'));
    exit();
}

$orderId = (int)($_POST['order_id'] ?? 0);
$action = (string)($_POST['action'] ?? '');
$userId = (int)($_SESSION['user_id'] ?? 0);

$statusByAction = [
    'start_preparing' => 'preparing',
    'mark_ready' => 'ready',
    'reset_preparation' => 'not_started',
];

$targetStatus = $statusByAction[$action] ?? '';
$validStatuses = prepTasksGetValidStatuses($conn);

if ($targetStatus === '' || !in_array($targetStatus, $validStatuses, true)) {
    header('Location: ../pages/inventory_staff_view.php?error=' . urlencode('Invalid preparation action.'));
    exit();
}

try {
    prepTasksUpdateStatus($conn, $orderId, $targetStatus, $userId);
    if (function_exists('logActivity')) {
        logActivity('INVENTORY', "Updated preparation task for Order #{$orderId} to {$statusByAction[$action]}", $orderId);
    }
    header('Location: ../pages/inventory_staff_view.php?success=' . urlencode('Preparation task updated.'));
    exit();
} catch (Throwable $e) {
    header('Location: ../pages/inventory_staff_view.php?error=' . urlencode($e->getMessage()));
    exit();
}
