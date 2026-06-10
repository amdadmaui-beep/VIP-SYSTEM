<?php
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Checking product_categories table for slug column...\n";
    $result = $conn->query("SHOW COLUMNS FROM product_categories LIKE 'slug'");
    if ($result->rowCount() > 0) {
        echo "Dropping slug column...\n";
        $conn->exec("ALTER TABLE product_categories DROP COLUMN slug");
        echo "Column dropped successfully.\n";
    } else {
        echo "slug column already removed.\n";
    }
    echo "Migration completed.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
