<?php
require_once 'capstone/includes/db.php';
$stmt = $conn->prepare("SELECT * FROM order_details WHERE Order_detail_ID = ?");
$stmt->execute([146]);
print_r($stmt->fetch(PDO::FETCH_ASSOC));
