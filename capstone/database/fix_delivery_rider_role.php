<?php
/**
 * Fix Delivery Rider Role - ensures delivery rider users have Role_ID = 3
 * Run: php database/fix_delivery_rider_role.php
 * 
 * Use this if your delivery rider account incorrectly redirects to dashboard/manual adjustment.
 */
require_once __DIR__ . '/../includes/db.php';

echo "=== Fix Delivery Rider Role ===\n\n";

// 1. Ensure delivery_rider exists with Role_ID 3 in roles table
$rcheck = $conn->query("SELECT Role_ID, role_name FROM roles ORDER BY Role_ID");
echo "Current roles:\n";
$has_rider_3 = false;
while ($r = $rcheck->fetch(PDO::FETCH_ASSOC)) {
    echo "  Role_ID={$r['Role_ID']} | {$r['role_name']}\n";
    if ((int)$r['Role_ID'] === 3 && stripos($r['role_name'], 'rider') !== false) $has_rider_3 = true;
}

if (!$has_rider_3) {
    echo "\nInserting delivery_rider with Role_ID 3...\n";
    try {
        $conn->exec("INSERT INTO roles (Role_ID, role_name, role_description) VALUES (3, 'delivery_rider', 'Delivery Rider - Rider dashboard only') ON DUPLICATE KEY UPDATE role_name='delivery_rider', role_description='Delivery Rider - Rider dashboard only'");
        echo "OK: delivery_rider role ready.\n";
    } catch (Exception $e) {
        echo "Note: " . $e->getMessage() . "\n";
    }
}

// 2. Find rider users (by role_name) and fix Role_ID if wrong
echo "\n=== Users (Delivery Rider should have Role_ID=3) ===\n";
$users = $conn->query("SELECT u.User_ID, u.user_name, u.full_name, u.Role_ID, r.role_name FROM user u LEFT JOIN roles r ON u.Role_ID = r.Role_ID WHERE u.is_active = 1");
while ($row = $users->fetch(PDO::FETCH_ASSOC)) {
    $rn = strtolower($row['role_name'] ?? '');
    $rid = (int)$row['Role_ID'];
    $is_rider = (stripos($rn, 'rider') !== false || $rn === 'delivery_rider');
    $needs_fix = $is_rider && $rid !== 3;
    $marker = $needs_fix ? ' <-- FIXING' : '';
    echo "{$row['user_name']} | Role_ID={$rid} | {$row['role_name']}{$marker}\n";
    
    if ($needs_fix) {
        try {
            $stmt = $conn->prepare("UPDATE user SET Role_ID = 3 WHERE User_ID = ?");
            $stmt->execute([$row['User_ID']]);
            echo "  -> Updated to Role_ID 3.\n";
        } catch (Exception $e) {
            echo "  -> Error: " . $e->getMessage() . "\n";
        }
    }
}

echo "\nDone. Try logging in again with your delivery rider account.\n";
