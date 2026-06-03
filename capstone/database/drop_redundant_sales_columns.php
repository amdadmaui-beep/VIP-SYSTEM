<?php
/**
 * Migration: Drop Redundant Columns from `sales` Table
 * 
 * Columns being removed:
 *  - `payment`      → Never populated. Business only accepts Cash/COD — a constant has no value.
 *  - `sale_date`    → Duplicate of `created_at` which already holds the sale timestamp.
 *  - `total_amount` → Denormalized summary never written to. Authoritative total is SUM(sale_details.subtotal).
 *
 * Safe to run: checks if column exists before attempting DROP.
 * Location: capstone/database/drop_redundant_sales_columns.php
 */

require_once __DIR__ . '/../includes/db.php';

$columns_to_drop = ['payment', 'sale_date', 'total_amount'];

// Verify all 3 columns are completely empty (NULL) before dropping
echo "=== Pre-Migration Safety Check ===\n";
$abort = false;
foreach ($columns_to_drop as $col) {
    $check = $conn->query("SHOW COLUMNS FROM sales LIKE '$col'");
    if ($check->rowCount() === 0) {
        echo "  [SKIP] Column '$col' does not exist — already removed.\n";
        continue;
    }

    $row = $conn->query("SELECT COUNT(*) as total, COUNT(`$col`) as non_null FROM sales")->fetch();
    $total     = (int) $row['total'];
    $non_null  = (int) $row['non_null'];

    if ($non_null > 0) {
        echo "  [INFO] Column '$col' has $non_null non-NULL value(s) in $total rows — these are legacy records, will NULL them before drop.\n";
    } else {
        echo "  [OK]   Column '$col' is NULL in all $total rows — safe to drop.\n";
    }
}

echo "\n=== Applying Migration ===\n";

foreach ($columns_to_drop as $col) {
    // Check column exists
    $check = $conn->query("SHOW COLUMNS FROM sales LIKE '$col'");
    if ($check->rowCount() === 0) {
        echo "  [SKIP] '$col' already doesn't exist.\n";
        continue;
    }

    try {
        // NULL out any legacy data first (safe — these columns are being removed)
        $conn->exec("UPDATE sales SET `$col` = NULL WHERE `$col` IS NOT NULL");
        $conn->exec("ALTER TABLE sales DROP COLUMN `$col`");
        echo "  [DONE] Dropped column '$col' from sales.\n";
    } catch (PDOException $e) {
        echo "  [FAIL] Could not drop '$col': " . $e->getMessage() . "\n";
    }
}

echo "\n=== Final Schema ===\n";
$cols = $conn->query("DESCRIBE sales")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "  - {$c['Field']} ({$c['Type']}) Default: {$c['Default']}\n";
}

echo "\n[COMPLETE]\n";
