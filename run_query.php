<?php
require_once 'capstone/includes/db.php';
$sql = "SELECT r.report_id, r.Delivery_ID, od.Order_ID, r.damaged_qty, r.reason, r.photo_path, r.submitted_at,
                p.product_name, od.ordered_qty,
                u.full_name AS rider_name, u.user_name AS rider_username
         FROM delivery_damage_report r
         INNER JOIN order_details od ON od.Order_detail_ID = r.Order_detail_ID
         INNER JOIN products p ON p.Product_ID = od.Product_ID
         INNER JOIN user u ON u.User_ID = r.submitted_by
         WHERE r.status = 'pending_review'
         ORDER BY r.submitted_at ASC";
$stmt = $conn->query($sql);
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Count: " . count($res) . "\n";
print_r($res);
