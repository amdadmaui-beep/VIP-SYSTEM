<?php
/**
 * Backfill destination_lat/destination_lng for deliveries missing coordinates.
 * Run: php capstone/database/backfill_delivery_destinations.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

$base = dirname(__DIR__);
require_once $base . '/includes/db.php';

function geocodeAddress($address) {
    $address = trim((string)$address);
    if ($address === '') return null;

    $queries = [];
    if (stripos($address, 'cagayan de oro') === false) {
        $queries[] = $address . ', Cagayan de Oro, Misamis Oriental, Philippines';
    }
    $queries[] = $address;
    if (stripos($address, 'philippines') === false) {
        $queries[] = $address . ', Philippines';
    }

    foreach ($queries as $q) {
        $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=' . rawurlencode($q);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'header' => "User-Agent: VIP-Ice-Plant-Destination-Backfill/1.0\r\nAccept: application/json\r\n"
            ]
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) continue;
        $json = json_decode($resp, true);
        if (!is_array($json) || empty($json[0])) continue;
        $lat = isset($json[0]['lat']) ? (float)$json[0]['lat'] : null;
        $lng = isset($json[0]['lon']) ? (float)$json[0]['lon'] : null;
        if ($lat === null || $lng === null) continue;
        if (abs($lat) < 0.000001 && abs($lng) < 0.000001) continue;
        $localHint = preg_match('/tablon|bugo|cagayan de oro|jasaan/i', $address) === 1;
        if ($localHint && !($lat >= 7.8 && $lat <= 9.2 && $lng >= 123.8 && $lng <= 125.6)) continue;
        return ['lat' => $lat, 'lng' => $lng, 'query' => $q];
    }

    // Fallback geocoder: Photon (komoot)
    foreach ($queries as $q) {
        $url = 'https://photon.komoot.io/api/?limit=1&q=' . rawurlencode($q);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'header' => "User-Agent: VIP-Ice-Plant-Destination-Backfill/1.0\r\nAccept: application/json\r\n"
            ]
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) continue;
        $json = json_decode($resp, true);
        $coords = $json['features'][0]['geometry']['coordinates'] ?? null;
        if (!is_array($coords) || count($coords) < 2) continue;
        $lng = isset($coords[0]) ? (float)$coords[0] : null;
        $lat = isset($coords[1]) ? (float)$coords[1] : null;
        if ($lat === null || $lng === null) continue;
        if (abs($lat) < 0.000001 && abs($lng) < 0.000001) continue;
        $localHint = preg_match('/tablon|bugo|cagayan de oro|jasaan/i', $address) === 1;
        if ($localHint && !($lat >= 7.8 && $lat <= 9.2 && $lng >= 123.8 && $lng <= 125.6)) continue;
        return ['lat' => $lat, 'lng' => $lng, 'query' => $q . ' [photon]'];
    }

    return null;
}

echo "Backfill delivery destinations\n";
echo "=============================\n";

// Ensure required columns exist.
$deliveryCols = array_column($conn->query("SHOW COLUMNS FROM delivery")->fetchAll(PDO::FETCH_ASSOC), 'Field');
foreach (['destination_lat', 'destination_lng'] as $requiredCol) {
    if (!in_array($requiredCol, $deliveryCols, true)) {
        echo "Columns destination_lat and destination_lng do not exist. Skipping backfill.\n";
        exit(0);
    }
}

$orderCols = array_column($conn->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_ASSOC), 'Field');
$orderAddrExpr = in_array('delivery_address', $orderCols, true) ? "NULLIF(TRIM(o.delivery_address), '')" : "NULL";

$sql = "SELECT d.Delivery_ID,
               COALESCE(NULLIF(TRIM(d.delivery_address), ''), {$orderAddrExpr}, NULLIF(TRIM(c.address), '')) AS resolved_address
        FROM delivery d
        LEFT JOIN orders o ON d.Order_ID = o.Order_ID
        LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID
        WHERE (d.destination_lat IS NULL OR d.destination_lng IS NULL OR (d.destination_lat = 0 AND d.destination_lng = 0))
          AND COALESCE(NULLIF(TRIM(d.delivery_address), ''), {$orderAddrExpr}, NULLIF(TRIM(c.address), '')) IS NOT NULL
        ORDER BY d.Delivery_ID ASC";

$rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
echo "Missing rows found: " . count($rows) . "\n";

if (empty($rows)) {
    echo "Nothing to backfill.\n";
    exit(0);
}

$updated = 0;
$failed = 0;

$upd = $conn->prepare("UPDATE delivery SET destination_lat = ?, destination_lng = ?, updated_at = NOW() WHERE Delivery_ID = ?");

foreach ($rows as $row) {
    $deliveryId = (int)$row['Delivery_ID'];
    $address = trim((string)($row['resolved_address'] ?? ''));
    if ($address === '') {
        $failed++;
        echo "[SKIP] #{$deliveryId}: empty address\n";
        continue;
    }

    $coords = geocodeAddress($address);
    if (!$coords) {
        $failed++;
        echo "[MISS] #{$deliveryId}: {$address}\n";
        usleep(1200000); // 1.2s to respect Nominatim usage
        continue;
    }

    $upd->execute([$coords['lat'], $coords['lng'], $deliveryId]);
    $updated++;
    echo "[OK] #{$deliveryId} -> {$coords['lat']}, {$coords['lng']} ({$coords['query']})\n";
    usleep(1200000); // 1.2s to respect Nominatim usage
}

echo "-----------------------------\n";
echo "Updated: {$updated}\n";
echo "Failed: {$failed}\n";
echo "Done.\n";

