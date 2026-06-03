<?php
/**
 * Migration: Add soft delete support to customers table
 * Adds `deleted_at` column for soft delete functionality
 */

$migration_name = 'migrate_customers_soft_delete';

require_once __DIR__ . '/../includes/db.php';

try {
    // Check if column already exists
    $check = $conn->query("SHOW COLUMNS FROM customers LIKE 'deleted_at'");
    if ($check && $check->rowCount() > 0) {
        echo "Column 'deleted_at' already exists in customers table.\n";
        exit(0);
    }

    $conn->exec("ALTER TABLE customers ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER credit_limit");
    echo "Migration successful: Added 'deleted_at' column to customers table.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
