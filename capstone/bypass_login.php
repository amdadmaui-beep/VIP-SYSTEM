<?php
session_start();
$_SESSION['user_id'] = 6;
$_SESSION['user_name'] = 'owner';
$_SESSION['user_role'] = 1;
$_SESSION['full_name'] = 'Marie Yagong';
header('Location: pages/owner_manager_view/delivery_damage_queue.php');
exit;
