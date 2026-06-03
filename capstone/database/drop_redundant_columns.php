<?php
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Starting column cleanup in stockin_inventory...\n";
    
    // Check if columns exist before dropping
    $stmt = $conn->query("SHOW COLUMNS FROM stockin_inventory");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $to_drop = [];
    if (in_array('number_of_bags', $columns)) $to_drop[] = "DROP COLUMN number_of_bags";
    if (in_array('Order_ID', $columns)) $to_drop[] = "DROP COLUMN Order_ID";
    if (in_array('produced_qty', $columns)) $to_drop[] = "DROP COLUMN produced_qty";
    
    if (!empty($to_drop)) {
        $sql = "ALTER TABLE stockin_inventory " . implode(', ', $to_drop);
        $conn->exec($sql);
        echo "Successfully dropped: " . implode(', ', array_map(function($s) { return str_replace('DROP COLUMN ', '', $s); }, $to_drop)) . "\n";
    } else {
        echo "No columns to drop (already removed).\n";
    }
    
    // Also check if quantity should be decimal instead of int
    $quantity_info = $conn->query("SHOW COLUMNS FROM stockin_inventory LIKE 'quantity'")->fetch();
    if ($quantity_info && strpos($quantity_info['Type'], 'int') !== false) {
        echo "Updating quantity column to DECIMAL(12,2) for better precision...\n";
        $conn->exec("ALTER TABLE stockin_inventory MODIFY COLUMN quantity DECIMAL(12,2) DEFAULT 0");
    }

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage() . "\n");
}
