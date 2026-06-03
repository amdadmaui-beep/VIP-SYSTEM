<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/logger.php';

echo "Starting migration to rename and normalize delivery_damage_details...\n";

try {
    $conn->beginTransaction();

    // 1. Rename table if it exists as delivery_damage_details
    $stmt = $conn->query("SHOW TABLES LIKE 'delivery_damage_details'");
    if ($stmt->rowCount() > 0) {
        $conn->exec("RENAME TABLE delivery_damage_details TO delivery_damage_report");
        echo "Table renamed to delivery_damage_report.\n";
    } else {
        $stmt2 = $conn->query("SHOW TABLES LIKE 'delivery_damage_report'");
        if ($stmt2->rowCount() > 0) {
            echo "Table delivery_damage_report already exists.\n";
        } else {
            echo "Neither delivery_damage_details nor delivery_damage_report found.\n";
            $conn->commit();
            exit;
        }
    }

    // 2. Identify foreign keys for Order_ID and Product_ID
    $dbNameStmt = $conn->query("SELECT DATABASE()");
    $dbName = $dbNameStmt->fetchColumn();

    $fkQuery = $conn->prepare("
        SELECT CONSTRAINT_NAME, COLUMN_NAME 
        FROM information_schema.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = ? 
          AND TABLE_NAME = 'delivery_damage_report' 
          AND REFERENCED_TABLE_NAME IS NOT NULL
          AND COLUMN_NAME IN ('Order_ID', 'Product_ID')
    ");
    $fkQuery->execute([$dbName]);
    $fks = $fkQuery->fetchAll(PDO::FETCH_ASSOC);

    // Drop foreign keys
    foreach ($fks as $fk) {
        $constraintName = $fk['CONSTRAINT_NAME'];
        $conn->exec("ALTER TABLE delivery_damage_report DROP FOREIGN KEY `$constraintName`");
        echo "Dropped foreign key $constraintName.\n";
    }

    // 3. Drop columns if they exist
    $colQuery = $conn->query("SHOW COLUMNS FROM delivery_damage_report");
    $columns = $colQuery->fetchAll(PDO::FETCH_COLUMN);
    
    if (in_array('Order_ID', $columns)) {
        $conn->exec("ALTER TABLE delivery_damage_report DROP COLUMN Order_ID");
        echo "Dropped column Order_ID.\n";
    }
    
    if (in_array('Product_ID', $columns)) {
        $conn->exec("ALTER TABLE delivery_damage_report DROP COLUMN Product_ID");
        echo "Dropped column Product_ID.\n";
    }

    $conn->commit();
    echo "Migration completed successfully.\n";

} catch (PDOException $e) {
    $conn->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>
