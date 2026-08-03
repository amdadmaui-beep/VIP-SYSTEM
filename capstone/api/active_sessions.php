<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles_helper.php';

header('Content-Type: application/json; charset=utf-8');
$isDebug = defined('APP_DEBUG') && APP_DEBUG;

try {
    // Only allow management roles (owner/manager IDs from DB)
    $roleId = (int)($_SESSION['user_role'] ?? 0);
    $managementIds = getManagementRoleIds($conn);
    if (empty($managementIds)) {
        // Safe fallback for legacy role setups
        $managementIds = [1, 2];
    }
    if (!in_array($roleId, $managementIds, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }

    ensureUserSessionsTable($conn);

    // Schema-safe column checks (avoid SQL errors across environments)
    $userCols = [];
    $userColsStmt = $conn->query("SHOW COLUMNS FROM user");
    if ($userColsStmt) {
        while ($c = $userColsStmt->fetch(PDO::FETCH_ASSOC)) {
            $userCols[] = $c['Field'];
        }
    }
    $hasUserStatus = in_array('status', $userCols, true);
    $userStatusSelect = $hasUserStatus ? "COALESCE(u.status, 'active') as user_status" : "'active' as user_status";

    // Consider active if seen within last 180 seconds and not logged out.
    $q = "SELECT us.session_id, us.user_id, us.login_at, us.last_seen_at, us.ip_address, us.last_path,
                 u.user_name,
                 $userStatusSelect,
                 r.role_name
          FROM user_sessions us
          INNER JOIN user u ON us.User_ID = u.User_ID
          LEFT JOIN roles r ON u.Role_ID = r.Role_ID
          WHERE us.is_active = 1
            AND us.logout_at IS NULL
            AND us.last_seen_at >= (NOW() - INTERVAL 180 SECOND)
          ORDER BY us.last_seen_at DESC
          LIMIT 200";

    $rows = $conn->query($q)->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $rows]);
} catch (Throwable $e) {
    http_response_code(500);
    $payload = ['success' => false, 'error' => 'Server error'];
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

