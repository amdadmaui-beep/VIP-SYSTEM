<?php
declare(strict_types=1);

$phpPath = PHP_BINARY;
if (!is_file($phpPath)) {
    $phpPath = 'C:\laragon\bin\php\php.exe';
    if (!is_file($phpPath)) {
        $phpPath = 'php';
    }
}

$scriptPath = realpath(__DIR__ . '/backup.php');
if (!$scriptPath) {
    echo "ERROR: backup.php not found\n";
    exit(1);
}

$taskName = 'VIPSystem_DatabaseBackup';

$existing = shell_exec("schtasks /query /tn \"$taskName\" 2>NUL");
if ($existing !== null && $existing !== '') {
    shell_exec("schtasks /delete /tn \"$taskName\" /f 2>NUL");
    echo "Removed existing task: $taskName\n";
}

$cmd = "schtasks /create /tn \"$taskName\" /tr \"$phpPath -f \\\"$scriptPath\\\"\" /sc hourly /st 00:00 /f 2>&1";
$output = shell_exec($cmd);

echo $output . "\n";

if ($output !== null && (strpos($output, 'SUCCESS') !== false || strpos($output, 'success') !== false)) {
    echo "Task '$taskName' registered successfully.\n";
    echo "   Backup script: $scriptPath\n";
    echo "   PHP: $phpPath\n";
    echo "   Schedule: Every hour, starting at midnight\n";
} else {
    echo "Failed to register task. Try running as Administrator.\n";
    echo "   Command attempted:\n";
    echo "   $cmd\n";
    exit(1);
}
