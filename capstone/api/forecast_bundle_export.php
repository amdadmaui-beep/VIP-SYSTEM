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
$horizonW = (int) ($_GET['horizon_weeks'] ?? 4);
$horizonM = (int) ($_GET['horizon_months'] ?? 3);
$horizonDays = (int) ($_GET['horizon_days'] ?? 14);

$daily = vip_forecast_daily_demand_from_sales_db($conn, $days);
$out = vip_forecast_pipeline($daily, $horizonW, $horizonM, $horizonDays);

if (empty($out['success'])) {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    vip_forecast_json_out($out);
    exit;
}

$fn = 'forecast_bundle_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fn . '"');

echo vip_forecast_bundle_to_csv($out, 'database_sales');
