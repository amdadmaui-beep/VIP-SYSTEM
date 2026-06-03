<?php
/**
 * Verify Foreign Keys Were Added Successfully
 * Execute: php database/verify_foreign_keys.php (from capstone/)
 * 
 * This script displays all foreign keys in the database and verifies
 * that the expected constraints are in place.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$base = dirname(__DIR__);
require_once $base . '/includes/db.php';

echo "Foreign Key Verification Report\n";
echo str_repeat("=", 70) . "\n\n";

// Get all foreign keys from database
$sql = "
    SELECT 
        TABLE_NAME,
        COLUMN_NAME,
        CONSTRAINT_NAME,
        REFERENCED_TABLE_NAME,
        REFERENCED_COLUMN_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
    AND REFERENCED_TABLE_NAME IS NOT NULL
    ORDER BY TABLE_NAME, COLUMN_NAME
";

try {
    $result = $conn->query($sql);
    $foreignKeys = $result->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($foreignKeys)) {
        echo "⚠️  No foreign keys found in the database.\n";
        echo "   Run: php database/add_foreign_keys.php\n";
        exit(1);
    }
    
    echo "Found " . count($foreignKeys) . " foreign key constraints:\n\n";
    
    // Group by table
    $grouped = [];
    foreach ($foreignKeys as $fk) {
        $table = $fk['TABLE_NAME'];
        if (!isset($grouped[$table])) {
            $grouped[$table] = [];
        }
        $grouped[$table][] = $fk;
    }
    
    // Display by table
    foreach ($grouped as $table => $fks) {
        echo "Table: {$table}\n";
        echo str_repeat("-", 70) . "\n";
        foreach ($fks as $fk) {
            echo sprintf(
                "  %-30s -> %-20s.%s\n",
                $fk['COLUMN_NAME'] . " (" . $fk['CONSTRAINT_NAME'] . ")",
                $fk['REFERENCED_TABLE_NAME'],
                $fk['REFERENCED_COLUMN_NAME']
            );
        }
        echo "\n";
    }
    
    // Check for expected foreign keys
    $expectedFKs = [
        'sale_details' => ['Sale_ID', 'Product_ID'],
        'order_details' => ['Order_ID', 'Product_ID'],
        'delivery_detail' => ['Delivery_ID', 'Product_ID'],
        'account_receivable' => ['Customer_ID', 'Sale_ID'],
        'productions' => ['Product_ID'],
        'stockin_inventory' => ['Product_ID'],
        'delivery' => ['Order_ID'],
        'sales' => ['Customer_ID', 'User_ID'],
        'orders' => ['Customer_ID'],
        'adjustment_details' => ['Adjustment_ID', 'Product_ID'],
    ];
    
    echo str_repeat("=", 70) . "\n";
    echo "Expected Foreign Keys Status\n";
    echo str_repeat("=", 70) . "\n\n";
    
    $allPassed = true;
    foreach ($expectedFKs as $table => $columns) {
        echo "Table: {$table}\n";
        $tableFKs = $grouped[$table] ?? [];
        $existingColumns = array_column($tableFKs, 'COLUMN_NAME');
        
        foreach ($columns as $column) {
            $found = in_array($column, $existingColumns);
            $status = $found ? '✅' : '❌';
            echo "  {$status} {$column}\n";
            if (!$found) {
                $allPassed = false;
            }
        }
        echo "\n";
    }
    
    echo str_repeat("=", 70) . "\n";
    if ($allPassed) {
        echo "✅ All expected foreign keys are in place!\n";
    } else {
        echo "⚠️  Some expected foreign keys are missing.\n";
        echo "   Run: php database/add_foreign_keys.php\n";
    }
    echo str_repeat("=", 70) . "\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
