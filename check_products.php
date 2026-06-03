<?php
require_once 'capstone/includes/db.php';
$ids = [3, 20, 19];
foreach ($ids as $id) {
    $stmt = $conn->prepare("SELECT * FROM products WHERE Product_ID = ?");
    $stmt->execute([$id]);
    echo "ID $id:\n";
    print_r($stmt->fetch(PDO::FETCH_ASSOC));
}
