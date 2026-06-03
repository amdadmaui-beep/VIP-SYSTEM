<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/logger.php';
require_once '../includes/csrf.php';
require_once '../includes/rate_limiter.php';
require_once '../includes/services/orders_service.php';

// Accessible to Owner (1), Manager (2), and Cashier (3)
requireRole([1, 2, 3]);

enforceRateLimit(rateLimitKey('orders'), 60, 60);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // If this backend is included by orders.php on a GET request, do nothing.
    // Only redirect when this endpoint is accessed directly.
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === 'orders_backend.php') {
        header("Location: ../pages/orders.php?error=Invalid request method");
        exit();
    }
    return;
}

$state_changing_actions = ['create_order', 'update_order', 'reorder_order', 'update_status', 'assign_delivery', 'cancel_order'];
$action = (string)($_POST['action'] ?? '');
if (in_array($action, $state_changing_actions, true) && !validateCsrfToken(false)) {
    $error_msg = 'Invalid or expired security token. Please refresh the page and try again.';
    if (isset($_GET['ajax']) || in_array($action, ['update_status', 'assign_delivery', 'cancel_order'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => $error_msg]);
        exit();
    }
    header("Location: ../pages/orders.php?error=" . urlencode($error_msg));
    exit();
}

if (isset($_SESSION['user_role']) && (int)$_SESSION['user_role'] === 1) {
    $error_msg = "Your account (Owner) is restricted to view-only access. Operations like creating/updating orders are not allowed.";
    if (isset($_GET['ajax']) || in_array($action, ['update_status', 'assign_delivery', 'cancel_order'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => $error_msg]);
        exit();
    }
    header("Location: ../pages/orders.php?error=" . urlencode($error_msg));
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? 1);
switch ($action) {
    case 'create_order':
        ordersHandleCreateOrder($conn, $user_id);
        break;
    case 'update_status':
        ordersHandleUpdateStatus($conn, $user_id);
        break;
    case 'update_order':
        ordersHandleUpdateOrder($conn, $user_id);
        break;
    case 'reorder_order':
        ordersHandleReorderOrder($conn, $user_id);
        break;
    case 'assign_delivery':
        ordersHandleAssignDelivery($conn);
        break;
    case 'cancel_order':
        ordersHandleCancelOrder($conn, $user_id);
        break;
    default:
        header("Location: ../pages/orders.php?error=Invalid action");
        exit();
}
