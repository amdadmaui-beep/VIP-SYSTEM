<?php
declare(strict_types=1);

if (!function_exists('publishRealtimeEvent')) {
    function publishRealtimeEvent(array $event): void
    {
        $baseDir = __DIR__;
        $queueDir = $baseDir . '/queue';
        if (!is_dir($queueDir)) {
            @mkdir($queueDir, 0775, true);
        }

        $payload = [
            'event' => (string)($event['event'] ?? 'unknown'),
            'timestamp' => time(),
            'data' => is_array($event['data'] ?? null) ? $event['data'] : []
        ];

        $line = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            return;
        }

        @file_put_contents($queueDir . '/events.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

