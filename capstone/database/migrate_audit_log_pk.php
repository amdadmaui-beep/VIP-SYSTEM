<?php
/**
 * Migration: Add primary key and auto-increment to audit_log.log_id
 * 
 * audit_log was created without a primary key or any indexes.
 * log_id INT NOT NULL exists but was never declared as PK/AI.
 * 
 * Created: 2026-05-27
 */

$migration = [
    'name' => 'Add primary key to audit_log.log_id',
    'sql' => [
        "ALTER TABLE audit_log
            ADD PRIMARY KEY (log_id),
            MODIFY log_id INT NOT NULL AUTO_INCREMENT",
        "ALTER TABLE audit_log
            ADD INDEX idx_audit_log_changed_at (changed_at)",
        "ALTER TABLE audit_log
            ADD INDEX idx_audit_log_table_name (table_name)",
    ],
];

// --- Execution ---
require_once __DIR__ . '/../includes/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "Migration: {$migration['name']}\n";
    echo str_repeat('-', 60) . "\n";

    // Check if PK already exists
    $check = $pdo->query("SHOW KEYS FROM audit_log WHERE Key_name = 'PRIMARY'");
    if ($check->fetch()) {
        echo "[SKIP] Primary key already exists on audit_log.\n";
        exit(0);
    }

    foreach ($migration['sql'] as $sql) {
        $label = substr($sql, 0, 60) . '...';
        echo "Running: {$sql}\n";
        $pdo->exec($sql);
        echo "  ✓ OK\n";
    }

    echo str_repeat('-', 60) . "\n";
    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
