<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../includes/db.php';

try {
    $table = 'cash_shifts';
    echo "Checking for {$table}.notes column...\n";

    $stmt = $conn->query("SHOW COLUMNS FROM {$table} LIKE 'notes'");
    $exists = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($exists) {
        $conn->exec("ALTER TABLE {$table} DROP COLUMN notes");
        echo "[OK] Dropped notes column from {$table}.\n";
    } else {
        echo "[SKIP] notes column does not exist in {$table}.\n";
    }

    echo "Done.\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
