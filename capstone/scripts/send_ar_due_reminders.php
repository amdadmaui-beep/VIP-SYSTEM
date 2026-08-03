<?php
declare(strict_types=1);

if (PHP_SAPI === 'cli' && session_status() === PHP_SESSION_NONE) {
    ini_set('session.save_path', sys_get_temp_dir());
    session_start();
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/ar_reminder_helper.php';

$targetDate = null;
$limit = 200;

foreach (array_slice($argv ?? [], 1) as $arg) {
    if (strpos($arg, '--date=') === 0) {
        $targetDate = substr($arg, 7);
        continue;
    }
    if (strpos($arg, '--limit=') === 0) {
        $limit = (int)substr($arg, 8);
    }
}

if ($targetDate !== null) {
    $parts = explode('-', $targetDate);
    if (count($parts) !== 3 || !checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
        fwrite(STDERR, "Invalid --date value. Use YYYY-MM-DD.\n");
        exit(1);
    }
}

try {
    $result = arReminderSendDueReminders($conn, $targetDate, $limit);
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
    exit($result['failed'] > 0 ? 2 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, "Failed to send AR due reminders: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
