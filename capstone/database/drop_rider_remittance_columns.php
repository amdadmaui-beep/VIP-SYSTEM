<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../includes/db.php';

try {
    $table = 'rider_remittance_tracking';
    echo "Starting column cleanup in {$table}...\n";

    // 1. Get current columns
    $stmt = $conn->query("SHOW COLUMNS FROM {$table}");
    $existingColumns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    echo "Current columns:\n" . implode(", ", $existingColumns) . "\n\n";

    // 2. Drop index on shortage_cleared if it exists
    $stmt = $conn->query("SHOW INDEX FROM {$table}");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $indexNames = array_unique(array_column($indexes, 'Key_name'));

    if (in_array('idx_shortage_cleared', $indexNames, true)) {
        echo "Dropping index idx_shortage_cleared...\n";
        $conn->exec("ALTER TABLE {$table} DROP INDEX idx_shortage_cleared");
        echo "[OK] Index idx_shortage_cleared dropped.\n";
    }

    // 3. Drop columns
    $columnsToDrop = ['shortage_cleared', 'cleared_at', 'cleared_by', 'notes'];
    $dropSqlParts = [];
    foreach ($columnsToDrop as $col) {
        if (in_array($col, $existingColumns, true)) {
            $dropSqlParts[] = "DROP COLUMN `{$col}`";
            echo "Column '{$col}' found. Will drop.\n";
        } else {
            echo "Column '{$col}' does not exist. Skipping.\n";
        }
    }

    if (!empty($dropSqlParts)) {
        $sql = "ALTER TABLE {$table} " . implode(", ", $dropSqlParts);
        echo "Executing SQL: {$sql}\n";
        $conn->exec($sql);
        echo "[OK] Columns successfully dropped.\n";
    } else {
        echo "No columns to drop.\n";
    }

    // 4. Show final columns
    $stmt = $conn->query("SHOW COLUMNS FROM {$table}");
    $finalColumns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    echo "\nFinal {$table} columns:\n" . implode(", ", $finalColumns) . "\n";
    echo "Done.\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
