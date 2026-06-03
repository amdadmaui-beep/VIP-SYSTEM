<?php
/**
 * Normalization migration for delivery_damage_reports
 * Drops redundant Order_ID and Product_ID columns since they can be derived from Order_detail_ID
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$base = dirname(__DIR__);
require_once $base . '/includes/db.php';

function runSql(PDO $conn, string $sql, string $label): bool {
    try {
        $conn->exec($sql);
        echo "[OK] $label\n";
        return true;
    } catch (PDOException $e) {
        if (stripos($e->getMessage(), 'check that column/key exists') !== false ||
            stripos($e->getMessage(), 'Can\'t DROP') !== false) {
            echo "[SKIP] $label: " . $e->getMessage() . "\n";
            return true;
        }
        echo "[ERROR] $label: " . $e->getMessage() . "\n";
        return false;
    }
}

echo "normalize_delivery_damage_reports\n================================\n";

$sql_drop_fks = [
    "ALTER TABLE delivery_damage_reports DROP FOREIGN KEY fk_ddr_order",
    "ALTER TABLE delivery_damage_reports DROP FOREIGN KEY fk_ddr_product",
];

foreach ($sql_drop_fks as $sql) {
    runSql($conn, $sql, 'DROP FOREIGN KEY');
}

$sql_drop_cols = [
    "ALTER TABLE delivery_damage_reports DROP COLUMN Order_ID",
    "ALTER TABLE delivery_damage_reports DROP COLUMN Product_ID"
];

foreach ($sql_drop_cols as $sql) {
    runSql($conn, $sql, 'DROP COLUMN');
}

echo "\nDone.\n";
