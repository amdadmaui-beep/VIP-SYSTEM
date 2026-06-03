<?php
/**
 * Add proof_of_delivery_path to delivery table for photo proof when marking Delivered.
 * Run once: php database/migrate_delivery_proof_photo.php
 */
require_once __DIR__ . '/../includes/db.php';

$col = $conn->query("SHOW COLUMNS FROM delivery LIKE 'proof_of_delivery_path'");
if ($col && $col->rowCount() > 0) {
    echo "proof_of_delivery_path already exists. Nothing to do.\n";
    exit(0);
}

$conn->exec("ALTER TABLE delivery ADD COLUMN proof_of_delivery_path VARCHAR(500) NULL");
echo "✓ proof_of_delivery_path added to delivery\n";
