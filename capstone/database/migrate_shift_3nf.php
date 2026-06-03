<?php
require __DIR__ . '/../includes/db.php';

try {
    $conn->exec('DROP TABLE IF EXISTS shift_cash_breakdown');
    echo "Successfully dropped table: shift_cash_breakdown\n";
} catch (Exception $e) {
    echo "Error dropping shift_cash_breakdown: " . $e->getMessage() . "\n";
}

try {
    // Check if amount column exists in shift_activity_log
    $cols = array_column($conn->query("SHOW COLUMNS FROM shift_activity_log")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (in_array('amount', $cols)) {
        $conn->exec('ALTER TABLE shift_activity_log DROP COLUMN amount');
        echo "Successfully dropped column 'amount' from shift_activity_log\n";
    } else {
        echo "Column 'amount' in shift_activity_log does not exist or was already dropped.\n";
    }
} catch (Exception $e) {
    echo "Error dropping amount column: " . $e->getMessage() . "\n";
}
