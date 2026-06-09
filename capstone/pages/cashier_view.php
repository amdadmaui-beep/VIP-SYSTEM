<?php
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/cashier_view/cashier_view_bootstrap.php';

$cashier_view_config = [
    'canCashierVoid' => (bool) $can_cashier_void,
    'canCashierZRead' => (bool) $can_cashier_z_read,
    'canCashierDeliveryOrders' => (bool) $can_cashier_delivery_orders,
    'canCashierArSales' => (bool) $can_cashier_ar_sales,
    'userRole' => (int) ($_SESSION['user_role'] ?? 0),
];

$cssPath = __DIR__ . '/../assets/css/style.css';
$cashierCssPath = __DIR__ . '/../assets/css/cashier.css';
$scriptJsPath = __DIR__ . '/../assets/js/script.js';
$offlineJsPath = __DIR__ . '/../assets/js/offline_support.js';
$cb = '?v=' . (file_exists($cssPath) ? filemtime($cssPath) : time());
$cbCashier = '?v=' . (file_exists($cashierCssPath) ? filemtime($cashierCssPath) : time());
$cbScript = '?v=' . (file_exists($scriptJsPath) ? filemtime($scriptJsPath) : time());
$cbOffline = '?v=' . (file_exists($offlineJsPath) ? filemtime($offlineJsPath) : time());
include __DIR__ . '/cashier_view/Cashier_head.php';
include __DIR__ . '/cashier_view/Cashier_main_and_body.php';
?>
<script>
window.CASHIER_VIEW_CONFIG = <?php echo json_encode($cashier_view_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.CAN_CASHIER_ZREAD = window.CASHIER_VIEW_CONFIG?.canCashierZRead ?? true;
</script>
<?php
include __DIR__ . '/cashier_view/Cashier_scripts_inline.php';
include __DIR__ . '/cashier_view/Cashier_scripts_footer.php';
