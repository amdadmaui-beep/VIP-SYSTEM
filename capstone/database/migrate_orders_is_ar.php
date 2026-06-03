<?php
$migration_name = 'migrate_orders_is_ar';
require_once __DIR__ . '/../includes/db.php';

try {
    $check = $conn->query("SHOW COLUMNS FROM orders LIKE 'is_ar'");
    if ($check && $check->rowCount() > 0) {
        echo "Column 'is_ar' already exists in orders table.\n";
        exit(0);
    }
    $conn->exec("ALTER TABLE orders ADD COLUMN is_ar TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_method");
    echo "Migration successful: Added 'is_ar' column to orders table.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
