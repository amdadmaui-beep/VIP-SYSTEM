<?php
/**
 * Migration: Add rider remittance tracking columns
 * 
 * Adds columns to track:
 * - Expected remittance (total value of dispatched items)
 * - Damaged value (total value of damaged items)
 * - Amount to collect (expected - damaged)
 * - Actual cash received
 * - Variance (cash received - amount to collect)
 * - Rider accountability status
 */

require_once __DIR__ . '/../includes/db.php';

try {
    $conn->beginTransaction();

    // Helper function to add column if not exists
    $addColumn = function($table, $column, $definition) use ($conn) {
        $check = $conn->prepare("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = ? 
            AND COLUMN_NAME = ?");
        $check->execute([$table, $column]);
        $exists = (int)$check->fetchColumn() > 0;
        
        if (!$exists) {
            $conn->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            echo "Added column {$column} to {$table}\n";
        } else {
            echo "Column {$column} already exists in {$table}\n";
        }
    };

    // Add remittance tracking columns to sales table
    $addColumn('sales', 'expected_remittance', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT "Total value of all dispatched items"');
    $addColumn('sales', 'damaged_value', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT "Total value of damaged items"');
    $addColumn('sales', 'amount_to_collect', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT "Expected remittance minus damaged value"');
    $addColumn('sales', 'cash_received', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT "Actual cash collected from rider"');
    $addColumn('sales', 'variance', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT "Cash received minus amount to collect (negative=shortage, positive=surplus)"');
    $addColumn('sales', 'rider_user_id', 'INT UNSIGNED NULL COMMENT "User ID of the delivery rider"');
    $addColumn('sales', 'remittance_status', "ENUM('Exact', 'Shortage', 'Surplus') DEFAULT 'Exact' COMMENT 'Variance status'");

    // Create rider_remittance_tracking table for detailed accountability
    $conn->exec("
        CREATE TABLE IF NOT EXISTS rider_remittance_tracking (
            Tracking_ID INT AUTO_INCREMENT PRIMARY KEY,
            rider_user_id INT UNSIGNED NOT NULL COMMENT 'User ID of the rider',
            Sale_ID INT UNSIGNED NOT NULL COMMENT 'Linked sale record',
            Delivery_ID INT UNSIGNED NOT NULL COMMENT 'Linked delivery',
            expected_remittance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            damaged_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            amount_to_collect DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            cash_received DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            variance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            remittance_status ENUM('Exact', 'Shortage', 'Surplus') DEFAULT 'Exact',
            shortage_cleared TINYINT(1) DEFAULT 0 COMMENT 'Whether shortage has been settled',
            cleared_at DATETIME NULL,
            cleared_by INT UNSIGNED NULL COMMENT 'User ID who cleared the shortage',
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (rider_user_id) REFERENCES user(User_ID) ON DELETE RESTRICT,
            FOREIGN KEY (Sale_ID) REFERENCES sales(Sale_ID) ON DELETE CASCADE,
            FOREIGN KEY (Delivery_ID) REFERENCES delivery(Delivery_ID) ON DELETE CASCADE,
            INDEX idx_rider (rider_user_id),
            INDEX idx_sale (Sale_ID),
            INDEX idx_delivery (Delivery_ID),
            INDEX idx_status (remittance_status),
            INDEX idx_shortage_cleared (shortage_cleared)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "Created rider_remittance_tracking table\n";

    $conn->commit();
    echo "\nMigration completed successfully!\n";

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
