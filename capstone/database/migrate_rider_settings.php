<?php
/**
 * Migration: Create rider_settings table
 * Moves rider_availability_status from user table to rider_settings
 * This normalizes the schema — rider-specific state belongs in a dedicated table.
 */
require_once __DIR__ . '/../includes/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "Migration: Create rider_settings table\n";
    echo str_repeat('-', 60) . "\n";

    // 1. Create rider_settings table
    $pdo->exec("CREATE TABLE IF NOT EXISTS rider_settings (
        Setting_ID INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        User_ID INT UNSIGNED NOT NULL,
        availability_status ENUM('Available', 'On Delivery', 'Off Duty') NOT NULL DEFAULT 'Available',
        last_set_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_id (User_ID),
        FOREIGN KEY (User_ID) REFERENCES user(User_ID) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  ✓ Created rider_settings table\n";

    // 2. Migrate existing data from user.rider_availability_status if column exists
    $checkCol = $pdo->query("SHOW COLUMNS FROM user LIKE 'rider_availability_status'");
    if ($checkCol && $checkCol->rowCount() > 0) {
        // Get rider role IDs
        $roleStmt = $pdo->query("SELECT Role_ID FROM roles WHERE LOWER(role_name) LIKE '%rider%' OR LOWER(role_name) = 'delivery_rider'");
        $riderRoleIds = $roleStmt ? $roleStmt->fetchAll(PDO::FETCH_COLUMN) : [];

        if (!empty($riderRoleIds)) {
            $placeholders = implode(',', array_fill(0, count($riderRoleIds), '?'));
            $insertSql = "INSERT IGNORE INTO rider_settings (User_ID, availability_status, last_set_at)
                          SELECT User_ID, COALESCE(rider_availability_status, 'Available'), NOW()
                          FROM user
                          WHERE Role_ID IN ($placeholders)";
            $stmt = $pdo->prepare($insertSql);
            $stmt->execute($riderRoleIds);
            $count = $stmt->rowCount();
            echo "  ✓ Migrated {$count} rider statuses\n";
        }

        // 3. Drop old column from user table
        $pdo->exec("ALTER TABLE user DROP COLUMN rider_availability_status");
        echo "  ✓ Dropped rider_availability_status column from user table\n";
    } else {
        echo "  ~ Column already removed, skipping migration\n";
    }

    echo str_repeat('-', 60) . "\n";
    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
