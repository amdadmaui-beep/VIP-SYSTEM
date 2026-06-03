<?php
/**
 * Cleanup Legacy Laravel User Linkage
 * 
 * This script:
 * 1. Re-maps manager_pins.user_id to user.User_ID
 * 2. Removes orphan PINs
 * 3. Drops the legacy linked_user_id column
 */

require_once __DIR__ . '/../includes/db.php';

echo "Starting Legacy Cleanup...\n";

try {
    $conn->beginTransaction();

    // 1. Identify valid mappings
    // We assume manager_pins.user_id was the old Laravel ID, which is stored in user.linked_user_id
    echo "  - Re-mapping manager_pins to direct User_ID...\n";
    
    // First, create a temporary mapping table to avoid updating and reading from the same table in complex ways
    $stmt = $conn->query("
        SELECT mp.pin_id, u.User_ID 
        FROM manager_pins mp
        JOIN user u ON u.linked_user_id = mp.user_id
    ");
    $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($mappings as $map) {
        $upd = $conn->prepare("UPDATE manager_pins SET user_id = ? WHERE pin_id = ?");
        $upd->execute([$map['User_ID'], $map['pin_id']]);
        echo "    - PIN ID {$map['pin_id']} re-mapped to User_ID {$map['User_ID']}\n";
    }

    // 2. Remove orphan PINs (those that don't exist in the user table anymore)
    echo "  - Removing orphan PINs...\n";
    $deleteOrphans = $conn->query("
        DELETE FROM manager_pins 
        WHERE user_id NOT IN (SELECT User_ID FROM user)
    ");
    echo "    - Deleted " . $deleteOrphans->rowCount() . " orphan PIN(s)\n";

    // 3. Drop the linked_user_id column
    echo "  - Dropping legacy linked_user_id column...\n";
    $conn->exec("ALTER TABLE user DROP COLUMN linked_user_id");

    // 4. Add Foreign Key to manager_pins (optional but good for 3NF)
    // First ensure types match. User_ID is int unsigned PRI, manager_pins.user_id is bigint unsigned UNI.
    // Let's make them both INT UNSIGNED to be consistent.
    echo "  - Standardizing manager_pins.user_id type...\n";
    $conn->exec("ALTER TABLE manager_pins MODIFY user_id INT UNSIGNED NOT NULL");
    
    // Add Foreign Key constraint
    echo "  - Adding Foreign Key constraint...\n";
    try {
        $conn->exec("ALTER TABLE manager_pins ADD CONSTRAINT fk_manager_pin_user FOREIGN KEY (user_id) REFERENCES user(User_ID) ON DELETE CASCADE");
    } catch (Exception $e) {
        echo "    - Warning: Could not add FK (might already exist or have naming conflict): " . $e->getMessage() . "\n";
    }

    $conn->commit();
    echo "\nCleanup completed successfully!\n";

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
