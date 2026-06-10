<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/roles_helper.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/delivery_damage_ui_helper.php';
require_once __DIR__ . '/../includes/preparation_tasks_helper.php';
require_once __DIR__ . '/../includes/inventory_staff_chrome.php';

$inv_staff_ids = getInventoryStaffRoleIds($conn);
requireRole(empty($inv_staff_ids) ? [0] : $inv_staff_ids);

$full_name = $_SESSION['full_name'] ?? $_SESSION['user_name'] ?? 'Staff';
$display_name = $full_name;
$display_role = 'Inventory Staff';
$ddr_role_id = (int)($_SESSION['user_role'] ?? 0);
$ddr_queue_show = ddr_table_exists($conn) && userCanAccessDeliveryDamageQueue($conn, $ddr_role_id);
$ddr_pending_n = $ddr_queue_show ? countPendingDeliveryDamageReports($conn) : 0;
$ddr_nav_href = $ddr_queue_show ? 'inventory_staff_delivery_damage.php' : '';
$prep_queue = prepTasksFetchQueue($conn);

// Fetch profile picture
$userId = (int)($_SESSION['user_id'] ?? 0);
$profilePicture = '';
try {
    $stmt = $conn->prepare("SELECT profile_picture FROM User_Profile WHERE User_ID = ? LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['profile_picture'])) {
        $profilePicture = $row['profile_picture'];
    }
} catch (Throwable $e) {
    // Ignore errors, fallback to initial
}

function renderPrepTaskCard(array $order, bool $allowActions): void
{
    global $conn;
    $orderId = (int)$order['Order_ID'];
    $status = prepTasksStatusLabel((string)$order['prep_status']);
    $deliveryDate = date('M j, Y', strtotime((string)$order['delivery_date_effective']));
    $deliveryTime = !empty($order['delivery_time']) ? date('g:i A', strtotime((string)$order['delivery_time'])) : '';
    $deliveryDateRaw = (string)$order['delivery_date_effective'];
    $todayYmd = date('Y-m-d');
    $isOverdue = $deliveryDateRaw !== '' && $deliveryDateRaw < $todayYmd;
    
    $statusColors = [];
    foreach (prepTasksGetValidStatuses($conn) as $s) {
        $k = str_replace('_', '-', $s);
        $statusColors[$k] = 'bg-slate-100 text-slate-600 border border-slate-200';
    }
    $statusColors['not-started'] = 'bg-slate-100 text-slate-600 border border-slate-200';
    $statusColors['preparing']   = 'bg-amber-100 text-amber-700 border border-amber-200';
    $statusColors['ready']       = 'bg-emerald-100 text-emerald-700 border border-emerald-200';
    $statusColors['short-stock'] = 'bg-rose-100 text-rose-700 border border-rose-200';
    $statusClass = $statusColors[str_replace('status-', '', $status['class'])] ?? 'bg-slate-100 text-slate-600';
    
    ?>
    <article data-prep-order-id="<?php echo $orderId; ?>" class="bg-white p-5 rounded-[1.25rem] shadow-sm border <?php echo $isOverdue ? 'border-rose-300 ring-1 ring-rose-200' : 'border-slate-100'; ?> transition-all hover:shadow-md relative overflow-hidden group">
        <!-- Accent Line -->
        <div data-prep-accent-line class="absolute top-0 left-0 w-1 h-full <?php echo in_array((string)$order['prep_status'], ['ready'], true) ? 'bg-emerald-400' : 'bg-indigo-400'; ?> opacity-80"></div>
        
        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-start gap-3 mb-4 border-b border-slate-50 pb-4 ml-2">
            <div>
                <h4 class="text-base font-black text-slate-800 tracking-tight flex items-center gap-2">
                    Order #<?php echo $orderId; ?>
                </h4>
                <p class="text-xs font-semibold text-slate-500 mt-1 flex items-center gap-1.5"><i class="fas fa-user text-indigo-400"></i> <?php echo htmlspecialchars((string)$order['customer_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                <?php if (!empty($order['rider_name'])): ?>
                    <p class="text-xs font-semibold text-emerald-600 mt-0.5 flex items-center gap-1.5"><i class="fas fa-motorcycle text-emerald-400"></i> <?php echo htmlspecialchars((string)$order['rider_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
                <div class="flex flex-wrap items-center gap-1.5 mt-1.5">
                    <p class="text-[11px] font-bold text-slate-600 bg-slate-100 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md"><i class="far fa-calendar-alt"></i> <span class="text-slate-500">Delivery Date:</span> <span class="text-slate-700"><?php echo htmlspecialchars($deliveryDate, ENT_QUOTES, 'UTF-8'); ?></span></p>
                    <?php if ($deliveryTime !== ''): ?>
                        <p class="text-[11px] font-bold text-indigo-600 bg-indigo-50 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md"><i class="far fa-clock"></i> <?php echo htmlspecialchars($deliveryTime, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                    <?php if ($isOverdue): ?>
                        <p class="text-[11px] font-black text-rose-700 bg-rose-50 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border border-rose-200">
                            <i class="fas fa-triangle-exclamation"></i> Missed Delivery Day
                        </p>
                    <?php endif; ?>
                </div>
            </div>
            <span data-prep-status-badge class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-wider <?php echo $statusClass; ?>">
                <i class="fas <?php echo $status['icon']; ?>"></i>
                <span><?php echo htmlspecialchars($status['label'], ENT_QUOTES, 'UTF-8'); ?></span>
            </span>
        </div>

        <?php if ($isOverdue): ?>
            <div class="ml-2 mb-3 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-[11px] font-bold text-rose-700 flex items-center gap-2">
                <i class="fas fa-circle-exclamation"></i>
                This order is overdue. The delivery date has already passed. Please prioritize for dispatch.
            </div>
        <?php endif; ?>

        <div class="mb-5 ml-2">
            <strong class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2.5">Order Items</strong>
            <?php if (!empty($order['items'])): ?>
                <ul class="space-y-2">
                    <?php foreach ($order['items'] as $item): ?>
                        <li class="flex justify-between items-center bg-slate-50/80 px-3.5 py-2.5 rounded-xl text-sm font-semibold text-slate-700 border border-slate-100/50">
                            <span class="flex items-center gap-2.5"><div class="w-6 h-6 rounded-md bg-white flex items-center justify-center shadow-sm border border-slate-100"><i class="fas fa-cube text-slate-400 text-[10px]"></i></div> <?php echo htmlspecialchars((string)$item['product_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="text-indigo-700 font-black bg-white px-2.5 py-1 rounded-lg shadow-sm border border-slate-100/80 text-xs">
                                <?php echo htmlspecialchars(prepTasksFormatQty((float)$item['quantity']) . ' ' . (string)$item['unit'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-xs font-semibold text-slate-400 italic bg-slate-50 p-3 rounded-xl text-center">No order items found.</p>
            <?php endif; ?>
        </div>

        <div class="pt-4 border-t border-slate-100 ml-2">
            <?php if ($allowActions): ?>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    <form method="POST" action="../api/preparation_tasks_backend.php" class="w-full m-0">
                        <?php echo csrfTokenField(); ?>
                        <input type="hidden" name="order_id" value="<?php echo $orderId; ?>">
                        <input type="hidden" name="action" value="start_preparing">
                        <button data-prep-start-btn type="submit" class="w-full flex justify-center items-center gap-2 py-3 rounded-xl text-xs font-bold transition-all <?php echo in_array((string)$order['prep_status'], ['preparing', 'ready'], true) ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-indigo-600 text-white hover:bg-indigo-700 shadow-md shadow-indigo-200/50 hover:shadow-lg hover:shadow-indigo-300/50 active:scale-[0.98]'; ?>" <?php echo in_array((string)$order['prep_status'], ['preparing', 'ready'], true) ? 'disabled' : ''; ?>>
                            <i class="fas fa-play"></i> Start Prep
                        </button>
                    </form>
                    <form method="POST" action="../api/preparation_tasks_backend.php" class="w-full m-0">
                        <?php echo csrfTokenField(); ?>
                        <input type="hidden" name="order_id" value="<?php echo $orderId; ?>">
                        <input type="hidden" name="action" value="mark_ready">
                        <button data-prep-ready-btn type="submit" class="w-full flex justify-center items-center gap-2 py-3 rounded-xl text-xs font-bold transition-all <?php echo (string)$order['prep_status'] === 'ready' ? 'bg-slate-100 text-slate-400 cursor-not-allowed' : 'bg-emerald-500 text-white hover:bg-emerald-600 shadow-md shadow-emerald-200/50 hover:shadow-lg hover:shadow-emerald-300/50 active:scale-[0.98]'; ?>" <?php echo (string)$order['prep_status'] === 'ready' ? 'disabled' : ''; ?>>
                            <i class="fas fa-check-circle"></i> Ready
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <details class="group bg-slate-50 rounded-xl overflow-hidden border border-slate-100 transition-all">
                    <summary class="cursor-pointer flex items-center justify-between px-4 py-3 text-xs font-bold text-slate-600 select-none hover:bg-slate-100/80 transition-colors">
                        <span class="flex items-center gap-2"><i class="fas fa-eye text-indigo-400"></i> View Details</span>
                        <div class="w-6 h-6 rounded-full bg-white flex items-center justify-center shadow-sm"><i class="fas fa-chevron-down text-slate-400 text-[10px] transition-transform group-open:rotate-180"></i></div>
                    </summary>
                    <div class="px-4 pb-4 pt-2 text-xs text-slate-500 font-medium leading-relaxed border-t border-slate-100/50 bg-white">
                        Scheduled for <strong class="text-slate-700"><?php echo htmlspecialchars($deliveryDate, ENT_QUOTES, 'UTF-8'); ?></strong>. This task becomes active closer to the delivery date.
                    </div>
                </details>
            <?php endif; ?>
        </div>
    </article>
    <?php
}

function renderPrepSection(string $key, string $tagText, string $title, string $subtitle, array $orders, bool $allowActions): void
{
    $colors = [
        'urgent' => ['bg' => 'bg-rose-50', 'border' => 'border-rose-200', 'text' => 'text-rose-700', 'badgeBg' => 'bg-gradient-to-br from-red-500 to-rose-600', 'badgeText' => 'text-white'],
        'tomorrow' => ['bg' => 'bg-amber-50', 'border' => 'border-amber-200', 'text' => 'text-amber-700', 'badgeBg' => 'bg-gradient-to-br from-amber-400 to-orange-500', 'badgeText' => 'text-white'],
        'upcoming' => ['bg' => 'bg-indigo-50', 'border' => 'border-indigo-200', 'text' => 'text-indigo-700', 'badgeBg' => 'bg-gradient-to-br from-indigo-500 to-blue-600', 'badgeText' => 'text-white'],
    ];
    $icons = [
        'urgent' => '<i class="fas fa-bolt"></i>',
        'tomorrow' => '<i class="fas fa-clock"></i>',
        'upcoming' => '<i class="fas fa-calendar-alt"></i>',
    ];
    $c = $colors[$key] ?? $colors['upcoming'];
    $icon = $icons[$key] ?? '<i class="fas fa-tasks"></i>';
    ?>
    <section class="mb-8">
        <div class="flex justify-between items-center mb-4 px-1">
            <div>
                <h3 class="text-sm font-black uppercase tracking-wider <?php echo $c['text']; ?> flex items-center gap-2">
                    <span class="w-8 h-8 rounded-xl <?php echo $c['badgeBg']; ?> <?php echo $c['badgeText']; ?> flex items-center justify-center text-[13px] shadow-sm"><?php echo $icon; ?></span>
                    <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>
                </h3>
                <p class="text-[10px] font-bold text-slate-400 mt-1 uppercase tracking-widest ml-10"><?php echo htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <span class="flex items-center justify-center h-8 px-3.5 rounded-xl <?php echo $c['bg']; ?> <?php echo $c['text']; ?> font-black text-xs border <?php echo $c['border']; ?> shadow-sm">
                <?php echo count($orders); ?>
            </span>
        </div>
        
        <?php if (empty($orders)): ?>
            <div class="bg-white border border-slate-100 rounded-[1.5rem] p-8 text-center flex flex-col items-center justify-center shadow-sm">
                <div class="w-16 h-16 bg-slate-50 border border-slate-100 rounded-2xl flex items-center justify-center mb-4 shadow-sm transform -rotate-3">
                    <i class="fas fa-check-double text-2xl text-slate-300"></i>
                </div>
                <h4 class="text-sm font-black text-slate-700">All Clear</h4>
                <p class="text-[11px] font-bold text-slate-400 mt-1 uppercase tracking-wider">No tasks in this section right now</p>
            </div>
        <?php else: ?>
            <div class="flex flex-col gap-3">
                <?php foreach ($orders as $order): ?>
                    <?php renderPrepTaskCard($order, $allowActions); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#4f46e5">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Inventory Staff">
    <link rel="manifest" href="../manifest_inventory_staff.webmanifest">
    <link rel="apple-touch-icon" href="../assets/images/vip_logo.jpg">
    <title>Inventory Staff - VIP Ice Plant</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <script src="https://cdn.tailwindcss.com"></script>
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
    <link rel="stylesheet" href="../assets/css/style.css">
    <?php inv_chrome_head_assets(); ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Poppins', sans-serif; }
        .prep-section { margin-bottom: 1rem; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; overflow: hidden; }
        .prep-section-head {
            display: flex; justify-content: space-between; gap: 1rem; align-items: center;
            padding: 0.9rem 1rem; border-bottom: 1px solid #e2e8f0;
        }
        .prep-section-head h3 { margin: 0; font-size: 0.9rem; font-weight: 800; }
        .prep-section-head p { margin: 0.15rem 0 0; font-size: 0.75rem; color: #64748b; }
        .prep-section-head span { min-width: 2rem; height: 2rem; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; }
        .prep-urgent .prep-section-head { background: #fff1f2; }
        .prep-urgent .prep-section-head span { background: #fee2e2; color: #b91c1c; }
        .prep-tomorrow .prep-section-head { background: #fffbeb; }
        .prep-tomorrow .prep-section-head span { background: #fef3c7; color: #a16207; }
        .prep-upcoming .prep-section-head { background: #eff6ff; }
        .prep-upcoming .prep-section-head span { background: #dbeafe; color: #1d4ed8; }
        .prep-list { display: flex; flex-direction: column; }
        .prep-card { padding: 1rem; border-bottom: 1px solid #f1f5f9; }
        .prep-card:last-child { border-bottom: none; }
        .prep-card-top { display: flex; justify-content: space-between; gap: 0.75rem; align-items: flex-start; }
        .prep-card h4 { margin: 0; font-size: 0.95rem; font-weight: 800; }
        .prep-card p { margin: 0.2rem 0 0; color: #475569; font-size: 0.8rem; }
        .prep-status {
            flex-shrink: 0; display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.35rem 0.55rem;
            border-radius: 8px; font-size: 0.7rem; font-weight: 800; white-space: nowrap;
        }
        .status-not-started { background: #f1f5f9; color: #475569; }
        .status-preparing { background: #fef3c7; color: #92400e; }
        .status-ready { background: #dcfce7; color: #166534; }
        .status-short-stock { background: #fee2e2; color: #991b1b; }
        <?php foreach (prepTasksGetValidStatuses($conn) as $s): $sk = str_replace('_', '-', $s); if (in_array($sk, ['not-started','preparing','ready','short-stock'], true)) continue; ?>
        .status-<?php echo $sk; ?> { background: #f1f5f9; color: #475569; }
        <?php endforeach; ?>
        .prep-items { margin-top: 0.8rem; font-size: 0.82rem; color: #0f172a; }
        .prep-items strong { display: block; margin-bottom: 0.25rem; }
        .prep-items ul { margin: 0; padding-left: 1.2rem; color: #334155; }
        .prep-muted, .prep-empty { color: #64748b; font-size: 0.8rem; }
        .prep-empty { padding: 1rem; background: #fff; }
        .prep-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.9rem; }
        .prep-actions form { margin: 0; }
        .prep-btn {
            border: none; border-radius: 8px; padding: 0.55rem 0.75rem; font-size: 0.76rem; font-weight: 800;
            cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem;
        }
        .prep-btn:disabled { opacity: 0.48; cursor: not-allowed; }
        .prep-btn-start { background: #312e81; color: #fff; }
        .prep-btn-ready { background: #047857; color: #fff; }
        .prep-btn-short { background: #fff1f2; color: #be123c; border: 1px solid #fecdd3; }
        .prep-details summary {
            cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem; border-radius: 8px;
            padding: 0.55rem 0.75rem; background: #eff6ff; color: #1d4ed8; font-size: 0.76rem; font-weight: 800;
        }
        @media (max-width: 640px) {
            .prep-card-top { flex-direction: column; }
            .prep-status { white-space: normal; }
            .prep-actions, .prep-actions form, .prep-btn { width: 100%; }
            .prep-btn { justify-content: center; }
        }
    </style>
</head>
<body class="text-slate-800 antialiased max-w-lg mx-auto relative shadow-xl min-h-screen bg-white md:bg-slate-50">

    <!-- Sticky Header -->
    <header class="glass-header sticky top-0 z-[60] px-5 pt-6 pb-4 border-b border-slate-100 shadow-sm bg-white/95">
        <?php
        $total_notifications_n = getUnreadNotificationsCount($conn);

        $INV_CHROME = [
            'display_name' => $display_name,
            'display_role' => $display_role,
            'session_label' => 'Preparation Session',
            'nav_active' => 'preparation',
            'inventory_href' => 'inventory_staff.php',
            'dashboard_href' => 'inventory_staff.php?tab=dashboard',
            'history_href' => 'inventory_staff.php?tab=history',
            'ddr_queue_show' => $ddr_queue_show,
            'ddr_nav_href' => $ddr_nav_href,
            'ddr_pending_n' => $ddr_pending_n,
            'total_notifications_n' => $total_notifications_n,
            'profile_picture' => $profilePicture,
        ];
        inv_chrome_render_header_block($INV_CHROME);
        ?>
    </header>
    <?php inv_chrome_render_mobile_drawer($INV_CHROME); ?>

    <main class="p-5 isolate">

    <?php if (!empty($_GET['success'])): ?>
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700 flex items-center gap-2">
            <i class="fas fa-circle-check"></i><?php echo htmlspecialchars((string)$_GET['success'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($_GET['error'])): ?>
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700 flex items-center gap-2">
            <i class="fas fa-triangle-exclamation"></i><?php echo htmlspecialchars((string)$_GET['error'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div class="mb-5 p-4 bg-gradient-to-br from-indigo-600 via-indigo-700 to-indigo-900 rounded-2xl shadow-lg shadow-indigo-200">
        <div class="flex items-center gap-3 mb-2">
            <div class="w-10 h-10 rounded-xl bg-white/15 backdrop-blur-sm text-white flex items-center justify-center">
                <i class="fas fa-clipboard-list text-lg"></i>
            </div>
            <div>
                <h2 class="text-sm font-black text-white tracking-wide">PREPARATION TASKS</h2>
                <p class="text-[10px] font-medium text-indigo-200 leading-tight">Scheduled orders ready for checking &amp; preparation</p>
            </div>
        </div>
    </div>

    <?php renderPrepSection('urgent', 'URGENT', 'Deliver Today', 'Orders due today or overdue.', $prep_queue['urgent'], true); ?>
    <?php renderPrepSection('tomorrow', 'PREPARE TODAY', 'For Tomorrow', 'Orders that should be prepared before tomorrow delivery.', $prep_queue['tomorrow'], true); ?>
    <?php renderPrepSection('upcoming', 'UPCOMING', 'Future Orders', 'Scheduled orders for 2+ days from today.', $prep_queue['upcoming'], false); ?>

    </main>

<script>
    const PREP_TASK_STATUSES = <?php echo json_encode(prepTasksGetValidStatuses($conn)); ?>;
</script>
<script>
    (function () {
        const installBtn = document.getElementById('installPwaBtn');
        if (!installBtn) return;

        if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) {
            installBtn.style.display = 'none';
            return;
        }

        let deferredPrompt = null;

        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            installBtn.style.display = 'block';
        });

        window.addEventListener('appinstalled', () => {
            deferredPrompt = null;
            installBtn.style.display = 'none';
        });

        function showManualInstallHelp() {
            installBtn.innerHTML = '<i class="fas fa-plus"></i> Add to Home Screen';
            installBtn.onclick = () => {
                Swal.fire({
                    icon: 'info',
                    title: 'Install the app',
                    html: 'Tap <b>Share</b> then <b>Add to Home Screen</b>.<br><br>If you do not see it, open browser menu and choose <b>Add to Home Screen</b>.',
                });
            };
        }

        installBtn.addEventListener('click', async () => {
            if (!deferredPrompt) {
                showManualInstallHelp();
                return;
            }
            deferredPrompt.prompt();
            try {
                await deferredPrompt.userChoice;
            } catch (e2) {}
            deferredPrompt = null;
            installBtn.style.display = 'none';
        });
    })();

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('../sw.js').catch(() => {});
        });
    }
</script>
<script src="../assets/js/inventory-staff-chrome.js"></script>
<script src="../assets/js/script.js"></script>
<script src="../assets/js/module_access_realtime.js"></script>
</body>
</html>
