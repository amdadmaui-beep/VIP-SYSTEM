<?php
session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/roles_helper.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/delivery_damage_ui_helper.php';

$staff_ids = getInventoryStaffRoleIds($conn);
$allowed_roles = array_values(array_unique(array_merge([1, 2, 4], $staff_ids)));
requireRole($allowed_roles);

/** Inventory staff use the dedicated mobile queue page. */
if (isInventoryStaffRole($conn, (int) ($_SESSION['user_role'] ?? 0))) {
    header('Location: inventory_staff_delivery_damage.php');
    exit;
}

$has_table = false;
try {
    $chk = $conn->query("SHOW TABLES LIKE 'delivery_damage_report'");
    $has_table = $chk && $chk->rowCount() > 0;
} catch (Throwable $e) {
    $has_table = false;
}

$pending = [];
if ($has_table) {
    $stmt = $conn->query(
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
         ORDER BY r.submitted_at ASC"
    );
    $pending = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

$pending_count = count($pending);
$staff_damage_reports = fetchStaffDamageReportsForManager($conn, 50);
$staff_damage_count = count($staff_damage_reports);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Damage Reports - VIP Villanueva Ice Plant</title>
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <script src="https://unpkg.com/lucide@1.16.0/dist/umd/lucide.min.js" integrity="sha384-ZgnJ3Zpr70Xoify35DjOZWqHib1iYJBpYpQUIEpDASG9+fJ745WzNQuC004dwU0W" crossorigin="anonymous"></script>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#6366f1',
                        secondary: '#8b5cf6',
                        accent: '#f59e0b',
                        surface: '#ffffff',
                        background: '#f8fafc',
                    },
                    fontFamily: {
                        sans: ['Plus Jakarta Sans', 'sans-serif'],
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.5s ease-out forwards',
                        'slide-up': 'slideUp 0.5s ease-out forwards',
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' },
                        },
                        slideUp: {
                            '0%': { opacity: '0', transform: 'translateY(20px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' },
                        },
                    },
                }
            }
        }
    </script>
    <style type="text/tailwindcss">
        @layer components {
            .glass-card {
                @apply bg-white/80 backdrop-blur-md border border-white/20 shadow-xl;
            }
            .premium-table th {
                @apply py-4 px-6 text-left text-xs font-bold text-indigo-600 uppercase tracking-wider bg-indigo-50/50;
            }
            .premium-table td {
                @apply py-4 px-6 text-base text-slate-700 border-b border-slate-100 transition-all duration-200;
            }
            .table-action-btn-label {
                height: 40px !important;
                font-size: 0.95rem !important;
                padding: 0 1.25rem !important;
            }
            .table-action-btn-label i {
                font-size: 1rem !important;
            }
            .stagger-item {
                opacity: 0;
            }
        }
        @keyframes slide-up-3d {
            from { opacity: 0; transform: translateY(30px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .animate-slide-up-3d { animation: slide-up-3d 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        .modal-active { overflow: hidden !important; }
        /* Custom scrollbar */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
    <link rel="stylesheet" href="../assets/css/inventory.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/orders.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="bg-slate-50 font-sans antialiased text-slate-900">
<div class="dashboard-wrapper min-h-screen flex">
    <?php
    require_once __DIR__ . '/../../includes/sidebar.php';
    renderSidebar($conn, ['base' => '../', 'active' => 'delivery_damage_queue']);
    ?>
    
    <main class="main-content flex-1 p-6 lg:p-10 transition-all duration-300" id="mainContent">
        <!-- Header Section -->
        <section class="mb-10 animate-fade-in">
            <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-amber-500 via-orange-500 to-amber-600 p-8 lg:p-12 shadow-2xl shadow-orange-200">
                <div class="relative z-10">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="p-3 bg-white/20 backdrop-blur-md rounded-2xl">
                            <i data-lucide="clipboard-check" class="w-8 h-8 text-white"></i>
                        </div>
                        <h1 class="text-3xl lg:text-4xl font-extrabold text-white tracking-tight">Damage Reports</h1>
                    </div>
                    <p class="text-white/90 text-lg max-w-2xl font-medium leading-relaxed">Review rider delivery damage (approve or reject) and view inventory staff damage photo evidence.</p>
                </div>
                <!-- Decorative Elements -->
                <div class="absolute -right-20 -top-20 w-80 h-80 bg-white/10 rounded-full blur-3xl"></div>
                <div class="absolute -left-10 -bottom-10 w-60 h-60 bg-amber-400/20 rounded-full blur-2xl"></div>
            </div>
        </section>

        <?php if (!$has_table): ?>
            <div class="glass-card rounded-3xl p-6 mb-10 border border-amber-200 bg-amber-50/50">
                <p class="text-amber-900 font-semibold text-sm"><i class="fas fa-exclamation-triangle mr-2"></i>Rider delivery damage is unavailable — run <code class="text-xs bg-white px-1 rounded">php database/migrate_delivery_damage_reports.php</code>. Staff damage reports below still work.</p>
            </div>
        <?php else: ?>
            
            <!-- Stats Overview -->
            <section class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-10">
                <div class="glass-card rounded-3xl p-6 hover:translate-y-[-4px] transition-all duration-300 stagger-item shadow-indigo-100/50">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-slate-500 text-sm font-bold uppercase tracking-wider mb-1">Rider — pending review</p>
                            <h3 class="text-3xl font-black text-slate-800"><?php echo (int)$pending_count; ?></h3>
                        </div>
                        <div class="p-4 bg-orange-100 rounded-2xl text-orange-600">
                            <i data-lucide="hourglass" class="w-6 h-6"></i>
                        </div>
                    </div>
                </div>
                <div class="glass-card rounded-3xl p-6 hover:translate-y-[-4px] transition-all duration-300 stagger-item shadow-violet-100/50">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-slate-500 text-sm font-bold uppercase tracking-wider mb-1">Staff damage reports</p>
                            <h3 class="text-3xl font-black text-slate-800"><?php echo (int)$staff_damage_count; ?></h3>
                        </div>
                        <div class="p-4 bg-violet-100 rounded-2xl text-violet-600">
                            <i data-lucide="camera" class="w-6 h-6"></i>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Rider delivery damage -->
            <section class="glass-card rounded-3xl overflow-hidden shadow-2xl shadow-indigo-100/50 stagger-item mb-10">
                <div class="p-6 border-b border-slate-100 bg-white/50 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-bold flex items-center gap-2">
                            <i data-lucide="truck" class="w-5 h-5 text-indigo-500"></i>
                            Rider Delivery Damage
                        </h2>
                        <p class="text-sm text-slate-500 mt-1">Approve or reject to adjust inventory.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="px-3 py-1 bg-indigo-50 text-indigo-600 text-xs font-bold rounded-full border border-indigo-100">
                            <?php echo (int)$pending_count; ?> Items Pending
                        </span>
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="premium-table w-full">
                        <thead>
                            <tr>
                                <th>Submitted</th>
                                <th>Order / Delivery</th>
                                <th>Product</th>
                                <th>Qty</th>
                                <th>Ordered</th>
                                <th>Rider</th>
                                <th>Reason</th>
                                <th>Photo</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pending)): ?>
                                <tr>
                                    <td colspan="9" class="py-20 text-center">
                                        <div class="flex flex-col items-center">
                                            <div class="p-4 bg-slate-50 rounded-full text-slate-300 mb-4">
                                                <i data-lucide="inbox" class="w-12 h-12"></i>
                                            </div>
                                            <p class="text-slate-400 font-medium text-lg">No pending delivery damage reports.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($pending as $index => $row): ?>
                                    <tr data-report-id="<?php echo (int)$row['report_id']; ?>" class="hover:bg-indigo-50/30 group">
                                        <td class="font-medium"><?php echo date('M j, Y', strtotime($row['submitted_at'])); ?><br><span class="text-slate-400 text-xs"><?php echo date('g:i A', strtotime($row['submitted_at'])); ?></span></td>
                                        <td>
                                            <div class="flex flex-col">
                                                <span class="font-bold text-indigo-600">Order #<?php echo (int)$row['Order_ID']; ?></span>
                                                <span class="text-xs text-slate-400">Delivery #<?php echo (int)$row['Delivery_ID']; ?></span>
                                            </div>
                                        </td>
                                        <td class="font-bold text-slate-800"><?php echo htmlspecialchars($row['product_name']); ?></td>
                                        <td>
                                            <span class="px-2.5 py-1 bg-red-50 text-red-600 rounded-lg font-black text-sm border border-red-100">
                                                <?php echo number_format((float)$row['damaged_qty'], 0); ?>
                                            </span>
                                        </td>
                                        <td class="text-slate-500 font-medium"><?php echo number_format((float)$row['ordered_qty'], 0); ?></td>
                                        <td>
                                            <div class="flex items-center gap-3">
                                                <div class="w-8 h-8 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 font-bold text-xs uppercase">
                                                    <?php echo substr($row['rider_name'] ?: $row['rider_username'], 0, 1); ?>
                                                </div>
                                                <span class="font-semibold text-slate-700"><?php echo htmlspecialchars($row['rider_name'] ?: $row['rider_username']); ?></span>
                                            </div>
                                        </td>
                                        <td class="max-w-[200px]">
                                            <p class="text-xs leading-relaxed text-slate-600 italic line-clamp-2 hover:line-clamp-none transition-all cursor-help" title="<?php echo htmlspecialchars($row['reason']); ?>">
                                                "<?php echo htmlspecialchars($row['reason']); ?>"
                                            </p>
                                        </td>
                                        <td>
                                            <div class="action-group">
                                                <?php if (!empty($row['photo_path'])): ?>
                                                    <?php $photos = explode(',', (string)$row['photo_path']); ?>
                                                    <button type="button" onclick="showPhotoModal('<?php echo addslashes('../' . trim($photos[0])); ?>')" class="table-action-btn table-action-btn-label table-action-btn-view">
                                                        <i data-lucide="image"></i> View<?php if (count($photos) > 1): ?> <span style="font-size:9px;opacity:0.7;">+<?php echo count($photos)-1; ?></span><?php endif; ?>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-slate-300 text-xs italic">No Photo</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-center whitespace-nowrap">
                                            <div class="action-group justify-center">
                                                <button type="button" onclick="ddrApprove(<?php echo (int)$row['report_id']; ?>)" class="table-action-btn table-action-btn-label table-action-btn-sale" title="Approve">
                                                    <i data-lucide="check"></i> Approve
                                                </button>
                                                <button type="button" onclick="ddrReject(<?php echo (int)$row['report_id']; ?>)" class="table-action-btn table-action-btn-label table-action-btn-cancel" title="Reject">
                                                    <i data-lucide="x"></i> Reject
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <!-- Staff damage reports (view photo only) -->
        <section class="glass-card rounded-3xl overflow-hidden shadow-2xl shadow-violet-100/50 stagger-item mt-10">
            <div class="p-6 border-b border-slate-100 bg-white/50 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold flex items-center gap-2">
                        <i data-lucide="warehouse" class="w-5 h-5 text-violet-500"></i>
                        Staff Damage Reports
                    </h2>
                    <p class="text-sm text-slate-500 mt-1">Inventory staff damage from manual adjustment — photo evidence for review only.</p>
                </div>
                <span class="px-3 py-1 bg-violet-50 text-violet-600 text-xs font-bold rounded-full border border-violet-100">
                    <?php echo (int)$staff_damage_count; ?> Report<?php echo $staff_damage_count === 1 ? '' : 's'; ?>
                </span>
            </div>
            <div class="overflow-x-auto">
                <table class="premium-table w-full">
                    <thead>
                        <tr>
                            <th>Reported</th>
                            <th>Product</th>
                            <th>Qty</th>
                            <th>Damage Type</th>
                            <th>Reported By</th>
                            <th>Photo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($staff_damage_reports)): ?>
                            <tr>
                                <td colspan="6" class="py-16 text-center">
                                    <div class="flex flex-col items-center">
                                        <div class="p-4 bg-slate-50 rounded-full text-slate-300 mb-4">
                                            <i data-lucide="image-off" class="w-10 h-10"></i>
                                        </div>
                                        <p class="text-slate-400 font-medium">No inventory staff damage reports yet.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($staff_damage_reports as $srow): ?>
                                <tr class="hover:bg-violet-50/30">
                                    <td class="font-medium"><?php echo date('M j, Y', strtotime($srow['created_at'])); ?><br><span class="text-slate-400 text-xs"><?php echo date('g:i A', strtotime($srow['created_at'])); ?></span></td>
                                    <td class="font-bold text-slate-800"><?php echo htmlspecialchars($srow['product_name']); ?></td>
                                    <td>
                                        <span class="px-2.5 py-1 bg-red-50 text-red-600 rounded-lg font-black text-sm border border-red-100">
                                            <?php echo number_format((float)$srow['quantity'], 0); ?>
                                        </span>
                                    </td>
                                    <td class="text-slate-600 font-medium"><?php echo htmlspecialchars($srow['damage_type']); ?></td>
                                    <td class="font-semibold text-slate-700"><?php echo htmlspecialchars($srow['reported_by_name'] ?: $srow['reported_by_username']); ?></td>
                                    <td>
                                        <?php if (!empty($srow['photo_path'])): ?>
                                            <button type="button" onclick="showPhotoModal('<?php echo addslashes('../' . trim(explode(',', (string)$srow['photo_path'])[0])); ?>')" class="table-action-btn table-action-btn-label table-action-btn-view">
                                                <i data-lucide="image"></i> View Photo
                                            </button>
                                        <?php else: ?>
                                            <span class="text-slate-300 text-xs italic">No photo</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<script>
window.csrfToken = <?php echo json_encode(getCsrfToken(), JSON_UNESCAPED_UNICODE); ?>;

// Initialize Lucide Icons
document.addEventListener('DOMContentLoaded', () => {
    lucide.createIcons();
    
    // Staggered Animations
    const items = document.querySelectorAll('.stagger-item');
    items.forEach((item, index) => {
        setTimeout(() => {
            item.classList.add('animate-slide-up');
            item.style.opacity = '1';
        }, index * 100);
    });
});

function ddrApprove(reportId) {
    Swal.fire({
        title: 'Approve damage report?',
        text: 'Inventory will be reduced by the reported quantity.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Approve',
        confirmButtonColor: '#10b981',
        cancelButtonText: 'Cancel',
        background: '#ffffff',
        customClass: {
            popup: 'rounded-3xl border-none',
            confirmButton: 'rounded-xl font-bold py-3 px-6',
            cancelButton: 'rounded-xl font-bold py-3 px-6'
        }
    }).then((res) => {
        if (!res.isConfirmed) return;
        
        Swal.fire({
            title: 'Processing...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        const fd = new FormData();
        fd.append('action', 'approve');
        fd.append('report_id', String(reportId));
        fd.append('csrf_token', window.csrfToken);
        
        fetch('../api/delivery_damage_backend.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        title: 'Approved!',
                        text: 'Stock and ledger updated successfully.',
                        icon: 'success',
                        confirmButtonColor: '#6366f1',
                        customClass: { popup: 'rounded-3xl' }
                    }).then(() => location.reload());
                } else {
                    Swal.fire('Error', data.error || 'Failed to approve report.', 'error');
                }
            })
            .catch(() => Swal.fire('Error', 'Network error. Please try again.', 'error'));
    });
}

function ddrReject(reportId) {
    Swal.fire({
        title: 'Reject Report',
        text: 'This report will be marked as rejected. You can provide notes for the rider.',
        input: 'textarea',
        inputLabel: 'Rejection Notes (Optional)',
        inputPlaceholder: 'Reason for rejection...',
        showCancelButton: true,
        confirmButtonText: 'Reject Report',
        confirmButtonColor: '#ef4444',
        cancelButtonText: 'Cancel',
        background: '#ffffff',
        customClass: {
            popup: 'rounded-3xl border-none',
            confirmButton: 'rounded-xl font-bold py-3 px-6',
            cancelButton: 'rounded-xl font-bold py-3 px-6',
            input: 'rounded-xl border-slate-200'
        }
    }).then((res) => {
        if (!res.isConfirmed) return;
        
        Swal.fire({
            title: 'Processing...',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading()
        });

        const fd = new FormData();
        fd.append('action', 'reject');
        fd.append('report_id', String(reportId));
        fd.append('staff_notes', res.value || '');
        fd.append('csrf_token', window.csrfToken);
        
        fetch('../api/delivery_damage_backend.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        title: 'Rejected',
                        text: 'Rider can see the status in their app.',
                        icon: 'info',
                        confirmButtonColor: '#6366f1',
                        customClass: { popup: 'rounded-3xl' }
                    }).then(() => location.reload());
                } else {
                    Swal.fire('Error', data.error || 'Failed to reject report.', 'error');
                }
            })
            .catch(() => Swal.fire('Error', 'Network error. Please try again.', 'error'));
    });
}

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

document.addEventListener('DOMContentLoaded', () => {
    if (typeof lucide !== 'undefined') lucide.createIcons();
});
</script>

<!-- Photo Viewer Modal -->
<div id="photoModal" class="hidden fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/95 backdrop-blur-sm">
    <div class="relative max-w-full max-h-full animate-slide-up-3d">
        <button type="button" onclick="hidePhotoModal()" class="absolute -top-12 right-0 w-10 h-10 bg-white rounded-full flex items-center justify-center text-slate-900 shadow-xl hover:scale-110 active:scale-95 transition-all">
            <i data-lucide="x" class="w-6 h-6"></i>
        </button>
        <img id="photoModalImg" src="" alt="Damage Photo" class="max-w-full max-h-[80vh] rounded-2xl shadow-2xl border-4 border-white object-contain">
        <div class="mt-4 text-center">
            <p class="text-white text-sm font-bold opacity-80 uppercase tracking-widest">Damage Evidence</p>
        </div>
    </div>
</div>
</body>
</html>
