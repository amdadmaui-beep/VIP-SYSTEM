<?php
/**
 * Migration: Add Work Schedules and Shift Cash Breakdowns
 * 
 * Creates scheduled work shifts and a table to track exact physical 
 * money denominations for opening floats and closing counts.
 */

require_once __DIR__ . '/../includes/db.php';

echo "Running Shift Workflow Migration...\n";

try {
    $conn->beginTransaction();

    /* 
    // 1. Create Work Schedules Table (DROPPED - Legacy)
    $sql1 = "CREATE TABLE IF NOT EXISTS work_schedules (
        schedule_id INT AUTO_INCREMENT PRIMARY KEY,
        schedule_name VARCHAR(50) NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        is_active BOOLEAN NOT NULL DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $conn->exec($sql1);
    echo "  - work_schedules table created/verified\n";

    // Insert Default Schedules if empty
    $checkSchedules = $conn->query("SELECT COUNT(*) as count FROM work_schedules")->fetch();
    if ($checkSchedules['count'] == 0) {
        echo "  - Inserting default work schedules...\n";
        $insertStmt = $conn->prepare("INSERT INTO work_schedules (schedule_name, start_time, end_time) VALUES (?, ?, ?)");
        $insertStmt->execute(['Morning (6am - 2pm)', '06:00:00', '14:00:00']);
        $insertStmt->execute(['Afternoon (2pm - 10pm)', '14:00:00', '22:00:00']);
        $insertStmt->execute(['Night (10pm - 6am)', '22:00:00', '06:00:00']);
    }

    // 2. Add schedule_id to cash_shifts if it doesn't exist
    $checkColumn = $conn->query("SHOW COLUMNS FROM cash_shifts LIKE 'schedule_id'")->fetch();
    if (!$checkColumn) {
        $conn->exec("ALTER TABLE cash_shifts ADD COLUMN schedule_id INT NULL AFTER User_ID");
        $conn->exec("ALTER TABLE cash_shifts ADD CONSTRAINT fk_shift_schedule FOREIGN KEY (schedule_id) REFERENCES work_schedules(schedule_id) ON DELETE SET NULL");
        echo "  - added schedule_id column to cash_shifts\n";
    }
    */

    // 3. Create Shift Cash Breakdown Table
    $sql3 = "CREATE TABLE IF NOT EXISTS shift_cash_breakdown (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shift_id INT NOT NULL,
        record_type ENUM('opening', 'closing') NOT NULL,
        denomination DECIMAL(10,2) NOT NULL,
        pieces INT NOT NULL DEFAULT 0,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (shift_id) REFERENCES cash_shifts(shift_id) ON DELETE CASCADE,
        INDEX idx_shift_type (shift_id, record_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $conn->exec($sql3);
    echo "  - shift_cash_breakdown table created/verified\n";

    $conn->commit();
    echo "\nMigration completed successfully!\n";

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo "Error during migration: " . $e->getMessage() . "\n";
    exit(1);
}
?>
