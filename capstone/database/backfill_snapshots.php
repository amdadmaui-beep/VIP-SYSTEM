<?php
/**
 * One-time backfill: populate snapshot columns for all existing orders.
 * Run once: php database/backfill_snapshots.php (from capstone/)
 */
require_once __DIR__ . '/../includes/db.php';

// --- Backfill orders ---
$o_count = $conn->exec("
    UPDATE orders o
    JOIN customers c ON o.Customer_ID = c.Customer_ID
    SET o.customer_name_snapshot = c.customer_name,
        o.customer_phone_snapshot = c.phone_number,
        o.customer_address_snapshot = c.address
    WHERE o.customer_name_snapshot IS NULL
");
echo "Backfilled orders: $o_count rows\n";

// --- Backfill order_details ---
$d_count = $conn->exec("
    UPDATE order_details od
    JOIN products p ON od.Product_ID = p.Product_ID
    LEFT JOIN units u ON p.unit_id = u.unit_id
    SET od.product_name_snapshot = p.product_name,
        od.unit_name_snapshot = u.unit_name
    WHERE od.product_name_snapshot IS NULL
");
echo "Backfilled order_details: $d_count rows\n";

echo "Done.\n";
