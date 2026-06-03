<?php
require_once __DIR__ . '/cashier_view/cashier_view_bootstrap.php';

$cashier_view_config = [
    'canCashierVoid' => (bool) $can_cashier_void,
    'canCashierZRead' => (bool) $can_cashier_z_read,
    'canCashierDeliveryOrders' => (bool) $can_cashier_delivery_orders,
    'canCashierArSales' => (bool) $can_cashier_ar_sales,
    'userRole' => (int) ($_SESSION['user_role'] ?? 0),
];

include __DIR__ . '/cashier_view/Cashier_head.php';
include __DIR__ . '/cashier_view/Cashier_main_and_body.php';
?>
<script>
window.CASHIER_VIEW_CONFIG = <?php echo json_encode($cashier_view_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<?php
include __DIR__ . '/cashier_view/Cashier_scripts_inline.php';
include __DIR__ . '/cashier_view/Cashier_scripts_footer.php';
