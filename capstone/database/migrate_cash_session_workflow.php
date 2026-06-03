<?php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/cash_session_helper.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    ensureCashSessionWorkflowSchema($conn);
    echo "Cash session workflow schema verified.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Migration failed: " . $e->getMessage() . "\n";
}
