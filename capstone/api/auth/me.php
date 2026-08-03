<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/jwt.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse(false, ['message' => 'Method not allowed'], 405);
    }

    $token = getBearerToken();
    if (!$token) {
        jsonResponse(false, ['message' => 'Missing Authorization: Bearer token'], 401);
    }

    $claims = jwtVerify($token);
    jsonResponse(true, ['claims' => $claims]);
} catch (Throwable $e) {
    jsonResponse(false, ['message' => 'Invalid or expired token'], 401);
}

