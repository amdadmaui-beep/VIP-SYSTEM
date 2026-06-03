<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../includes/db.php';

try {
    $table = 'rider_remittance_tracking';
    echo "Renaming tracking_id to Tracking_ID in {$table}...\n";

    $stmt = $conn->query("SHOW COLUMNS FROM {$table}");
    $existingColumns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');

    if (in_array('tracking_id', $existingColumns, true)) {
        $conn->exec("ALTER TABLE {$table} CHANGE COLUMN tracking_id Tracking_ID INT AUTO_INCREMENT");
        echo "[OK] Column renamed from tracking_id to Tracking_ID.\n";
    } elseif (in_array('Tracking_ID', $existingColumns, true)) {
        echo "[SKIP] Column already named Tracking_ID.\n";
    } else {
        echo "[SKIP] Neither tracking_id nor Tracking_ID found.\n";
    }

    $stmt = $conn->query("SHOW COLUMNS FROM {$table}");
    $finalColumns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    echo "\nFinal columns:\n" . implode(", ", $finalColumns) . "\n";
    echo "Done.\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
