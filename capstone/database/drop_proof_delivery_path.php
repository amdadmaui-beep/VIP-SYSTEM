<?php
/**
 * Migration: Drop proof_delivery_path (and proof_of_delivery_path) columns
 * safely from delivery and delivery_detail tables.
 *
 * Run once: php capstone/database/drop_proof_delivery_path.php
 */
require_once __DIR__ . '/../includes/db.php';

// Columns to drop, keyed by table name
$targets = [
    'delivery'        => ['proof_delivery_path', 'proof_of_delivery_path', 'proof_delivery'],
    'delivery_detail' => ['proof_delivery_path', 'proof_of_delivery_path', 'proof_delivery'],
];

$dropped = 0;

foreach ($targets as $table => $columns) {
    // Fetch existing columns for this table
    $existing = $conn->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN, 0);

    foreach ($columns as $col) {
        if (in_array($col, $existing, true)) {
            $conn->exec("ALTER TABLE `{$table}` DROP COLUMN `{$col}`");
            echo "Dropped column `{$table}`.`{$col}`\n";
            $dropped++;
        } else {
            echo "Skipped: `{$table}`.`{$col}` does not exist.\n";
        }
    }
}

echo $dropped > 0
    ? "\nDone. {$dropped} column(s) removed.\n"
    : "\nNothing to drop — all target columns were already absent.\n";
