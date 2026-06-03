<?php
/**
 * Delivery damage queue — inventory staff only (mobile/session UI).
 * Owners/managers use pages/delivery_damage_queue.php (sidebar layout).
 */
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles_helper.php';
require_once __DIR__ . '/../includes/delivery_damage_ui_helper.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/inventory_staff_chrome.php';

$staff_ids = getInventoryStaffRoleIds($conn);
$allowed_roles = array_unique(array_merge([1, 2, 4], $staff_ids));
requireRole($allowed_roles);

$is_inventory_staff = in_array((int)($_SESSION['user_role'] ?? 0), $staff_ids, true);
if (!$is_inventory_staff) {
    header('Location: delivery_damage_queue.php');
    exit;
}

$ddr_role_id = (int)($_SESSION['user_role'] ?? 0);
$ddr_queue_show = ddr_table_exists($conn) && userCanAccessDeliveryDamageQueue($conn, $ddr_role_id);
$ddr_pending_n = $ddr_queue_show ? countPendingDeliveryDamageReports($conn) : 0;

$current_user_id = (int)($_SESSION['user_id'] ?? 0);
$user_info_stmt = $conn->prepare('SELECT u.full_name, r.role_name FROM user u LEFT JOIN roles r ON u.Role_ID = r.Role_ID WHERE u.User_ID = :uid');
$user_info_stmt->execute([':uid' => $current_user_id]);
$user_info = $user_info_stmt->fetch(PDO::FETCH_ASSOC);
$display_name = $user_info['full_name'] ?? $_SESSION['user_name'] ?? 'User';
$display_role = $user_info['role_name'] ?? 'Staff';

$has_table = ddr_table_exists($conn);
$pending = [];
$total_pending = 0;
$limit = 5;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

if ($has_table) {
    // Get total count for pagination
    $count_stmt = $conn->query("
        SELECT COUNT(*) FROM delivery_damage_report r
        LEFT JOIN damage_report_reviews rev ON rev.report_id = r.report_id
        WHERE COALESCE(rev.status, 'pending_review') = 'pending_review'
    ");
    $total_pending = (int)$count_stmt->fetchColumn();
    $total_pages = ceil($total_pending / $limit);

    $stmt = $conn->prepare(
        "SELECT r.report_id, r.Delivery_ID, od.Order_ID, r.damaged_qty, r.reason, r.photo_path, r.submitted_at,
                p.product_name, od.ordered_qty,
                u.full_name AS rider_name, u.user_name AS rider_username,
                COALESCE(rev.status, 'pending_review') AS status
         FROM delivery_damage_report r
         LEFT JOIN damage_report_reviews rev ON rev.report_id = r.report_id
         INNER JOIN order_details od ON od.Order_detail_ID = r.Order_detail_ID
         INNER JOIN products p ON p.Product_ID = od.Product_ID
         INNER JOIN user u ON u.User_ID = r.submitted_by
         WHERE COALESCE(rev.status, 'pending_review') = 'pending_review'
         ORDER BY r.submitted_at ASC
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$pending_count = $total_pending;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Delivery damage — Staff</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://unpkg.com/lucide@1.16.0/dist/umd/lucide.min.js" integrity="sha384-ZgnJ3Zpr70Xoify35DjOZWqHib1iYJBpYpQUIEpDASG9+fJ745WzNQuC004dwU0W" crossorigin="anonymous"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Poppins', 'sans-serif'] },
                    colors: { primary: '#6366f1', secondary: '#4f46e5', accent: '#a855f7' }
                }
            }
        }
    </script>
    <style>
        body { background-color: #f8fafc; font-family: 'Poppins', sans-serif; -webkit-tap-highlight-color: transparent; padding-bottom: 20px; }
        .glass-header { background: rgba(255,255,255,0.95); backdrop-filter: blur(10px); }
        .hide-scroll::-webkit-scrollbar { display: none; }
        .hide-scroll { -ms-overflow-style: none; scrollbar-width: none; -webkit-overflow-scrolling: touch; }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
        .modal-active { overflow: hidden; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        /* Antigravity Staggered Animations */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .animate-fade-in-up {
            animation: fadeInUp 0.6s cubic-bezier(0.22, 1, 0.36, 1) forwards;
        }

        .staggered-group > * {
            opacity: 0;
            animation: fadeInUp 0.6s cubic-bezier(0.22, 1, 0.36, 1) forwards;
        }

        .staggered-group > *:nth-child(1) { animation-delay: 0.1s; }
        .staggered-group > *:nth-child(2) { animation-delay: 0.15s; }
        .staggered-group > *:nth-child(3) { animation-delay: 0.2s; }
        .staggered-group > *:nth-child(4) { animation-delay: 0.25s; }
        .staggered-group > *:nth-child(5) { animation-delay: 0.3s; }
        .staggered-group > *:nth-child(6) { animation-delay: 0.35s; }
        .staggered-group > *:nth-child(7) { animation-delay: 0.4s; }
        .staggered-group > *:nth-child(8) { animation-delay: 0.45s; }
    </style>
    <?php inv_chrome_head_assets(); ?>
</head>
<body class="text-slate-800 antialiased max-w-lg mx-auto relative shadow-xl min-h-screen bg-white md:bg-slate-50">

<header class="glass-header sticky top-0 z-40 px-5 pt-6 pb-4 border-b border-slate-100 shadow-sm">
    <?php
    $ddr_nav_href_chrome = ($ddr_queue_show && $has_table) ? 'inventory_staff_delivery_damage.php' : '';
    
    // Calculate overdue orders count for notification badge
    $overdue_orders_count = 0;
    try {
        $orderStatusCol = 'order_status';
        $stmtCol = $conn->query("SHOW COLUMNS FROM orders WHERE Field IN ('order_status', 'status')");
        if ($stmtCol && $stmtCol->rowCount() > 0) {
            $rowCol = $stmtCol->fetch(PDO::FETCH_ASSOC);
            $orderStatusCol = (string)$rowCol['Field'];
        }
        
        $orderColumns = [];
        $stmtCols = $conn->query("SHOW COLUMNS FROM orders");
        if ($stmtCols) {
            $orderColumns = $stmtCols->fetchAll(PDO::FETCH_COLUMN);
        }
        $hasDeliveryDate = in_array('delivery_date', $orderColumns, true);
        $hasScheduleDate = false;
        $tableCheck = $conn->query("SHOW TABLES LIKE 'delivery'");
        if ($tableCheck && $tableCheck->rowCount() > 0) {
            $hasScheduleDate = true;
        }
        
        $deliveryDateSelect = $hasDeliveryDate ? 'o.delivery_date' : 'o.order_date';
        $scheduleDateJoin = $hasScheduleDate ? "LEFT JOIN delivery d ON d.Order_ID = o.Order_ID" : "";
        $scheduleDateCol = $hasScheduleDate ? "COALESCE(d.schedule_date, $deliveryDateSelect)" : $deliveryDateSelect;
        
        $countQuery = "
            SELECT COUNT(DISTINCT o.Order_ID)
            FROM orders o
            $scheduleDateJoin
            LEFT JOIN order_preparation_tasks opt ON o.Order_ID = opt.Order_ID
            WHERE LOWER(COALESCE(o.{$orderStatusCol}, '')) NOT IN ('completed', 'cancelled', 'canceled', 'out for delivery', 'delivered', 'delivered (pending cash turnover)')
              AND COALESCE(opt.status, 'not_started') != 'ready'
              AND $scheduleDateCol < CURRENT_DATE()
        ";
        $countStmt = $conn->query($countQuery);
        if ($countStmt) {
            $overdue_orders_count = (int)$countStmt->fetchColumn();
        }
    } catch (Throwable $e) {}

    $total_notifications_n = getUnreadNotificationsCount($conn);

    $INV_CHROME = [
        'display_name' => $display_name,
        'display_role' => $display_role,
        'session_label' => 'Delivery damage',
        'nav_active' => 'delivery',
        'inventory_href' => 'inventory_staff.php',
        'dashboard_href' => 'manual_adjustment.php',
        'history_href' => 'inventory_staff.php?tab=history',
        'ddr_queue_show' => $ddr_queue_show && $has_table,
        'ddr_nav_href' => $ddr_nav_href_chrome,
        'ddr_pending_n' => $ddr_pending_n,
        'total_notifications_n' => $total_notifications_n,
    ];
    inv_chrome_render_header_block($INV_CHROME);
    ?>
</header>
<?php inv_chrome_render_mobile_drawer($INV_CHROME); ?>

<main class="p-5">
    <?php if (!$has_table): ?>
        <div class="rounded-2xl border border-slate-200 bg-white p-5 text-sm text-slate-600">
            The <code class="text-xs bg-slate-100 px-1 rounded">delivery_damage_report</code> table is not installed. Run
            <code class="text-xs bg-slate-100 px-1 rounded">php database/migrate_delivery_damage_report.php</code> from the capstone folder.
        </div>
    <?php else: ?>
        <div class="mb-4 rounded-2xl bg-gradient-to-br from-indigo-50 to-blue-50 border border-blue-100 p-4 flex items-center justify-between gap-3 shadow-sm animate-fade-in-up">
            <div>
                <p class="text-[10px] font-bold text-indigo-700 uppercase tracking-wider">Pending review</p>
                <p class="text-3xl font-black text-indigo-900"><?php echo (int) $pending_count; ?></p>
            </div>
            <div class="w-12 h-12 rounded-xl bg-white shadow-sm flex items-center justify-center text-indigo-600">
                <i data-lucide="clock" class="w-6 h-6"></i>
            </div>
        </div>

        <?php if (empty($pending)): ?>
            <div class="text-center py-12 text-slate-500 text-sm font-medium animate-fade-in-up">
                <i data-lucide="check-circle" class="w-10 h-10 text-emerald-400 mx-auto mb-3 opacity-50"></i>
                No pending delivery damage reports.
            </div>
        <?php else: ?>
            <div class="space-y-4 max-h-[calc(100vh-340px)] overflow-y-auto custom-scrollbar px-1 pb-4 staggered-group">
                <?php foreach ($pending as $row): ?>
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm hover:border-indigo-200 transition-colors" data-report-id="<?php echo (int) $row['report_id']; ?>">
                        <div class="flex justify-between items-start gap-2 mb-2">
                            <div>
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Order #<?php echo (int) $row['Order_ID']; ?> · Del #<?php echo (int) $row['Delivery_ID']; ?></p>
                                <p class="font-bold text-slate-900"><?php echo htmlspecialchars($row['product_name']); ?></p>
                            </div>
                            <span class="text-[10px] font-bold text-slate-400 uppercase bg-slate-100 px-2 py-1 rounded-lg"><?php echo date('M j g:i A', strtotime($row['submitted_at'])); ?></span>
                        </div>
                        <div class="grid grid-cols-2 gap-2 text-sm mb-3">
                            <div class="bg-slate-50 p-2 rounded-xl border border-slate-100 text-center">
                                <span class="text-[10px] font-bold text-slate-400 uppercase block">Damaged</span>
                                <strong class="text-lg text-rose-600"><?php echo (int) $row['damaged_qty']; ?></strong>
                            </div>
                            <div class="bg-slate-50 p-2 rounded-xl border border-slate-100 text-center">
                                <span class="text-[10px] font-bold text-slate-400 uppercase block">Ordered</span>
                                <strong class="text-lg text-slate-700"><?php echo (int) $row['ordered_qty']; ?></strong>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 mb-3 bg-indigo-50/50 p-2 rounded-xl border border-indigo-100/50">
                            <i data-lucide="user-circle" class="w-3.5 h-3.5 text-indigo-400"></i>
                            <span class="text-xs font-bold text-indigo-600 uppercase tracking-tight"><?php echo htmlspecialchars($row['rider_name'] ?: $row['rider_username']); ?> <span class="text-indigo-300 font-medium">| Rider</span></span>
                        </div>
                        <p class="text-sm text-slate-600 mb-4 bg-slate-50 p-3 rounded-xl italic leading-relaxed font-medium">"<?php echo htmlspecialchars($row['reason']); ?>"</p>
                        
                        <?php if (!empty($row['photo_path'])): ?>
                            <button type="button" onclick="showPhotoModal('../<?php echo htmlspecialchars($row['photo_path']); ?>')" class="w-full mb-3 py-2.5 rounded-xl border-2 border-dashed border-indigo-100 text-indigo-600 text-xs font-bold flex items-center justify-center gap-2 hover:bg-indigo-50 hover:border-indigo-200 transition-all">
                                <i data-lucide="image" class="w-4 h-4"></i> View photo
                            </button>
                        <?php endif; ?>
                        
                        <div class="flex gap-2 pt-2 border-t border-slate-100">
                            <button type="button" onclick="ddrApprove(<?php echo (int) $row['report_id']; ?>)" class="flex-1 py-3 rounded-xl bg-blue-600 text-white text-xs font-black shadow-lg shadow-blue-600/20 hover:bg-blue-700 transition-all active:scale-95">Approve</button>
                            <button type="button" onclick="ddrReject(<?php echo (int) $row['report_id']; ?>)" class="flex-1 py-3 rounded-xl bg-red-600 text-white text-xs font-black shadow-lg shadow-red-600/20 hover:bg-red-700 transition-all active:scale-95">Reject</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination Controls -->
            <?php if ($total_pages > 1): ?>
                <div class="flex items-center justify-between gap-4 mt-6 pt-4 border-t border-slate-100">
                    <button type="button" onclick="<?php echo ($page > 1) ? "location.href='?page=".($page-1)."'" : ""; ?>" 
                            class="flex-1 py-2.5 rounded-xl border border-slate-200 flex items-center justify-center gap-2 text-xs font-bold <?php echo ($page <= 1) ? "opacity-50 cursor-not-allowed text-slate-400" : "text-slate-600 hover:bg-slate-50"; ?>">
                        <i data-lucide="chevron-left" class="w-4 h-4"></i> Previous
                    </button>
                    <span class="text-xs font-black text-slate-400 uppercase tracking-widest">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                    <button type="button" onclick="<?php echo ($page < $total_pages) ? "location.href='?page=".($page+1)."'" : ""; ?>" 
                            class="flex-1 py-2.5 rounded-xl border border-slate-200 flex items-center justify-center gap-2 text-xs font-bold <?php echo ($page >= $total_pages) ? "opacity-50 cursor-not-allowed text-slate-400" : "text-slate-600 hover:bg-slate-50"; ?>">
                        Next <i data-lucide="chevron-right" class="w-4 h-4"></i>
                    </button>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</main>

<!-- Photo Viewer Modal -->
<div id="photoModal" class="hidden fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/95 backdrop-blur-sm">
    <div class="relative max-w-full max-h-full animate-slide-up-3d">
        <button type="button" onclick="hidePhotoModal()" class="absolute -top-12 right-0 w-10 h-10 bg-white rounded-full flex items-center justify-center text-slate-900 shadow-xl hover:scale-110 transition-all">
            <i data-lucide="x" class="w-6 h-6"></i>
        </button>
        <img id="photoModalImg" src="" alt="Damage Photo" class="max-w-full max-h-[80vh] rounded-2xl shadow-2xl border-4 border-white">
        <div class="mt-4 text-center">
            <p class="text-white text-sm font-bold opacity-80 uppercase tracking-widest">Damage Evidence</p>
        </div>
    </div>
</div>

<script>
window.csrfToken = <?php echo json_encode(getCsrfToken(), JSON_UNESCAPED_UNICODE); ?>;

document.addEventListener('DOMContentLoaded', () => {
    lucide.createIcons();
});

function showPhotoModal(src) {
    const modal = document.getElementById('photoModal');
    const img = document.getElementById('photoModalImg');
    if (!modal || !img) return;
    img.src = src;
    modal.classList.remove('hidden');
    document.body.classList.add('modal-active');
}

function hidePhotoModal() {
    const modal = document.getElementById('photoModal');
    if (!modal) return;
    modal.classList.add('hidden');
    document.body.classList.remove('modal-active');
}

function ddrApprove(reportId) {
    Swal.fire({
        title: 'Approve damage report?',
        text: 'Inventory will be reduced by the reported quantity.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Approve',
        confirmButtonColor: '#2563eb',
        cancelButtonText: 'Cancel'
    }).then((res) => {
        if (!res.isConfirmed) return;
        const fd = new FormData();
        fd.append('action', 'approve');
        fd.append('report_id', String(reportId));
        fd.append('csrf_token', window.csrfToken);
        fetch('../api/delivery_damage_backend.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Approved', 'Stock and ledger updated.', 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', data.error || 'Failed', 'error');
                }
            })
            .catch(() => Swal.fire('Error', 'Network error', 'error'));
    });
}

function ddrReject(reportId) {
    Swal.fire({
        title: 'Reject report',
        input: 'textarea',
        inputLabel: 'Notes for rider (optional)',
        inputPlaceholder: 'Reason for rejection...',
        showCancelButton: true,
        confirmButtonText: 'Reject',
        confirmButtonColor: '#dc2626'
    }).then((res) => {
        if (!res.isConfirmed) return;
        const fd = new FormData();
        fd.append('action', 'reject');
        fd.append('report_id', String(reportId));
        fd.append('staff_notes', res.value || '');
        fd.append('csrf_token', window.csrfToken);
        fetch('../api/delivery_damage_backend.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Rejected', 'Rider can see the status in their app.', 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', data.error || 'Failed', 'error');
                }
            })
            .catch(() => Swal.fire('Error', 'Network error', 'error'));
    });
}
</script>
<script src="../assets/js/inventory-staff-chrome.js"></script>
</body>
</html>
