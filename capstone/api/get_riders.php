<?php
/**
 * Fetch delivery riders for dropdown
 */
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles_helper.php';

try {
    $rider_ids = getRiderRoleIds($conn);
    if (empty($rider_ids)) {
        echo json_encode(['success' => true, 'riders' => []]);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($rider_ids), '?'));
    $stmt = $conn->prepare("SELECT u.User_ID, COALESCE(u.full_name, u.user_name) as rider_name FROM user u WHERE u.Role_ID IN ($placeholders) AND u.is_active = 1 ORDER BY COALESCE(u.full_name, u.user_name)");
    $stmt->execute($rider_ids);
    $riders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'riders' => $riders]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('get_riders server error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An internal error occurred.']);
}
