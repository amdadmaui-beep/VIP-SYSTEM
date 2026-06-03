<?php
require_once 'capstone/includes/db.php';
$stmt = $conn->prepare("SELECT * FROM user WHERE User_ID = ?");
$stmt->execute([8]);
print_r($stmt->fetch(PDO::FETCH_ASSOC));
