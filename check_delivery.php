<?php
require_once 'capstone/includes/db.php';
$stmt = $conn->query("DESCRIBE delivery");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
