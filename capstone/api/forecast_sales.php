<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/forecast_pipeline.php';

header('Content-Type: application/json; charset=utf-8');

if (!vip_forecast_user_allowed($conn)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$days = (int) ($_GET['days'] ?? 90);
$days = max(30, min($days, 365));
$horizonW = (int) ($_GET['horizon_weeks'] ?? 4);
$horizonM = (int) ($_GET['horizon_months'] ?? 3);
$horizonDays = (int) ($_GET['horizon_days'] ?? 14);

try {
    $daily = vip_forecast_daily_demand_from_sales_db($conn, $days);
    $out = vip_forecast_pipeline($daily, $horizonW, $horizonM, $horizonDays);
    $out['source'] = 'database';
    $out['source_days'] = $days;
    vip_forecast_json_out($out);
} catch (Throwable $e) {
    error_log('forecast_sales failed: ' . $e->getMessage());
    http_response_code(500);
    vip_forecast_json_out([
        'success' => false,
        'error' => 'Server error',
    ]);
}
