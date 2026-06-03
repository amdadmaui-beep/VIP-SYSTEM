<?php
/**
 * Migration: Create/update password_reset_codes table
 * Run once: php capstone/database/migrate_password_reset_codes.php
 */
require_once __DIR__ . '/../includes/db.php';

echo "Starting migration for password_reset_codes...\n";

try {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS password_reset_codes (
            Reset_ID INT AUTO_INCREMENT PRIMARY KEY,
            User_ID INT NOT NULL,
            email VARCHAR(191) NOT NULL,
            code_hash VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            attempt_count INT NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_email_created (User_ID, email, created_at),
            INDEX idx_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $columns = $conn->query("SHOW COLUMNS FROM password_reset_codes")->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('attempt_count', $columns, true)) {
        $conn->exec("ALTER TABLE password_reset_codes ADD COLUMN attempt_count INT NOT NULL DEFAULT 0");
        echo "Added attempt_count column.\n";
    }
    if (!in_array('locked_until', $columns, true)) {
        $conn->exec("ALTER TABLE password_reset_codes ADD COLUMN locked_until DATETIME NULL");
        echo "Added locked_until column.\n";
    }

    echo "Migration completed successfully.\n";
    exit(0);
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

