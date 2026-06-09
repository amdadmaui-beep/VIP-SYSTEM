<?php
/**
 * Maintenance Mode Toggle (CLI-only)
 * Usage: php scripts/toggle_maintenance.php [on|off|status] ["Optional message"]
 *
 * Examples:
 *   php scripts/toggle_maintenance.php on
 *   php scripts/toggle_maintenance.php on "Upgrading database, expected 5min"
 *   php scripts/toggle_maintenance.php off
 *   php scripts/toggle_maintenance.php status
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

$flagFile = __DIR__ . '/../maintenance.flag';
$action = $argv[1] ?? 'status';

switch ($action) {
    case 'on':
        $message = $argv[2] ?? '';
        file_put_contents($flagFile, $message);
        echo "Maintenance mode ENABLED.\n";
        if ($message) {
            echo "Message: $message\n";
        }
        break;

    case 'off':
        if (file_exists($flagFile)) {
            unlink($flagFile);
            echo "Maintenance mode DISABLED.\n";
        } else {
            echo "Maintenance mode was not enabled.\n";
        }
        break;

    case 'status':
        if (file_exists($flagFile)) {
            $msg = trim(file_get_contents($flagFile));
            echo "Maintenance mode: ON\n";
            if ($msg) {
                echo "Message: $msg\n";
            }
        } else {
            echo "Maintenance mode: OFF\n";
        }
        break;

    default:
        echo "Usage: php scripts/toggle_maintenance.php [on|off|status] [\"message\"]\n";
        exit(1);
}
