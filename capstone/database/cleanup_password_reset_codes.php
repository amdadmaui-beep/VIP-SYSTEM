<?php
/**
 * Cleanup script for password_reset_codes.
 * Safe for cron/scheduler usage.
 *
 * Suggested schedule:
 * - Every 15 minutes
 * Example (Linux cron):
 *   every 15 minutes -> /usr/bin/php /path/to/capstone/database/cleanup_password_reset_codes.php
 */
require_once __DIR__ . '/../includes/db.php';

try {
    // Remove expired and already-used rows older than 1 day.
    $stmt = $conn->prepare("
        DELETE FROM password_reset_codes
        WHERE expires_at < NOW()
           OR (used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL 1 DAY))
    ");
    $stmt->execute();
    $deleted = $stmt->rowCount();

    echo "Cleanup complete. Deleted rows: " . $deleted . "\n";
    exit(0);
} catch (Throwable $e) {
    echo "Cleanup failed: " . $e->getMessage() . "\n";
    exit(1);
}

