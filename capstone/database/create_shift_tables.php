<?php
/**
 * Create Shift Management Tables
 * 
 * This script creates tables for managing cashier shifts, including:
 * - cash_shifts: Main shift records
 * - shift_transactions: Transaction logs within shifts
 * - manager_pins: Manager PIN management
 */

require_once __DIR__ . '/../includes/db.php';

echo "Creating Shift Management Tables...\n";

try {
    // Create cash_shifts table
    $sql1 = "CREATE TABLE IF NOT EXISTS cash_shifts (
        shift_id INT AUTO_INCREMENT PRIMARY KEY,
        User_ID INT UNSIGNED NOT NULL,
        shift_date DATE NOT NULL,
        shift_start_time DATETIME NOT NULL,
        shift_end_time DATETIME NULL,
        starting_cash DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        ending_cash DECIMAL(10,2) NULL,
        gross_sales DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        cash_sales DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        credit_sales DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        void_count INT NOT NULL DEFAULT 0,
        void_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        status ENUM('Open', 'Closed') NOT NULL DEFAULT 'Open',

        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (User_ID) REFERENCES user(User_ID) ON DELETE CASCADE,
        INDEX idx_user_date (User_ID, shift_date),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $conn->exec($sql1);
    echo "  - cash_shifts table created/verified\n";
    
    // Create manager_pins table
    $sql2 = "CREATE TABLE IF NOT EXISTS manager_pins (
        pin_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        pin_hash VARCHAR(255) NOT NULL,
        is_active BOOLEAN NOT NULL DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (User_ID) REFERENCES user(User_ID) ON DELETE CASCADE,
        UNIQUE KEY unique_user_pin (user_id),
        INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $conn->exec($sql2);
    echo "  - manager_pins table created/verified\n";
    
    // Create shift_activity_log table for detailed tracking
    $sql3 = "CREATE TABLE IF NOT EXISTS shift_activity_log (
        log_id INT AUTO_INCREMENT PRIMARY KEY,
        shift_id INT NOT NULL,
        User_ID INT UNSIGNED NOT NULL,
        activity_type ENUM('Open', 'X-Read', 'Close', 'Void', 'Sale') NOT NULL,
        description TEXT NULL,
        amount DECIMAL(10,2) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (shift_id) REFERENCES cash_shifts(shift_id) ON DELETE CASCADE,
        FOREIGN KEY (User_ID) REFERENCES user(User_ID) ON DELETE CASCADE,
        INDEX idx_shift_activity (shift_id, activity_type),
        INDEX idx_user_activity (User_ID, activity_type),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $conn->exec($sql3);
    echo "  - shift_activity_log table created/verified\n";
    
    echo "\nShift management tables created successfully!\n";
    
} catch (Exception $e) {
    echo "Error creating tables: " . $e->getMessage() . "\n";
    exit(1);
}
?>
