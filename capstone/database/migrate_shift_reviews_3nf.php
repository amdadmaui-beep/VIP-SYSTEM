<?php
require __DIR__ . '/../includes/db.php';

try {
    $conn->beginTransaction();
    echo "Starting migration for 3NF shift_reviews...\n";

    // 1. Create the new table
    $conn->exec("
        CREATE TABLE IF NOT EXISTS shift_reviews (
            review_id INT AUTO_INCREMENT PRIMARY KEY,
            shift_id INT NOT NULL,
            review_status VARCHAR(50) NOT NULL,
            reviewed_by BIGINT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            review_notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_shift_reviews_shift FOREIGN KEY (shift_id) REFERENCES cash_shifts(shift_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "[OK] Created shift_reviews table.\n";

    // 2. Check if cash_shifts still has the review columns
    $cols = array_column($conn->query("SHOW COLUMNS FROM cash_shifts")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    
    if (in_array('review_status', $cols)) {
        // 3. Migrate data
        // Only migrate rows that have a review status other than NULL or 'Open'
        $migrated = $conn->exec("
            INSERT INTO shift_reviews (shift_id, review_status, reviewed_by, reviewed_at, review_notes)
            SELECT shift_id, review_status, reviewed_by, reviewed_at, review_notes
            FROM cash_shifts
            WHERE review_status IS NOT NULL AND review_status != 'Open'
        ");
        echo "[OK] Migrated {$migrated} reviews from cash_shifts to shift_reviews.\n";

        // 4. Drop old columns
        $conn->exec("ALTER TABLE cash_shifts DROP COLUMN review_status");
        $conn->exec("ALTER TABLE cash_shifts DROP COLUMN reviewed_by");
        $conn->exec("ALTER TABLE cash_shifts DROP COLUMN reviewed_at");
        $conn->exec("ALTER TABLE cash_shifts DROP COLUMN review_notes");
        echo "[OK] Dropped review columns from cash_shifts.\n";
    } else {
        echo "[SKIP] review_status column not found in cash_shifts. Already migrated?\n";
    }

    $conn->commit();
    echo "Migration completed successfully!\n";

} catch (Exception $e) {
    $conn->rollBack();
    echo "Error during migration: " . $e->getMessage() . "\n";
}
