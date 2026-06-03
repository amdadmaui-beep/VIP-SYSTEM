<?php
require_once __DIR__ . '/includes/db.php';
// Password is 'password123'
$conn->query("UPDATE user SET password = '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi' WHERE user_name = 'owner'");
echo "Done";
