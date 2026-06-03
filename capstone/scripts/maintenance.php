<?php
/**
 * Maintenance Script — run periodically (e.g., daily cron)
 *
 * 1. Backup retention: keep last 7 database dumps, delete rest
 * 2. Routing cache cleanup: delete cache files older than 7 days
 * 3. Events log rotation: truncate if > 1 MB
 *
 * Usage: php scripts/maintenance.php
 */

$rootDir = dirname(__DIR__);
echo "[Maintenance] Starting...\n";

// ── 1. Backup retention ─────────────────────────────────────────────────────
$backupDir = $rootDir . '/backups';
if (is_dir($backupDir)) {
    $files = glob($backupDir . '/*.sql') + glob($backupDir . '/*.sql.gz');
    usort($files, function ($a, $b) {
        return filemtime($a) - filemtime($b);
    });
    $keep = 7;
    $toDelete = array_slice($files, 0, max(0, count($files) - $keep));
    foreach ($toDelete as $f) {
        unlink($f);
        echo "  [backup] Deleted: " . basename($f) . "\n";
    }
    echo "  [backup] Retained " . min($keep, count($files)) . " of " . count($files) . " backups.\n";
} else {
    echo "  [backup] No backups directory found.\n";
}

// ── 2. Routing cache cleanup (files older than 7 days) ──────────────────────
$cacheDirs = [
    $rootDir . '/cache/routing',
    $rootDir . '/cache/rate_limits',
];
foreach ($cacheDirs as $dir) {
    if (!is_dir($dir)) continue;
    $cutoff = time() - (7 * 24 * 3600);
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    $deleted = 0;
    foreach ($files as $file) {
        if ($file->isFile() && $file->getMTime() < $cutoff) {
            unlink($file->getRealPath());
            $deleted++;
        }
    }
    echo "  [cache] $dir: deleted $deleted old files.\n";
}

// ── 3. Events log rotation ──────────────────────────────────────────────────
$eventsLog = $rootDir . '/realtime/queue/events.log';
if (file_exists($eventsLog) && filesize($eventsLog) > 1048576) {
    $backup = $eventsLog . '.old';
    rename($eventsLog, $backup);
    file_put_contents($eventsLog, '');
    echo "  [events] Log rotated (was " . number_format(filesize($backup)) . " bytes).\n";
} else {
    $size = file_exists($eventsLog) ? filesize($eventsLog) : 0;
    echo "  [events] Log size: " . number_format($size) . " bytes (threshold: 1 MB).\n";
}

echo "[Maintenance] Done.\n";
