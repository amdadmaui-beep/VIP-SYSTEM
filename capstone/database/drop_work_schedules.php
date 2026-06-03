<?php
require_once __DIR__ . '/../includes/db.php';

echo "Dropping work_schedules table and cleaning up dependencies...\n";

try {
    $conn->beginTransaction();

    // 1. Drop Foreign Key from cash_shifts
    try {
        $conn->exec("ALTER TABLE cash_shifts DROP FOREIGN KEY fk_shift_schedule");
        echo "  - Dropped Foreign Key fk_shift_schedule from cash_shifts\n";
    } catch (Exception $e) {
        echo "  - Foreign Key fk_shift_schedule not found or already dropped\n";
    }

    // 2. Drop Column schedule_id from cash_shifts
    try {
        $conn->exec("ALTER TABLE cash_shifts DROP COLUMN schedule_id");
        echo "  - Dropped column schedule_id from cash_shifts\n";
    } catch (Exception $e) {
        echo "  - Column schedule_id not found or already dropped\n";
    }

    // 3. Drop Table work_schedules
    try {
        $conn->exec("DROP TABLE IF EXISTS work_schedules");
        echo "  - Dropped table work_schedules\n";
    } catch (Exception $e) {
        echo "  - Error dropping table work_schedules: " . $e->getMessage() . "\n";
    }

    $conn->commit();
    echo "\nCleanup successful!\n";

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo "Fatal Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
