<?php
/**
 * Migration script to drop assigned_rider_id and delivered_by_user_id columns and their indexes from the delivery table.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../includes/db.php';

try {
    // 1. Get current columns
    $stmt = $conn->query("SHOW COLUMNS FROM delivery");
    $existingColumns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    echo "Current delivery columns:\n" . implode(", ", $existingColumns) . "\n\n";

    // 2. Drop indexes if they exist
    $stmt = $conn->query("SHOW INDEX FROM delivery");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $indexNames = array_unique(array_column($indexes, 'Key_name'));

    if (in_array('idx_assigned_rider', $indexNames, true)) {
        echo "Dropping index idx_assigned_rider...\n";
        $conn->exec("ALTER TABLE delivery DROP INDEX idx_assigned_rider");
        echo "[OK] Index idx_assigned_rider dropped.\n";
    }

    if (in_array('idx_delivered_by_user', $indexNames, true)) {
        echo "Dropping index idx_delivered_by_user...\n";
        $conn->exec("ALTER TABLE delivery DROP INDEX idx_delivered_by_user");
        echo "[OK] Index idx_delivered_by_user dropped.\n";
    }

    // 3. Drop columns
    $columnsToDrop = ['assigned_rider_id', 'delivered_by_user_id'];
    $dropSqlParts = [];
    foreach ($columnsToDrop as $col) {
        if (in_array($col, $existingColumns, true)) {
            $dropSqlParts[] = "DROP COLUMN `$col`";
        } else {
            echo "Column '$col' does not exist in delivery table. Skipping.\n";
        }
    }

    if (!empty($dropSqlParts)) {
        $sql = "ALTER TABLE delivery " . implode(", ", $dropSqlParts);
        echo "Executing SQL: $sql\n";
        $conn->exec($sql);
        echo "[OK] Columns successfully dropped.\n";
    } else {
        echo "No columns to drop.\n";
    }

    // 4. Show final columns
    $stmt = $conn->query("SHOW COLUMNS FROM delivery");
    $finalColumns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    echo "\nFinal delivery columns:\n" . implode(", ", $finalColumns) . "\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
