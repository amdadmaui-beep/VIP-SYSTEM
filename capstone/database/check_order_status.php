<?php
require_once '../includes/db.php';

$result = $conn->query("SHOW COLUMNS FROM orders WHERE Field = 'order_status' OR Field = 'status'");
echo "Order status column:\n";
echo str_repeat("=", 80) . "\n";
while($row = $result->fetch_assoc()) {
    echo "Field: " . $row['Field'] . "\n";
    echo "Type: " . $row['Type'] . "\n";
    echo "\n";
}
$conn->close();
?>
