<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/roles_helper.php';

function ensureUserProfileSchema(PDO $conn): void
{
    $conn->exec("CREATE TABLE IF NOT EXISTS User_Profile (
        User_ID INT PRIMARY KEY,
        first_name VARCHAR(100) NULL,
        last_name VARCHAR(100) NULL,
        email VARCHAR(191) NULL,
        contact_no VARCHAR(32) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $cols = [];
    $stmt = $conn->query("SHOW COLUMNS FROM User_Profile");
    if ($stmt) {
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    if (!in_array('profile_picture', $cols, true)) {
        $conn->exec("ALTER TABLE User_Profile ADD COLUMN profile_picture VARCHAR(255) NULL");
    }
}

function roleHomeHref(PDO $conn, int $roleId): string
{
    if (in_array($roleId, getRiderRoleIds($conn), true)) {
        return 'rider_view.php';
    }
    if (in_array($roleId, getInventoryStaffRoleIds($conn), true)) {
        return 'inventory_staff_view.php';
    }
    if (in_array($roleId, getManagerRoleIds($conn), true)) {
        return 'orders.php';
    }
    return '../index.php';
}

function normalizeNamePart(string $value): string
{
    return trim(preg_replace('/\s+/', ' ', $value));
}

function normalizeUsername(string $value): string
{
    return trim((string)preg_replace('/\s+/', '', $value));
}

ensureUserProfileSchema($conn);

$userId = (int)($_SESSION['user_id'] ?? 0);
$roleId = (int)($_SESSION['user_role'] ?? 0);
$homeHref = roleHomeHref($conn, $roleId);

// Determine if this is a mobile-only role (no sidebar)
$riderRoleIds    = getRiderRoleIds($conn);
$inventoryRoleIds = getInventoryStaffRoleIds($conn);
$isMobileRole = in_array($roleId, $riderRoleIds, true) || in_array($roleId, $inventoryRoleIds, true);

if ($userId <= 0) {
    header('Location: ../login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_errors = [];
    if (!validateCsrfToken(false)) {
        $post_errors[] = 'Invalid or expired security token. Please refresh and try again.';
    } else {
        $firstName = normalizeNamePart((string)($_POST['first_name'] ?? ''));
        $lastName = normalizeNamePart((string)($_POST['last_name'] ?? ''));
        $userName = normalizeUsername((string)($_POST['user_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $contactNo = trim((string)($_POST['contact_no'] ?? ''));

        if ($firstName === '') {
            $post_errors[] = 'First name is required.';
        }
        if ($lastName === '') {
            $post_errors[] = 'Last name is required.';
        }
        if ($userName === '') {
            $post_errors[] = 'Username is required.';
        } elseif (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $userName)) {
            $post_errors[] = 'Username must be 3-50 characters and can only contain letters, numbers, dot, underscore, and hyphen.';
        } else {
            $checkUserName = $conn->prepare("SELECT User_ID FROM user WHERE user_name = ? AND User_ID <> ? LIMIT 1");
            $checkUserName->execute([$userName, $userId]);
            if ($checkUserName->fetch(PDO::FETCH_ASSOC)) {
                $post_errors[] = 'Username is already taken. Please choose another username.';
            }
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $post_errors[] = 'Invalid email format.';
        }
        if ($contactNo !== '' && !preg_match('/^[0-9+\-\s()]{7,32}$/', $contactNo)) {
            $post_errors[] = 'Phone number format is invalid.';
        }

        $profilePicturePath = null;
        $removeCurrentPicture = isset($_POST['remove_profile_picture']) && $_POST['remove_profile_picture'] === '1';

        if (isset($_FILES['profile_picture']) && (int)($_FILES['profile_picture']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['profile_picture'];
            if ((int)$file['error'] !== UPLOAD_ERR_OK) {
                $post_errors[] = 'Failed to upload profile picture.';
            } else {
                $maxBytes = 2 * 1024 * 1024;
                if ((int)$file['size'] > $maxBytes) {
                    $post_errors[] = 'Profile picture must be 2MB or less.';
                }

                $tmp = (string)$file['tmp_name'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = $finfo ? (string)finfo_file($finfo, $tmp) : '';
                if ($finfo) {
                    finfo_close($finfo);
                }

                $allowed = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                ];
                if (!isset($allowed[$mime])) {
                    $post_errors[] = 'Only JPG, PNG, and WEBP images are allowed.';
                } else {
                    $ext = $allowed[$mime];
                    // Store uploads under capstone/uploads (NOT capstone/pages/uploads)
                    $dirAbs = dirname(__DIR__, 2) . '/uploads/profile_pictures';
                    if (!is_dir($dirAbs) && !mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
                        $post_errors[] = 'Unable to create profile picture directory.';
                    } else {
                        $fileName = 'profile_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        $destAbs = $dirAbs . '/' . $fileName;
                        if (!move_uploaded_file($tmp, $destAbs)) {
                            $post_errors[] = 'Unable to save uploaded image.';
                        } else {
                            $profilePicturePath = 'uploads/profile_pictures/' . $fileName;
                        }
                    }
                }
            }
        }

        if (empty($post_errors)) {
            $conn->beginTransaction();
            try {
                $fullName = trim($firstName . ' ' . $lastName);
                $updateUser = $conn->prepare("UPDATE user SET full_name = ?, user_name = ? WHERE User_ID = ?");
                $updateUser->execute([$fullName, $userName, $userId]);

                $check = $conn->prepare("SELECT profile_picture FROM User_Profile WHERE User_ID = ? LIMIT 1");
                $check->execute([$userId]);
                $existing = $check->fetch(PDO::FETCH_ASSOC);
                $oldPicture = (string)($existing['profile_picture'] ?? '');

                $newPictureValue = $oldPicture;
                if ($removeCurrentPicture) {
                    $newPictureValue = null;
                }
                if ($profilePicturePath !== null) {
                    $newPictureValue = $profilePicturePath;
                }

                if ($existing) {
                    $up = $conn->prepare("UPDATE User_Profile SET first_name = ?, last_name = ?, email = ?, contact_no = ?, profile_picture = ? WHERE User_ID = ?");
                    $up->execute([$firstName, $lastName, ($email !== '' ? $email : null), ($contactNo !== '' ? $contactNo : null), $newPictureValue, $userId]);
                } else {
                    $ins = $conn->prepare("INSERT INTO User_Profile (User_ID, first_name, last_name, email, contact_no, profile_picture) VALUES (?, ?, ?, ?, ?, ?)");
                    $ins->execute([$userId, $firstName, $lastName, ($email !== '' ? $email : null), ($contactNo !== '' ? $contactNo : null), $newPictureValue]);
                }

                $conn->commit();

                $_SESSION['full_name'] = $fullName;
                $_SESSION['user_name'] = $userName;
                $_SESSION['username'] = $userName;

                if (($removeCurrentPicture || $profilePicturePath !== null) && $oldPicture !== '' && strpos($oldPicture, 'uploads/profile_pictures/') === 0) {
                    $oldAbs = dirname(__DIR__, 2) . '/' . $oldPicture;
                    if (is_file($oldAbs)) {
                        @unlink($oldAbs);
                    }
                }

                $_SESSION['profile_success'] = 'Profile updated successfully.';
                header('Location: profile_settings.php');
                exit;
            } catch (Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $post_errors[] = 'Unable to save profile changes.';
            }
        }
    }

    // Validation errors: store in session and redirect (PRG)
    if (!empty($post_errors)) {
        $_SESSION['profile_errors'] = $post_errors;
        header('Location: profile_settings.php');
        exit;
    }
}

// Retrieve PRG flash messages
$errors = $_SESSION['profile_errors'] ?? [];
unset($_SESSION['profile_errors']);

$success = $_SESSION['profile_success'] ?? '';
unset($_SESSION['profile_success']);

$profile = [
    'user_name' => '',
    'full_name' => '',
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'contact_no' => '',
    'profile_picture' => '',
];

$stmt = $conn->prepare(
    "SELECT u.user_name, u.full_name, up.first_name, up.last_name, up.email, up.contact_no, up.profile_picture
     FROM user u
     LEFT JOIN User_Profile up ON up.User_ID = u.User_ID
     WHERE u.User_ID = ?
     LIMIT 1"
);
$stmt->execute([$userId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row) {
    $profile = array_merge($profile, $row);
}

if (($profile['first_name'] ?? '') === '' || ($profile['last_name'] ?? '') === '') {
    $nameParts = preg_split('/\s+/', (string)($profile['full_name'] ?? ''), 2);
    $profile['first_name'] = $profile['first_name'] ?: (string)($nameParts[0] ?? '');
    $profile['last_name'] = $profile['last_name'] ?: (string)($nameParts[1] ?? '');
}

$profilePicture = (string)($profile['profile_picture'] ?? '');
$profilePictureSrc = ($profilePicture !== '' && file_exists(dirname(__DIR__, 2) . '/' . $profilePicture))
    ? '../' . $profilePicture
    : '';

$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$apiBase = preg_replace('#/pages(?:/owner_manager_view)?/profile_settings\.php$#', '', $scriptName);
if (!is_string($apiBase) || $apiBase === '' || $apiBase === $scriptName) {
    $apiBase = '/capstone';
}
$changePasswordApiUrl = rtrim($apiBase, '/') . '/api/management_change_password.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Settings</title>
    <?php if (!$isMobileRole): ?>
    <link rel="stylesheet" href="../assets/css/style.css">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            margin: 0;
            min-height: 100vh;
        }
        /* ── Mobile-role: no sidebar, full-width scroll layout ── */
        <?php if ($isMobileRole): ?>
        body {
            padding: 0;
            background: linear-gradient(160deg, #ede9fe 0%, #dbeafe 50%, #e0f2fe 100%);
        }
        .mobile-page-wrap {
            width: 100%;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        /* Sticky gradient top-bar for mobile */
        .mobile-topbar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: linear-gradient(135deg, #3b82f6 0%, #6366f1 50%, #8b5cf6 100%);
            padding: 1rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            box-shadow: 0 4px 20px rgba(99,102,241,0.3);
        }
        .mobile-topbar-left { display: flex; flex-direction: column; }
        .mobile-topbar-left h1 {
            font-size: 1.1rem;
            font-weight: 700;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .mobile-topbar-left p {
            font-size: 0.75rem;
            color: rgba(255,255,255,0.8);
            margin-top: 0.2rem;
            line-height: 1.4;
        }
        .mobile-content {
            flex: 1;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        @media (min-width: 480px) {
            .mobile-content { padding: 1.25rem; }
        }
        @media (min-width: 640px) {
            .mobile-content { padding: 1.5rem; max-width: 560px; margin: 0 auto; width: 100%; }
        }
        /* Cards inside mobile layout */
        .mobile-card {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-radius: 20px;
            border: 1px solid rgba(255,255,255,0.7);
            box-shadow: 0 8px 32px rgba(99,102,241,0.10);
            padding: 1.25rem;
            transition: box-shadow 0.2s;
        }
        .mobile-card:hover { box-shadow: 0 12px 40px rgba(99,102,241,0.16); }
        /* Avatar section – center on mobile */
        .pfp-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.75rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid #e0e7ff;
            margin-bottom: 1rem;
            text-align: center;
        }
        .pfp-controls { display: flex; flex-direction: column; align-items: center; gap: 0.5rem; }
        <?php else: ?>
        body { padding: 1rem; }
        @media (min-width: 640px) { body { padding: 2rem 1rem; } }
        /* Desktop/manager profile shell */
        .profile-shell { max-width: 600px; margin: 0 auto; }
        <?php endif; ?>
        .profile-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(2,6,23,0.12);
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }
        .profile-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 1.25rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            background: linear-gradient(135deg, #3b82f6 0%, #6366f1 50%, #8b5cf6 100%);
        }
        @media (min-width: 640px) {
            .profile-head { padding: 1.5rem 2rem; }
        }
        .profile-head-left h2 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 700;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        @media (min-width: 640px) {
            .profile-head-left h2 { font-size: 1.25rem; }
        }
        .profile-head-left p {
            margin: 0.35rem 0 0 0;
            font-size: 0.8rem;
            color: rgba(255,255,255,0.8);
        }
        @media (min-width: 640px) {
            .profile-head-left p { font-size: 0.875rem; }
        }
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            color: #fff;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 500;
            padding: 0.5rem 0.75rem;
            border-radius: 8px;
            transition: all 0.2s;
            background: rgba(255,255,255,0.2);
        }
        .btn-back:hover { background: rgba(255,255,255,0.3); color: #fff; }
        .profile-body { padding: 1.25rem; }
        @media (min-width: 640px) {
            .profile-body { padding: 2rem; }
        }
        
        .alert {
            padding: 0.875rem 1rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
        }
        .alert-danger {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }
        .alert-success {
            background: #d1fae5;
            border: 1px solid #a7f3d0;
            color: #065f46;
        }
        .alert ul { margin: 0; padding-left: 1.25rem; }
        .alert li { margin-bottom: 0.25rem; }
        
        .pfp-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #f1f5f9;
            text-align: center;
        }
        @media (min-width: 640px) {
            .pfp-section {
                flex-direction: row;
                text-align: left;
                gap: 1.25rem;
                margin-bottom: 2rem;
            }
        }
        .pfp-preview {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #e2e8f0;
            background: #f8fafc;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            cursor: pointer;
            transition: all 0.2s;
        }
        .pfp-preview:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 20px rgba(99,102,241,0.3);
            border-color: #6366f1;
        }
        @media (min-width: 640px) {
            .pfp-preview { width: 100px; height: 100px; }
        }
        .pfp-fallback {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff;
            font-size: 1.75rem;
            font-weight: 700;
            border: 4px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            cursor: pointer;
            transition: all 0.2s;
        }
        .pfp-fallback:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 20px rgba(99,102,241,0.3);
            border-color: #6366f1;
        }
        @media (min-width: 640px) {
            .pfp-fallback { width: 100px; height: 100px; font-size: 2rem; }
        }
        
        /* Manager Profile Photo Modal (independent from staff/rider) */
        .mgr-pfp-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15,23,42,0.6);
            backdrop-filter: blur(4px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .mgr-pfp-modal-overlay.active { display: flex; }
        .mgr-pfp-modal {
            position: relative;
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            max-width: 320px;
            width: 100%;
            box-shadow: 0 20px 50px rgba(0,0,0,0.2);
            animation: slideUp 0.3s ease;
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .mgr-pfp-modal-title {
            font-size: 1.125rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 1rem;
            text-align: center;
        }
        .mgr-pfp-modal-btn {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            width: 100%;
            padding: 1rem;
            border-radius: 12px;
            border: none;
            background: #f8fafc;
            color: #374151;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            margin-bottom: 1.25rem;
            transition: all 0.2s;
        }
        .mgr-pfp-modal-btn:hover { background: #ecfeff; color: #0891b2; }
        .mgr-pfp-modal-btn i { font-size: 1.125rem; }
        .mgr-pfp-modal-btn.danger { color: #dc2626; }
        .mgr-pfp-modal-btn.danger:hover { background: #fef2f2; color: #dc2626; }
        .pfp-modal-close {
            display: block;
            width: 100%;
            padding: 0.875rem;
            margin-top: 1.5rem;
            border-radius: 12px;
            border: none;
            background: #e2e8f0;
            color: #64748b;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .pfp-modal-close:hover { background: #cbd5e1; color: #475569; }
        .mgr-pfp-modal-x {
            position: absolute;
            top: 1rem;
            right: 1rem;
            width: 2rem;
            height: 2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            background: transparent;
            color: #94a3b8;
            font-size: 1.125rem;
            cursor: pointer;
            border-radius: 50%;
            transition: all 0.2s;
        }
        .mgr-pfp-modal-x:hover { background: #f1f5f9; color: #475569; }
        .pfp-controls { flex: 1; }
        .pfp-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
        }

        /* Change Password Modal */
        .pwd-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,0.6);
            backdrop-filter: blur(6px);
            z-index: 1100;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .pwd-modal-overlay.active { display: flex; }
        .pwd-modal {
            position: relative;
            background: #fff;
            border-radius: 20px;
            padding: 1.25rem;
            max-width: 520px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.25);
            border: 1px solid #e2e8f0;
            animation: slideUp 0.3s ease;
        }
        @media (min-width: 640px) {
            .pwd-modal { padding: 1.5rem; }
        }
        .pwd-modal-title {
            margin: 0 0 0.75rem 0;
            font-size: 1.125rem;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .pwd-modal-subtitle {
            margin: 0 0 1rem 0;
            font-size: 0.875rem;
            color: #64748b;
            line-height: 1.5;
        }
        .pwd-modal-x {
            position: absolute;
            top: 1rem;
            right: 1rem;
            width: 2rem;
            height: 2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            background: transparent;
            color: #94a3b8;
            font-size: 1.125rem;
            cursor: pointer;
            border-radius: 50%;
            transition: all 0.2s;
        }
        .pwd-modal-x:hover { background: #f1f5f9; color: #475569; }
        .pwd-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }
        @media (min-width: 640px) {
            .pwd-grid { grid-template-columns: 1fr 1fr; }
        }
        .pwd-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-top: 1.25rem;
            padding-top: 1rem;
            border-top: 1px solid #f1f5f9;
        }
        @media (min-width: 640px) {
            .pwd-actions { flex-direction: row; justify-content: flex-end; }
        }
        .btn-secondary {
            background: #f1f5f9;
            color: #475569;
        }
        .btn-secondary:hover { background: #e2e8f0; color: #334155; }
        .btn-info {
            background: linear-gradient(135deg, #0891b2, #22c55e);
            color: #fff;
            box-shadow: 0 4px 16px rgba(8,145,178,0.35);
        }
        .btn-info:hover { transform: translateY(-1px); }
        .code-hint {
            font-size: 0.8rem;
            color: #64748b;
            margin-top: -0.5rem;
        }
        .file-input-wrapper {
            position: relative;
            display: inline-block;
        }
        .file-input-wrapper input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        .file-input-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: #fff;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            color: #374151;
            cursor: pointer;
            transition: all 0.2s;
        }
        .file-input-wrapper:hover .file-input-btn {
            background: #f9fafb;
            border-color: #9ca3af;
        }
        .file-name {
            display: inline-block;
            margin-left: 0.75rem;
            font-size: 0.875rem;
            color: #6b7280;
        }
        .remove-pfp {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.75rem;
            font-size: 0.875rem;
            color: #dc2626;
            cursor: pointer;
        }
        .remove-pfp input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: #dc2626;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }
        @media (min-width: 640px) {
            .form-grid {
                grid-template-columns: 1fr 1fr;
                gap: 1.25rem;
            }
        }
        .form-group {
            display: flex;
            flex-direction: column;
        }
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        .form-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: #374151;
            margin-bottom: 0.5rem;
        }
        .form-control {
            padding: 0.625rem 0.875rem;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            font-size: 0.875rem;
            color: #1f2937;
            background: #fff;
            transition: all 0.2s;
            font-family: inherit;
        }
        .form-control:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99,102,241,0.1);
        }
        .form-control[readonly] {
            background: #f9fafb;
            color: #6b7280;
            cursor: not-allowed;
        }
        
        .form-actions {
            display: flex;
            flex-direction: column-reverse;
            gap: 0.75rem;
            margin-top: 1.5rem;
            padding-top: 1.25rem;
            border-top: 1px solid #f1f5f9;
        }
        @media (min-width: 480px) {
            .form-actions {
                flex-direction: row;
                justify-content: flex-end;
                margin-top: 2rem;
                padding-top: 1.5rem;
            }
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            border-radius: 12px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            border: none;
            font-family: inherit;
            width: 100%;
        }
        @media (min-width: 480px) {
            .btn { width: auto; }
        }
        .btn-cancel {
            background: #f1f5f9;
            color: #64748b;
        }
        .btn-cancel:hover {
            background: #e2e8f0;
            color: #475569;
        }
        .btn-primary {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff;
            box-shadow: 0 4px 16px rgba(99,102,241,0.4);
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(99,102,241,0.5);
        }
        .btn-primary:active {
            transform: translateY(0);
        }
    </style>
</head>
<body>
<?php if ($isMobileRole): ?>
<!-- ════════════════════════════════════════════════════════════
     MOBILE LAYOUT: Rider / Inventory Staff (no sidebar)
════════════════════════════════════════════════════════════ -->
<div class="mobile-page-wrap">
    <!-- Sticky top-bar -->
    <div class="mobile-topbar">
        <div class="mobile-topbar-left">
            <h1><i class="fas fa-user-circle" style="color:#c4b5fd;"></i> Profile Settings</h1>
            <p>Update your name, email, phone &amp; photo.</p>
        </div>
        <a href="<?php echo htmlspecialchars($homeHref, ENT_QUOTES, 'UTF-8'); ?>" class="btn-back">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>
    <div class="mobile-content">
<?php else: ?>
<!-- ════════════════════════════════════════════════════════════
     DESKTOP LAYOUT: Manager / Owner (with sidebar)
════════════════════════════════════════════════════════════ -->
<div class="dashboard-wrapper">
    <!-- Sidebar -->
    <?php
    require_once __DIR__ . '/../../includes/sidebar.php';
    renderSidebar($conn, ['base' => '../', 'active' => 'profile_settings']);
    ?>
    <!-- Main Content -->
    <main class="main-content">
        <div class="profile-shell">
<?php endif; ?>
<?php if ($isMobileRole): ?>
            <!-- Mobile: profile card wraps form directly -->
            <div class="mobile-card">
<?php else: ?>
            <div class="profile-card">
        <div class="profile-head">
            <div class="profile-head-left">
                <h2><i class="fas fa-user-cog" style="color: #818cf8;"></i> Profile Settings</h2>
                <p>Update your name, email, phone number, and profile picture.</p>
            </div>
            <a href="<?php echo htmlspecialchars($homeHref, ENT_QUOTES, 'UTF-8'); ?>" class="btn-back">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
        <div class="profile-body">
<?php endif; ?>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <?php if ($success !== ''): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <?php echo csrfTokenField(); ?>

                <div class="pfp-section">
                    <?php if ($profilePictureSrc !== ''): ?>
                        <img src="<?php echo htmlspecialchars($profilePictureSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile picture" class="pfp-preview" id="pfpPreview" onclick="openPfpModal()">
                    <?php else: ?>
                        <div class="pfp-fallback" id="pfpFallback" onclick="openPfpModal()"><?php echo htmlspecialchars(strtoupper(substr((string)($profile['full_name'] ?: $profile['user_name']), 0, 1)), ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <div class="pfp-controls">
                        <label class="pfp-label">Profile Picture</label>
                        <div class="file-input-wrapper">
                            <button type="button" class="file-input-btn" onclick="openPfpModal()">
                                <i class="fas fa-camera"></i> Change Photo
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Hidden file input -->
                <input type="file" name="profile_picture" id="profilePicture" accept=".jpg,.jpeg,.png,.webp" style="display:none" onchange="updateFileName(this)">
                <input type="checkbox" name="remove_profile_picture" value="1" id="removePfp" style="display:none">
                <input type="hidden" id="userInitial" value="<?php echo htmlspecialchars(strtoupper(substr((string)($profile['full_name'] ?: $profile['user_name']), 0, 1)), ENT_QUOTES, 'UTF-8'); ?>">

                <!-- Manager/Owner Profile Picture Modal (independent) -->
                <div id="mgrPfpModal" class="mgr-pfp-modal-overlay" onclick="closeMgrPfpModal(event)">
                    <div class="mgr-pfp-modal" onclick="event.stopPropagation()">
                        <button type="button" class="mgr-pfp-modal-x" onclick="closeMgrPfpModal()" aria-label="Close">
                            <i class="fas fa-times"></i>
                        </button>
                        <h3 class="mgr-pfp-modal-title"><i class="fas fa-id-badge" style="color: #0891b2;"></i> Manager Photo</h3>
                        <button type="button" class="mgr-pfp-modal-btn" onclick="triggerFileSelect()">
                            <i class="fas fa-camera" style="color: #0891b2;"></i> Upload New Photo
                        </button>
                        <?php if ($profilePictureSrc !== ''): ?>
                            <button type="button" class="mgr-pfp-modal-btn danger" onclick="triggerRemovePfp()">
                                <i class="fas fa-trash-alt"></i> Remove Profile
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">First Name</label>
                        <input type="text" name="first_name" class="form-control" maxlength="100" required value="<?php echo htmlspecialchars((string)$profile['first_name'], ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" class="form-control" maxlength="100" required value="<?php echo htmlspecialchars((string)$profile['last_name'], ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" maxlength="191" value="<?php echo htmlspecialchars((string)$profile['email'], ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone Number</label>
                        <input type="text" name="contact_no" class="form-control" maxlength="32" value="<?php echo htmlspecialchars((string)$profile['contact_no'], ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="form-group full-width">
                        <label class="form-label">Username</label>
                        <input type="text" name="user_name" class="form-control" maxlength="50" required value="<?php echo htmlspecialchars((string)$profile['user_name'], ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>

                <div class="form-actions">
                    <a href="<?php echo htmlspecialchars($homeHref, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-cancel">Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
                </div>
            </form>

            <!-- Security (manager/owner layout only; mobile has its own card below) -->
            <?php if (!$isMobileRole): ?>
            <div style="margin-top: 1.75rem; padding-top: 1.25rem; border-top: 1px solid #f1f5f9;">
                <div style="display:flex; align-items:center; justify-content:space-between; gap: 1rem; flex-wrap: wrap;">
                    <div>
                        <div style="font-weight:800; color:#0f172a; font-size: 1rem; display:flex; align-items:center; gap:0.5rem;">
                            <i class="fas fa-shield-halved" style="color:#0891b2;"></i> Security
                        </div>
                        <div style="color:#64748b; font-size:0.875rem; margin-top: 0.25rem;">
                            Change your password with a verification code sent to your linked email.
                        </div>
                    </div>
                    <button type="button" class="btn btn-info" onclick="openPwdModal()">
                        <i class="fas fa-key"></i> Change Password
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <!-- Change Password Modal -->
            <div id="pwdModal" class="pwd-modal-overlay" onclick="closePwdModal(event)">
                <div class="pwd-modal" onclick="event.stopPropagation()">
                    <button type="button" class="pwd-modal-x" onclick="closePwdModal()" aria-label="Close">
                        <i class="fas fa-times"></i>
                    </button>
                    <h3 class="pwd-modal-title"><i class="fas fa-key" style="color:#0891b2;"></i> Change Password</h3>
                    <p class="pwd-modal-subtitle">
                        Enter your old password and new password, then request a verification code sent to your profile email.
                    </p>

                    <div class="pwd-grid">
                        <div class="form-group">
                            <label class="form-label">Old Password</label>
                            <input type="password" id="pwdOld" class="form-control" autocomplete="current-password" placeholder="Enter old password">
                        </div>
                        <div class="form-group">
                            <label class="form-label">New Password</label>
                            <input type="password" id="pwdNew" class="form-control" autocomplete="new-password" placeholder="Enter new password">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" id="pwdConfirm" class="form-control" autocomplete="new-password" placeholder="Confirm new password">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Authentication Code</label>
                            <input type="text" id="pwdCode" class="form-control" inputmode="numeric" placeholder="6-digit code">
                            <div class="code-hint">Code expires in 5 minutes.</div>
                        </div>
                    </div>

                    <div class="pwd-actions">
                        <button type="button" class="btn btn-secondary" onclick="closePwdModal()">
                            Cancel
                        </button>
                        <button type="button" class="btn btn-info" id="btnSendCode" onclick="sendPwdCode()">
                            <i class="fas fa-paper-plane"></i> Send Code
                        </button>
                        <button type="button" class="btn btn-primary" id="btnChangePwd" onclick="confirmChangePassword()">
                            <i class="fas fa-check"></i> Confirm Change
                        </button>
                    </div>
                </div>
            </div>
<?php if ($isMobileRole): ?>
        </div><!-- /.mobile-card -->

        <!-- Security card (mobile) -->
        <div class="mobile-card">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:0.75rem; flex-wrap:wrap;">
                <div>
                    <div style="font-weight:800; color:#0f172a; font-size:0.95rem; display:flex; align-items:center; gap:0.5rem;">
                        <i class="fas fa-shield-halved" style="color:#0891b2;"></i> Security
                    </div>
                    <div style="color:#64748b; font-size:0.8rem; margin-top:0.2rem; line-height:1.4;">
                        Change password via email verification code.
                    </div>
                </div>
                <button type="button" class="btn btn-info" onclick="openPwdModal()" style="white-space:nowrap;">
                    <i class="fas fa-key"></i> Change Password
                </button>
            </div>
        </div>

        <!-- spacer so content isn't hidden behind bottom nav bar on iOS -->
        <div style="height: 2rem;"></div>
    </div><!-- /.mobile-content -->
</div><!-- /.mobile-page-wrap -->
<?php else: ?>
        </div><!-- /.profile-body -->
        </div><!-- /.profile-card -->
        </div><!-- /.profile-shell -->
    </main>
</div><!-- /.dashboard-wrapper -->
<?php endif; ?>

<script>
    const CHANGE_PASSWORD_API_URL = <?php echo json_encode($changePasswordApiUrl, JSON_UNESCAPED_SLASHES); ?>;
    const PROFILE_CSRF_TOKEN = <?php echo json_encode(getCsrfToken(), JSON_UNESCAPED_SLASHES); ?>;

    function updateFileName(input) {
        const fileName = document.getElementById('fileName');
        if (input.files && input.files[0]) {
            fileName.textContent = input.files[0].name;
        } else {
            fileName.textContent = 'No file chosen';
        }
    }
    
    function toggleRemovePfp() {
        const checkbox = document.getElementById('removePfp');
        const fileInput = document.getElementById('profilePicture');
        const fileName = document.getElementById('fileName');
        
        if (checkbox.checked) {
            fileInput.disabled = true;
            fileName.textContent = 'Will be removed';
            fileName.style.color = '#dc2626';
        } else {
            fileInput.disabled = false;
            fileName.textContent = 'No file chosen';
            fileName.style.color = '#6b7280';
        }
    }

    // Manager Profile Photo Modal Functions (independent)
    function openPfpModal() {
        document.getElementById('mgrPfpModal').classList.add('active');
    }

    function closeMgrPfpModal(event) {
        if (event && event.target !== event.currentTarget) return;
        document.getElementById('mgrPfpModal').classList.remove('active');
    }

    function triggerFileSelect() {
        document.getElementById('profilePicture').click();
        closeMgrPfpModal();
    }

    function triggerRemovePfp() {
        const checkbox = document.getElementById('removePfp');
        checkbox.checked = true;
        closeMgrPfpModal();
        
        // Get initial for fallback from hidden input
        const initialInput = document.getElementById('userInitial');
        const initial = initialInput ? initialInput.value : 'U';
        const previewImg = document.getElementById('pfpPreview');
        
        // Replace image with fallback div showing initial
        if (previewImg) {
            const newFallback = document.createElement('div');
            newFallback.className = 'pfp-fallback';
            newFallback.id = 'pfpFallback';
            newFallback.textContent = initial;
            newFallback.onclick = openPfpModal;
            previewImg.parentNode.replaceChild(newFallback, previewImg);
        }
        
        // Show confirmation
        Swal.fire({
            title: 'Photo will be removed',
            text: 'Preview updated! Click Save Changes to confirm removal.',
            icon: 'info',
            confirmButtonText: 'OK',
            confirmButtonColor: '#6366f1',
            customClass: { popup: 'rounded-2xl' }
        });
    }

    function updateFileName(input) {
        if (input.files && input.files[0]) {
            // Show preview of selected image
            const reader = new FileReader();
            reader.onload = function(e) {
                const previewImg = document.getElementById('pfpPreview');
                const fallbackDiv = document.getElementById('pfpFallback');
                
                if (previewImg) {
                    previewImg.src = e.target.result;
                } else if (fallbackDiv) {
                    // Replace fallback with new image
                    const newImg = document.createElement('img');
                    newImg.src = e.target.result;
                    newImg.alt = 'Profile picture';
                    newImg.className = 'pfp-preview';
                    newImg.id = 'pfpPreview';
                    newImg.onclick = openPfpModal;
                    fallbackDiv.parentNode.replaceChild(newImg, fallbackDiv);
                }
                
                Swal.fire({
                    title: 'Photo Selected',
                    text: 'Preview updated! Click Save Changes to confirm.',
                    icon: 'success',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#6366f1',
                    customClass: { popup: 'rounded-2xl' }
                });
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    // Change Password Modal
    function openPwdModal() {
        document.getElementById('pwdModal').classList.add('active');
    }
    function closePwdModal(event) {
        if (event && event.target !== event.currentTarget) return;
        document.getElementById('pwdModal').classList.remove('active');
    }

    function setPwdBusy(isBusy) {
        const sendBtn = document.getElementById('btnSendCode');
        const changeBtn = document.getElementById('btnChangePwd');
        if (sendBtn) sendBtn.disabled = !!isBusy;
        if (changeBtn) changeBtn.disabled = !!isBusy;
    }

    async function sendPwdCode() {
        const oldPassword = (document.getElementById('pwdOld')?.value || '').trim();
        const newPassword = (document.getElementById('pwdNew')?.value || '');

        if (!oldPassword || !newPassword) {
            Swal.fire({ title: 'Missing details', text: 'Old password and new password are required.', icon: 'warning', confirmButtonColor: '#0891b2' });
            return;
        }

        setPwdBusy(true);
        try {
            const fd = new FormData();
            fd.append('action', 'send_code');
            fd.append('csrf_token', PROFILE_CSRF_TOKEN);
            fd.append('old_password', oldPassword);
            fd.append('new_password', newPassword);

            const res = await fetch(CHANGE_PASSWORD_API_URL, { method: 'POST', body: fd, credentials: 'same-origin' });
            const contentType = (res.headers.get('content-type') || '').toLowerCase();
            let data = null;
            let rawText = '';
            if (contentType.includes('application/json')) {
                data = await res.json().catch(() => null);
            } else {
                rawText = await res.text().catch(() => '');
            }

            if (!res.ok || !data?.success) {
                const msg =
                    (data && (data.message || data.error)) ||
                    (rawText ? rawText.slice(0, 300) : '') ||
                    `Unable to send code. (HTTP ${res.status})`;
                throw new Error(msg);
            }

            Swal.fire({ title: 'Code sent', text: data.message || 'Verification code sent to your email.', icon: 'success', confirmButtonColor: '#0891b2' });
            const codeInput = document.getElementById('pwdCode');
            if (codeInput) codeInput.focus();
        } catch (e) {
            Swal.fire({ title: 'Failed', text: e.message || 'Unable to send code.', icon: 'error', confirmButtonColor: '#0891b2' });
        } finally {
            setPwdBusy(false);
        }
    }

    async function confirmChangePassword() {
        const oldPassword = (document.getElementById('pwdOld')?.value || '').trim();
        const newPassword = (document.getElementById('pwdNew')?.value || '');
        const confirmPassword = (document.getElementById('pwdConfirm')?.value || '');
        const code = (document.getElementById('pwdCode')?.value || '').trim();

        if (!oldPassword || !newPassword || !confirmPassword || !code) {
            Swal.fire({ title: 'Missing fields', text: 'Please fill in all fields.', icon: 'warning', confirmButtonColor: '#0891b2' });
            return;
        }

        setPwdBusy(true);
        try {
            const fd = new FormData();
            fd.append('action', 'change_password');
            fd.append('csrf_token', PROFILE_CSRF_TOKEN);
            fd.append('old_password', oldPassword);
            fd.append('new_password', newPassword);
            fd.append('confirm_password', confirmPassword);
            fd.append('code', code);

            const res = await fetch(CHANGE_PASSWORD_API_URL, { method: 'POST', body: fd, credentials: 'same-origin' });
            const contentType = (res.headers.get('content-type') || '').toLowerCase();
            let data = null;
            let rawText = '';
            if (contentType.includes('application/json')) {
                data = await res.json().catch(() => null);
            } else {
                rawText = await res.text().catch(() => '');
            }

            if (!res.ok || !data?.success) {
                const msg =
                    (data && (data.message || data.error)) ||
                    (rawText ? rawText.slice(0, 300) : '') ||
                    `Unable to change password. (HTTP ${res.status})`;
                throw new Error(msg);
            }

            Swal.fire({ title: 'Password updated', text: data.message || 'Password updated successfully.', icon: 'success', confirmButtonColor: '#0891b2' });
            closePwdModal();

            // Clear sensitive inputs
            ['pwdOld', 'pwdNew', 'pwdConfirm', 'pwdCode'].forEach((id) => {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });
        } catch (e) {
            Swal.fire({ title: 'Failed', text: e.message || 'Unable to change password.', icon: 'error', confirmButtonColor: '#0891b2' });
        } finally {
            setPwdBusy(false);
        }
    }

    // Success message on load
    <?php if ($success !== ''): ?>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            title: 'Profile Updated!',
            text: 'Your profile has been updated successfully.',
            icon: 'success',
            confirmButtonText: 'Great!',
            confirmButtonColor: '#6366f1',
            customClass: {
                popup: 'rounded-2xl',
                confirmButton: 'rounded-xl font-bold'
            }
        });
    });
    <?php endif; ?>
</script>
    </main>
</div>
</body>
</html>
