<?php
/**
 * Migration: Drop Redundant 'created_by' Column
 * 
 * Safely removes the 'created_by' column from the 'sales' table.
 * The system already stores 'User_ID', making 'created_by' redundant.
 * The codebase (sales_backend.php and get_sale_details.php) uses
 * dynamic fallback logic, so dropping this column will not cause any errors.
 */

require_once __DIR__ . '/../includes/db.php';

echo "=== Removing 'created_by' column ===\n";

try {
    // Check if it exists
    $check = $conn->query("SHOW COLUMNS FROM sales LIKE 'created_by'");
    if ($check->rowCount() === 0) {
        echo "[SKIP] Column 'created_by' does not exist.\n";
    } else {
        // Drop foreign key constraint first
        try {
            $conn->exec("ALTER TABLE sales DROP FOREIGN KEY sales_created_by_foreign");
            echo "[DONE] Dropped foreign key 'sales_created_by_foreign'.\n";
        } catch (PDOException $e) {
            echo "[WARN] Could not drop foreign key (maybe it doesn't exist): " . $e->getMessage() . "\n";
        }
        
        $conn->exec("ALTER TABLE sales DROP COLUMN created_by");
        echo "[DONE] Successfully dropped 'created_by' column.\n";
    }
} catch (PDOException $e) {
    echo "[FAIL] Error dropping column: " . $e->getMessage() . "\n";
}

echo "\n=== Current Schema ===\n";
$cols = $conn->query("DESCRIBE sales")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "  - {$c['Field']} ({$c['Type']})\n";
}
