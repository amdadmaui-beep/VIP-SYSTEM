<?php
/**
 * Migration: Ensure order_status ENUM includes values needed for delivery
 * Run once: php database/migrate_order_status_enum.php
 */
require_once __DIR__ . '/../includes/db.php';

$col = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
if (!$col || $col->rowCount() === 0) {
    die("order_status/status column not found\n");
}
$row = $col->fetch(PDO::FETCH_ASSOC);
$col_name = $row['Field'];
$type = $row['Type'] ?? '';

if (stripos($type, 'Delivered (Pending Cash Turnover)') !== false) {
    echo "order_status already includes required values. Nothing to do.\n";
    exit(0);
}

$enum_sql = "'Requested','Confirmed','Scheduled for Delivery','Out for Delivery','Delivered (Pending Cash Turnover)','Completed','Cancelled','pending','delivered'";
$sql = "ALTER TABLE orders MODIFY COLUMN {$col_name} ENUM({$enum_sql}) DEFAULT 'Requested'";
try {
    $conn->exec($sql);
    echo "✓ {$col_name} ENUM updated with delivery status values\n";
} catch (Exception $e) {
    die("Error: " . $e->getMessage() . "\n");
}
