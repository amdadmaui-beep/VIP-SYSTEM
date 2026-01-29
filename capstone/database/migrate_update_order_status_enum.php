<?php
/**
 * Migration Script: Update order_status ENUM
 * This script automatically detects the column name and updates the ENUM values
 * to include: 'pending', 'out for delivery', 'delivered', 'cancelled'
 */

require_once __DIR__ . '/../includes/db.php';

echo "Starting migration: Update order_status ENUM...\n";

try {
    // Detect the actual column name
    $columns_result = $conn->query("SHOW COLUMNS FROM orders");
    $status_column_name = null;
    $current_enum = null;
    
    while ($row = $columns_result->fetch_assoc()) {
        if ($row['Field'] === 'order_status' || $row['Field'] === 'status') {
            $status_column_name = $row['Field'];
            $current_enum = $row['Type'];
            break;
        }
    }
    $columns_result->close();
    
    if (!$status_column_name) {
        throw new Exception("Could not find order_status or status column in orders table");
    }
    
    echo "Found column: {$status_column_name}\n";
    echo "Current type: {$current_enum}\n";
    
    // Build new ENUM with all status values (Requested removed as requested)
    $new_enum_values = [
        'pending',
        'Confirmed',
        'Scheduled for Delivery',
        'out for delivery',
        'Out for Delivery',
        'delivered',
        'Delivered (Pending Cash Turnover)',
        'Completed',
        'cancelled',
        'Cancelled'
    ];
    
    // Escape enum values for SQL
    $enum_sql = "'" . implode("','", array_map(function($v) use ($conn) {
        return $conn->real_escape_string($v);
    }, $new_enum_values)) . "'";
    
    $sql = "ALTER TABLE orders MODIFY COLUMN {$status_column_name} ENUM({$enum_sql}) DEFAULT 'pending'";
    
    echo "Executing: {$sql}\n";
    
    if ($conn->query($sql)) {
        echo "✓ Successfully updated {$status_column_name} ENUM\n";
        echo "New status values available:\n";
        foreach ($new_enum_values as $status) {
            echo "  - {$status}\n";
        }
    } else {
        throw new Exception("Failed to update ENUM: " . $conn->error);
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

$conn->close();
echo "\nMigration completed successfully!\n";
