<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security_headers.php';
require_once __DIR__ . '/cache.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)parse_url((string)APP_URL, PHP_URL_SCHEME)) === 'https';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!function_exists('jwtVerify')) {
    require_once __DIR__ . '/jwt.php';
}

// Roles helper (requires $conn - ensure db.php is included before auth on pages that need role redirects)
if (!function_exists('getRiderRoleIds')) {
    require_once __DIR__ . '/roles_helper.php';
}

if (!function_exists('isApiRequest')) {
    function isApiRequest(): bool {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if (strpos($script, '/api/') !== false) {
            return true;
        }
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return strpos($accept, 'application/json') !== false;
    }
}

function initApiErrorHandling(): void {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);

    set_error_handler(function ($severity, $message, $file, $line) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    set_exception_handler(function ($e) {
        error_log('Uncaught exception: ' . $e->getMessage());
        if (isApiRequest()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Server error']);
        } else {
            http_response_code(500);
            $errorFile = __DIR__ . '/../500.html';
            if (file_exists($errorFile)) {
                readfile($errorFile);
            } else {
                echo '<h1>500 — Server Error</h1><p>Something went wrong. Please contact the administrator.</p>';
            }
        }
        exit;
    });
}

function authDeny(?string $message = null, int $status = 401): void {
    if (isApiRequest()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => $message ?: 'Unauthorized',
        ]);
        exit;
    }

    $redirect_path = file_exists('login.php') ? 'login.php' : '../login.php';
    header('Location: ' . $redirect_path);
    exit;
}

function hydrateSessionFromJwtIfPresent(): void {
    if (isset($_SESSION['user_id'])) {
        return;
    }

    $token = getBearerToken();
    if (!$token) {
        return;
    }

    try {
        $claims = jwtVerify($token);
        $sub = isset($claims['sub']) ? (int)$claims['sub'] : 0;
        if ($sub <= 0) {
            return;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $sub;
        if (isset($claims['username'])) {
            $_SESSION['username'] = (string)$claims['username'];
        }
        if (isset($claims['name'])) {
            $_SESSION['full_name'] = (string)$claims['name'];
        }
        if (isset($claims['role']) && $claims['role'] !== '') {
            // Preserve existing numeric role_id behavior whenever possible.
            $_SESSION['user_role'] = is_numeric($claims['role']) ? (int)$claims['role'] : $claims['role'];
        }
        $_SESSION['last_activity'] = time();
    } catch (Throwable $e) {
        error_log('JWT hydration failed: ' . $e->getMessage());
    }
}

function getSessionIdleTimeoutSeconds(): int {
    $configured = defined('SESSION_LIFETIME') ? (int)SESSION_LIFETIME : 0;
    if ($configured <= 0) {
        $configured = 1800; // 30 minutes default idle timeout
    }
    return $configured;
}

function destroyCurrentSessionForTimeout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

function enforceSessionIdleTimeout(): void {
    if (!isset($_SESSION['user_id'])) {
        return;
    }
    $now = time();
    $lastActivity = (int)($_SESSION['last_activity'] ?? 0);
    $idleTimeout = getSessionIdleTimeoutSeconds();
    if ($lastActivity > 0 && ($now - $lastActivity) > $idleTimeout) {
        destroyCurrentSessionForTimeout();
        authDeny('Session expired due to inactivity. Please log in again.', 401);
    }
    $_SESSION['last_activity'] = $now;
}

hydrateSessionFromJwtIfPresent();
initApiErrorHandling();
enforceSessionIdleTimeout();

// Basic login check
if (!isset($_SESSION['user_id'])) {
    authDeny('Unauthorized', 401);
}

function enforceAuthenticatedUserIsActive(PDO $conn): void {
    if (!isset($_SESSION['user_id'])) {
        return;
    }

    try {
        $stmt = $conn->prepare("SELECT is_active, status FROM user WHERE User_ID = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('User active check failed: ' . $e->getMessage());
        return;
    }

    $active = $user ? ((int)($user['is_active'] ?? 1) === 1) : false;
    $statusOk = $user ? strtolower(trim((string)($user['status'] ?? 'active'))) !== 'inactive' : false;

    if (!$user || !$active || !$statusOk) {
        destroyCurrentSessionForTimeout();
        authDeny('Your account has been deactivated. Please contact an administrator.', 401);
    }
}

/**
 * Session monitoring (for manager realtime view)
 * Keeps a normalized record per PHP session.
 *
 * Table design (3NF):
 * - One row per authenticated session_id
 * - user_id references user(User_ID)
 * - Session attributes depend only on session_id
 */
function ensureUserSessionsTable(PDO $conn): void {
    // 1) Ensure table exists (without FK first for broad compatibility).
    $create = "CREATE TABLE IF NOT EXISTS user_sessions (
        session_id VARCHAR(128) PRIMARY KEY,
        User_ID INT NOT NULL,
        login_at DATETIME NOT NULL,
        last_seen_at DATETIME NOT NULL,
        logout_at DATETIME NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        last_path VARCHAR(255) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->exec($create);

    // 2) Add missing columns safely for legacy installs.
    $cols = [];
    $colStmt = $conn->query("SHOW COLUMNS FROM user_sessions");
    if ($colStmt) {
        while ($c = $colStmt->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = $c['Field'];
        }
    }

    $columnSql = [
        'session_id' => "ALTER TABLE user_sessions ADD COLUMN session_id VARCHAR(128) PRIMARY KEY",
        'User_ID' => "ALTER TABLE user_sessions ADD COLUMN User_ID INT NOT NULL",
        'login_at' => "ALTER TABLE user_sessions ADD COLUMN login_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'last_seen_at' => "ALTER TABLE user_sessions ADD COLUMN last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'logout_at' => "ALTER TABLE user_sessions ADD COLUMN logout_at DATETIME NULL",
        'is_active' => "ALTER TABLE user_sessions ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1",
        'ip_address' => "ALTER TABLE user_sessions ADD COLUMN ip_address VARCHAR(45) NULL",
        'user_agent' => "ALTER TABLE user_sessions ADD COLUMN user_agent VARCHAR(255) NULL",
        'last_path' => "ALTER TABLE user_sessions ADD COLUMN last_path VARCHAR(255) NULL",
    ];
    foreach ($columnSql as $col => $sql) {
        if (!in_array($col, $cols, true)) {
            try { $conn->exec($sql); } catch (Throwable $e) { error_log('Schema migration failed: ' . $e->getMessage()); }
        }
    }

    // 3) Ensure indexes exist.
    $indexes = [];
    $idxStmt = $conn->query("SHOW INDEX FROM user_sessions");
    if ($idxStmt) {
        while ($i = $idxStmt->fetch(PDO::FETCH_ASSOC)) {
            $indexes[] = $i['Key_name'];
        }
    }
    if (!in_array('idx_user_sessions_user', $indexes, true)) {
        try { $conn->exec("CREATE INDEX idx_user_sessions_user ON user_sessions(User_ID)"); } catch (Throwable $e) { error_log('Failed to create user_sessions index: ' . $e->getMessage()); }
    }
    if (!in_array('idx_user_sessions_last_seen', $indexes, true)) {
        try { $conn->exec("CREATE INDEX idx_user_sessions_last_seen ON user_sessions(last_seen_at)"); } catch (Throwable $e) { error_log('Failed to create user_sessions index: ' . $e->getMessage()); }
    }

    // 4) Try to add FK only when it does not already exist.
    // If schema/engine mismatch exists, skip gracefully (no hard failure).
    $fkExists = false;
    try {
        $fkStmt = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                                WHERE TABLE_SCHEMA = DATABASE()
                                  AND TABLE_NAME = 'user_sessions'
                                  AND COLUMN_NAME = 'User_ID'
                                  AND REFERENCED_TABLE_NAME = 'user'");
        if ($fkStmt && $fkStmt->fetch(PDO::FETCH_ASSOC)) {
            $fkExists = true;
        }
    } catch (Throwable $e) {
        $fkExists = true; // avoid failing on restricted metadata access
    }
    if (!$fkExists) {
        try {
            $conn->exec("ALTER TABLE user_sessions
                        ADD CONSTRAINT fk_user_sessions_user
                        FOREIGN KEY (User_ID) REFERENCES user(User_ID)
                        ON UPDATE CASCADE ON DELETE CASCADE");
        } catch (Throwable $e) {
            error_log('Failed to add user_sessions FK: ' . $e->getMessage());
        }
    }
}

function upsertCurrentUserSession(PDO $conn, ?string $lastPath = null): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $sid = session_id();
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($sid === '' || $uid <= 0) {
        return;
    }

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $path = $lastPath !== null ? trim($lastPath) : null;

    ensureUserSessionsTable($conn);

    // Upsert: keep original login_at, but refresh last_seen_at + meta.
    $sql = "INSERT INTO user_sessions (session_id, User_ID, login_at, last_seen_at, logout_at, is_active, ip_address, user_agent, last_path)
            VALUES (:sid, :uid, NOW(), NOW(), NULL, 1, :ip, :ua, :path)
            ON DUPLICATE KEY UPDATE
                User_ID = VALUES(User_ID),
                last_seen_at = NOW(),
                logout_at = NULL,
                is_active = 1,
                ip_address = VALUES(ip_address),
                user_agent = VALUES(user_agent),
                last_path = COALESCE(VALUES(last_path), last_path)";
    $clip = static function (?string $value, int $maxLen): ?string {
        if ($value === null || $value === '') return null;
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLen);
        }
        return substr($value, 0, $maxLen);
    };

    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':sid' => $sid,
        ':uid' => $uid,
        ':ip' => $ip !== '' ? $ip : null,
        ':ua' => $clip($ua, 255),
        ':path' => $clip($path, 255),
    ]);
}

// Record a baseline session row on every request (best-effort; no hard failure).
try {
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof PDO) {
        upsertCurrentUserSession($GLOBALS['conn']);
    }
} catch (Throwable $e) {
    error_log('Session monitoring failed: ' . $e->getMessage());
}

/**
 * Require specific role(s) to access a page.
 */
function requireRole($allowed_roles, $redirect = null) {
    if (!isset($_SESSION['user_id'])) {
        authDeny('Unauthorized', 401);
    }

    $user_role = $_SESSION['user_role'] ?? 0;
    
    if (!is_array($allowed_roles)) {
        $allowed_roles = [$allowed_roles];
    }
    
    if (!in_array($user_role, $allowed_roles, true)) {
        if (isApiRequest()) {
            authDeny('Forbidden', 403);
        }
        // Redirect based on their actual role if not allowed
        if ($redirect) {
            header('Location: ' . $redirect);
        } else {
            $is_subpage = !file_exists('login.php');
            $prefix = $is_subpage ? '../pages/' : 'pages/';
            $rider_ids = isset($GLOBALS['conn']) ? getRiderRoleIds($GLOBALS['conn']) : [];
            $inv_ids = isset($GLOBALS['conn']) ? getInventoryStaffRoleIds($GLOBALS['conn']) : [];
            $cashier_ids = isset($GLOBALS['conn']) ? getCashierRoleIds($GLOBALS['conn']) : [];
            $is_rider = in_array($user_role, $rider_ids);
            $is_inv_staff = in_array($user_role, $inv_ids);
            $is_cashier = in_array($user_role, $cashier_ids);
            if ($is_rider) {
                header('Location: ' . $prefix . 'rider_view.php');
            } elseif ($is_inv_staff) {
                // Inventory staff default to the preparation board.
                header('Location: ' . $prefix . 'inventory_staff_view.php');
            } elseif ($is_cashier) {
                // Cashiers default to cashier POS view
                header('Location: ' . $prefix . 'cashier_view.php');
            } else {
                header('Location: ' . ($is_subpage ? '../index.php' : 'index.php'));
            }
        }
        exit;
    }
}

/**
 * Check if current user has a specific role.
 * @param int|array $roles
 * @return bool
 */
function hasRole($roles) {
    $user_role = $_SESSION['user_role'] ?? 0;
    if (!is_array($roles)) $roles = [$roles];
    return in_array($user_role, $roles, true);
}

// ── Global loading screen injection (output buffer) ────────────────────────
if (!defined('LS_REGISTERED')) {
    define('LS_REGISTERED', true);
    ob_start(function ($html) {
        $trimmed = trim($html);
        if ($trimmed === '' || $trimmed[0] === '{' || $trimmed[0] === '[') {
            return $html;
        }
        $overlay = file_get_contents(__DIR__ . '/loading_screen.php');
        return preg_replace('/<body[^>]*>/', '$0' . "\n" . $overlay, $html, 1);
    });
}
?>
