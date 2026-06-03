<?php
/**
 * Migration: Restore Payment Column
 * 
 * Restores the 'payment' column to the 'sales' table for future use
 * (e.g., if GCash, Card, or other payment methods are added later).
 */

require_once __DIR__ . '/../includes/db.php';

echo "=== Restoring 'payment' column ===\n";

try {
    // Check if it already exists
    $check = $conn->query("SHOW COLUMNS FROM sales LIKE 'payment'");
    if ($check->rowCount() > 0) {
        echo "[SKIP] Column 'payment' already exists.\n";
    } else {
        // Add the column back. Putting it after 'created_by' as it was before.
        $conn->exec("ALTER TABLE sales ADD COLUMN payment VARCHAR(50) DEFAULT NULL AFTER created_by");
        echo "[DONE] Successfully restored 'payment' column (VARCHAR 50, Default NULL).\n";
    }
} catch (PDOException $e) {
    echo "[FAIL] Error restoring column: " . $e->getMessage() . "\n";
}

echo "\n=== Current Schema ===\n";
$cols = $conn->query("DESCRIBE sales")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "  - {$c['Field']} ({$c['Type']})\n";
}
