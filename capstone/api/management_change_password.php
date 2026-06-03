<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/password_security.php';
require_once __DIR__ . '/../includes/csrf.php';

const CHANGE_CODE_TTL_MINUTES = 5;
const MAX_CODE_ATTEMPTS = 5;
const CODE_LOCK_MINUTES = 5;

function validateStrongPassword(string $password): ?string
{
    if (strlen($password) < 10) return 'Password must be at least 10 characters.';
    if (strlen($password) > 100) return 'Password must not exceed 100 characters.';
    if (!preg_match('/[A-Z]/', $password)) return 'Password must include at least one uppercase letter.';
    if (!preg_match('/[a-z]/', $password)) return 'Password must include at least one lowercase letter.';
    if (!preg_match('/\d/', $password)) return 'Password must include at least one number.';
    if (!preg_match('/[^A-Za-z0-9]/', $password)) return 'Password must include at least one special character.';
    return null;
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

function ensureChangeCodeStorage(PDO $conn): void
{
    $conn->exec("
        CREATE TABLE IF NOT EXISTS password_change_codes (
            Change_ID INT AUTO_INCREMENT PRIMARY KEY,
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

    if (!columnExists($conn, 'password_change_codes', 'attempt_count')) {
        $conn->exec("ALTER TABLE password_change_codes ADD COLUMN attempt_count INT NOT NULL DEFAULT 0");
    }
    if (!columnExists($conn, 'password_change_codes', 'locked_until')) {
        $conn->exec("ALTER TABLE password_change_codes ADD COLUMN locked_until DATETIME NULL");
    }
}

function getCurrentUserProfile(PDO $conn, int $userId): ?array
{
    try {
        $stmt = $conn->prepare("
            SELECT u.User_ID, u.user_name, COALESCE(u.full_name, u.user_name) AS full_name, up.email
            FROM user u
            LEFT JOIN User_Profile up ON up.User_ID = u.User_ID
            WHERE u.User_ID = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) return null;
        $user['email'] = trim((string)($user['email'] ?? ''));
        return $user;
    } catch (Throwable $e) {
        return null;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, ['message' => 'Method not allowed'], 405);
}

$userId = (int)($_SESSION['user_id'] ?? 0);
// Any authenticated user can change their own password.
if ($userId <= 0) {
    jsonResponse(false, ['message' => 'Forbidden'], 403);
}

try {
    ensureChangeCodeStorage($conn);
} catch (Throwable $e) {
    jsonResponse(false, ['message' => 'Unable to initialize verification codes storage.'], 500);
}

$action = trim((string)($_POST['action'] ?? ''));

if (!validateCsrfToken(false)) {
    jsonResponse(false, ['message' => 'Invalid or expired security token. Please refresh the page and try again.'], 403);
}

if ($action === 'send_code') {
    $oldPassword = (string)($_POST['old_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');

    if ($oldPassword === '' || $newPassword === '') {
        jsonResponse(false, ['message' => 'Old password and new password are required.'], 400);
    }
    $validation = validateStrongPassword($newPassword);
    if ($validation !== null) {
        jsonResponse(false, ['message' => $validation], 400);
    }

    $user = getCurrentUserProfile($conn, $userId);
    if (!$user) {
        jsonResponse(false, ['message' => 'User not found.'], 404);
    }
    if (empty($user['email']) || !filter_var((string)$user['email'], FILTER_VALIDATE_EMAIL)) {
        jsonResponse(false, ['message' => 'No valid email is linked to your profile. Please update your email first.'], 400);
    }

    $stmt = $conn->prepare("SELECT password FROM user WHERE User_ID = ? LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !vipPasswordVerify($oldPassword, (string)$row['password'])) {
        jsonResponse(false, ['message' => 'Old password is incorrect.'], 400);
    }
    vipUpgradePasswordHashIfNeeded($conn, $userId, $oldPassword, (string)$row['password']);

    $code = (string)random_int(100000, 999999);
    $codeHash = hash('sha256', $code);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . CHANGE_CODE_TTL_MINUTES . ' minutes'));

    $ins = $conn->prepare("
        INSERT INTO password_change_codes (User_ID, email, code_hash, expires_at, attempt_count, locked_until)
        VALUES (?, ?, ?, ?, 0, NULL)
    ");
    $ins->execute([$userId, (string)$user['email'], $codeHash, $expiresAt]);

    $mail = sendPasswordChangeCodeEmail((string)$user['email'], (string)$user['full_name'], $code);
    if (empty($mail['ok'])) {
        jsonResponse(false, ['message' => (string)($mail['message'] ?? 'Unable to send email.')], 500);
    }

    jsonResponse(true, ['message' => 'Verification code sent to your email.']);
}

if ($action === 'change_password') {
    $oldPassword = (string)($_POST['old_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    $code = trim((string)($_POST['code'] ?? ''));

    if ($oldPassword === '' || $newPassword === '' || $confirmPassword === '' || $code === '') {
        jsonResponse(false, ['message' => 'All fields are required.'], 400);
    }
    if (!preg_match('/^\d{6,}$/', $code)) {
        jsonResponse(false, ['message' => 'Verification code must be at least 6 digits.'], 400);
    }
    $validation = validateStrongPassword($newPassword);
    if ($validation !== null) {
        jsonResponse(false, ['message' => $validation], 400);
    }
    if ($newPassword !== $confirmPassword) {
        jsonResponse(false, ['message' => 'Password confirmation does not match.'], 400);
    }

    $user = getCurrentUserProfile($conn, $userId);
    if (!$user || empty($user['email'])) {
        jsonResponse(false, ['message' => 'No email is linked to your profile.'], 400);
    }

    $stmt = $conn->prepare("SELECT password FROM user WHERE User_ID = ? LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || !vipPasswordVerify($oldPassword, (string)$row['password'])) {
        jsonResponse(false, ['message' => 'Old password is incorrect.'], 400);
    }

    $codeStmt = $conn->prepare("
        SELECT Change_ID, code_hash, expires_at, attempt_count, locked_until
        FROM password_change_codes
        WHERE User_ID = ? AND email = ? AND used_at IS NULL
        ORDER BY Change_ID DESC
        LIMIT 1
    ");
    $codeStmt->execute([$userId, (string)$user['email']]);
    $codeRow = $codeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$codeRow) {
        jsonResponse(false, ['message' => 'No active verification code found. Please send a new code.'], 400);
    }
    if (!empty($codeRow['locked_until']) && strtotime((string)$codeRow['locked_until']) > time()) {
        $remain = (int)ceil((strtotime((string)$codeRow['locked_until']) - time()) / 60);
        jsonResponse(false, ['message' => 'Too many invalid code attempts. Try again in ' . $remain . ' minute(s).'], 400);
    }
    if (strtotime((string)$codeRow['expires_at']) < time()) {
        jsonResponse(false, ['message' => 'Verification code has expired. Please send a new code.'], 400);
    }
    if (!hash_equals((string)$codeRow['code_hash'], hash('sha256', $code))) {
        $newAttemptCount = ((int)($codeRow['attempt_count'] ?? 0)) + 1;
        $lockUntil = null;
        if ($newAttemptCount >= MAX_CODE_ATTEMPTS) {
            $lockUntil = date('Y-m-d H:i:s', strtotime('+' . CODE_LOCK_MINUTES . ' minutes'));
        }
        $attemptStmt = $conn->prepare("UPDATE password_change_codes SET attempt_count = ?, locked_until = ? WHERE Change_ID = ?");
        $attemptStmt->execute([$newAttemptCount, $lockUntil, $codeRow['Change_ID']]);

        if ($lockUntil !== null) {
            jsonResponse(false, ['message' => 'Maximum code attempts reached. Locked for ' . CODE_LOCK_MINUTES . ' minutes.'], 400);
        }
        jsonResponse(false, ['message' => 'Invalid verification code.'], 400);
    }

    $conn->beginTransaction();
    try {
        $passwordHash = vipPasswordHash($newPassword);
        $upd = $conn->prepare("UPDATE user SET password = ?, login_attempts = 0, lock_until = NULL WHERE User_ID = ?");
        $upd->execute([$passwordHash, $userId]);

        $markUsed = $conn->prepare("UPDATE password_change_codes SET used_at = NOW() WHERE Change_ID = ?");
        $markUsed->execute([$codeRow['Change_ID']]);

        $conn->commit();
        jsonResponse(true, ['message' => 'Password updated successfully.']);
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        jsonResponse(false, ['message' => 'Unable to change password right now.'], 500);
    }
}

jsonResponse(false, ['message' => 'Unknown action.'], 400);
