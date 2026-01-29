<?php
require_once 'includes/db.php';

$username = 'amdad';

$stmt = $conn->prepare("SELECT User_ID, user_name, password, Role_ID, is_active FROM app_users WHERE user_name = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();
    echo "User found: " . print_r($user, true);
} else {
    echo "User not found.";
}
$stmt->close();
$conn->close();
?>
