<?php
/**
 * Migration: Add proof_delivery column to delivery_detail
 * Run once: php capstone/database/migrate_delivery_detail_proof_delivery.php
 */
require_once __DIR__ . '/../includes/db.php';

$col = 'proof_delivery';
$table = 'delivery_detail';

$cols = $conn->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_COLUMN, 0);
if (!in_array($col, $cols, true)) {
    $conn->exec("ALTER TABLE {$table} ADD COLUMN {$col} TEXT NULL");
    echo "Added column {$table}.{$col}\n";
} else {
    echo "Column {$table}.{$col} already exists\n";
}

