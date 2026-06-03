<?php
// Lightweight export endpoint for production (CSV for Excel/Sheets)
// No auth redirect here so it never bounces to the homepage

require_once __DIR__ . '/../includes/auth.php';
require_once '../includes/db.php';
try {

// Determine export date range:
// - Preferred: ?start=YYYY-MM-DD and/or ?end=YYYY-MM-DD
// - Legacy:   ?date=YYYY-MM-DD or ?date=today (single day)
// - Default:  today's date from DB (single day)
$startDate = null;
$endDate = null;

if (!empty($_GET['start']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start'])) {
    $startDate = $_GET['start'];
}
if (!empty($_GET['end']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end'])) {
    $endDate = $_GET['end'];
}

// Fallback to legacy ?date= param if no start/end
if ($startDate === null && $endDate === null && isset($_GET['date']) && $_GET['date'] !== '') {
    if ($_GET['date'] === 'today') {
        // handled after fetching today's date
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) {
        $startDate = $_GET['date'];
        $endDate = $_GET['date'];
    }
}

// Get today's date from DB to avoid timezone drift
$today = $conn->query("SELECT CURDATE()")->fetchColumn() ?: date('Y-m-d');

// If still no explicit range, default to today
if ($startDate === null && $endDate === null) {
    $startDate = $today;
    $endDate   = $today;
} elseif ($startDate === null && $endDate !== null) {
    $startDate = $endDate;
} elseif ($startDate !== null && $endDate === null) {
    $endDate = $startDate;
}

// Ensure start <= end
if ($startDate > $endDate) {
    $tmp = $startDate;
    $startDate = $endDate;
    $endDate = $tmp;
}

// Include productions within selected date range
$query = "SELECT 
    p.production_date,
    pr.product_name,
    u.unit_name,
    p.number_of_bags,
    pr.retail_price
FROM productions p
INNER JOIN products pr ON p.Product_ID = pr.Product_ID
LEFT JOIN units u ON pr.unit_id = u.unit_id
WHERE DATE(p.production_date) BETWEEN ? AND ?
ORDER BY p.production_date ASC, p.Production_ID ASC";

$stmt = $conn->prepare($query);
$stmt->execute([$startDate, $endDate]);
$result = $stmt->fetchAll();

// Build rows
$rows = [];
$totalQty = 0;
$totalAmount = 0.0;

foreach ($result as $row) {
    $product = $row['product_name'];
    $unit = $row['unit_name'];          // Qty Unit (e.g. KG)
    $qtyBags = (int) ($row['number_of_bags'] ?? 0);
    $price = (float) ($row['retail_price'] ?? 0);
    $lineTotal = $qtyBags * $price;

    $totalQty += $qtyBags;
    $totalAmount += $lineTotal;

    $rows[] = [
        $product,
        $unit,
        $qtyBags,
        '₱' . number_format($price, 2),
        '₱' . number_format($lineTotal, 2),
    ];
}

// Send headers for CSV download
$filename = ($startDate === $endDate)
    ? ('production_report_' . $startDate . '.csv')
    : ('production_report_' . $startDate . '_to_' . $endDate . '.csv');
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Optional BOM so Excel handles UTF-8/peso sign correctly
echo "\xEF\xBB\xBF";

// Helper to write one CSV line
$writeCsvLine = function (array $fields) {
    $escaped = array_map(function ($v) {
        $v = (string) $v;
        $v = str_replace('"', '""', $v); // escape quotes
        return '"' . $v . '"';
    }, $fields);
    echo implode(',', $escaped) . "\r\n";
};

// Header row in the requested format
$writeCsvLine([
    'Product',
    'Unit',
    'Qty Unit',
    'Qty',
    'Price',
    'Total',
]);

// Data rows (only today's records due to WHERE clause)
foreach ($rows as $r) {
    $writeCsvLine($r);
}

// Totals row
$writeCsvLine([
    'Totals',
    '',
    '',
    $totalQty,
    '',
    '₱' . number_format($totalAmount, 2),
]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('export_production_today failed: ' . $e->getMessage());
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => false, 'error' => 'Export failed']);
}

