<?php
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Checking product_categories table for deleted_at column...\n";
    $result = $conn->query("SHOW COLUMNS FROM product_categories LIKE 'deleted_at'");
    if ($result->rowCount() == 0) {
        echo "Adding deleted_at column...\n";
        $conn->exec("ALTER TABLE product_categories ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL");
        echo "Column added successfully.\n";
    } else {
        echo "deleted_at column already exists.\n";
    }
    echo "Migration completed.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
