<?php
declare(strict_types=1);

if (!function_exists('jsonResponse')) {
    function jsonResponse(bool $success, $data = [], int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        $payload = ['success' => $success];
        if (is_array($data)) {
            $payload = array_merge($payload, $data);
        } else {
            $payload['message'] = $data;
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
