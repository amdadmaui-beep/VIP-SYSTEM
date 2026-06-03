<?php
/**
 * Database Index Optimization Script
 * Adds performance indexes for frequently queried columns
 * 
 * Run this after deploying pagination fixes for optimal performance
 * Location: capstone/database/optimize_indexes.php
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

echo "=== Database Index Optimization ===\n\n";

$indexes = [
    // Account Receivable indexes
    'account_receivable' => [
        'idx_ar_customer_status' => 'CREATE INDEX idx_ar_customer_status ON account_receivable (Customer_ID, status)',
        'idx_ar_invoice_date' => 'CREATE INDEX idx_ar_invoice_date ON account_receivable (invoice_date)',
        'idx_ar_due_date' => 'CREATE INDEX idx_ar_due_date ON account_receivable (due_date)',
        'idx_ar_status_amount' => 'CREATE INDEX idx_ar_status_amount ON account_receivable (status, amount_due)',
    ],
    
    // Sales indexes
    'sales' => [
        'idx_sales_created_at' => 'CREATE INDEX idx_sales_created_at ON sales (created_at)',
        'idx_sales_customer' => 'CREATE INDEX idx_sales_customer ON sales (Customer_ID)',
        'idx_sales_status' => 'CREATE INDEX idx_sales_status ON sales (status)',
    ],
    
    // Orders indexes
    'orders' => [
        'idx_orders_status' => 'CREATE INDEX idx_orders_status ON orders (order_status)',
        'idx_orders_created' => 'CREATE INDEX idx_orders_created ON orders (created_at)',
        'idx_orders_customer' => 'CREATE INDEX idx_orders_customer ON orders (Customer_ID)',
    ],
    
    // Delivery indexes
    'delivery' => [
        'idx_delivery_order' => 'CREATE INDEX idx_delivery_order ON delivery (Order_ID)',
        'idx_delivery_status' => 'CREATE INDEX idx_delivery_status ON delivery (delivery_status)',
    ],
    
    // Products indexes
    'products' => [
        'idx_products_discontinued' => 'CREATE INDEX idx_products_discontinued ON products (is_discontinued)',
    ],
    
    // Inventory indexes
    'stockin_inventory' => [
        'idx_inventory_product' => 'CREATE INDEX idx_inventory_product ON stockin_inventory (Product_ID)',
        'idx_inventory_updated' => 'CREATE INDEX idx_inventory_updated ON stockin_inventory (updated_at)',
    ],
];

$total_created = 0;
$total_skipped = 0;
$errors = [];

foreach ($indexes as $table => $table_indexes) {
    echo "Processing table: $table\n";
    
    // Check if table exists
    $table_check = $conn->query("SHOW TABLES LIKE '$table'");
    if (!$table_check || $table_check->rowCount() === 0) {
        echo "  ⚠️  Table '$table' does not exist, skipping...\n";
        continue;
    }
    
    // Get existing indexes
    $existing = [];
    try {
        $index_query = $conn->query("SHOW INDEX FROM $table");
        while ($row = $index_query->fetch(PDO::FETCH_ASSOC)) {
            $existing[] = $row['Key_name'];
        }
    } catch (PDOException $e) {
        echo "  ⚠️  Could not check existing indexes: " . $e->getMessage() . "\n";
        continue;
    }
    
    foreach ($table_indexes as $index_name => $create_sql) {
        if (in_array($index_name, $existing)) {
            echo "  ✓ Index '$index_name' already exists\n";
            $total_skipped++;
            continue;
        }
        
        try {
            $conn->exec($create_sql);
            echo "  ✓ Created index '$index_name'\n";
            $total_created++;
        } catch (PDOException $e) {
            echo "  ✗ Failed to create index '$index_name': " . $e->getMessage() . "\n";
            $errors[] = "$table.$index_name: " . $e->getMessage();
        }
    }
    
    echo "\n";
}

echo "=== Optimization Complete ===\n";
echo "Indexes created: $total_created\n";
echo "Indexes skipped (already exist): $total_skipped\n";

if (!empty($errors)) {
    echo "\nErrors encountered:\n";
    foreach ($errors as $error) {
        echo "  - $error\n";
    }
}

echo "\n=== Recommended Next Steps ===\n";
echo "1. Run ANALYZE TABLE on optimized tables\n";
echo "2. Monitor query performance with EXPLAIN\n";
echo "3. Consider adding composite indexes for frequently joined columns\n";
