<?php
require_once 'capstone/includes/db.php';
$stmt = $conn->query("DESCRIBE delivery_damage_report");
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($res as $col) {
    if ($col['Field'] === 'status') {
        print_r($col);
    }
}
