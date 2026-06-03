<?php
/**
 * Migration: Drop orphaned app_roles table
 * 
 * The app_roles table was created by migrate_user_roles.sql but is
 * never referenced by any PHP code. The application uses the roles table instead.
 * 
 * Run: php database/migrate_drop_app_roles.php (from capstone/)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$base = dirname(__DIR__);
require_once $base . '/includes/db.php';

echo "Dropping orphaned app_roles table...\n";
try {
    $conn->exec("DROP TABLE IF EXISTS app_roles");
    echo "  [OK] app_roles table dropped\n";
} catch (Exception $e) {
    echo "  [ERROR] " . $e->getMessage() . "\n";
}

echo "Done.\n";
