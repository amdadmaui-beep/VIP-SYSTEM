<?php
require_once 'capstone/includes/db.php';
$stmt = $conn->query("DESCRIBE delivery_damage_report");
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($res);
