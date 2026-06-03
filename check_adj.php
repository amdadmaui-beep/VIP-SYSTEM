<?php
require_once 'capstone/includes/db.php';
$stmt = $conn->prepare("SELECT * FROM manual_adjustment WHERE adjustment_id = ?");
$stmt->execute([35]);
print_r($stmt->fetch(PDO::FETCH_ASSOC));
