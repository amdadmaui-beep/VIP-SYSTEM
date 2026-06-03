<?php
require_once 'capstone/includes/db.php';
$stmt = $conn->query("SELECT * FROM roles");
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($res);
