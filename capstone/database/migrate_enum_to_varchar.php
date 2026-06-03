<?php
/**
 * Migration: Replace ENUM columns with VARCHAR
 * 
 * ENUM types are brittle — every new value requires ALTER TABLE.
 * VARCHAR is more flexible and allows the same data validation in application code.
 * 
 * Changed columns:
 *   - orders.order_status / orders.status: ENUM → VARCHAR(50)
 *   - delivery.delivery_status: ENUM → VARCHAR(50)
 *   - sales.status: ENUM → VARCHAR(50)
 * 
 * Run: php database/migrate_enum_to_varchar.php (from capstone/)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$base = dirname(__DIR__);
require_once $base . '/includes/db.php';

$changes = [
    [
        'table' => 'delivery',
        'column' => 'delivery_status',
        'default' => "'Scheduled'",
        'desc' => 'delivery.delivery_status',
    ],
    [
        'table' => 'sales',
        'column' => 'status',
        'default' => "'Pending'",
        'desc' => 'sales.status',
    ],
];

// Check which column name orders uses: order_status or status
try {
    $colCheck = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
    $ordersCol = null;
    while ($row = $colCheck->fetch(PDO::FETCH_ASSOC)) {
        if (stripos($row['Type'] ?? '', 'enum') !== false) {
            $ordersCol = $row['Field'];
            break;
        }
    }
    if ($ordersCol) {
        $changes[] = [
            'table' => 'orders',
            'column' => $ordersCol,
            'default' => "'Requested'",
            'desc' => "orders.{$ordersCol}",
        ];
    } else {
        echo "[WARN] No ENUM column found in orders table (checked: order_status, status)\n";
    }
} catch (Exception $e) {
    echo "[ERROR] Cannot inspect orders table: " . $e->getMessage() . "\n";
}

echo "Converting ENUM columns to VARCHAR...\n\n";

foreach ($changes as $c) {
    $table = $c['table'];
    $column = $c['column'];
    $default = $c['default'];
    $desc = $c['desc'];

    // Check if column exists and is ENUM
    try {
        $colRow = $conn->query("SHOW COLUMNS FROM {$table} WHERE Field = '{$column}'")->fetch(PDO::FETCH_ASSOC);
        if (!$colRow) {
            echo "  [SKIP] {$desc} - column not found\n";
            continue;
        }
        if (stripos($colRow['Type'] ?? '', 'enum') === false) {
            echo "  [SKIP] {$desc} - already VARCHAR or other type: {$colRow['Type']}\n";
            continue;
        }
    } catch (Exception $e) {
        echo "  [SKIP] {$desc} - cannot inspect: " . $e->getMessage() . "\n";
        continue;
    }

    try {
        $conn->exec("ALTER TABLE {$table} MODIFY COLUMN {$column} VARCHAR(50) DEFAULT {$default}");
        echo "  [OK] {$desc} → VARCHAR(50)\n";
    } catch (Exception $e) {
        echo "  [ERROR] {$desc}: " . $e->getMessage() . "\n";
    }
}

echo "\nMigration complete.\n";
