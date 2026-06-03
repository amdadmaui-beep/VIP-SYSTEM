<?php
/**
 * Delivery damage reports — pending review workflow (rider submit → staff approve/reject).
 * Run: php database/migrate_delivery_damage_reports.php (from capstone/)
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$base = dirname(__DIR__);
require_once $base . '/includes/db.php';

function runSql(PDO $conn, string $sql, string $label): bool {
    try {
        $conn->exec($sql);
        echo "[OK] $label\n";
        return true;
    } catch (PDOException $e) {
        if (stripos($e->getMessage(), 'Duplicate') !== false ||
            stripos($e->getMessage(), 'already exists') !== false) {
            echo "[SKIP] $label\n";
            return true;
        }
        echo "[ERROR] $label: " . $e->getMessage() . "\n";
        return false;
    }
}

echo "migrate_delivery_damage_reports\n================================\n";

$sql = <<<SQL
CREATE TABLE IF NOT EXISTS delivery_damage_report (
    report_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    Delivery_ID INT UNSIGNED NOT NULL,
    Order_detail_ID INT UNSIGNED NOT NULL,
    damaged_qty DECIMAL(12,3) NOT NULL,
    reason TEXT NOT NULL,
    photo_path VARCHAR(500) NULL,
    submitted_by INT UNSIGNED NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ddr_delivery (Delivery_ID),
    INDEX idx_ddr_submitted (submitted_by),
    INDEX idx_ddr_order_detail (Order_detail_ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

runSql($conn, $sql, 'CREATE TABLE delivery_damage_report');

// Align signed INT columns from older CREATE runs (must match referenced unsigned keys)
$altersUnsigned = [
    'ALTER TABLE delivery_damage_report MODIFY report_id INT UNSIGNED NOT NULL AUTO_INCREMENT',
    'ALTER TABLE delivery_damage_report MODIFY Delivery_ID INT UNSIGNED NOT NULL',
    'ALTER TABLE delivery_damage_report MODIFY Order_detail_ID INT UNSIGNED NOT NULL',
    'ALTER TABLE delivery_damage_report MODIFY submitted_by INT UNSIGNED NOT NULL',
];
foreach ($altersUnsigned as $alt) {
    runSql($conn, $alt, 'ALIGN UNSIGNED');
}

// Foreign keys (may SKIP if types mismatch or table engine differs)
$fks = [
    "ALTER TABLE delivery_damage_report ADD CONSTRAINT fk_ddr_delivery FOREIGN KEY (Delivery_ID) REFERENCES delivery(Delivery_ID) ON DELETE RESTRICT",
    "ALTER TABLE delivery_damage_report ADD CONSTRAINT fk_ddr_order_detail FOREIGN KEY (Order_detail_ID) REFERENCES order_details(Order_detail_ID) ON DELETE RESTRICT",
    "ALTER TABLE delivery_damage_report ADD CONSTRAINT fk_ddr_submitted_by FOREIGN KEY (submitted_by) REFERENCES user(User_ID) ON DELETE RESTRICT",
];
foreach ($fks as $i => $fkSql) {
    runSql($conn, $fkSql, 'FK ' . ($i + 1));
}

echo "\nDone.\n";
