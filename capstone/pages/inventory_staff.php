<?php
require_once __DIR__ . '/inventory_staff/inventory_staff_bootstrap.php';
include __DIR__ . '/inventory_staff/Inventory_staff_head.php';
include __DIR__ . '/inventory_staff/Inventory_staff_header_shell.php';
include __DIR__ . '/inventory_staff/Inventory_staff_main.php';
include __DIR__ . '/inventory_staff/Inventory_staff_fab_modals.php';

$productPickerData = array_map(function ($p) {
    return [
        'id' => (int)$p['Product_ID'],
        'name' => $p['product_name'],
        'qty' => (float)$p['current_quantity'],
        'unit' => $p['unit_name'] ?? '',
    ];
}, $products);

$inventory_staff_config = [
    'adjustmentOtherReasonLabel' => $has_other_reason ? 'Other (with remarks)' : '',
    'damageTypeReasons' => getDamageTypeOptions(),
    'flashSuccess' => $inv_staff_flash_success !== '' ? $inv_staff_flash_success : null,
    'flashSuccessDetails' => $inv_staff_flash_success_details,
    'flashError' => $inv_staff_flash_error !== '' ? $inv_staff_flash_error : null,
    'csrfToken' => getCsrfToken(),
    'products' => $productPickerData,
];
?>
<script>
window.INVENTORY_STAFF_CONFIG = <?php echo json_encode($inventory_staff_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.RESERVATION_DETAILS = <?php echo json_encode($reservationDetailsMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<script>
function showReservationDebug(productId, productName) {
    const holds = window.RESERVATION_DETAILS?.[productId] || [];
    if (!Array.isArray(holds) || holds.length === 0) {
        Swal.fire({
            icon: 'info',
            title: productName + ' — Reservation Holds',
            text: 'No active reservation rows found for this product.'
        });
        return;
    }

    const totalQty = holds.reduce((sum, h) => sum + (Number(h.ordered_qty) || 0), 0);

    const statusColors = {
        'requested': { bg: '#fef3c7', text: '#92400e', icon: 'fa-clock' },
        'pending': { bg: '#fef3c7', text: '#92400e', icon: 'fa-hourglass-half' },
        'confirmed': { bg: '#e0e7ff', text: '#3730a3', icon: 'fa-check' },
        'scheduled': { bg: '#dbeafe', text: '#1d4ed8', icon: 'fa-calendar' },
        'preparing': { bg: '#fef9c3', text: '#854d0e', icon: 'fa-spinner' },
        'ready': { bg: '#dcfce7', text: '#166534', icon: 'fa-check-circle' },
        'out for delivery': { bg: '#dbeafe', text: '#1d4ed8', icon: 'fa-truck' },
        'in transit': { bg: '#e0e7ff', text: '#4338ca', icon: 'fa-shipping-fast' }
    };

    const rows = holds.map((h) => {
        const qty = Number(h.ordered_qty || 0).toString().replace(/\.0+$/, '');
        const os = (h.order_status || '').toLowerCase();
        const cs = statusColors[os] || { bg: '#f3f4f6', text: '#374151', icon: 'fa-info' };
        const statusLabel = h.order_status || '-';
        return '<tr style="transition:background 0.2s;">' +
            '<td style="padding:0.875rem 1rem;border-bottom:1px solid #e2e8f0;font-weight:600;color:#1e293b;">#' + (h.order_id || '-') + '</td>' +
            '<td style="padding:0.875rem 1rem;border-bottom:1px solid #e2e8f0;font-weight:600;color:#475569;"><i class="fas fa-user-circle" style="color:#94a3b8;margin-right:0.5rem;"></i>' + (h.customer_name || 'N/A') + '</td>' +
            '<td style="padding:0.875rem 1rem;border-bottom:1px solid #e2e8f0;text-align:center;font-weight:700;color:#6366f1;">' + qty + '</td>' +
            '<td style="padding:0.875rem 1rem;border-bottom:1px solid #e2e8f0;"><span style="display:inline-flex;align-items:center;gap:0.375rem;padding:0.375rem 0.75rem;border-radius:9999px;font-size:0.75rem;font-weight:600;background:' + cs.bg + ';color:' + cs.text + ';"><i class="fas ' + cs.icon + '"></i> ' + statusLabel + '</span></td>' +
            '</tr>';
    }).join('');

    Swal.fire({
        title: '<div style="display:flex;align-items:center;gap:0.75rem;"><i class="fas fa-box-open" style="color:#6366f1;"></i><span>' + productName + ' — Reservation Holds</span></div>',
        width: 850,
        html: '<style>.reservation-modal-table{width:100%;border-collapse:separate;border-spacing:0;font-size:0.875rem;}.reservation-modal-table th{background:linear-gradient(135deg,#f8fafc,#f1f5f9);color:#475569;font-weight:600;text-transform:uppercase;font-size:0.7rem;letter-spacing:0.05em;padding:0.75rem 1rem;text-align:left;border-bottom:2px solid #e2e8f0;}.reservation-modal-table tr:hover td{background:#f8fafc;}</style>' +
            '<div style="display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,#fef3c7,#fde68a);border-radius:12px;padding:1rem 1.25rem;margin-bottom:1rem;border:1px solid #fcd34d;">' +
                '<div style="display:flex;align-items:center;gap:0.75rem;">' +
                    '<div style="width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#f59e0b,#d97706);display:flex;align-items:center;justify-content:center;color:white;font-size:1rem;"><i class="fas fa-link"></i></div>' +
                    '<div><div style="font-size:0.75rem;color:#92400e;text-transform:uppercase;font-weight:600;">Active Reservations</div>' +
                    '<div style="font-size:1rem;font-weight:700;color:#78350f;">' + holds.length + ' order' + (holds.length !== 1 ? 's' : '') + ' holding inventory</div></div>' +
                '</div>' +
                '<div style="text-align:right;"><div style="font-size:0.75rem;color:#92400e;text-transform:uppercase;font-weight:600;">Total Reserved Qty</div>' +
                '<div style="font-size:1.25rem;font-weight:800;color:#78350f;">' + totalQty + '</div></div>' +
            '</div>' +
            '<div style="overflow:auto;max-height:380px;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">' +
                '<table class="reservation-modal-table"><thead><tr><th>Order ID</th><th>Customer Name</th><th style="text-align:center;">Reserved Qty</th><th>Order Status</th></tr></thead><tbody>' +
                rows + '</tbody></table></div>',
        confirmButtonText: 'Close',
        confirmButtonColor: '#6366f1'
    });
}
</script>
<script src="../assets/js/Inventory_staff.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/Inventory_staff.js'); ?>"></script>
<script src="../assets/js/inventory-staff-chrome.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/inventory-staff-chrome.js'); ?>"></script>
<script src="../assets/js/script.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/script.js'); ?>"></script>
<script src="../assets/js/module_access_realtime.js"></script>
</body>
</html>
