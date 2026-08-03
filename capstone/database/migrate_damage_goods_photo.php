<?php
/**
 * Add photo_path to damage_goods for inventory staff damage evidence.
 * Run: php database/migrate_damage_goods_photo.php (from capstone/)
 */
require_once __DIR__ . '/../includes/db.php';

echo "migrate_damage_goods_photo\n==========================\n";

try {
    $cols = $conn->query('SHOW COLUMNS FROM damage_goods')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('photo_path', $cols, true)) {
        $conn->exec('ALTER TABLE damage_goods ADD COLUMN photo_path VARCHAR(500) NULL AFTER reason');
        echo "Added photo_path column to damage_goods.\n";
    } else {
        echo "photo_path column already exists.\n";
    }
} catch (Throwable $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}

echo "Done.\n";
