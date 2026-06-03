<?php
/**
 * Migration: Create order_details and roles tables with proper DDL
 * 
 * These tables were previously created at runtime (order_details via INSERT queries,
 * roles via manual setup). This migration ensures they have explicit schema definitions.
 * 
 * Run: php database/migrate_create_order_details_and_roles.php (from capstone/)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$base = dirname(__DIR__);
require_once $base . '/includes/db.php';

echo "Creating order_details table...\n";
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS order_details (
            Order_detail_ID INT AUTO_INCREMENT PRIMARY KEY,
            Order_ID INT NOT NULL,
            Product_ID INT NOT NULL,
            ordered_qty DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            product_name_snapshot VARCHAR(255) DEFAULT NULL,
            unit_name_snapshot VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order_detail_order (Order_ID),
            INDEX idx_order_detail_product (Product_ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  [OK] order_details table ready\n";
} catch (Exception $e) {
    echo "  [ERROR] " . $e->getMessage() . "\n";
}

echo "Creating roles table...\n";
try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS roles (
            Role_ID INT AUTO_INCREMENT PRIMARY KEY,
            role_name VARCHAR(50) NOT NULL,
            role_description VARCHAR(255) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  [OK] roles table ready\n";
} catch (Exception $e) {
    echo "  [ERROR] " . $e->getMessage() . "\n";
}

// Insert default roles only if table is empty
$count = $conn->query("SELECT COUNT(*) FROM roles")->fetchColumn();
if ((int)$count === 0) {
    echo "Inserting default roles...\n";
    try {
        $conn->exec("
            INSERT INTO roles (Role_ID, role_name, role_description) VALUES
            (1, 'owner', 'Full access to all modules including User Management'),
            (2, 'cashier', 'Access to Sales module only'),
            (3, 'delivery_rider', 'Access to Delivery/Rider module only'),
            (4, 'manager', 'Management access to oversee operations'),
            (5, 'inventory_staff', 'Access to Inventory module only')
        ");
        echo "  [OK] Default roles inserted\n";
    } catch (Exception $e) {
        echo "  [SKIP] " . $e->getMessage() . "\n";
    }
} else {
    echo "  [SKIP] Roles table already has $count entries\n";
}

// Add FKs for order_details if they don't exist
echo "Adding foreign keys for order_details...\n";
try {
    $conn->exec("
        ALTER TABLE order_details
        ADD CONSTRAINT fk_od_orders
        FOREIGN KEY (Order_ID) REFERENCES orders(Order_ID)
        ON DELETE CASCADE ON UPDATE CASCADE
    ");
    echo "  [OK] FK: order_details.Order_ID -> orders.Order_ID\n";
} catch (Exception $e) {
    echo "  [SKIP] " . $e->getMessage() . "\n";
}

try {
    $conn->exec("
        ALTER TABLE order_details
        ADD CONSTRAINT fk_od_products
        FOREIGN KEY (Product_ID) REFERENCES products(Product_ID)
        ON DELETE RESTRICT ON UPDATE CASCADE
    ");
    echo "  [OK] FK: order_details.Product_ID -> products.Product_ID\n";
} catch (Exception $e) {
    echo "  [SKIP] " . $e->getMessage() . "\n";
}

echo "\nMigration complete.\n";
