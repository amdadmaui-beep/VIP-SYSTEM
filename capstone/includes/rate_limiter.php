<?php
declare(strict_types=1);

if (!function_exists('checkRateLimit')) {
    function checkRateLimit(string $key, int $maxRequests = 60, int $windowSeconds = 60): array {
        $dir = __DIR__ . '/../cache/rate_limits';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $file = $dir . '/' . md5($key) . '.json';
        $data = ['count' => 0, 'reset' => time() + $windowSeconds];

        if (file_exists($file)) {
            $raw = @file_get_contents($file);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }

        $now = time();
        if ($now > $data['reset']) {
            $data = ['count' => 0, 'reset' => $now + $windowSeconds];
        }

        $data['count']++;

        @file_put_contents($file, json_encode($data), LOCK_EX | LOCK_NB);

        $retryAfter = max(0, $data['reset'] - $now);
        $allowed = $data['count'] <= $maxRequests;

        return ['allowed' => $allowed, 'retryAfter' => $retryAfter];
    }
}

if (!function_exists('rateLimitKey')) {
    function rateLimitKey(string $prefix = 'api'): string {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $uid = $_SESSION['user_id'] ?? 'anon';
        return $prefix . ':' . $ip . ':' . $uid;
    }
}

if (!function_exists('enforceRateLimit')) {
    function enforceRateLimit(string $key, int $maxRequests = 60, int $windowSeconds = 60): void {
        $result = checkRateLimit($key, $maxRequests, $windowSeconds);
        if (!$result['allowed']) {
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
            header('Retry-After: ' . $result['retryAfter']);
            echo json_encode([
                'success' => false,
                'message' => 'Too many requests. Please try again in ' . $result['retryAfter'] . ' seconds.',
                'retry_after' => $result['retryAfter'],
            ]);
            exit;
        }
    }
}
