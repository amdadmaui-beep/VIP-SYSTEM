<?php
/**
 * Migration: Add 'Returning' and 'Completed' to delivery_status ENUM
 * Run once: php database/migrate_delivery_status_enum.php
 */
require_once __DIR__ . '/../includes/db.php';

$col = $conn->query("SHOW COLUMNS FROM delivery WHERE Field = 'delivery_status'");
if (!$col || $col->rowCount() === 0) {
    die("delivery_status column not found\n");
}
$row = $col->fetch(PDO::FETCH_ASSOC);
$type = $row['Type'] ?? '';

if (stripos($type, 'Returning') !== false && stripos($type, 'Completed') !== false) {
    echo "delivery_status already has Returning and Completed. Nothing to do.\n";
    exit(0);
}

// Add new values: Scheduled, In Transit, Delivered, Returning, Completed
$sql = "ALTER TABLE delivery MODIFY COLUMN delivery_status ENUM('Scheduled', 'In Transit', 'Delivered', 'Returning', 'Completed') DEFAULT 'Scheduled'";
try {
    $conn->exec($sql);
    echo "✓ delivery_status ENUM updated (Added: Returning, Completed)\n";
} catch (Exception $e) {
    die("Error: " . $e->getMessage() . "\n");
}
