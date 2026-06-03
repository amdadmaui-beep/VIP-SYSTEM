<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/config.php';

use Spatie\DbDumper\Databases\MySql;

function findMysqldump(): string {
    $candidates = [
        'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqldump.exe',
        'C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysqldump.exe',
        'C:\laragon\bin\mysql\mysql-8.0.29-winx64\bin\mysqldump.exe',
        'C:\laragon\bin\mysql\mysql-8.0.28-winx64\bin\mysqldump.exe',
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    $glob = glob('C:\laragon\bin\mysql\mysql-*\bin\mysqldump.exe');
    if ($glob !== false && count($glob) > 0) {
        return $glob[0];
    }
    $which = trim((string)shell_exec('where mysqldump 2>NUL'));
    if ($which !== '') {
        $lines = explode(PHP_EOL, $which);
        return trim($lines[0]);
    }
    return 'mysqldump';
}

$mysqldumpPath = findMysqldump();
$backupDir = __DIR__ . '/../backups';

if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

$timestamp = date('Y-m-d_H-i-s');
$filename = "vip_db_{$timestamp}.sql";
$filepath = "{$backupDir}/{$filename}";
$gzippedPath = "{$filepath}.gz";
$logFile = "{$backupDir}/backup.log";

function backupLog(string $message): void {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

backupLog('=== Backup started ===');

try {
    $dumper = MySql::create()
        ->setDumpBinaryPath(dirname($mysqldumpPath) . DIRECTORY_SEPARATOR)
        ->setHost(DB_HOST)
        ->setDbName(DB_NAME)
        ->setUserName(DB_USER)
        ->setPassword(DB_PASS)
        ->setDefaultCharacterSet(DB_CHARSET ?: 'utf8mb4');

    if (defined('DB_PORT') && DB_PORT) {
        $dumper->setPort((int)DB_PORT);
    }

    $dumper->dumpToFile($filepath);

    $sqlContent = file_get_contents($filepath);
    $compressed = gzencode($sqlContent, 6);
    file_put_contents($gzippedPath, $compressed);
    unlink($filepath);

    $size = filesize($gzippedPath);
    $sizeFormatted = number_format($size / 1024, 1) . ' KB';
    backupLog("Backup saved: {$gzippedPath} ({$sizeFormatted})");

    $files = glob("{$backupDir}/vip_db_*.sql.gz");
    if ($files !== false) {
        rsort($files);
        $maxFiles = 48;
        $toDelete = array_slice($files, $maxFiles);
        foreach ($toDelete as $oldFile) {
            unlink($oldFile);
            backupLog("Pruned old backup: " . basename((string)$oldFile));
        }
    }

    backupLog('=== Backup completed successfully ===');
    echo "OK: {$gzippedPath}\n";
    exit(0);
} catch (Throwable $e) {
    backupLog('ERROR: ' . $e->getMessage());
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
