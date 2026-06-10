<?php
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Creating product_categories table...\n";
    $conn->exec('
        CREATE TABLE IF NOT EXISTS product_categories (
            category_id INT AUTO_INCREMENT PRIMARY KEY,
            category_name VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ');
    
    echo "Adding default categories...\n";
    $stmt = $conn->prepare('INSERT IGNORE INTO product_categories (category_name) VALUES (?)');
    $categories = [
        'All Items',
        'Ice Cubes',
        'Ice Tubes',
        'Crushed Ice',
        'Ice Blocks'
    ];
    foreach ($categories as $cat) {
        $stmt->execute([$cat]);
    }
    
    echo "Checking products table for category_id...\n";
    $result = $conn->query("SHOW COLUMNS FROM products LIKE 'category_id'");
    if ($result->rowCount() == 0) {
        echo "Adding category_id column to products table...\n";
        $conn->exec('ALTER TABLE products ADD COLUMN category_id INT DEFAULT NULL');
        
        echo "Adding foreign key constraint...\n";
        $conn->exec('ALTER TABLE products ADD CONSTRAINT fk_product_category FOREIGN KEY (category_id) REFERENCES product_categories(category_id) ON DELETE SET NULL');
    } else {
        echo "Column category_id already exists in products table.\n";
    }
    
    // Automatically assign existing products to categories based on name
    echo "Assigning products to categories...\n";
    $assignQueries = [
        "UPDATE products SET category_id = (SELECT category_id FROM product_categories WHERE category_name = 'Ice Cubes') WHERE LOWER(product_name) LIKE '%cube%'",
        "UPDATE products SET category_id = (SELECT category_id FROM product_categories WHERE category_name = 'Ice Tubes') WHERE LOWER(product_name) LIKE '%tube%'",
        "UPDATE products SET category_id = (SELECT category_id FROM product_categories WHERE category_name = 'Crushed Ice') WHERE LOWER(product_name) LIKE '%crush%'",
        "UPDATE products SET category_id = (SELECT category_id FROM product_categories WHERE category_name = 'Ice Blocks') WHERE LOWER(product_name) LIKE '%block%'"
    ];
    foreach ($assignQueries as $q) {
        $conn->exec($q);
    }
    
    echo "Migration completed successfully.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
