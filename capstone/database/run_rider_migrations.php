<?php
/**
 * Run Rider Dashboard Migrations
 * Execute: php run_rider_migrations.php (from capstone/database/)
 * Or: php database/run_rider_migrations.php (from capstone/)
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$base = dirname(__DIR__);
require_once $base . '/includes/db.php';

$migrations = [
    "CREATE TABLE IF NOT EXISTS activity_logs (
        Log_ID INT AUTO_INCREMENT PRIMARY KEY,
        User_ID INT NOT NULL,
        Activity VARCHAR(500) NOT NULL,
        Time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (User_ID) REFERENCES user(User_ID) ON DELETE CASCADE,
        INDEX idx_user (User_ID),
        INDEX idx_time (Time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
];

function runMigration($conn, $sql, $desc) {
    try {
        $conn->exec($sql);
        echo "[OK] $desc\n";
        return true;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false ||
            strpos($e->getMessage(), 'already exists') !== false ||
            strpos($e->getMessage(), 'Duplicate key') !== false) {
            echo "[SKIP] $desc - already applied\n";
            return true;
        }
        echo "[ERROR] $desc: " . $e->getMessage() . "\n";
        return false;
    }
}

echo "Rider Dashboard Migrations\n======================\n";

runMigration($conn, $migrations[0], "Create activity_logs table");

// Note: assigned_rider_id, delivered_by_user_id, destination_lat, destination_lng, rider_lat, rider_lng, last_location_at, and destination metadata columns have been permanently removed from schema.

echo "\nDone.\n";

