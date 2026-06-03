<?php
// Require authentication (session/JWT) just like other APIs.
require_once __DIR__ . '/../includes/auth.php';

// Only allow downloading files from /capstone/reports by filename.
$file = (string)($_GET['file'] ?? '');
$file = trim($file);

if ($file === '' || basename($file) !== $file) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Invalid file parameter.']);
    exit;
}

$reportsDir = realpath(__DIR__ . '/../reports');
if (!$reportsDir) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Reports directory not found on server.']);
    exit;
}

$path = realpath($reportsDir . DIRECTORY_SEPARATOR . $file);
if (!$path || strpos($path, $reportsDir) !== 0 || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Report not found.']);
    exit;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$contentType = 'application/octet-stream';
if ($ext === 'csv') {
    $contentType = 'text/csv; charset=utf-8';
} elseif ($ext === 'xls' || $ext === 'xlsx') {
    // We generate HTML-table .xls; Excel still opens it fine.
    $contentType = 'application/vnd.ms-excel';
}

// Clean any previous output buffer to avoid corrupting download.
while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: ' . $contentType);
header('Content-Disposition: attachment; filename="' . basename($file) . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');

readfile($path);
exit;

