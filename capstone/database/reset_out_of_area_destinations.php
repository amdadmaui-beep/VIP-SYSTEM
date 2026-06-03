<?php
/**
 * Reset clearly wrong destination coordinates for local CDO deliveries.
 * Run before backfill to recalculate with better locality constraints.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$base = dirname(__DIR__);
require_once $base . '/includes/db.php';

$deliveryCols = array_column($conn->query("SHOW COLUMNS FROM delivery")->fetchAll(PDO::FETCH_ASSOC), 'Field');
if (!in_array('destination_lat', $deliveryCols, true)) {
    echo "Columns destination_lat and destination_lng do not exist. Skipping reset.\n";
    exit(0);
}

$orderCols = array_column($conn->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_ASSOC), 'Field');
$orderAddrExpr = in_array('delivery_address', $orderCols, true) ? "o.delivery_address" : "NULL";

$sql = "UPDATE delivery d
        LEFT JOIN orders o ON d.Order_ID = o.Order_ID
        LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
        SET d.destination_lat = NULL,
            d.destination_lng = NULL,
            d.updated_at = NOW()
        WHERE (
            LOWER(COALESCE(d.delivery_address, {$orderAddrExpr}, c.address, '')) REGEXP 'tablon|bugo|cagayan de oro|jasaan'
        )
          AND d.destination_lat IS NOT NULL
          AND d.destination_lng IS NOT NULL
          AND NOT (d.destination_lat BETWEEN 7.8 AND 9.2 AND d.destination_lng BETWEEN 123.8 AND 125.6)";

$affected = $conn->exec($sql);
echo "Reset rows: " . (int)$affected . PHP_EOL;

