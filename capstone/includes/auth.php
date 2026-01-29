<?php
// Authentication check
// Redirect to login if user is not authenticated

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Optional: Check user role/permissions here
// function checkPermission($required_role) {
//     if ($_SESSION['user_role'] !== $required_role) {
//         header('Location: index.php');
//         exit;
//     }
// }
?>
