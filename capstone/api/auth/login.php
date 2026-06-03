<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/jwt.php';

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, ['message' => 'Method not allowed'], 405);
    }

    $raw = file_get_contents('php://input');
    $body = json_decode($raw ?: '', true);
    if (!is_array($body)) $body = $_POST;

    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');

    if ($username === '' || $password === '') {
        jsonResponse(false, ['message' => 'Username and password are required.'], 400);
    }

    // NOTE: Keep consistent with existing app hashing (user_name column, sha256).
    $stmt = $conn->prepare(
        'SELECT User_ID, user_name, full_name, Role_ID, password, is_active, status FROM user WHERE user_name = ? LIMIT 1'
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $active = isset($user['is_active']) ? (int) $user['is_active'] === 1 : true;
    $statusOk = !isset($user['status']) || strtolower((string) $user['status']) !== 'inactive';

    if (!$user || hash('sha256', $password) !== (string) $user['password'] || !$active || !$statusOk) {
        jsonResponse(false, ['message' => 'Invalid username or password.'], 401);
    }

    $roleValue = $user['Role_ID'] ?? '';

    $_SESSION['user_id'] = (int)$user['User_ID'];
    $_SESSION['user_role'] = is_numeric($roleValue) ? (int)$roleValue : $roleValue;
    $_SESSION['full_name'] = (string)($user['full_name'] ?? '');
    $_SESSION['user_name'] = (string)$user['user_name'];
    $_SESSION['last_activity'] = time();
    $token = jwtIssue([
        'sub' => (int)$user['User_ID'],
        'username' => (string) $user['user_name'],
        'name' => (string)($user['full_name'] ?? ''),
        'role' => is_numeric($roleValue) ? (int)$roleValue : (string)$roleValue,
    ], 60 * 60 * 24); // 24h

    jsonResponse(true, [
        'token' => $token,
        'token_type' => 'Bearer',
        'expires_in' => 60 * 60 * 24,
        'user' => [
            'id' => (int)$user['User_ID'],
            'username' => (string) $user['user_name'],
            'name' => (string)($user['full_name'] ?? ''),
            'role' => is_numeric($roleValue) ? (int)$roleValue : (string)$roleValue,
        ],
    ]);
} catch (Throwable $e) {
    jsonResponse(false, ['message' => 'Server error', 'error' => $e->getMessage()], 500);
}

