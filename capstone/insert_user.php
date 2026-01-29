<?php
require_once 'includes/db.php';

$username = 'amdad';
$password = '123';
$role_id = 1; // Assuming 1 is owner

$stmt = $conn->prepare("INSERT INTO app_users (user_name, password, Role_ID, is_active) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE password=?, Role_ID=?, is_active=1");
$stmt->bind_param("ssiss", $username, $password, $role_id, $password, $role_id);
if ($stmt->execute()) {
    echo "User inserted or updated successfully.";
} else {
    echo "Error: " . $stmt->error;
}
$stmt->close();
$conn->close();
?>
