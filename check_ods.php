<?php
require_once 'capstone/includes/db.php';
$ids = [47, 53, 61];
foreach ($ids as $id) {
    $stmt = $conn->prepare("SELECT * FROM order_details WHERE Order_detail_ID = ?");
    $stmt->execute([$id]);
    echo "ID $id:\n";
    print_r($stmt->fetch(PDO::FETCH_ASSOC));
}
