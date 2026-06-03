<?php
/**
 * JSON snapshot for polling: detects role / module-access changes without full page refresh.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$version = getModuleAccessVersionForUser($conn, $userId);

echo json_encode([
    'success' => true,
    'version' => $version,
]);
