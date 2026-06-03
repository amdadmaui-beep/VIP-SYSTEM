<?php
/**
 * Adds snapshot columns to orders and order_details for data immutability.
 * Run once: php database/migrate_snapshot_columns.php (from capstone/)
 */
require_once __DIR__ . '/../includes/db.php';

function colExists(PDO $conn, string $table, string $col): bool {
    $st = $conn->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$table, $col]);
    return (int) $st->fetchColumn() > 0;
}

// --- orders table ---
$orderCols = [
    'customer_name_snapshot'    => 'VARCHAR(255) NULL AFTER Customer_ID',
    'customer_phone_snapshot'   => 'VARCHAR(50) NULL AFTER customer_name_snapshot',
    'customer_address_snapshot' => 'TEXT NULL AFTER customer_phone_snapshot',
];

foreach ($orderCols as $col => $def) {
    if (colExists($conn, 'orders', $col)) {
        echo "Skip (exists): orders.$col\n";
        continue;
    }
    $conn->exec("ALTER TABLE orders ADD COLUMN `$col` $def");
    echo "Added: orders.$col\n";
}

// --- order_details table ---
$detailCols = [
    'product_name_snapshot' => 'VARCHAR(255) NULL AFTER Product_ID',
    'unit_name_snapshot'    => 'VARCHAR(50) NULL AFTER product_name_snapshot',
];

foreach ($detailCols as $col => $def) {
    if (colExists($conn, 'order_details', $col)) {
        echo "Skip (exists): order_details.$col\n";
        continue;
    }
    $conn->exec("ALTER TABLE order_details ADD COLUMN `$col` $def");
    echo "Added: order_details.$col\n";
}

echo "Done.\n";
