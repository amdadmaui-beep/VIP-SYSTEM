<body class="rider-app-shell text-slate-800 antialiased max-w-lg mx-auto relative shadow-xl min-h-screen bg-white md:bg-slate-50 overflow-x-hidden">

    <!-- Sticky Header -->
    <header class="glass-header rider-top-header sticky top-0 z-40 px-4 sm:px-5 pt-4 sm:pt-6 pb-3 sm:pb-4 border-b border-slate-100 shadow-sm">
        <div class="flex justify-between items-center mb-3 sm:mb-4 gap-2">
            <div class="flex items-center gap-2 min-w-0 flex-1">
                <button type="button" id="riderDrawerToggle" class="md:hidden flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 shadow-sm hover:bg-slate-50 hover:border-slate-300 transition-colors" aria-label="Open navigation menu" aria-expanded="false" aria-controls="riderDrawerPanel">
                    <i class="fas fa-bars text-sm" aria-hidden="true"></i>
                </button>
                <div class="flex items-center gap-2.5 min-w-0">
                    <?php if (!empty($rider_has_profile_pic) && !empty($rider_profile_pic_src)): ?>
                        <img src="<?= htmlspecialchars($rider_profile_pic_src, ENT_QUOTES, 'UTF-8') ?>" alt="Profile" class="w-10 h-10 sm:w-12 sm:h-12 shrink-0 rounded-2xl object-cover border-2 border-indigo-200 shadow-md">
                    <?php else: ?>
                        <div class="w-10 h-10 sm:w-12 sm:h-12 shrink-0 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-500 text-white flex items-center justify-center text-lg sm:text-xl font-bold shadow-md">
                            <?= strtoupper(substr($full_name, 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <div class="min-w-0">
                        <span class="text-[10px] font-bold tracking-wider text-indigo-500 uppercase block truncate"><?= htmlspecialchars($rider_session_label, ENT_QUOTES, 'UTF-8') ?></span>
                        <h2 class="text-sm sm:text-base font-bold leading-tight truncate"><?= htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8') ?></h2>
                        <p class="hidden sm:block text-xs text-slate-500 font-medium truncate"><?= htmlspecialchars($display_role, ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-1.5 sm:gap-2 shrink-0">
                <div class="dropdown d-inline-block" id="riderNotifRoot" data-user-id="<?= (int)$user_id ?>" data-unread-count="<?= (int)$total_notifications_n ?>">
                    <button type="button" class="relative flex h-9 w-9 sm:h-10 sm:w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 shadow-sm transition-all duration-200 hover:border-slate-300 hover:bg-slate-50 hover:text-slate-600" id="notificationDropdown" aria-expanded="false" title="Notifications">
                        <i class="fas fa-bell text-lg" aria-hidden="true"></i>
                        <span id="notificationBadge" class="pointer-events-none absolute -top-1 -right-1 flex h-[1.125rem] min-w-[1.125rem] items-center justify-center rounded-full bg-red-500 px-0.5 text-[9px] font-black leading-none text-white shadow-sm ring-2 ring-white<?= $total_notifications_n > 0 ? '' : ' hidden' ?>"><?= $total_notifications_n > 99 ? '99+' : (string)$total_notifications_n ?></span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end dropdown-menu-rider shadow-2xl rounded-2xl border border-slate-200" aria-labelledby="notificationDropdown">
                        <div class="border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white px-4 py-3 rounded-t-2xl">
                            <p class="text-[11px] font-black uppercase tracking-wider text-slate-800">Notifications</p>
                        </div>
                        <div id="notificationList" class="max-h-64 overflow-y-auto overscroll-contain">
                            <div id="noNotifItem" class="p-3 text-center text-muted small">No new notifications</div>
                        </div>
                    </div>
                </div>
                <a href="profile_settings.php" class="flex h-9 w-9 sm:h-10 sm:w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 shadow-sm transition-colors hover:bg-slate-50 hover:text-indigo-600" title="Profile settings" aria-label="Profile settings">
                    <i class="fas fa-user-cog text-sm" aria-hidden="true"></i>
                </a>
                <button type="button" onclick="location.href='../logout.php'" class="flex h-9 w-9 sm:h-10 sm:w-auto sm:px-3 flex-col sm:flex-row items-center justify-center rounded-xl bg-slate-100 text-slate-500 transition-colors hover:bg-slate-200 hover:text-red-500" title="Logout" aria-label="Logout">
                    <i class="fas fa-sign-out-alt text-sm" aria-hidden="true"></i>
                    <span class="hidden sm:inline sm:ml-1.5 text-[10px] font-bold">Logout</span>
                </button>
            </div>
        </div>

        <!-- Shared nav tabs -->
        <nav class="flex gap-2 overflow-x-auto hide-scroll pb-1" aria-label="Rider sections">
            <?php if ($can_rider_dashboard): ?>
            <a href="rider_view.php?tab=dashboard" data-rider-tab="dashboard" class="rider-nav-tab <?= $activeTab === 'dashboard' ? 'rider-nav-active' : '' ?>"><i class="fas fa-th-large"></i> Dashboard</a>
            <?php endif; ?>
            <?php if ($can_rider_queue): ?>
            <a href="rider_view.php?tab=queue" data-rider-tab="queue" class="rider-nav-tab <?= $activeTab === 'queue' ? 'rider-nav-active' : '' ?>"><i class="fas fa-truck"></i> Queue</a>
            <?php endif; ?>
            <?php if ($can_rider_history): ?>
            <a href="rider_view.php?tab=history" data-rider-tab="history" class="rider-nav-tab <?= $activeTab === 'history' ? 'rider-nav-active' : '' ?>"><i class="fas fa-clock-rotate-left"></i> Delivered</a>
            <?php endif; ?>
            <?php if ($can_rider_history): ?>
            <a href="rider_view.php?tab=cancelled" data-rider-tab="cancelled" class="rider-nav-tab <?= $activeTab === 'cancelled' ? 'rider-nav-active' : '' ?>"><i class="fas fa-ban"></i> Cancelled</a>
            <?php endif; ?>
            <?php if (!empty($has_delivery_damage_reports)): ?>
            <a href="rider_view.php?tab=damage-reports" data-rider-tab="damage-reports" class="rider-nav-tab <?= $activeTab === 'damage-reports' ? 'rider-nav-active' : '' ?>"><i class="fas fa-clipboard-list"></i> Damage Reports</a>
            <?php endif; ?>
        </nav>
    </header>

    <!-- Mobile Drawer -->
    <div id="riderDrawerBackdrop" class="rider-drawer-backdrop fixed inset-0 z-[100] bg-slate-900/40 md:hidden" aria-hidden="true"></div>
    <div id="riderDrawerPanel" class="rider-drawer-panel is-closed fixed left-0 top-0 z-[110] flex h-full min-h-0 w-[min(20rem,calc(100vw-1rem))] max-w-[calc(100vw-1rem)] flex-col overflow-hidden border-r border-slate-200 bg-white shadow-2xl md:hidden" role="dialog" aria-modal="true" aria-label="Navigation menu">
        <div class="flex shrink-0 items-center justify-between border-b border-slate-100 px-4 py-4 bg-gradient-to-r from-indigo-50 to-white">
            <div class="flex items-center gap-3">
                <?php if (!empty($rider_has_profile_pic) && !empty($rider_profile_pic_src)): ?>
                    <img src="<?= htmlspecialchars($rider_profile_pic_src, ENT_QUOTES, 'UTF-8') ?>" alt="Profile" class="w-10 h-10 rounded-xl object-cover border-2 border-indigo-200">
                <?php else: ?>
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-500 text-white flex items-center justify-center font-bold">
                        <?= strtoupper(substr($full_name, 0, 1)) ?>
                    </div>
                <?php endif; ?>
                <div>
                    <p class="text-sm font-bold text-slate-800 truncate"><?= htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="text-xs text-slate-500"><?= htmlspecialchars($display_role, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
            <button type="button" id="riderDrawerClose" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-800" aria-label="Close menu">
                <i class="fas fa-times text-lg" aria-hidden="true"></i>
            </button>
        </div>
        <nav class="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto overflow-x-hidden overscroll-contain p-3 pb-10" aria-label="Main navigation">
            <?php if ($can_rider_dashboard): ?>
            <a data-rider-drawer-link href="rider_view.php?tab=dashboard" data-rider-tab="dashboard" class="rider-drawer-link <?= $activeTab === 'dashboard' ? 'rider-drawer-active' : '' ?>">
                <i class="fas fa-th-large w-5 shrink-0 text-center opacity-90"></i><span class="min-w-0 truncate">Dashboard</span>
            </a>
            <?php endif; ?>
            <?php if ($can_rider_queue): ?>
            <a data-rider-drawer-link href="rider_view.php?tab=queue" data-rider-tab="queue" class="rider-drawer-link <?= $activeTab === 'queue' ? 'rider-drawer-active' : '' ?>">
                <i class="fas fa-truck w-5 shrink-0 text-center opacity-90"></i><span class="min-w-0 truncate">Queue</span>
            </a>
            <?php endif; ?>
            <?php if ($can_rider_history): ?>
            <a data-rider-drawer-link href="rider_view.php?tab=history" data-rider-tab="history" class="rider-drawer-link <?= $activeTab === 'history' ? 'rider-drawer-active' : '' ?>">
                <i class="fas fa-clock-rotate-left w-5 shrink-0 text-center opacity-90"></i><span class="min-w-0 truncate">Delivered History</span>
            </a>
            <?php endif; ?>
            <?php if ($can_rider_history): ?>
            <a data-rider-drawer-link href="rider_view.php?tab=cancelled" data-rider-tab="cancelled" class="rider-drawer-link <?= $activeTab === 'cancelled' ? 'rider-drawer-active' : '' ?>">
                <i class="fas fa-ban w-5 shrink-0 text-center opacity-90"></i><span class="min-w-0 truncate">Cancelled Orders</span>
            </a>
            <?php endif; ?>
            <?php if (!empty($has_delivery_damage_reports)): ?>
            <a data-rider-drawer-link href="rider_view.php?tab=damage-reports" data-rider-tab="damage-reports" class="rider-drawer-link <?= $activeTab === 'damage-reports' ? 'rider-drawer-active' : '' ?>">
                <i class="fas fa-clipboard-list w-5 shrink-0 text-center opacity-90"></i><span class="min-w-0 truncate">My Damage Reports</span>
            </a>
            <?php endif; ?>
        </nav>
    </div>
