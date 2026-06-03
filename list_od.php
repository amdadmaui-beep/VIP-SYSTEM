<?php
require_once 'capstone/includes/db.php';
$stmt = $conn->query("SELECT Order_detail_ID FROM order_details LIMIT 10");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
