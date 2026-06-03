<?php
require_once 'capstone/includes/db.php';
$stmt = $conn->query("SELECT * FROM delivery_damage_report");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
