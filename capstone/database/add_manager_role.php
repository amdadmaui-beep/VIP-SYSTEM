<?php
require_once __DIR__ . '/../includes/db.php';

$sql = "INSERT INTO roles (Role_ID, role_name, role_description) VALUES (4, 'manager', 'Full dashboard access, restricted user management.') ON DUPLICATE KEY UPDATE role_name='manager', role_description='Full dashboard access, restricted user management.';";

if ($conn->query($sql)) {
    echo "Manager role added to roles table successfully.";
} else {
    echo "Error adding Manager role to roles table: " . $conn->error;
}

$conn->close();
?>
