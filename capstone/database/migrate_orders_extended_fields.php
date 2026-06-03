<?php
/**
 * Adds payment_status, payment_method, order_source, discount_amount to orders if missing.
 * Usage: php migrate_orders_extended_fields.php
 */
require_once __DIR__ . '/../includes/db.php';

function ordersColumnExists(PDO $conn, string $col): bool {
    $st = $conn->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute(['orders', $col]);
    return (int) $st->fetchColumn() > 0;
}

$adds = [
    'payment_status' => "VARCHAR(20) NOT NULL DEFAULT 'Unpaid'",
    'payment_method' => "VARCHAR(50) NOT NULL DEFAULT 'Cash'",
    'order_source' => "VARCHAR(50) NOT NULL DEFAULT 'Phone Call'",
    'discount_amount' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
];

foreach ($adds as $col => $def) {
    if (ordersColumnExists($conn, $col)) {
        echo "Skip (exists): $col\n";
        continue;
    }
    $conn->exec("ALTER TABLE orders ADD COLUMN `$col` $def");
    echo "Added: $col\n";
}

echo "Done.\n";
