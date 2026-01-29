<?php
/**
 * Migration Script: Add order_time column to orders table
 * 
 * This script adds the order_time column to the orders table if it doesn't exist.
 * Run this script once to fix the "Unknown column 'order_time' in 'field list'" error.
 * 
 * Usage: Navigate to this file in your browser or run via command line:
 * php migrate_add_order_time.php
 */

require_once '../includes/db.php';

echo "Starting migration: Add order_time column to orders table...\n\n";

// Check if column already exists
$check_query = "SHOW COLUMNS FROM orders LIKE 'order_time'";
$result = $conn->query($check_query);

if ($result && $result->num_rows > 0) {
    echo "✓ Column 'order_time' already exists. No migration needed.\n";
    $result->close();
    exit(0);
}

// Add the column
$alter_query = "ALTER TABLE orders ADD COLUMN order_time TIME NULL AFTER order_date";
if ($conn->query($alter_query)) {
    echo "✓ Successfully added 'order_time' column to orders table.\n";
    
    // Update existing records to have a default time based on created_at
    $update_query = "UPDATE orders SET order_time = TIME(created_at) WHERE order_time IS NULL";
    if ($conn->query($update_query)) {
        $affected = $conn->affected_rows;
        echo "✓ Updated $affected existing order(s) with default order_time.\n";
    } else {
        echo "⚠ Warning: Could not update existing orders: " . $conn->error . "\n";
    }
    
    echo "\nMigration completed successfully!\n";
} else {
    echo "✗ Error adding column: " . $conn->error . "\n";
    exit(1);
}

$conn->close();
?>
