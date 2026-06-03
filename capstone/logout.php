<?php
session_start();

// Best-effort: mark this session as logged out for active monitoring
try {
    require_once __DIR__ . '/includes/db.php';
    require_once __DIR__ . '/includes/auth.php';
    if (isset($conn) && $conn instanceof PDO && session_status() === PHP_SESSION_ACTIVE) {
        $sid = session_id();
        if ($sid !== '') {
            ensureUserSessionsTable($conn);
            $stmt = $conn->prepare("UPDATE user_sessions
                                    SET is_active = 0, logout_at = NOW()
                                    WHERE session_id = ?");
            $stmt->execute([$sid]);
        }

        // Auto mark rider as Off Duty on logout
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $roleId = (int)($_SESSION['user_role'] ?? 0);
        if ($userId > 0 && $roleId > 0) {
            require_once __DIR__ . '/includes/roles_helper.php';
            $riderIds = getRiderRoleIds($conn);
            if (in_array($roleId, $riderIds, true)) {
                $conn->prepare("UPDATE user SET rider_availability_status = 'Off Duty' WHERE User_ID = ?")->execute([$userId]);
            }
        }
    }
} catch (Throwable $e) {
    // ignore
}

$_SESSION = [];
session_regenerate_id(true);
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

header('Location: login.php');
exit;
?>
