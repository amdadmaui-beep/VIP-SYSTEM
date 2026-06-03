<?php
/**
 * Add Inventory Staff role (Role_ID = 5)
 * Run: php database/add_inventory_staff_role.php
 * Or visit in browser
 */
require_once __DIR__ . '/../includes/db.php';

$sql = "INSERT INTO roles (Role_ID, role_name, role_description) VALUES 
        (5, 'inventory_staff', 'Access to Manual Adjustment and Production only.') 
        ON DUPLICATE KEY UPDATE 
        role_name = 'inventory_staff', 
        role_description = 'Access to Manual Adjustment and Production only.'";

try {
    $conn->exec($sql);
    echo "Inventory Staff role added to roles table successfully.";
} catch (Exception $e) {
    echo "Error adding Inventory Staff role: " . $e->getMessage();
}
