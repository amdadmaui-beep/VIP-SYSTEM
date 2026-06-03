<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/db.php';
require_once '../includes/mailer.php';
require_once '../includes/password_security.php';

header('Content-Type: application/json; charset=utf-8');

const RESET_CODE_TTL_MINUTES = 5;
const MAX_CODE_ATTEMPTS = 5;
const CODE_LOCK_MINUTES = 5;

function normalizeInput(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/\s+/', ' ', $value);
    return strtolower((string)$value);
}

function validateStrongPassword(string $password): ?string
{
    if (strlen($password) < 10) {
        return 'Password must be at least 10 characters.';
    }
    if (strlen($password) > 100) {
        return 'Password must not exceed 100 characters.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must include at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must include at least one lowercase letter.';
    }
    if (!preg_match('/\d/', $password)) {
        return 'Password must include at least one number.';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password must include at least one special character.';
    }
    return null;
}

function respond(bool $success, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.');
}

$action = trim($_POST['action'] ?? '');

$csrf = (string)($_POST['csrf'] ?? $_POST['csrf_token'] ?? '');
if ($csrf === '' || !hash_equals((string)($_SESSION['login_csrf'] ?? ''), $csrf)) {
    respond(false, 'Invalid session. Please refresh the page and try again.');
}

function columnExists(PDO $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function ensureResetStorage(PDO $conn): array
{
    try {
        // Keep schema simple and resilient (no foreign key dependency at runtime).
        $conn->exec("
            CREATE TABLE IF NOT EXISTS password_reset_codes (
                Reset_ID INT AUTO_INCREMENT PRIMARY KEY,
                User_ID INT NOT NULL,
                email VARCHAR(191) NOT NULL,
                code_hash VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                attempt_count INT NOT NULL DEFAULT 0,
                locked_until DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_email_created (User_ID, email, created_at),
                INDEX idx_expires_at (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        if (!columnExists($conn, 'password_reset_codes', 'attempt_count')) {
            $conn->exec("ALTER TABLE password_reset_codes ADD COLUMN attempt_count INT NOT NULL DEFAULT 0");
        }
        if (!columnExists($conn, 'password_reset_codes', 'locked_until')) {
            $conn->exec("ALTER TABLE password_reset_codes ADD COLUMN locked_until DATETIME NULL");
        }
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

$storage = ensureResetStorage($conn);
if (!$storage['ok']) {
    respond(false, 'Unable to initialize reset code storage. Please run database migration for password_reset_codes.');
}

function fetchUserByIdentity(PDO $conn, string $email, string $username, string $fullName): ?array
{
    // User_Profile may not exist in all environments.
    try {
        $stmt = $conn->prepare("
            SELECT u.User_ID, u.user_name, COALESCE(u.full_name, u.user_name) AS full_name, p.email
            FROM user u
            INNER JOIN User_Profile p ON p.User_ID = u.User_ID
            WHERE u.is_active = 1 AND p.email = ?
            ORDER BY u.User_ID ASC
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return null;
        }
        $user['email'] = trim((string)$user['email']);
        $dbUsername = normalizeInput((string)($user['user_name'] ?? ''));
        $dbFullName = normalizeInput((string)($user['full_name'] ?? ''));
        if ($dbUsername !== normalizeInput($username) || $dbFullName !== normalizeInput($fullName)) {
            return null;
        }
        return $user;
    } catch (Throwable $e) {
        return null;
    }
}

if ($action === 'send_code') {
    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($email === '' || $username === '' || $fullName === '') {
        respond(false, 'Username, full name, and email are required.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(false, 'Please enter a valid email address.');
    }

    try {
        $user = fetchUserByIdentity($conn, $email, $username, $fullName);

        if (!$user || empty($user['email']) || strcasecmp(trim((string)$user['email']), $email) !== 0) {
            respond(false, 'User verification failed. Please check username, full name, and email.');
        }

        $code = (string)random_int(100000, 999999); // 6-digit numeric code
        $codeHash = hash('sha256', $code);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . RESET_CODE_TTL_MINUTES . ' minutes'));

        $insert = $conn->prepare("
            INSERT INTO password_reset_codes (User_ID, email, code_hash, expires_at, attempt_count, locked_until)
            VALUES (?, ?, ?, ?, 0, NULL)
        ");
        $insert->execute([$user['User_ID'], $email, $codeHash, $expiresAt]);

        $mailResult = sendPasswordResetCodeEmail($email, (string)$user['full_name'], $code);
        if (!$mailResult['ok']) {
            respond(false, $mailResult['message']);
        }

        respond(true, 'Verification code sent to your email.');
    } catch (Throwable $e) {
        respond(false, 'Unable to process forgot password right now.');
    }
}

if ($action === 'reset_password') {
    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($email === '' || $username === '' || $fullName === '' || $code === '' || $newPassword === '' || $confirmPassword === '') {
        respond(false, 'All fields are required.');
    }
    if (!preg_match('/^\d{6,}$/', $code)) {
        respond(false, 'Verification code must be at least 6 digits.');
    }
    $passwordValidationError = validateStrongPassword($newPassword);
    if ($passwordValidationError !== null) {
        respond(false, $passwordValidationError);
    }
    if ($newPassword !== $confirmPassword) {
        respond(false, 'Password confirmation does not match.');
    }

    try {
        $user = fetchUserByIdentity($conn, $email, $username, $fullName);

        if (!$user || empty($user['email']) || strcasecmp(trim((string)$user['email']), $email) !== 0) {
            respond(false, 'Invalid reset request details.');
        }

        $codeStmt = $conn->prepare("
            SELECT Reset_ID, code_hash, expires_at, attempt_count, locked_until
            FROM password_reset_codes
            WHERE User_ID = ? AND email = ? AND used_at IS NULL
            ORDER BY Reset_ID DESC
            LIMIT 1
        ");
        $codeStmt->execute([$user['User_ID'], $email]);
        $row = $codeStmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            respond(false, 'No active reset code found. Please request a new code.');
        }
        if (!empty($row['locked_until']) && strtotime((string)$row['locked_until']) > time()) {
            $remain = ceil((strtotime((string)$row['locked_until']) - time()) / 60);
            respond(false, 'Too many invalid code attempts. Try again in ' . $remain . ' minute(s).');
        }
        if (strtotime((string)$row['expires_at']) < time()) {
            respond(false, 'Reset code has expired. Please request a new one.');
        }
        if (!hash_equals((string)$row['code_hash'], hash('sha256', $code))) {
            $newAttemptCount = ((int)($row['attempt_count'] ?? 0)) + 1;
            $lockUntil = null;
            if ($newAttemptCount >= MAX_CODE_ATTEMPTS) {
                $lockUntil = date('Y-m-d H:i:s', strtotime('+' . CODE_LOCK_MINUTES . ' minutes'));
            }
            $attemptStmt = $conn->prepare("UPDATE password_reset_codes SET attempt_count = ?, locked_until = ? WHERE Reset_ID = ?");
            $attemptStmt->execute([$newAttemptCount, $lockUntil, $row['Reset_ID']]);

            if ($lockUntil !== null) {
                respond(false, 'Maximum code attempts reached. Code is locked for ' . CODE_LOCK_MINUTES . ' minutes. Request a new code if needed.');
            }
            respond(false, 'Invalid verification code.');
        }

        $conn->beginTransaction();
        $passwordHash = vipPasswordHash($newPassword);

        $upd = $conn->prepare("UPDATE user SET password = ?, login_attempts = 0, lock_until = NULL WHERE User_ID = ?");
        $upd->execute([$passwordHash, $user['User_ID']]);

        $markUsed = $conn->prepare("UPDATE password_reset_codes SET used_at = NOW() WHERE Reset_ID = ?");
        $markUsed->execute([$row['Reset_ID']]);

        $conn->commit();

        respond(true, 'Password has been reset successfully. You can now log in.');
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        respond(false, 'Unable to reset password right now.');
    }
}

respond(false, 'Unknown action.');
