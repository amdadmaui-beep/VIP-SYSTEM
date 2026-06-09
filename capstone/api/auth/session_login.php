<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/jwt.php';
require_once __DIR__ . '/../../includes/roles_helper.php';
require_once __DIR__ . '/../../includes/password_security.php';
require_once __DIR__ . '/../../includes/rate_limiter.php';
require_once __DIR__ . '/../../includes/response.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, ['message' => 'Method not allowed'], 405);
}

enforceRateLimit(rateLimitKey('login'), 5, 30);

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);
if (!is_array($body)) {
    $body = $_POST;
}

$username = trim((string) ($body['username'] ?? ''));
$password = (string) ($body['password'] ?? '');
$captchaIn = $body['captcha'] ?? '';
$captcha = is_numeric($captchaIn) ? (int) $captchaIn : null;
$csrf = (string) ($body['csrf'] ?? '');

if ($csrf === '' || !hash_equals((string) ($_SESSION['login_csrf'] ?? ''), $csrf)) {
    jsonResponse(false, ['message' => 'Invalid session. Please refresh the page.'], 403);
}

$expected = $_SESSION['login_captcha_expected'] ?? null;
if ($expected === null || $captcha !== (int) $expected) {
    vip_login_regenerate_captcha();
    jsonResponse(false, [
        'message' => 'Invalid verification answer.',
        'captcha' => vip_login_captcha_client_payload(),
    ], 400);
}

if ($username === '' || $password === '') {
    vip_login_regenerate_captcha();
    jsonResponse(false, [
        'message' => 'Username and password are required.',
        'captcha' => vip_login_captcha_client_payload(),
    ], 400);
}

try {
    $lockoutColumnsReady = vip_login_ensure_lockout_columns($conn);
    $lockoutSelect = $lockoutColumnsReady ? ', login_attempts, lock_until' : ', 0 AS login_attempts, NULL AS lock_until';
    $stmt = $conn->prepare(
        'SELECT User_ID, user_name, full_name, Role_ID, password, is_active, status' . $lockoutSelect . '
         FROM user WHERE user_name = ? LIMIT 1'
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    jsonResponse(false, ['message' => 'Server error.'], 500);
}

$genericFailure = [
    'message' => 'Invalid username or password.',
    'captcha' => vip_login_captcha_client_payload(),
];

if (!$user) {
    vip_login_regenerate_captcha();
    jsonResponse(false, $genericFailure, 401);
}

$lockUntil = trim((string)($user['lock_until'] ?? ''));
$lockUntilTs = strtotime($lockUntil);
if ($lockUntil !== '' && $lockUntilTs !== false && $lockUntilTs > time()) {
    $retryAfter = $lockUntilTs - time();
    vip_login_regenerate_captcha();
    jsonResponse(false, [
        'message' => "Too many failed login attempts. Try again in {$retryAfter} seconds.",
        'captcha' => vip_login_captcha_client_payload(),
        'retry_after' => $retryAfter,
    ], 429);
}

$active = isset($user['is_active']) ? (int) $user['is_active'] === 1 : true;
$statusOk = !isset($user['status']) || strtolower((string) $user['status']) !== 'inactive';
$passwordOk = vipPasswordVerify($password, (string)$user['password']);

if (!$passwordOk || !$active || !$statusOk) {
    vip_login_record_failed_attempt($conn, (int)$user['User_ID'], (int)($user['login_attempts'] ?? 0));
    vip_login_regenerate_captcha();
    jsonResponse(false, $genericFailure, 401);
}

$roleId = (int) ($user['Role_ID'] ?? 0);
vipUpgradePasswordHashIfNeeded($conn, (int)$user['User_ID'], $password, (string)$user['password']);
vip_login_clear_failed_attempts($conn, (int)$user['User_ID']);

session_regenerate_id(true);
$_SESSION['user_id'] = (int) $user['User_ID'];
$_SESSION['user_role'] = $roleId;
$_SESSION['full_name'] = (string) ($user['full_name'] ?? '');
$_SESSION['user_name'] = (string) ($user['user_name'] ?? '');
$_SESSION['last_activity'] = time();

unset($_SESSION['login_captcha_expected'], $_SESSION['login_captcha_n1'], $_SESSION['login_captcha_n2']);

$redirect = vip_post_login_redirect_relative($conn, $roleId);

// Auto sync rider availability on login
$riderIds = getRiderRoleIds($conn);
if (in_array($roleId, $riderIds, true)) {
    require_once __DIR__ . '/../../includes/rider_availability_helper.php';
    $hasActive = riderHasActiveDeliveries($conn, (int)$user['User_ID']);
    $newStatus = $hasActive ? 'On Delivery' : 'Available';
    ensureRiderWorkflowSchema($conn);
    $conn->prepare("INSERT INTO rider_settings (User_ID, availability_status, last_set_at)
                    VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE availability_status = VALUES(availability_status), last_set_at = NOW()")->execute([(int)$user['User_ID'], $newStatus]);
}

jsonResponse(true, [
    'message' => 'OK',
    'redirect' => $redirect,
]);

function vip_login_regenerate_captcha(): void
{
    $_SESSION['login_captcha_n1'] = random_int(1, 12);
    $_SESSION['login_captcha_n2'] = random_int(1, 12);
    $_SESSION['login_captcha_expected'] = (int) $_SESSION['login_captcha_n1'] + (int) $_SESSION['login_captcha_n2'];
}

function vip_login_captcha_client_payload(): array
{
    return [
        'n1' => (int) ($_SESSION['login_captcha_n1'] ?? 0),
        'n2' => (int) ($_SESSION['login_captcha_n2'] ?? 0),
    ];
}

function vip_login_ensure_lockout_columns(PDO $conn): bool
{
    try {
        $cols = $conn->query('SHOW COLUMNS FROM user')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('login_attempts', $cols, true)) {
            $conn->exec('ALTER TABLE user ADD COLUMN login_attempts INT NOT NULL DEFAULT 0');
        }
        if (!in_array('lock_until', $cols, true)) {
            $conn->exec('ALTER TABLE user ADD COLUMN lock_until DATETIME NULL');
        }
        $cols = $conn->query('SHOW COLUMNS FROM user')->fetchAll(PDO::FETCH_COLUMN);
        return in_array('login_attempts', $cols, true) && in_array('lock_until', $cols, true);
    } catch (Throwable $e) {
        error_log('Unable to ensure login lockout columns: ' . $e->getMessage());
        return false;
    }
}

function vip_login_record_failed_attempt(PDO $conn, int $userId, int $currentAttempts): void
{
    if ($userId <= 0) {
        return;
    }

    $nextAttempts = $currentAttempts + 1;
    $lockUntil = $nextAttempts >= 5 ? date('Y-m-d H:i:s', strtotime('+1 minute')) : null;

    try {
        $stmt = $conn->prepare('UPDATE user SET login_attempts = ?, lock_until = ? WHERE User_ID = ?');
        $stmt->execute([$nextAttempts, $lockUntil, $userId]);
    } catch (Throwable $e) {
        error_log('Unable to record failed login attempt for user ' . $userId . ': ' . $e->getMessage());
    }
}

function vip_login_clear_failed_attempts(PDO $conn, int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    try {
        $stmt = $conn->prepare('UPDATE user SET login_attempts = 0, lock_until = NULL WHERE User_ID = ?');
        $stmt->execute([$userId]);
    } catch (Throwable $e) {
        error_log('Unable to clear login attempts for user ' . $userId . ': ' . $e->getMessage());
    }
}

function vip_post_login_redirect_relative(PDO $conn, int $roleId): string
{
    $riderIds = getRiderRoleIds($conn);
    $invIds = getInventoryStaffRoleIds($conn);
    $cashierIds = getCashierRoleIds($conn);

    if ($riderIds !== [] && in_array($roleId, $riderIds, true)) {
        return 'pages/rider_view.php';
    }
    if ($invIds !== [] && in_array($roleId, $invIds, true)) {
        return 'pages/inventory_staff.php?tab=dashboard';
    }
    if ($cashierIds !== [] && in_array($roleId, $cashierIds, true)) {
        return 'pages/cashier_view.php';
    }

    return 'index.php';
}
