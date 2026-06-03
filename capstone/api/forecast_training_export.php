<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/forecast_pipeline.php';

if (!vip_forecast_user_allowed($conn)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

$days = (int) ($_GET['days'] ?? 90);
$days = max(30, min($days, 365));
$daily = vip_forecast_daily_demand_from_sales_db($conn, $days);

$fn = 'sales_daily_demand_training_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fn . '"');

echo "\xEF\xBB\xBF";
echo "date,total_bags,revenue\r\n";
foreach ($daily as $r) {
    echo vip_csv_escape($r['date']) . ','
        . vip_csv_escape((string) $r['total_bags']) . ','
        . vip_csv_escape((string) $r['revenue']) . "\r\n";
}
