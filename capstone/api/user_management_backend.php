<?php
/**
 * User Management Backend API
 * Handles user CRUD operations
 * 
 * SECURITY UPDATE: Added CSRF protection for state-changing operations
 * Location: capstone/api/user_management_backend.php
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/module_access.php';
require_once __DIR__ . '/../includes/csrf.php'; // CSRF Protection - Security Fix
require_once __DIR__ . '/../includes/password_security.php';

// Required roles: Owner (1), Manager (2)
requireRole([1, 2]);

if (!function_exists('userManagementRedirectUrl')) {
    function userManagementRedirectUrl(array $params = []): string {
        $status_filter = strtolower(trim((string)($_POST['status_filter'] ?? $_GET['status_filter'] ?? '')));
        if (in_array($status_filter, ['active', 'deactivated', 'all'], true)) {
            $params['status_filter'] = $status_filter;
        }
        $prefix = (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'user_management_backend.php') ? '../pages/' : '';
        return $prefix . 'user_management.php' . (!empty($params) ? ('?' . http_build_query($params)) : '');
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF Protection: Validate token for state-changing POST actions - Security Fix
    $state_changing_actions = ['add_user', 'edit_user', 'toggle_user', 'update_module_access', 'update_pin'];
    $action = $_POST['action'] ?? '';
    if (in_array($action, $state_changing_actions)) {
        if (!validateCsrfToken(false)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid or expired security token. Please refresh the page and try again.']);
            exit;
        }
    }

    if ($action === 'add_user') {
        // Sanitize and validate inputs
        $first_name = htmlspecialchars(trim($_POST['first_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $last_name  = htmlspecialchars(trim($_POST['last_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $user_name  = htmlspecialchars(trim($_POST['user_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $password   = $_POST['password'] ?? '';
        $email      = htmlspecialchars(trim($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8');
        $contact_no = htmlspecialchars(trim($_POST['contact_no'] ?? ''), ENT_QUOTES, 'UTF-8');
        $role_id    = (int)($_POST['role_id'] ?? 1);
        
        $full_name = trim($first_name . ' ' . $last_name);

        // Comprehensive validation
        $errors = [];
        
        // Required fields validation
        if (empty($first_name)) $errors[] = "First name is required.";
        if (empty($last_name)) $errors[] = "Last name is required.";
        if (empty($user_name)) $errors[] = "Username is required.";
        if (empty($password)) $errors[] = "Password is required.";
        
        // Length validation
        if (!empty($first_name) && strlen($first_name) > 50) $errors[] = "First name must not exceed 50 characters.";
        if (!empty($last_name) && strlen($last_name) > 50) $errors[] = "Last name must not exceed 50 characters.";
        if (!empty($user_name) && strlen($user_name) > 50) $errors[] = "Username must not exceed 50 characters.";
        if (!empty($user_name) && strlen($user_name) < 3) $errors[] = "Username must be at least 3 characters.";
        
        // Password strength validation
        if (!empty($password)) {
            if (strlen($password) < 10) $errors[] = "Password must be at least 10 characters.";
            if (strlen($password) > 100) $errors[] = "Password must not exceed 100 characters.";
            if (strlen($password) >= 10 && strlen($password) <= 100) {
                if (!preg_match('/[A-Z]/', $password)) $errors[] = "Password must include at least one uppercase letter.";
                if (!preg_match('/[a-z]/', $password)) $errors[] = "Password must include at least one lowercase letter.";
                if (!preg_match('/\d/', $password)) $errors[] = "Password must include at least one number.";
                if (!preg_match('/[^A-Za-z0-9]/', $password)) $errors[] = "Password must include at least one special character.";
            }
        }
        
        // Email validation
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format.";
        }
        if (!empty($email) && strlen($email) > 100) {
            $errors[] = "Email must not exceed 100 characters.";
        }
        
        // Contact number validation
        if (!empty($contact_no) && strlen($contact_no) > 20) {
            $errors[] = "Contact number must not exceed 20 characters.";
        }
        if (!empty($contact_no) && !preg_match('/^[0-9\s\-\+\(\)]+$/', $contact_no)) {
            $errors[] = "Contact number contains invalid characters.";
        }
        
        // Role ID validation
        if ($role_id <= 0) {
            $errors[] = "Invalid role selected.";
        } else {
            $role_check = $conn->prepare("SELECT Role_ID FROM roles WHERE Role_ID = ?");
            $role_check->execute([$role_id]);
            if (!$role_check->fetch()) {
                $errors[] = "Selected role does not exist.";
            }
        }
        
        // Duplicate username check
        if (!empty($user_name)) {
            $check_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM user WHERE user_name = ?");
            $check_stmt->execute([$user_name]);
            $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
            if ($result && intval($result['cnt']) > 0) {
                $errors[] = "Username already exists. Please choose a different username.";
            }
        }
        
        if (!empty($errors)) {
            header('Location: ' . userManagementRedirectUrl(['error' => implode(' ', $errors)]));
            exit;
        }

        $conn->beginTransaction();
        try {
            $hashed = vipPasswordHash($password);
            $stmt = $conn->prepare("INSERT INTO user (user_name, full_name, password, Role_ID, is_active, status) VALUES (?, ?, ?, ?, 1, 'active')");
            $stmt->execute([$user_name, $full_name, $hashed, $role_id]);
            $new_user_id = $conn->lastInsertId();

            // User_Profile is optional (table may not exist)
            try {
                $stmt2 = $conn->prepare("INSERT INTO User_Profile (User_ID, first_name, last_name, email, contact_no) VALUES (?, ?, ?, ?, ?)");
                $stmt2->execute([$new_user_id, $first_name, $last_name, $email, $contact_no]);
            } catch (Exception $e) {
                // Ignore if User_Profile table doesn't exist - user is still created
            }

            $conn->commit();
            logActivity('USER_MGMT', "Added new user: $user_name ($full_name)", $new_user_id);
            header('Location: ' . userManagementRedirectUrl(['success' => 'User added successfully!']));
        } catch (Exception $e) {
            $conn->rollBack();
            $err = ($e instanceof PDOException && $e->getCode() == 23000) ? 'Username already exists.' : 'Failed to add user: ' . $e->getMessage();
            header('Location: ' . userManagementRedirectUrl(['error' => $err]));
        }
        exit;
    }

    if ($action === 'edit_user') {
        // Sanitize and validate inputs
        $user_id    = (int)($_POST['user_id'] ?? 0);
        $first_name = htmlspecialchars(trim($_POST['first_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $last_name  = htmlspecialchars(trim($_POST['last_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $user_name  = htmlspecialchars(trim($_POST['user_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $email      = htmlspecialchars(trim($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8');
        $contact_no = htmlspecialchars(trim($_POST['contact_no'] ?? ''), ENT_QUOTES, 'UTF-8');
        $role_id    = (int)($_POST['role_id'] ?? 1);
        $password   = $_POST['password'] ?? '';
        
        $full_name = trim($first_name . ' ' . $last_name);

        // Comprehensive validation
        $errors = [];
        
        // Required fields validation
        if (empty($user_id) || $user_id <= 0) {
            $errors[] = "Invalid user ID.";
        } else {
            // Verify user exists
            $user_check = $conn->prepare("SELECT User_ID FROM user WHERE User_ID = ?");
            $user_check->execute([$user_id]);
            if (!$user_check->fetch()) {
                $errors[] = "User not found.";
            }
        }
        
        if (empty($first_name)) $errors[] = "First name is required.";
        if (empty($last_name)) $errors[] = "Last name is required.";
        if (empty($user_name)) $errors[] = "Username is required.";
        
        // Length validation
        if (!empty($first_name) && strlen($first_name) > 50) $errors[] = "First name must not exceed 50 characters.";
        if (!empty($last_name) && strlen($last_name) > 50) $errors[] = "Last name must not exceed 50 characters.";
        if (!empty($user_name) && strlen($user_name) > 50) $errors[] = "Username must not exceed 50 characters.";
        if (!empty($user_name) && strlen($user_name) < 3) $errors[] = "Username must be at least 3 characters.";
        
        // Password validation (if provided)
        if (!empty($password)) {
            if (strlen($password) < 10) $errors[] = "Password must be at least 10 characters.";
            if (strlen($password) > 100) $errors[] = "Password must not exceed 100 characters.";
            if (strlen($password) >= 10 && strlen($password) <= 100) {
                if (!preg_match('/[A-Z]/', $password)) $errors[] = "Password must include at least one uppercase letter.";
                if (!preg_match('/[a-z]/', $password)) $errors[] = "Password must include at least one lowercase letter.";
                if (!preg_match('/\d/', $password)) $errors[] = "Password must include at least one number.";
                if (!preg_match('/[^A-Za-z0-9]/', $password)) $errors[] = "Password must include at least one special character.";
            }
        }
        
        // Email validation
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format.";
        }
        if (!empty($email) && strlen($email) > 100) {
            $errors[] = "Email must not exceed 100 characters.";
        }
        
        // Contact number validation
        if (!empty($contact_no) && strlen($contact_no) > 20) {
            $errors[] = "Contact number must not exceed 20 characters.";
        }
        if (!empty($contact_no) && !preg_match('/^[0-9\s\-\+\(\)]+$/', $contact_no)) {
            $errors[] = "Contact number contains invalid characters.";
        }
        
        // Role ID validation
        if ($role_id <= 0) {
            $errors[] = "Invalid role selected.";
        } else {
            $role_check = $conn->prepare("SELECT Role_ID FROM roles WHERE Role_ID = ?");
            $role_check->execute([$role_id]);
            if (!$role_check->fetch()) {
                $errors[] = "Selected role does not exist.";
            }
        }
        
        // Duplicate username check (excluding current user)
        if (!empty($user_name) && !empty($user_id)) {
            $check_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM user WHERE user_name = ? AND User_ID != ?");
            $check_stmt->execute([$user_name, $user_id]);
            $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
            if ($result && intval($result['cnt']) > 0) {
                $errors[] = "Username already exists. Please choose a different username.";
            }
        }
        
        if (!empty($errors)) {
            header('Location: ' . userManagementRedirectUrl(['error' => implode(' ', $errors)]));
            exit;
        }

        $conn->beginTransaction();
        try {
            if ($password) {
                $hashed = vipPasswordHash($password);
                $stmt = $conn->prepare("UPDATE user SET full_name=?, user_name=?, password=?, Role_ID=? WHERE User_ID=?");
                $stmt->execute([$full_name, $user_name, $hashed, $role_id, $user_id]);
            } else {
                $stmt = $conn->prepare("UPDATE user SET full_name=?, user_name=?, Role_ID=? WHERE User_ID=?");
                $stmt->execute([$full_name, $user_name, $role_id, $user_id]);
            }

            // Check if profile exists (upsert)
            $check = $conn->prepare("SELECT User_ID FROM User_Profile WHERE User_ID = ?");
            $check->execute([$user_id]);
            $p_exists = $check->fetch();

            if ($p_exists) {
                $stmt2 = $conn->prepare("UPDATE User_Profile SET first_name=?, last_name=?, email=?, contact_no=? WHERE User_ID=?");
                $stmt2->execute([$first_name, $last_name, $email, $contact_no, $user_id]);
            } else {
                $stmt2 = $conn->prepare("INSERT INTO User_Profile (User_ID, first_name, last_name, email, contact_no) VALUES (?, ?, ?, ?, ?)");
                $stmt2->execute([$user_id, $first_name, $last_name, $email, $contact_no]);
            }

            $conn->commit();
            logActivity('USER_MGMT', "Updated user: $user_name", $user_id);
            header('Location: ' . userManagementRedirectUrl(['success' => 'User updated successfully!']));
        } catch (Exception $e) {
            $conn->rollBack();
            header('Location: ' . userManagementRedirectUrl(['error' => 'Failed to update user: ' . $e->getMessage()]));
        }
        exit;
    }

    if ($action === 'toggle_user') {
        $user_id   = (int)($_POST['user_id'] ?? 0);
        $is_active = (int)($_POST['is_active'] ?? 0);
        $status    = $is_active ? 'active' : 'inactive';
        $success_action = $is_active ? 'restored' : 'deactivated';

        // Prevent deactivating yourself
        if ($user_id === (int)$_SESSION['user_id']) {
            header('Location: ' . userManagementRedirectUrl(['error' => 'You cannot deactivate your own account.']));
            exit;
        }

        try {
            $check_stmt = $conn->prepare("SELECT User_ID, user_name FROM user WHERE User_ID = ?");
            $check_stmt->execute([$user_id]);
            $target_user = $check_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$target_user) {
                header('Location: ' . userManagementRedirectUrl(['error' => 'User not found.']));
                exit;
            }

            $stmt = $conn->prepare("UPDATE user SET is_active=?, status=? WHERE User_ID=?");
            $stmt->execute([$is_active, $status, $user_id]);
            $msg = $is_active ? 'User restored successfully!' : 'User deactivated successfully. The account was kept in the database.';
            logActivity('USER_MGMT', ($is_active ? "Restored" : "Deactivated") . " user: " . ($target_user['user_name'] ?? $user_id), $user_id);
            header('Location: ' . userManagementRedirectUrl(['success' => $msg, 'success_action' => $success_action]));
        } catch (Exception $e) {
            header('Location: ' . userManagementRedirectUrl(['error' => 'Failed to update user status.']));
        }
        exit;
    }

    if ($action === 'update_module_access') {
        $target_user_id = (int)($_POST['target_user_id'] ?? 0);
        $allowed_modules = $_POST['allowed_modules'] ?? [];
        if (!is_array($allowed_modules)) {
            $allowed_modules = [];
        }

        if ($target_user_id <= 0) {
            header('Location: ' . userManagementRedirectUrl(['error' => 'Invalid user selected.']));
            exit;
        }

        try {
            $check_user = $conn->prepare("SELECT u.User_ID, u.user_name, u.Role_ID, COALESCE(r.role_name,'') as role_name 
                                          FROM user u 
                                          LEFT JOIN roles r ON u.Role_ID = r.Role_ID 
                                          WHERE u.User_ID = ?");
            $check_user->execute([$target_user_id]);
            $target = $check_user->fetch(PDO::FETCH_ASSOC);
            if (!$target) {
                header('Location: ' . userManagementRedirectUrl(['error' => 'User not found.']));
                exit;
            }

            $role_allowed = getRoleDefaultModuleKeys((string)($target['role_name'] ?? ''));
            $role_allowed = array_values(array_filter($role_allowed, function ($k) {
                return isset(getManagedModuleDefinitions()[$k]);
            }));

            ensureUserModuleAccessTable($conn);
            $conn->beginTransaction();

            if (!empty($role_allowed)) {
                $ph = implode(',', array_fill(0, count($role_allowed), '?'));
                $delete_stmt = $conn->prepare("DELETE FROM user_module_access WHERE User_ID = ? AND module_key IN ($ph)");
                $delete_stmt->execute(array_merge([$target_user_id], $role_allowed));
            }

            $insert_stmt = $conn->prepare("INSERT INTO user_module_access (User_ID, module_key, is_allowed, updated_by) VALUES (?, ?, ?, ?)");
            foreach ($role_allowed as $module_key) {
                $is_allowed = in_array($module_key, $allowed_modules, true) ? 1 : 0;
                $insert_stmt->execute([$target_user_id, $module_key, $is_allowed, (int)($_SESSION['user_id'] ?? 0)]);
            }

            $conn->commit();
            logActivity('USER_MGMT', "Updated module access for user: " . ($target['user_name'] ?? ('ID ' . $target_user_id)), $target_user_id);
            header('Location: ' . userManagementRedirectUrl(['success' => 'Module access updated successfully.']));
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            header('Location: ' . userManagementRedirectUrl(['error' => 'Failed to update module access: ' . $e->getMessage()]));
        }
        exit;
    }

    if ($action === 'update_pin') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $current_pin = trim($_POST['current_pin'] ?? '');
        $new_pin = trim($_POST['new_pin'] ?? '');
        $confirm_pin = trim($_POST['confirm_pin'] ?? '');

        if ($user_id <= 0) {
            header('Location: ' . userManagementRedirectUrl(['error' => 'Invalid user selected.']));
            exit;
        }

        if (empty($current_pin) || empty($new_pin) || empty($confirm_pin)) {
            header('Location: ' . userManagementRedirectUrl(['error' => 'All PIN fields are required.']));
            exit;
        }

        if ($new_pin !== $confirm_pin) {
            header('Location: ' . userManagementRedirectUrl(['error' => 'New PIN and confirmation do not match.']));
            exit;
        }

        if (!preg_match('/^[0-9]{4,10}$/', $new_pin)) {
            header('Location: ' . userManagementRedirectUrl(['error' => 'PIN must be 4-10 digits.']));
            exit;
        }

        try {
            $conn->beginTransaction();

            // Get user info and verify they are supervisor (Owner or Manager)
            $check_user = $conn->prepare("SELECT u.User_ID, u.user_name, up.email, u.Role_ID 
                                          FROM user u 
                                          LEFT JOIN User_Profile up ON u.User_ID = up.User_ID 
                                          WHERE u.User_ID = ?");
            $check_user->execute([$user_id]);
            $target = $check_user->fetch(PDO::FETCH_ASSOC);
            
            if (!$target) {
                header('Location: ' . userManagementRedirectUrl(['error' => 'User not found.']));
                exit;
            }

            // For now, we'll assume all users can have PINs (you can add role checking later)
            // The Laravel user ID is the same as the user_id we received

            // Check if manager PIN exists
            $check_pin = $conn->prepare("SELECT pin_hash FROM manager_pins WHERE user_id = ? AND is_active = TRUE");
            $check_pin->execute([$user_id]);
            $existing_pin = $check_pin->fetch(PDO::FETCH_ASSOC);

            // Verify current PIN
            if ($existing_pin && !password_verify($current_pin, $existing_pin['pin_hash'])) {
                header('Location: ' . userManagementRedirectUrl(['error' => 'Current PIN is incorrect.']));
                exit;
            }

            // Update or insert new PIN
            $new_pin_hash = password_hash($new_pin, PASSWORD_DEFAULT);
            if ($existing_pin) {
                $update_pin = $conn->prepare("UPDATE manager_pins SET pin_hash = ?, updated_at = NOW() WHERE user_id = ?");
                $update_pin->execute([$new_pin_hash, $user_id]);
            } else {
                $insert_pin = $conn->prepare("INSERT INTO manager_pins (user_id, pin_hash, is_active, created_at, updated_at) VALUES (?, ?, TRUE, NOW(), NOW())");
                $insert_pin->execute([$user_id, $new_pin_hash]);
            }

            $conn->commit();
            logActivity('USER_MGMT', "Updated manager PIN for user: " . ($target['user_name'] ?? ('ID ' . $user_id)), $user_id);
            header('Location: ' . userManagementRedirectUrl(['success' => 'Manager PIN updated successfully.']));
            exit;
        } catch (Exception $e) {
            $conn->rollBack();
            logActivity('USER_MGMT', "Failed to update PIN for user ID $user_id: " . $e->getMessage(), $user_id);
            header('Location: ' . userManagementRedirectUrl(['error' => 'Failed to update PIN.']));
            exit;
        }
    }
}
?>
