<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/logger.php';

echo "Starting migration to extract reviews into damage_report_reviews...\n";

try {
    $conn->beginTransaction();

    // 1. Create the new damage_report_reviews table
    $createTableSql = "
        CREATE TABLE IF NOT EXISTS damage_report_reviews (
            review_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            report_id INT UNSIGNED NOT NULL,
            status ENUM('pending_review', 'approved', 'rejected') DEFAULT 'pending_review',
            reviewed_by INT UNSIGNED DEFAULT NULL,
            reviewed_at DATETIME DEFAULT NULL,
            staff_notes TEXT DEFAULT NULL,
            Adjustment_ID INT UNSIGNED DEFAULT NULL,
            Damage_ID INT UNSIGNED DEFAULT NULL,
            UNIQUE KEY (report_id),
            FOREIGN KEY (report_id) REFERENCES delivery_damage_report(report_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $conn->exec($createTableSql);

    // Ensure columns exist if table was already created
    $revCols = $conn->query("SHOW COLUMNS FROM damage_report_reviews")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('Adjustment_ID', $revCols)) {
        $conn->exec("ALTER TABLE damage_report_reviews ADD COLUMN Adjustment_ID INT UNSIGNED DEFAULT NULL");
    }
    if (!in_array('Damage_ID', $revCols)) {
        $conn->exec("ALTER TABLE damage_report_reviews ADD COLUMN Damage_ID INT UNSIGNED DEFAULT NULL");
    }

    echo "Created or updated damage_report_reviews table.\n";

    // 2. Check if delivery_damage_report has the columns
    $colQuery = $conn->query("SHOW COLUMNS FROM delivery_damage_report");
    $columns = $colQuery->fetchAll(PDO::FETCH_COLUMN);

    if (in_array('status', $columns)) {
        // 3. Migrate data
        $insertSql = "
            INSERT INTO damage_report_reviews (report_id, status, reviewed_by, reviewed_at, staff_notes, Adjustment_ID, Damage_ID)
            SELECT report_id, status, reviewed_by, reviewed_at, staff_notes, 
                   " . (in_array('Adjustment_ID', $columns) ? "Adjustment_ID" : "NULL") . ",
                   " . (in_array('Damage_ID', $columns) ? "Damage_ID" : "NULL") . "
            FROM delivery_damage_report
            WHERE report_id NOT IN (SELECT report_id FROM damage_report_reviews)
        ";
        $conn->exec($insertSql);
        echo "Migrated existing review data.\n";

        // Drop foreign keys if exists (e.g. reviewed_by)
        $dbNameStmt = $conn->query("SELECT DATABASE()");
        $dbName = $dbNameStmt->fetchColumn();

        $fks_to_drop = ['reviewed_by', 'Adjustment_ID', 'Damage_ID'];
        foreach ($fks_to_drop as $col) {
            $fkQuery = $conn->prepare("
                SELECT CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = ? 
                  AND TABLE_NAME = 'delivery_damage_report' 
                  AND REFERENCED_TABLE_NAME IS NOT NULL
                  AND COLUMN_NAME = ?
            ");
            $fkQuery->execute([$dbName, $col]);
            $fks = $fkQuery->fetchAll(PDO::FETCH_ASSOC);

            foreach ($fks as $fk) {
                $constraintName = $fk['CONSTRAINT_NAME'];
                $conn->exec("ALTER TABLE delivery_damage_report DROP FOREIGN KEY `$constraintName`");
                echo "Dropped foreign key $constraintName for $col.\n";
            }
        }

        // 4. Drop columns
        $conn->exec("ALTER TABLE delivery_damage_report DROP COLUMN status");
        $conn->exec("ALTER TABLE delivery_damage_report DROP COLUMN reviewed_by");
        $conn->exec("ALTER TABLE delivery_damage_report DROP COLUMN reviewed_at");
        $conn->exec("ALTER TABLE delivery_damage_report DROP COLUMN staff_notes");
        if (in_array('Adjustment_ID', $columns)) $conn->exec("ALTER TABLE delivery_damage_report DROP COLUMN Adjustment_ID");
        if (in_array('Damage_ID', $columns)) $conn->exec("ALTER TABLE delivery_damage_report DROP COLUMN Damage_ID");
        echo "Dropped review columns from delivery_damage_report.\n";
    } else {
        echo "Review columns are already dropped from delivery_damage_report.\n";
    }

    $conn->commit();
    echo "Migration completed successfully.\n";

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>
