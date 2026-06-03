<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/forecast_pipeline.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json; charset=utf-8');

if (!vip_forecast_user_allowed($conn)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

if (!validateCsrfToken(false)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired security token. Please refresh the page and try again.']);
    exit;
}

$horizonW = (int) ($_POST['horizon_weeks'] ?? 4);
$horizonM = (int) ($_POST['horizon_months'] ?? 3);
$horizonDays = (int) ($_POST['horizon_days'] ?? 14);

try {
    $raw = '';
    if (!empty($_FILES['csv']['tmp_name']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
        $raw = (string) file_get_contents($_FILES['csv']['tmp_name']);
    } elseif (isset($_POST['csv_text'])) {
        $raw = (string) $_POST['csv_text'];
    }

    if ($raw === '') {
        echo json_encode(['success' => false, 'error' => 'No CSV file or csv_text provided.']);
        exit;
    }

    if (strlen($raw) > 2_000_000) {
        echo json_encode(['success' => false, 'error' => 'CSV too large (max ~2MB).']);
        exit;
    }

    $daily = vip_forecast_parse_daily_csv($raw);
    if ($daily === []) {
        echo json_encode(['success' => false, 'error' => 'Could not parse any valid date,revenue rows. Use header: date,revenue']);
        exit;
    }

    $out = vip_forecast_pipeline($daily, $horizonW, $horizonM, $horizonDays);
    $out['source'] = 'csv_import';
    $out['imported_rows'] = count($daily);
    vip_forecast_json_out($out);
} catch (Throwable $e) {
    error_log('forecast_import failed: ' . $e->getMessage());
    http_response_code(500);
    vip_forecast_json_out([
        'success' => false,
        'error' => 'Server error',
    ]);
}
