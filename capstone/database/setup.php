<?php
/**
 * Run essential idempotent database setup scripts.
 *
 * Usage (from capstone/): php database/setup.php
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$base = dirname(__DIR__);
$scripts = [
    'run_rider_migrations.php' => 'Rider dashboard tables',
    'migrate_delivery_damage_reports.php' => 'Delivery damage reports + reviews',
    'create_delivery_proofs_table.php' => 'Delivery proof photos (3NF)',
    'migrate_damage_goods_photo.php' => 'Damage goods photo column',
];

echo "VIP Database Setup\n==================\n\n";

foreach ($scripts as $file => $label) {
    $path = __DIR__ . '/' . $file;
    if (!is_file($path)) {
        echo "[SKIP] $label — $file not found\n\n";
        continue;
    }

    echo "--- $label ($file) ---\n";
    passthru(PHP_BINARY . ' ' . escapeshellarg($path), $code);
    echo ($code === 0 ? "[OK]" : "[WARN exit $code]") . " $label\n\n";
}

echo "Optional (run manually if needed):\n";
echo "  php database/add_foreign_keys.php\n";
echo "  php database/verify_foreign_keys.php\n";
echo "  php database/optimize_indexes.php\n";
echo "\nSetup complete.\n";
