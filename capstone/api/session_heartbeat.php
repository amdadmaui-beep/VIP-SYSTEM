<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json; charset=utf-8');
$isDebug = defined('APP_DEBUG') && APP_DEBUG;

// Optional CSRF (allow if missing for background pings, but validate when provided)
$csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if ($csrf !== null && $csrf !== '') {
    try {
        if (!validateCsrfToken(false)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }
    } catch (Throwable $e) {
        // If CSRF helper isn't available, continue as auth already validated.
    }
}

$lastPath = $_POST['path'] ?? null;

try {
    upsertCurrentUserSession($conn, is_string($lastPath) ? $lastPath : null);
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    $payload = ['success' => false, 'error' => 'Failed to update session'];
    if ($isDebug) {
        $payload['debug'] = [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
    }
    echo json_encode($payload);
}
?>

