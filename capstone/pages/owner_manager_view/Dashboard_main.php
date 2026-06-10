<body class="bg-slate-50 text-slate-800 antialiased selection:bg-violet-200 selection:text-violet-900">
    <div class="dashboard-wrapper">
        <!-- Sidebar logic remains untouched via PHP rendering -->
        <?php
        require_once __DIR__ . '/../../includes/sidebar.php';
        renderSidebar($conn, ['base' => '', 'active' => 'dashboard', 'hide_mgr_notifications' => true]);
        ?>

        <main class="main-content index-dashboard flex-1 w-full" id="mainContent">
            <!-- Topbar (Mobile Menu) -->
            <div class="lg:hidden flex items-center p-4 mb-2">
                <button class="mobile-sidebar-toggle p-2.5 bg-white rounded-xl shadow-sm border border-slate-200 text-slate-600 hover:text-violet-600 hover:bg-violet-50 transition-colors" id="mobileSidebarToggle" aria-label="Toggle sidebar">
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>
            </div>

            <div class="p-4 lg:p-8 space-y-8 w-full">
                <!-- Welcome Banner -->
                <div class="relative z-[60] bg-gradient-to-br from-violet-600 via-violet-700 to-indigo-800 rounded-[2rem] p-8 md:p-10 shadow-2xl shadow-indigo-500/30 overflow-visible text-white flex flex-col md:flex-row justify-between items-start md:items-center gap-6 group animate-slide-up-3d animate-pulse-glow">
                    <div class="absolute inset-0 bg-[url('data:image/svg+xml,%3Csvg width=\'60\' height=\'60\' viewBox=\'0 0 60 60\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cg fill=\'none\' fill-rule=\'evenodd\'%3E%3Cg fill=\'%23ffffff\' fill-opacity=\'0.05\'%3E%3Cpath d=\'M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z\'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E')] opacity-50 pointer-events-none rounded-[2rem]"></div>
                    <div class="relative z-10 w-full flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
                        <div class="flex flex-col gap-2">
                            <h2 class="text-3xl md:text-4xl font-extrabold tracking-tight flex items-center gap-3 drop-shadow-sm">
                                <i data-lucide="sparkles" class="w-8 h-8 md:w-10 md:h-10 text-amber-300 animate-float"></i>
                                Welcome back, <?php echo isset($_SESSION['user_name']) ? htmlspecialchars($_SESSION['user_name']) : 'User'; ?>!
                            </h2>
                            <p class="text-indigo-100 font-medium text-lg max-w-xl">Here's a snapshot of your Villanueva Ice Plant today.</p>
                        </div>
                        
                        <div class="relative flex-none flex items-center gap-3">
                            <button id="notificationToggle" class="relative bg-white/10 hover:bg-white/20 backdrop-blur-md border border-white/20 w-14 h-14 rounded-2xl flex items-center justify-center text-white transition-all shadow-lg hover:scale-105" aria-label="Open notifications">
                                <i data-lucide="bell" class="w-6 h-6"></i>
                                <span id="notificationBadge" class="absolute -top-2 -right-2 bg-rose-500 text-white text-[11px] font-bold w-6 h-6 rounded-full flex justify-center items-center border-2 border-indigo-700 shadow-xl hidden">0</span>
                            </button>

                            <?php
                            $userProfilePic = '';
                            if (isset($_SESSION['user_id']) && isset($conn)) {
                                $stmtPfp = $conn->prepare("SELECT profile_picture FROM User_Profile WHERE User_ID = ? LIMIT 1");
                                $stmtPfp->execute([$_SESSION['user_id']]);
                                $pfpRow = $stmtPfp->fetch(PDO::FETCH_ASSOC);
                                if ($pfpRow && !empty($pfpRow['profile_picture'])) {
                                    $pfpPath = dirname(__DIR__, 2) . '/' . $pfpRow['profile_picture'];
                                    if (file_exists($pfpPath)) {
                                        $userProfilePic = $pfpRow['profile_picture'] . '?v=' . time();
                                    }
                                }
                            }
                            $userNameForInitials = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'User';
                            $userInitial = strtoupper(substr($userNameForInitials, 0, 1));
                            ?>
                            <a href="pages/profile_settings.php" class="relative bg-white/10 hover:bg-white/20 backdrop-blur-md border border-white/20 w-14 h-14 rounded-2xl flex items-center justify-center text-white transition-all shadow-lg hover:scale-105 overflow-hidden group" title="Profile Settings" aria-label="Profile Settings">
                                <?php if ($userProfilePic): ?>
                                    <img src="<?php echo htmlspecialchars($userProfilePic); ?>" alt="Profile" class="w-full h-full object-cover transition-transform group-hover:scale-110">
                                <?php else: ?>
                                    <span class="text-2xl font-black drop-shadow-md"><?php echo htmlspecialchars($userInitial); ?></span>
                                <?php endif; ?>
                            </a>

                            <!-- Notification Dropdown -->
                            <div id="notificationDropdown" class="hidden absolute right-0 top-[calc(100%+16px)] w-[340px] md:w-[400px] bg-white rounded-3xl shadow-2xl shadow-slate-900/10 border border-slate-100 z-50 overflow-hidden flex-col origin-top-right transition-all">
                                <div class="px-6 py-4 border-b border-slate-100 bg-slate-50/80 backdrop-blur-sm flex justify-between items-center">
                                    <h3 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                                        <div class="bg-violet-100 p-1.5 rounded-lg text-violet-600"><i data-lucide="bell-dot" class="w-4 h-4"></i></div>
                                        System Notifications
                                    </h3>
                                </div>
                                <div id="notificationList" class="max-h-[350px] overflow-y-auto custom-scrollbar flex flex-col">
                                    <div class="p-8 text-center text-slate-400 flex flex-col items-center gap-3">
                                        <i data-lucide="loader-2" class="w-7 h-7 animate-spin text-violet-500"></i>
                                        <span class="text-sm font-medium">Loading notifications...</span>
                                    </div>
                                </div>
                                <div class="p-3 text-center border-t border-slate-100 bg-slate-50/50 hover:bg-slate-50 transition-colors">
                                    <a href="pages/activity_logs.php" class="text-violet-600 hover:text-violet-700 text-sm font-bold flex items-center justify-center gap-1.5 group">
                                        View All Logs <i data-lucide="arrow-right" class="w-4 h-4 group-hover:translate-x-1 transition-transform"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Statistics Cards grid (5 columns) -->
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-6">

                    <!-- Total Sales -->
                    <div class="tilt-card relative bg-white rounded-3xl p-6 shadow-sm border border-slate-200/60 hover:shadow-xl hover:shadow-violet-500/20 hover:border-violet-300 transition-all duration-500 group animate-slide-up-3d delay-100 glass-panel overflow-hidden cursor-pointer"
                         data-bar-color="#7132f5" data-bar-pct="<?php echo min(100,round(($totalSales/max(1,$lastMonthSales))*60)); ?>">
                        <div class="tilt-shine"></div>
                        <div class="stat-shimmer"></div>
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <p class="text-slate-500 font-bold text-[11px] tracking-widest uppercase mb-1.5">Total Sales (This Month)</p>
                                <h3 class="text-slate-800 text-2xl font-black tracking-tight">
                                    <span class="stat-counter" data-prefix="₱" data-value="<?php echo $totalSales; ?>" data-decimals="2">₱0.00</span>
                                </h3>
                            </div>
                            <div class="bg-violet-100 text-violet-600 p-3.5 rounded-[1.25rem] group-hover:scale-110 group-hover:-rotate-6 transition-transform duration-300">
                                <i data-lucide="banknote" class="w-6 h-6"></i>
                            </div>
                        </div>
                        <div class="stat-bar-track"><div class="stat-bar-fill" style="background:#7132f5"></div></div>
                        <div class="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold <?php echo $salesChange >= 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'; ?>">
                            <i data-lucide="<?php echo $salesChange >= 0 ? 'trending-up' : 'trending-down'; ?>" class="w-4 h-4"></i>
                            <?php echo abs($salesChange); ?>% vs last month
                        </div>
                    </div>

                    <!-- Today Sales -->
                    <div class="tilt-card relative bg-white rounded-3xl p-6 shadow-sm border border-slate-200/60 hover:shadow-xl hover:shadow-emerald-500/20 hover:border-emerald-300 transition-all duration-500 group animate-slide-up-3d delay-200 glass-panel overflow-hidden cursor-pointer"
                         data-bar-color="#10b981" data-bar-pct="72">
                        <div class="tilt-shine"></div>
                        <div class="stat-shimmer"></div>
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <p class="text-slate-500 font-bold text-[11px] tracking-widest uppercase mb-1.5">Today's Sales</p>
                                <h3 class="text-slate-800 text-2xl font-black tracking-tight">
                                    <span class="stat-counter" data-prefix="₱" data-value="<?php echo $todaysSales; ?>" data-decimals="2">₱0.00</span>
                                </h3>
                            </div>
                            <div class="bg-emerald-100 text-emerald-600 p-3.5 rounded-[1.25rem] group-hover:scale-110 group-hover:rotate-6 transition-transform duration-300">
                                <i data-lucide="receipt-text" class="w-6 h-6"></i>
                            </div>
                        </div>
                        <div class="stat-bar-track"><div class="stat-bar-fill" style="background:#10b981"></div></div>
                        <div class="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-slate-50 text-slate-600 border border-slate-100">
                            <i data-lucide="calendar-check" class="w-4 h-4"></i>
                            Sales for today
                        </div>
                    </div>

                    <!-- Low Stocks Modal Trigger -->
                    <a href="#" id="lowStocksCard" class="tilt-card block relative bg-white rounded-3xl p-6 shadow-sm border border-slate-200/60 hover:shadow-xl hover:shadow-amber-500/20 hover:border-amber-300 transition-all duration-500 group outline-none focus-visible:ring-4 focus-visible:ring-amber-500/20 animate-slide-up-3d delay-300 glass-panel overflow-hidden"
                       data-bar-color="#f59e0b" data-bar-pct="<?php echo min(100,$lowStocksCount*8); ?>">
                        <div class="tilt-shine"></div>
                        <div class="stat-shimmer"></div>
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <p class="text-slate-500 font-bold text-[11px] tracking-widest uppercase mb-1.5">Low Stocks</p>
                                <h3 class="text-amber-500 text-2xl font-black tracking-tight">
                                    <span class="stat-counter" data-prefix="" data-value="<?php echo $lowStocksCount; ?>" data-decimals="0"><?php echo $lowStocksCount; ?></span>
                                </h3>
                            </div>
                            <div class="bg-amber-100 text-amber-600 p-3.5 rounded-[1.25rem] group-hover:scale-110 group-hover:rotate-6 transition-transform duration-300">
                                <i data-lucide="package-minus" class="w-6 h-6"></i>
                            </div>
                        </div>
                        <div class="stat-bar-track"><div class="stat-bar-fill" style="background:#f59e0b"></div></div>
                        <div class="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-amber-50 text-amber-700 group-hover:bg-amber-100 transition-colors">
                            <i data-lucide="search" class="w-4 h-4"></i>
                            View alerts
                        </div>
                    </a>

                    <!-- Accounts Receivable -->
                    <div class="tilt-card relative bg-white rounded-3xl p-6 shadow-sm border border-slate-200/60 hover:shadow-xl hover:shadow-sky-500/20 hover:border-sky-300 transition-all duration-500 group animate-slide-up-3d delay-400 glass-panel overflow-hidden cursor-pointer"
                         data-bar-color="#0ea5e9" data-bar-pct="<?php echo $arCount > 0 ? min(100,$arCount*10) : 20; ?>">
                        <div class="tilt-shine"></div>
                        <div class="stat-shimmer"></div>
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <p class="text-slate-500 font-bold text-[11px] tracking-widest uppercase mb-1.5">Accounts Receivable</p>
                                <h3 class="text-slate-800 text-2xl font-black tracking-tight">
                                    <span class="stat-counter" data-prefix="₱" data-value="<?php echo $arTotal; ?>" data-decimals="2">₱0.00</span>
                                </h3>
                            </div>
                            <div class="bg-sky-100 text-sky-600 p-3.5 rounded-[1.25rem] group-hover:scale-110 group-hover:-rotate-6 transition-transform duration-300">
                                <i data-lucide="wallet" class="w-6 h-6"></i>
                            </div>
                        </div>
                        <div class="stat-bar-track"><div class="stat-bar-fill" style="background:#0ea5e9"></div></div>
                        <div class="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold <?php echo $arCount > 0 ? 'bg-amber-50 text-amber-700' : 'bg-slate-50 text-slate-600 border border-slate-100'; ?>">
                            <i data-lucide="triangle-alert" class="w-4 h-4"></i>
                            <?php echo $arCount; ?> pending
                        </div>
                    </div>

                    <!-- Pending Orders -->
                    <div class="tilt-card relative bg-white rounded-3xl p-6 shadow-sm border border-slate-200/60 hover:shadow-xl hover:shadow-pink-500/20 hover:border-pink-300 transition-all duration-500 group animate-slide-up-3d delay-500 glass-panel overflow-hidden cursor-pointer"
                         data-bar-color="#ec4899" data-bar-pct="<?php echo min(100,$pendingOrders*12); ?>">
                        <div class="tilt-shine"></div>
                        <div class="stat-shimmer"></div>
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <p class="text-slate-500 font-bold text-[11px] tracking-widest uppercase mb-1.5">Pending Orders</p>
                                <h3 class="text-slate-800 text-2xl font-black tracking-tight">
                                    <span class="stat-counter" data-prefix="" data-value="<?php echo $pendingOrders; ?>" data-decimals="0"><?php echo $pendingOrders; ?></span>
                                </h3>
                            </div>
                            <div class="bg-pink-100 text-pink-600 p-3.5 rounded-[1.25rem] group-hover:scale-110 group-hover:rotate-6 transition-transform duration-300">
                                <i data-lucide="shopping-cart" class="w-6 h-6"></i>
                            </div>
                        </div>
                        <div class="stat-bar-track"><div class="stat-bar-fill" style="background:#ec4899"></div></div>
                        <div class="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold bg-pink-50 text-pink-700">
                            <i data-lucide="clock" class="w-4 h-4"></i>
                            awaiting prep
                        </div>
                    </div>
                </div>

                <!-- Deadline Notifications Banner -->
                <?php
                $hasDeadlineAlerts = $deadlineOverdueOrders > 0 || $deadlineDueTodayOrders > 0 || $deadlineDueWeekOrders > 0
                    || $deadlineOverdueDeliveries > 0 || $deadlineDueTodayDeliveries > 0 || $deadlineDueWeekDeliveries > 0;
                ?>
                <?php if ($hasDeadlineAlerts): ?>
                <div class="mb-6 grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <?php if ($deadlineOverdueOrders > 0 || $deadlineOverdueDeliveries > 0): ?>
                    <div class="rounded-2xl border border-red-200 bg-red-50/90 px-4 py-4 flex items-center gap-3 shadow-sm animate-slide-up-3d">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-red-100 text-red-600">
                            <i data-lucide="alert-triangle" class="w-5 h-5"></i>
                        </span>
                        <div>
                            <p class="text-sm font-black text-red-800">Overdue</p>
                            <p class="text-xs font-semibold text-red-700/80">
                                <?php
                                $parts = [];
                                if ($deadlineOverdueOrders > 0) $parts[] = '<span class="count-up" data-target="' . $deadlineOverdueOrders . '">0</span> order' . ($deadlineOverdueOrders === 1 ? '' : 's');
                                if ($deadlineOverdueDeliveries > 0) $parts[] = '<span class="count-up" data-target="' . $deadlineOverdueDeliveries . '">0</span> deliver' . ($deadlineOverdueDeliveries === 1 ? 'y' : 'ies');
                                echo implode(' & ', $parts) . ' past deadline';
                                ?>
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($deadlineDueTodayOrders > 0 || $deadlineDueTodayDeliveries > 0): ?>
                    <div class="rounded-2xl border border-amber-200 bg-amber-50/90 px-4 py-4 flex items-center gap-3 shadow-sm animate-slide-up-3d">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-600">
                            <i data-lucide="clock" class="w-5 h-5"></i>
                        </span>
                        <div>
                            <p class="text-sm font-black text-amber-800">Due Today</p>
                            <p class="text-xs font-semibold text-amber-700/80">
                                <?php
                                $parts = [];
                                if ($deadlineDueTodayOrders > 0) $parts[] = '<span class="count-up" data-target="' . $deadlineDueTodayOrders . '">0</span> order' . ($deadlineDueTodayOrders === 1 ? '' : 's');
                                if ($deadlineDueTodayDeliveries > 0) $parts[] = '<span class="count-up" data-target="' . $deadlineDueTodayDeliveries . '">0</span> deliver' . ($deadlineDueTodayDeliveries === 1 ? 'y' : 'ies');
                                echo implode(' & ', $parts) . ' due today';
                                ?>
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($deadlineDueWeekOrders > 0 || $deadlineDueWeekDeliveries > 0): ?>
                    <div class="rounded-2xl border border-blue-200 bg-blue-50/90 px-4 py-4 flex items-center gap-3 shadow-sm animate-slide-up-3d">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-100 text-blue-600">
                            <i data-lucide="calendar" class="w-5 h-5"></i>
                        </span>
                        <div>
                            <p class="text-sm font-black text-blue-800">Due This Week</p>
                            <p class="text-xs font-semibold text-blue-700/80">
                                <?php
                                $parts = [];
                                if ($deadlineDueWeekOrders > 0) $parts[] = '<span class="count-up" data-target="' . $deadlineDueWeekOrders . '">0</span> order' . ($deadlineDueWeekOrders === 1 ? '' : 's');
                                if ($deadlineDueWeekDeliveries > 0) $parts[] = '<span class="count-up" data-target="' . $deadlineDueWeekDeliveries . '">0</span> deliver' . ($deadlineDueWeekDeliveries === 1 ? 'y' : 'ies');
                                echo implode(' & ', $parts) . ' upcoming';
                                ?>
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($showDdrDashboardBanner && $pendingDeliveryDamageCount > 0): ?>
                <div class="mb-6 rounded-2xl border border-amber-200 bg-amber-50/90 px-4 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 shadow-sm animate-slide-up-3d">
                    <div class="flex items-center gap-3 text-amber-900">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-100 text-amber-700">
                            <i data-lucide="clipboard-list" class="w-5 h-5"></i>
                        </span>
                        <div>
                            <p class="text-sm font-black"><span class="count-up" data-target="<?php echo (int)$pendingDeliveryDamageCount; ?>">0</span> delivery damage report<?php echo $pendingDeliveryDamageCount === 1 ? '' : 's'; ?> awaiting review</p>
                            <p class="text-xs font-semibold text-amber-800/80">Approve or reject to adjust inventory.</p>
                        </div>
                    </div>
                    <a href="<?php echo htmlspecialchars(deliveryDamageQueueHrefForUser($conn, $ddrRoleId)); ?>" class="inline-flex items-center justify-center gap-2 rounded-xl bg-amber-600 px-4 py-2.5 text-sm font-black text-white hover:bg-amber-700 transition-colors shrink-0">
                        Open queue <i data-lucide="arrow-right" class="w-4 h-4"></i>
                    </a>
                </div>
                <?php endif; ?>

                <!-- ═══ Weather + Quick Insight Row ═══ -->
                <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

                    <!-- Weather Widget (2/3 width) -->
                    <div id="weatherWidget" class="xl:col-span-2 relative bg-white rounded-3xl p-6 shadow-sm border border-slate-200/60 glass-panel animate-slide-up-3d overflow-hidden hover:shadow-xl hover:-translate-y-1 transition-all duration-500">
                        <!-- Decorative blobs -->
                        <div class="absolute -top-10 -right-10 w-48 h-48 bg-sky-50 rounded-full blur-3xl pointer-events-none"></div>
                        <div class="absolute -bottom-10 -left-10 w-40 h-40 bg-indigo-50 rounded-full blur-2xl pointer-events-none"></div>

                        <div class="relative z-10 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                            <!-- Main info -->
                            <div class="flex items-center gap-5">
                                <div id="weatherIconWrap" class="text-6xl sm:text-7xl leading-none select-none" title="Weather condition">🌤️</div>
                                <div>
                                    <p id="weatherTemp" class="text-5xl sm:text-6xl font-black text-slate-800 leading-none tracking-tight">—°</p>
                                    <p id="weatherDesc" class="text-slate-500 font-bold text-sm mt-1 capitalize">Loading weather…</p>
                                    <p id="weatherLocation" class="text-slate-400 text-xs font-semibold mt-0.5 flex items-center gap-1">
                                        <i data-lucide="map-pin" class="w-3 h-3 inline-block"></i> Detecting location…
                                    </p>
                                </div>
                            </div>
                            <!-- Detail pills -->
                            <div class="flex flex-wrap gap-3">
                                <div class="bg-slate-50 border border-slate-100 rounded-2xl px-4 py-2.5 text-center min-w-[72px]">
                                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Humidity</p>
                                    <p id="weatherHumidity" class="text-lg font-black text-slate-800">—</p>
                                </div>
                                <div class="bg-slate-50 border border-slate-100 rounded-2xl px-4 py-2.5 text-center min-w-[72px]">
                                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Wind</p>
                                    <p id="weatherWind" class="text-lg font-black text-slate-800">—</p>
                                </div>
                                <div class="bg-slate-50 border border-slate-100 rounded-2xl px-4 py-2.5 text-center min-w-[72px]">
                                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Feels Like</p>
                                    <p id="weatherFeels" class="text-lg font-black text-slate-800">—</p>
                                </div>
                            </div>
                        </div>
                        <!-- 5-day forecast strip -->
                        <div id="weatherForecast" class="relative z-10 mt-5 flex gap-3 overflow-x-auto pb-1 scrollbar-hide">
                            <!-- populated by JS -->
                        </div>
                        <p id="weatherUpdatedAt" class="relative z-10 text-slate-400 text-[10px] font-bold mt-3">Powered by Open-Meteo · free &amp; accurate</p>
                    </div>

                    <!-- Overdue Customers List (1/3) -->
                    <div class="bg-white rounded-3xl p-5 shadow-sm border border-slate-200/60 glass-panel animate-slide-up-3d delay-200 hover:shadow-xl hover:-translate-y-1 transition-all duration-500 flex flex-col gap-3">
                        <!-- Header -->
                        <div class="flex items-center justify-between shrink-0">
                            <h3 class="text-base font-black text-slate-800 flex items-center gap-2">
                                <div class="bg-rose-100 p-2 rounded-xl text-rose-600"><i data-lucide="wallet" class="w-4 h-4"></i></div>
                                Overdue Balances
                            </h3>
                            <?php if ($customersWithOverdue > 0): ?>
                            <span class="text-[11px] font-black text-rose-700 bg-rose-100 px-2.5 py-1 rounded-xl tracking-widest uppercase"><?php echo $customersWithOverdue; ?> customers</span>
                            <?php endif; ?>
                        </div>

                        <!-- Total pill -->
                        <div class="flex items-center justify-between bg-rose-50 border border-rose-100 rounded-2xl px-4 py-2.5 shrink-0">
                            <span class="text-[11px] font-black text-rose-500 uppercase tracking-widest">Total Overdue</span>
                            <span class="text-sm font-black text-rose-700"><?php echo formatPeso($overdueARValue); ?></span>
                        </div>

                        <!-- Customer List -->
                        <div class="flex-1 overflow-y-auto custom-scrollbar flex flex-col gap-2" style="max-height:200px;">
                            <?php if (empty($overdueCustomersList)): ?>
                                <div class="flex flex-col items-center justify-center py-8 text-center text-slate-400">
                                    <div class="bg-emerald-50 p-3 rounded-full mb-2">
                                        <i data-lucide="check-circle-2" class="w-7 h-7 text-emerald-500"></i>
                                    </div>
                                    <p class="text-sm font-bold text-slate-600">No overdue balances</p>
                                    <p class="text-xs font-medium mt-0.5">All customers are up to date!</p>
                                </div>
                            <?php else: ?>
                                <?php 
                                $avatarBgs = ['bg-violet-600','bg-rose-500','bg-amber-500','bg-sky-500','bg-emerald-600','bg-pink-600','bg-indigo-600'];
                                foreach ($overdueCustomersList as $idx => $cust):
                                    $nameParts = explode(' ', trim($cust['customer_name']));
                                    $initials = '';
                                    foreach ($nameParts as $p) { if ($p) $initials .= strtoupper($p[0]); if (strlen($initials) >= 2) break; }
                                    if (!$initials) $initials = '?';
                                    $bg = $avatarBgs[$idx % count($avatarBgs)];
                                    $daysOverdue = max(0, floor((strtotime('today') - strtotime($cust['earliest_due'])) / 86400));
                                ?>
                                <div class="flex items-center gap-3 p-3 bg-rose-50 border border-rose-100 rounded-2xl hover:bg-rose-100 transition-colors">
                                    <div class="<?php echo $bg; ?> w-9 h-9 rounded-xl flex items-center justify-center font-black text-white text-xs shrink-0"><?php echo htmlspecialchars($initials); ?></div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-bold text-slate-800 truncate"><?php echo htmlspecialchars($cust['customer_name']); ?></p>
                                        <p class="text-[11px] font-medium text-rose-500 flex items-center gap-1 mt-0.5">
                                            <i data-lucide="clock" class="w-3 h-3"></i>
                                            <?php echo $daysOverdue; ?> day<?php echo $daysOverdue !== 1 ? 's' : ''; ?> overdue
                                        </p>
                                    </div>
                                    <span class="text-[11px] font-black text-rose-700 bg-white border border-rose-200 px-2 py-1 rounded-lg shrink-0"><?php echo formatPeso($cust['total_overdue']); ?></span>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <!-- end weather row -->

                <!-- Charts Section Row 1 -->
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                    <!-- Sales Trend -->
                    <div class="bg-white rounded-3xl p-6 md:p-8 shadow-sm border border-slate-200/60 glass-panel animate-slide-up-3d delay-600 hover:shadow-xl hover:-translate-y-1 transition-all duration-500">
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-4 gap-4">
                            <div>
                                <h3 class="text-lg font-black text-slate-800 flex items-center gap-3">
                                    <div class="bg-violet-100 p-2.5 rounded-xl text-violet-600"><i data-lucide="activity" class="w-5 h-5"></i></div>
                                    Sales Trend
                                </h3>
                                <p class="text-xs text-slate-400 font-medium mt-1 pl-11">Solid = current period &middot; Dashed = last period</p>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <div class="flex gap-1.5 bg-slate-100 p-1.5 rounded-2xl">
                                    <button id="trendDayBtn" class="trend-btn px-4 py-2 rounded-xl text-xs font-bold border-2 border-violet-600 text-violet-700 bg-white shadow-sm transition-all focus:outline-none">Daily</button>
                                    <button id="trend7Btn" class="trend-btn px-4 py-2 rounded-xl text-xs font-bold border-2 border-transparent text-slate-500 hover:text-slate-700 transition-all focus:outline-none">7 Days</button>
                                    <button id="trendMonthBtn" class="trend-btn px-4 py-2 rounded-xl text-xs font-bold border-2 border-transparent text-slate-500 hover:text-slate-700 transition-all focus:outline-none">Monthly</button>
                                </div>
                                <div class="chart-actions">
                                    <button class="chart-action-btn" title="Expand" onclick="expandChart('apexSalesTrend','Sales Trend')"><i data-lucide="maximize-2" class="w-4 h-4"></i></button>
                                    <button class="chart-action-btn" title="Download PNG" onclick="downloadChart('apexSalesTrend','sales-trend')"><i data-lucide="download" class="w-4 h-4"></i></button>
                                </div>
                            </div>
                        </div>
                        <div id="apexSalesTrend" class="min-h-[300px]"></div>
                    </div>

                    <!-- Top Selling Products -->
                    <div class="bg-white rounded-3xl p-6 md:p-8 shadow-sm border border-slate-200/60 glass-panel animate-slide-up-3d delay-700 hover:shadow-xl hover:-translate-y-1 transition-all duration-500">
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
                            <h3 class="text-lg font-black text-slate-800 flex items-center gap-3">
                                <div class="bg-emerald-100 p-2.5 rounded-xl text-emerald-600"><i data-lucide="bar-chart-3" class="w-5 h-5"></i></div>
                                Top Selling Products <span class="text-sm font-bold text-slate-400 bg-slate-100 px-2.5 py-1 rounded-lg ml-2">30d</span>
                            </h3>
                            <div class="chart-actions">
                                <button class="chart-action-btn" title="Expand" onclick="expandChart('apexTopProducts','Top Selling Products')"><i data-lucide="maximize-2" class="w-4 h-4"></i></button>
                                <button class="chart-action-btn" title="Download PNG" onclick="downloadChart('apexTopProducts','top-products')"><i data-lucide="download" class="w-4 h-4"></i></button>
                            </div>
                        </div>
                        <div id="apexTopProducts" class="min-h-[300px]"></div>
                    </div>
                </div>

                <!-- Sales Pie Chart Row -->
                <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                    <!-- Sales by Category Pie -->
                    <div class="bg-white rounded-3xl p-6 md:p-8 shadow-sm border border-slate-200/60 glass-panel animate-slide-up-3d hover:shadow-xl hover:-translate-y-1 transition-all duration-500">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-lg font-black text-slate-800 flex items-center gap-3">
                                <div class="bg-rose-100 p-2.5 rounded-xl text-rose-600"><i data-lucide="trending-down" class="w-5 h-5"></i></div>
                                Least Selling <span class="text-xs font-bold text-slate-400 bg-slate-100 px-2 py-1 rounded-lg ml-1">30d</span>
                            </h3>
                            <div class="chart-actions">
                                <button class="chart-action-btn" title="Expand" onclick="expandChart('apexLeastSelling','Least Selling')"><i data-lucide="maximize-2" class="w-4 h-4"></i></button>
                                <button class="chart-action-btn" title="Download PNG" onclick="downloadChart('apexLeastSelling','least-selling')"><i data-lucide="download" class="w-4 h-4"></i></button>
                            </div>
                        </div>
                        <div id="apexLeastSelling" class="min-h-[260px]"></div>
                    </div>
                    <!-- Revenue Heatmap by Weekday -->
                    <div class="bg-white rounded-3xl p-6 md:p-8 shadow-sm border border-slate-200/60 glass-panel animate-slide-up-3d hover:shadow-xl hover:-translate-y-1 transition-all duration-500 xl:col-span-2">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-black text-slate-800 flex items-center gap-3">
                                <div class="bg-amber-100 p-2.5 rounded-xl text-amber-600"><i data-lucide="grid-3x3" class="w-5 h-5"></i></div>
                                Revenue Pattern <span class="text-xs font-bold text-slate-400 bg-slate-100 px-2 py-1 rounded-lg ml-1">by Weekday</span>
                            </h3>
                            <div class="flex gap-2">
                                <button class="chart-action-btn" title="Expand" onclick="expandChart('apexRevenueHeatmap','Revenue Pattern')"><i data-lucide="maximize-2" class="w-4 h-4"></i></button>
                                <button class="chart-action-btn" title="Download" onclick="downloadChart('apexRevenueHeatmap','revenue-heatmap')"><i data-lucide="download" class="w-4 h-4"></i></button>
                            </div>
                        </div>
                        <!-- KPI Row -->
                        <div class="grid grid-cols-3 gap-3 mb-5">
                            <div class="bg-violet-50 rounded-2xl p-3 text-center border border-violet-100">
                                <p class="text-[10px] font-black text-violet-500 uppercase tracking-widest mb-1">Avg Daily</p>
                                <p id="kpiAvgDaily" class="text-lg font-black text-violet-700">—</p>
                            </div>
                            <div class="bg-emerald-50 rounded-2xl p-3 text-center border border-emerald-100">
                                <p class="text-[10px] font-black text-emerald-500 uppercase tracking-widest mb-1">Best Day</p>
                                <p id="kpiBestDay" class="text-lg font-black text-emerald-700">—</p>
                            </div>
                            <div class="bg-rose-50 rounded-2xl p-3 text-center border border-rose-100">
                                <p class="text-[10px] font-black text-rose-500 uppercase tracking-widest mb-1">Slowest Day</p>
                                <p id="kpiSlowestDay" class="text-lg font-black text-rose-600">—</p>
                            </div>
                        </div>
                        <div id="apexRevenueHeatmap" class="min-h-[200px]"></div>
                    </div>
                </div>

                <!-- Charts Section Row 2 -->
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                    <!-- Top Customers -->
                    <div class="bg-white rounded-3xl p-6 md:p-8 shadow-sm border border-slate-200/60 glass-panel animate-slide-up-3d delay-200 hover:shadow-xl hover:-translate-y-1 transition-all duration-500">
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
                            <h3 class="text-lg font-black text-slate-800 flex items-center gap-3">
                                <div class="bg-sky-100 p-2.5 rounded-xl text-sky-600"><i data-lucide="users" class="w-5 h-5"></i></div>
                                Top Customers <span class="text-sm font-bold text-slate-400 bg-slate-100 px-2.5 py-1 rounded-lg ml-2">30d</span>
                            </h3>
                            <div class="chart-actions">
                                <button class="chart-action-btn" title="Expand" onclick="expandChart('apexTopCustomers','Top Customers')"><i data-lucide="maximize-2" class="w-4 h-4"></i></button>
                                <button class="chart-action-btn" title="Download PNG" onclick="downloadChart('apexTopCustomers','top-customers')"><i data-lucide="download" class="w-4 h-4"></i></button>
                            </div>
                        </div>
                        <div id="apexTopCustomers" class="min-h-[320px]"></div>
                    </div>

                    <!-- Damage Goods Analytics -->
                    <div class="bg-white rounded-3xl p-6 md:p-8 shadow-sm border border-slate-200/60 group glass-panel animate-slide-up-3d delay-300 hover:shadow-xl hover:-translate-y-1 transition-all duration-500 flex flex-col justify-between">
                        <div>
                            <div class="flex justify-between items-center mb-8">
                                <h3 class="text-lg font-black text-slate-800 flex items-center gap-3">
                                    <div class="bg-rose-100 p-2.5 rounded-xl text-rose-600"><i data-lucide="heart-crack" class="w-5 h-5"></i></div>
                                    Damage Analytics
                                </h3>
                                <a href="pages/damage_goods.php" class="text-violet-600 text-sm font-bold flex items-center gap-1.5 hover:gap-2.5 transition-all outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-violet-600 rounded">
                                    View Log <i data-lucide="arrow-right" class="w-4 h-4"></i>
                                </a>
                            </div>
                            <div class="grid grid-cols-2 gap-4 md:gap-6 mb-6 mt-4">
                                <div class="bg-slate-50 p-5 rounded-2xl border border-slate-100/60">
                                    <p class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-2.5">Damaged This Mth</p>
                                    <p class="text-3xl font-black text-slate-800"><?php echo number_format($damageThisMonth); ?></p>
                                    <?php if ($damageLastMonth > 0): ?>
                                        <div class="text-[11px] font-bold mt-3 <?php echo $damageChange > 0 ? 'text-rose-600' : 'text-emerald-600'; ?> flex items-center gap-1.5">
                                            <i data-lucide="<?php echo $damageChange > 0 ? 'trending-up' : 'trending-down'; ?>" class="w-3.5 h-3.5"></i>
                                            <?php echo abs($damageChange); ?>% vs last
                                        </div>
                                    <?php else: ?>
                                        <p class="text-[11px] font-bold text-slate-400 mt-3 flex items-center gap-1.5"><i data-lucide="info" class="w-3.5 h-3.5"></i> No prior data</p>
                                    <?php endif; ?>
                                </div>
                                <div class="bg-rose-50 p-5 rounded-2xl border border-rose-100">
                                    <p class="text-xs font-bold text-rose-600/80 uppercase tracking-widest mb-2.5">Loss (30 Days)</p>
                                    <p class="text-3xl font-black text-rose-600"><?php echo formatPeso($damageLoss); ?></p>
                                    <p class="text-[11px] font-bold text-rose-500/70 mt-3 flex items-center gap-1.5"><i data-lucide="calculator" class="w-3.5 h-3.5"></i> Retail value equivalent</p>
                                </div>
                            </div>
                        </div>
                        <div id="apexDamageTrend" class="min-h-[160px] -mx-2"></div>
                    </div>
                </div>

                <!-- Delivery Calendar Section — compact -->
                <div class="bg-white rounded-3xl shadow-sm border border-slate-200/60 overflow-hidden flex flex-col lg:flex-row mt-4 glass-panel animate-slide-up-3d delay-400 hover:shadow-xl hover:-translate-y-1 transition-all duration-500" style="max-height:520px;">
                    <!-- Left Agenda -->
                    <div class="p-5 lg:w-1/2 lg:border-r border-slate-100 flex flex-col bg-slate-50/30">
                        <div class="flex justify-between items-center mb-4 shrink-0">
                            <h3 class="text-base font-black text-slate-800 flex items-center gap-2">
                                <div class="bg-indigo-100 p-2.5 rounded-xl text-indigo-600"><i data-lucide="truck" class="w-5 h-5"></i></div>
                                All Deliveries
                            </h3>
                            <a href="pages/delivery.php" class="text-violet-600 text-sm font-bold hover:underline">View All</a>
                        </div>
                        <div class="overflow-y-auto custom-scrollbar pr-3 flex flex-col gap-2" style="max-height:300px;">
                            <?php if (empty($upcomingDeliveries) && empty($overdueDeliveries) && empty($scheduledOrders)): ?>
                                <div class="text-center py-16 text-slate-400 flex flex-col items-center">
                                    <div class="bg-emerald-50 p-4 rounded-full mb-4">
                                        <i data-lucide="check-circle-2" class="w-10 h-10 text-emerald-500"></i>
                                    </div>
                                    <p class="font-bold text-slate-600 text-lg">No pending deliveries</p>
                                    <p class="text-sm font-medium mt-1">All deliveries have been completed or none are scheduled.</p>
                                </div>
                            <?php else: ?>
                                <?php
                                $avatarColors = ['bg-violet-600', 'bg-indigo-600', 'bg-emerald-600', 'bg-amber-600', 'bg-pink-600', 'bg-sky-600'];
                                $itemIndex = 0;
                                // Show overdue deliveries first with warning
                                foreach ($overdueDeliveries as $del):
                                    $initials = '';
                                    if (!empty($del['customer_name'])) {
                                        $nameParts = explode(' ', $del['customer_name']);
                                        foreach ($nameParts as $part) {
                                            if (!empty($part)) $initials .= strtoupper($part[0]);
                                            if (strlen($initials) >= 2) break;
                                        }
                                    }
                                    if (empty($initials)) $initials = 'D';
                                    $bgColor = $avatarColors[$itemIndex % count($avatarColors)];
                                    $itemIndex++;
                                    $scheduleDate = !empty($del['schedule_date']) ? $del['schedule_date'] : date('Y-m-d');
                                    $daysOverdue = floor((strtotime('today') - strtotime($scheduleDate)) / 86400);
                                    if ($daysOverdue < 0) $daysOverdue = 0;
                                ?>
                                <div class="flex items-center gap-3 p-3 rounded-2xl bg-white border border-rose-200 hover:border-rose-300 hover:shadow-md transition-all group">
                                    <div class="<?php echo $bgColor; ?> w-9 h-9 rounded-xl flex items-center justify-center font-bold text-white text-xs shrink-0 shadow-sm transition-transform group-hover:scale-105 group-hover:rotate-3"><?php echo htmlspecialchars($initials); ?></div>
                                    <div class="flex-1 min-w-0">
                                        <p class="font-bold text-slate-800 truncate"><?php echo htmlspecialchars($del['customer_name'] ?? 'Unknown'); ?></p>
                                        <p class="text-xs text-slate-500 font-medium flex items-center gap-1.5 mt-1.5">
                                            <i data-lucide="alert-triangle" class="w-3.5 h-3.5 text-rose-500"></i>
                                            <span class="text-rose-600 font-semibold"><?php echo date('M j, Y', strtotime($scheduleDate)); ?> (<?php echo $daysOverdue; ?> day<?php echo $daysOverdue > 1 ? 's' : ''; ?> overdue)</span>
                                            <?php if (!empty($del['delivery_address'])): ?>
                                                <span class="truncate ml-1.5 pl-1.5 border-l border-slate-300" title="<?php echo htmlspecialchars($del['delivery_address']); ?>"><?php echo htmlspecialchars($del['delivery_address']); ?></span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <span class="shrink-0 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest bg-rose-100 text-rose-700">
                                        Missed delivery
                                    </span>
                                </div>
                                <?php endforeach; ?>
                                
                                <!-- Upcoming deliveries (today and future) -->
                                <?php foreach ($upcomingDeliveries as $del):
                                    $initials = '';
                                    if (!empty($del['customer_name'])) {
                                        $nameParts = explode(' ', $del['customer_name']);
                                        foreach ($nameParts as $part) {
                                            if (!empty($part)) $initials .= strtoupper($part[0]);
                                            if (strlen($initials) >= 2) break;
                                        }
                                    }
                                    if (empty($initials)) $initials = 'D';
                                    $bgColor = $avatarColors[$itemIndex % count($avatarColors)];
                                    $itemIndex++;
                                    
                                    $statusClass = 'bg-violet-100 text-violet-700';
                                    if (stripos($del['delivery_status'], 'Transit') !== false) {
                                        $statusClass = 'bg-emerald-100 text-emerald-700';
                                    } elseif (stripos($del['delivery_status'], 'Return') !== false) {
                                        $statusClass = 'bg-amber-100 text-amber-700';
                                    }
                                    
                                    $upcomingScheduleDate = !empty($del['schedule_date']) ? $del['schedule_date'] : date('Y-m-d');
                                    $isToday = date('Y-m-d', strtotime($upcomingScheduleDate)) === date('Y-m-d');
                                    $dateLabel = $isToday ? 'Today' : date('M j, Y', strtotime($upcomingScheduleDate));
                                    $dateClass = $isToday ? 'text-emerald-600 font-semibold' : '';
                                ?>
                                <div class="flex items-center gap-3 p-3 rounded-2xl bg-white border border-slate-100 hover:border-violet-200 hover:shadow-md transition-all group">
                                    <div class="<?php echo $bgColor; ?> w-9 h-9 rounded-xl flex items-center justify-center font-bold text-white text-xs shrink-0 shadow-sm transition-transform group-hover:scale-105 group-hover:rotate-3"><?php echo htmlspecialchars($initials); ?></div>
                                    <div class="flex-1 min-w-0">
                                        <p class="font-bold text-slate-800 truncate"><?php echo htmlspecialchars($del['customer_name'] ?? 'Unknown'); ?></p>
                                        <p class="text-xs text-slate-500 font-medium flex items-center gap-1.5 mt-1.5">
                                            <i data-lucide="calendar" class="w-3.5 h-3.5 text-slate-400"></i>
                                            <span class="<?php echo $dateClass; ?>"><?php echo $dateLabel; ?></span>
                                            <?php if (!empty($del['delivery_address'])): ?>
                                                <span class="truncate ml-1.5 pl-1.5 border-l border-slate-300" title="<?php echo htmlspecialchars($del['delivery_address']); ?>"><?php echo htmlspecialchars($del['delivery_address']); ?></span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <span class="shrink-0 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest <?php echo $statusClass; ?>">
                                        <?php echo htmlspecialchars($del['delivery_status']); ?>
                                    </span>
                                </div>
                                <?php endforeach; ?>
                                
                                <!-- Scheduled Orders (not yet assigned) -->
                                <?php foreach ($scheduledOrders as $order):
                                    $initials = '';
                                    if (!empty($order['customer_name'])) {
                                        $nameParts = explode(' ', $order['customer_name']);
                                        foreach ($nameParts as $part) {
                                            if (!empty($part)) $initials .= strtoupper($part[0]);
                                            if (strlen($initials) >= 2) break;
                                        }
                                    }
                                    if (empty($initials)) $initials = 'O';
                                    $bgColor = $avatarColors[$itemIndex % count($avatarColors)];
                                    $itemIndex++;
                                ?>
                                <div class="flex items-center gap-3 p-3 rounded-2xl bg-white border border-dashed border-slate-300 hover:border-violet-300 hover:shadow-md transition-all group">
                                    <div class="<?php echo $bgColor; ?> w-9 h-9 rounded-xl flex items-center justify-center font-bold text-white text-xs shrink-0 shadow-sm transition-transform group-hover:scale-105 group-hover:rotate-3 opacity-80"><?php echo htmlspecialchars($initials); ?></div>
                                    <div class="flex-1 min-w-0">
                                        <p class="font-bold text-slate-700 truncate"><?php echo htmlspecialchars($order['customer_name'] ?? 'Unknown'); ?></p>
                                        <p class="text-xs text-slate-500 font-medium flex items-center gap-1.5 mt-1.5">
                                            <i data-lucide="clock" class="w-3.5 h-3.5 text-slate-400"></i>
                                            <?php $orderScheduleDate = !empty($order['schedule_date']) ? $order['schedule_date'] : date('Y-m-d'); ?>
                                            <span>Order #<?php echo $order['Order_ID']; ?> · Scheduled for <?php echo date('M j, Y', strtotime($orderScheduleDate)); ?></span>
                                            <?php if (!empty($order['delivery_address'])): ?>
                                                <span class="truncate ml-1.5 pl-1.5 border-l border-slate-300" title="<?php echo htmlspecialchars($order['delivery_address']); ?>"><?php echo htmlspecialchars($order['delivery_address']); ?></span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <span class="shrink-0 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest bg-slate-100 text-slate-600">
                                        PENDING
                                    </span>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Right Calendar Grid -->
                    <div class="p-5 flex flex-col lg:w-1/2">
                        <div class="flex justify-between items-center mb-4">
                            <button id="calPrev" class="p-2.5 bg-slate-50 hover:bg-violet-100 text-slate-600 hover:text-violet-600 rounded-xl transition-colors shrink-0 shadow-sm border border-slate-100">
                                <i data-lucide="chevron-left" class="w-5 h-5"></i>
                            </button>
                            <h3 id="calMonthTitle" class="text-lg font-black text-slate-800 uppercase tracking-widest"></h3>
                            <button id="calNext" class="p-2.5 bg-slate-50 hover:bg-violet-100 text-slate-600 hover:text-violet-600 rounded-xl transition-colors shrink-0 shadow-sm border border-slate-100">
                                <i data-lucide="chevron-right" class="w-5 h-5"></i>
                            </button>
                        </div>
                        <!-- Calendar Legend -->
                        <div class="flex items-center justify-center gap-4 mb-3">
                            <div class="flex items-center gap-1.5 text-xs font-bold text-slate-500">
                                <div class="w-3 h-3 rounded-full bg-violet-600"></div> Has Delivery
                            </div>
                            <div class="flex items-center gap-2 text-xs font-bold text-slate-500">
                                <div class="w-3 h-3 rounded-full bg-emerald-500"></div> Today
                            </div>
                            <div class="flex items-center gap-2 text-xs font-bold text-slate-500">
                                <div class="w-3 h-3 rounded-full bg-rose-500"></div> Missed
                            </div>
                        </div>
                        <div id="calendarGrid" class="grid grid-cols-7 gap-0.5 text-center overflow-y-auto custom-scrollbar" style="max-height:360px;">
                            <!-- populated by JS -->
                        </div>
                    </div>
                </div>

                <!-- Recent Transactions + Orders Row -->
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-6 mb-8">

                    <!-- Recent Sales Transactions -->
                    <div class="bg-white rounded-3xl shadow-sm border border-slate-200/60 overflow-hidden glass-panel animate-slide-up-3d delay-500 hover:shadow-xl hover:-translate-y-1 transition-all duration-500">
                        <div class="p-6 md:px-8 py-5 border-b border-slate-100 flex items-center justify-between">
                            <h3 class="text-lg font-black text-slate-800 flex items-center gap-3">
                                <div class="bg-violet-100 p-2.5 rounded-xl text-violet-600"><i data-lucide="file-spreadsheet" class="w-5 h-5"></i></div>
                                Recent Sales
                            </h3>
                            <a href="pages/sales.php" class="text-sm font-bold text-violet-600 hover:text-violet-700 flex items-center gap-1.5 group">
                                View All <i data-lucide="arrow-right" class="w-4 h-4 group-hover:translate-x-1 transition-transform"></i>
                            </a>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse whitespace-nowrap">
                                <thead>
                                    <tr class="bg-slate-50/80">
                                        <th class="py-3 px-5 font-bold text-[10px] text-slate-500 uppercase tracking-widest border-b border-slate-200">ID</th>
                                        <th class="py-3 px-5 font-bold text-[10px] text-slate-500 uppercase tracking-widest border-b border-slate-200">Customer</th>
                                        <th class="py-3 px-5 font-bold text-[10px] text-slate-500 uppercase tracking-widest border-b border-slate-200">Amount</th>
                                        <th class="py-3 px-5 font-bold text-[10px] text-slate-500 uppercase tracking-widest border-b border-slate-200">Status</th>
                                        <th class="py-3 px-5 font-bold text-[10px] text-slate-500 uppercase tracking-widest border-b border-slate-200 text-right">→</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 text-sm">
                                    <?php if (empty($recentSales)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-10 text-slate-400">
                                                <i data-lucide="inbox" class="w-10 h-10 mx-auto mb-3 opacity-30 text-slate-500"></i>
                                                <p class="font-bold text-slate-600">No recent sales</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recentSales as $sale): ?>
                                            <tr class="ripple-row hover:bg-violet-50/40 transition-colors group cursor-pointer" onclick="window.location='pages/sale_view.php?id=<?php echo $sale['Sale_ID']; ?>'">
                                                <td class="py-3 px-5 font-bold text-violet-600">#<?php echo $sale['Sale_ID']; ?></td>
                                                <td class="py-3 px-5 font-semibold text-slate-800 max-w-[130px] truncate"><?php echo htmlspecialchars($sale['customer_name'] ?? 'Walk-in'); ?></td>
                                                <td class="py-3 px-5 font-black text-slate-900"><?php echo formatPeso($sale['total_amount']); ?></td>
                                                <td class="py-3 px-5">
                                                    <?php
                                                    $status = strtolower($sale['status'] ?? 'pending');
                                                    $sCls = $status === 'completed' ? 'bg-emerald-100 text-emerald-700' : ($status === 'cancelled' ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700');
                                                    $sIcon = $status === 'completed' ? 'check-circle' : ($status === 'cancelled' ? 'x-circle' : 'clock');
                                                    ?>
                                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[10px] font-black <?php echo $sCls; ?> uppercase tracking-widest">
                                                        <i data-lucide="<?php echo $sIcon; ?>" class="w-3 h-3"></i> <?php echo htmlspecialchars($sale['status'] ?? 'Pending'); ?>
                                                    </span>
                                                </td>
                                                <td class="py-3 px-5 text-right">
                                                    <a href="pages/sale_view.php?id=<?php echo $sale['Sale_ID']; ?>" class="inline-flex items-center justify-center p-2 bg-slate-50 text-slate-500 hover:bg-violet-600 hover:text-white rounded-xl transition-all shadow-sm" onclick="event.stopPropagation()">
                                                        <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Recent Orders List -->
                    <div class="bg-white rounded-3xl shadow-sm border border-slate-200/60 overflow-hidden glass-panel animate-slide-up-3d delay-600 hover:shadow-xl hover:-translate-y-1 transition-all duration-500">
                        <div class="p-6 md:px-8 py-5 border-b border-slate-100 flex items-center justify-between">
                            <h3 class="text-lg font-black text-slate-800 flex items-center gap-3">
                                <div class="bg-sky-100 p-2.5 rounded-xl text-sky-600"><i data-lucide="shopping-bag" class="w-5 h-5"></i></div>
                                Recent Orders
                            </h3>
                            <a href="pages/orders.php" class="text-sm font-bold text-sky-600 hover:text-sky-700 flex items-center gap-1.5 group">
                                View All <i data-lucide="arrow-right" class="w-4 h-4 group-hover:translate-x-1 transition-transform"></i>
                            </a>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse whitespace-nowrap">
                                <thead>
                                    <tr class="bg-slate-50/80">
                                        <th class="py-3 px-5 font-bold text-[10px] text-slate-500 uppercase tracking-widest border-b border-slate-200">ID</th>
                                        <th class="py-3 px-5 font-bold text-[10px] text-slate-500 uppercase tracking-widest border-b border-slate-200">Customer</th>
                                        <th class="py-3 px-5 font-bold text-[10px] text-slate-500 uppercase tracking-widest border-b border-slate-200">Amount</th>
                                        <th class="py-3 px-5 font-bold text-[10px] text-slate-500 uppercase tracking-widest border-b border-slate-200">Status</th>
                                        <th class="py-3 px-5 font-bold text-[10px] text-slate-500 uppercase tracking-widest border-b border-slate-200 text-right">Date</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 text-sm">
                                    <?php if (empty($recentOrders)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-10 text-slate-400">
                                                <i data-lucide="package-open" class="w-10 h-10 mx-auto mb-3 opacity-30 text-slate-500"></i>
                                                <p class="font-bold text-slate-600">No orders found</p>
                                                <p class="text-xs mt-1 text-slate-400">Orders will appear here once placed.</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recentOrders as $order): ?>
                                            <?php
                                            $os = strtolower($order['status'] ?? 'pending');
                                            $oCls = $os === 'completed' || $os === 'delivered' ? 'bg-emerald-100 text-emerald-700' : ($os === 'cancelled' ? 'bg-rose-100 text-rose-700' : ($os === 'processing' ? 'bg-sky-100 text-sky-700' : 'bg-amber-100 text-amber-700'));
                                            $oIcon = $os === 'completed' || $os === 'delivered' ? 'check-circle' : ($os === 'cancelled' ? 'x-circle' : ($os === 'processing' ? 'loader' : 'clock'));
                                            ?>
                                            <tr class="hover:bg-sky-50/40 transition-colors group cursor-default">
                                                <td class="py-3 px-5 font-bold text-sky-600">#<?php echo $order['Order_ID']; ?></td>
                                                <td class="py-3 px-5 font-semibold text-slate-800 max-w-[130px] truncate"><?php echo htmlspecialchars($order['customer_name'] ?? 'Unknown'); ?></td>
                                                <td class="py-3 px-5 font-black text-slate-900"><?php echo formatPeso($order['total_amount'] ?? 0); ?></td>
                                                <td class="py-3 px-5">
                                                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[10px] font-black <?php echo $oCls; ?> uppercase tracking-widest">
                                                        <i data-lucide="<?php echo $oIcon; ?>" class="w-3 h-3"></i> <?php echo htmlspecialchars($order['status'] ?? 'Pending'); ?>
                                                    </span>
                                                </td>
                                                <td class="py-3 px-5 text-right text-slate-500 font-medium text-xs"><?php echo date('M j', strtotime($order['order_date'] ?? 'now')); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div><!-- /transactions + orders grid -->


            </div>
        </main>
    </div>

    <!-- Chart Expand Modal -->
    <div id="chartModal">
        <div id="chartModalInner">
            <div id="chartModalHeader">
                <span id="chartModalTitle">Chart</span>
                <button id="chartModalClose" onclick="closeChartModal()"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>
            <div id="chartModalBody"></div>
        </div>
    </div>

    <!-- Cursor Aura -->
    <div id="cursorAura"></div>

    <!-- Low Stock Modal -->
    <div id="lowStockModal" class="hidden fixed inset-0 z-[9999] bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-[2rem] shadow-2xl w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden animate-slide-up">
            <!-- Header -->
            <div class="bg-gradient-to-r from-amber-500 to-rose-500 px-8 py-6 flex justify-between items-center text-white shrink-0 border-b border-amber-600/30">
                <div>
                    <h3 class="text-2xl font-black flex items-center gap-3 drop-shadow-md">
                        <i data-lucide="triangle-alert" class="w-8 h-8 text-amber-200"></i> Low Stock Alerts
                    </h3>
                    <p class="text-amber-50 font-medium mt-1 text-sm">Items visually tracked below your safety thresholds. Restock soon to prevent outages.</p>
                </div>
                <button id="lowStockModalClose" class="w-12 h-12 bg-white/20 hover:bg-white/30 border-2 border-white/30 rounded-2xl flex items-center justify-center transition-all hover:rotate-90 shadow-sm focus:outline-none">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
            <!-- Toolbar -->
            <div class="px-8 py-5 border-b border-slate-100 flex flex-wrap gap-4 items-center justify-between bg-slate-50/80 shrink-0">
                <div class="relative w-full md:w-96 group">
                    <i data-lucide="search" class="w-5 h-5 absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-violet-600 transition-colors"></i>
                    <input type="text" id="lowStockSearch" placeholder="Search products..." class="w-full pl-11 pr-4 py-3 bg-white border-2 border-slate-200 focus:border-violet-600 focus:ring-4 focus:ring-violet-600/10 rounded-xl outline-none font-medium transition-all shadow-sm">
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <div id="lowStockCountPill" class="px-4 py-2.5 bg-amber-100 border-2 border-amber-200 text-amber-800 rounded-xl font-bold text-sm shadow-sm whitespace-nowrap">
                        Loading...
                    </div>
                    <a href="pages/inventory.php" class="px-5 py-2.5 bg-slate-800 hover:bg-slate-900 text-white rounded-xl font-bold text-sm flex items-center gap-2 shadow-md transition-all whitespace-nowrap">
                        <i data-lucide="boxes" class="w-4 h-4"></i> View Inventory
                    </a>
                </div>
            </div>
            <!-- Body -->
            <div id="lowStockModalBody" class="flex-1 overflow-y-auto custom-scrollbar p-6 md:p-8 bg-slate-50/50">
                <div class="py-20 text-center text-slate-400 flex flex-col items-center">
                    <i data-lucide="loader-circle" class="w-10 h-10 animate-spin text-violet-600 mb-4"></i>
                    <p class="font-bold text-lg text-slate-600">Loading alerts securely...</p>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/script.js"></script>
    <script src="assets/js/notification_helper.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/notification_helper.js'); ?>"></script>
    <script>
        lucide.createIcons();
        // =============================================
        // Low Stock Modal
        // =============================================
        function openLowStockModal() {
            const modal = document.getElementById('lowStockModal');
            if (!modal) return;
            modal.classList.remove('hidden');
            document.body.classList.add('modal-active');
        }
        function closeLowStockModal() {
            const modal = document.getElementById('lowStockModal');
            if (!modal) return;
            modal.classList.add('hidden');
            document.body.classList.remove('modal-active');
        }

        function renderLowStockList(alerts) {
            const body = document.getElementById('lowStockModalBody');
            const pill = document.getElementById('lowStockCountPill');
            if (!body || !pill) return;

            pill.innerHTML = `<i data-lucide="bell" class="w-4 h-4 inline-block mr-1"></i> ${alerts.length} alert${alerts.length !== 1 ? 's' : ''}`;
            lucide.createIcons();

            if (!alerts.length) {
                body.innerHTML = `
                <div class="text-center py-16 text-slate-400 flex flex-col items-center">
                    <div class="bg-emerald-50 p-6 rounded-[2rem] mb-6">
                        <i data-lucide="shield-check" class="w-16 h-16 text-emerald-500"></i>
                    </div>
                    <div class="font-black text-slate-800 text-2xl mb-2">No low stock alerts</div>
                    <div class="font-medium text-slate-500">Everything is safely within expected threshold levels.</div>
                </div>
                `;
                lucide.createIcons();
                return;
            }

            body.innerHTML = `
            <div id="lowStockList" class="grid gap-4">
                ${alerts.map((a, idx) => `
                    <div class="low-stock-item group relative overflow-hidden bg-white border border-slate-200 border-l-[6px] border-l-amber-500 rounded-2xl p-6 shadow-sm hover:shadow-md transition-all" data-name="${String(a.item || '').toLowerCase()}">
                        <div class="flex flex-col md:flex-row justify-between gap-6 md:items-start">
                            <div class="flex-1">
                                <div class="flex items-center gap-3 mb-2">
                                    <div class="bg-amber-100 p-2 rounded-xl text-amber-600"><i data-lucide="triangle-alert" class="w-5 h-5"></i></div>
                                    <div class="font-bold text-slate-800 text-lg">${escapeHtml(a.item || 'Unknown Product')}</div>
                                </div>
                                <div class="text-slate-500 text-sm pl-11">${escapeHtml(a.message || 'Stock level is below safety threshold.')}</div>
                            </div>
                            <button type="button" class="js-toggle-rec shrink-0 px-4 py-2.5 bg-slate-50 hover:bg-violet-600 border border-slate-200 hover:border-violet-600 text-slate-700 hover:text-white rounded-xl font-bold text-sm transition-all focus:outline-none flex items-center justify-center gap-2" data-idx="${idx}">
                                <i data-lucide="lightbulb" class="w-4 h-4"></i> Resolve
                            </button>
                        </div>
                        <div class="js-rec hidden mt-4 bg-amber-50 border border-amber-200 p-5 rounded-2xl animate-slide-up" id="rec_${idx}">
                            <div class="flex items-center gap-2 font-black text-amber-800 text-xs uppercase tracking-widest mb-2"><i data-lucide="map" class="w-4 h-4"></i> Action Plan</div>
                            <div class="text-amber-900/80 text-sm font-medium leading-relaxed">${escapeHtml(a.recommendation || 'Please review inventory levels and consider restocking immediately.')}</div>
                        </div>
                    </div>
                `).join('')}
            </div>
            `;
            lucide.createIcons();
            
            body.querySelectorAll('.js-toggle-rec').forEach(btn => {
                btn.addEventListener('click', () => {
                    const idx = btn.getAttribute('data-idx');
                    const rec = document.getElementById('rec_' + idx);
                    if (!rec) return;
                    const isHidden = rec.classList.contains('hidden');
                    if (isHidden) {
                        rec.classList.remove('hidden');
                        btn.innerHTML = `<i data-lucide="chevron-up" class="w-4 h-4"></i> Close`;
                        btn.classList.add('bg-slate-800', 'text-white', 'border-slate-800');
                        btn.classList.remove('bg-slate-50', 'text-slate-700');
                    } else {
                        rec.classList.add('hidden');
                        btn.innerHTML = `<i data-lucide="lightbulb" class="w-4 h-4"></i> Resolve`;
                        btn.classList.remove('bg-slate-800', 'text-white', 'border-slate-800');
                        btn.classList.add('bg-slate-50', 'text-slate-700');
                    }
                    lucide.createIcons();
                });
            });
        }

        function escapeHtml(s) {
            return String(s || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        async function loadLowStockAlertsIntoModal() {
            const body = document.getElementById('lowStockModalBody');
            const pill = document.getElementById('lowStockCountPill');
            if (body) {
                body.innerHTML = '<div class="py-16 text-center text-slate-400 font-medium flex flex-col items-center"><i data-lucide="loader-2" class="w-10 h-10 animate-spin text-violet-500 mb-3"></i> Loading alerts...</div>';
                lucide.createIcons();
            }
            if (pill) pill.textContent = 'Loading...';

            try {
                const res = await fetch('api/dss_backend.php');
                const json = await res.json();
                const alerts = (json?.success && Array.isArray(json.data)) ? json.data : [];
                const low = alerts.filter(a => String(a.title || '').toLowerCase().includes('low stock'));
                renderLowStockList(low);
            } catch(err) {
                 if (body) {
                     body.innerHTML = '<div class="py-16 text-center text-rose-500 font-medium">Failed to load stock alerts.</div>';
                 }
                 if(pill) pill.textContent = 'Error';
            }
        }

        // =============================================
        // Delivery Calendar
        // =============================================
        const deliveryDates = <?php echo $deliveryDatesJson; ?>;
        const overdueDates = <?php echo $overdueDatesJson; ?>;
        let calYear, calMonth;

        function initCalendar() {
            const today = new Date();
            calYear = today.getFullYear();
            calMonth = today.getMonth();
            renderCalendar();
        }

        function renderCalendar() {
            const grid = document.getElementById('calendarGrid');
            const title = document.getElementById('calMonthTitle');
            if (!grid || !title) return;

            const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
            title.textContent = monthNames[calMonth] + ' ' + calYear;

            const firstDay = new Date(calYear, calMonth, 1).getDay();
            const daysInMonth = new Date(calYear, calMonth + 1, 0).getDate();
            const daysInPrev = new Date(calYear, calMonth, 0).getDate();
            const today = new Date();
            const todayStr = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');

            let html = '';
            const dayLabels = ['Su','Mo','Tu','We','Th','Fr','Sa'];
            dayLabels.forEach(d => {
                html += `<div class="text-[10px] font-black text-slate-400 py-2 uppercase tracking-widest">${d}</div>`;
            });

            for (let i = firstDay - 1; i >= 0; i--) {
                html += `<div class="p-1 text-xs font-medium text-slate-300 rounded-lg flex items-center justify-center">${daysInPrev - i}</div>`;
            }

            for (let d = 1; d <= daysInMonth; d++) {
                const dateStr = calYear + '-' + String(calMonth + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
                const isToday = dateStr === todayStr;
                const hasDelivery = deliveryDates.includes(dateStr);
                const isOverdue = overdueDates.includes(dateStr);
                
                let cls = 'group p-1 text-xs font-bold text-slate-600 relative cursor-default rounded-lg transition-all aspect-square flex items-center justify-center border border-transparent hover:bg-slate-100 hover:border-slate-200';
                let style = '';
                
                // Demo logic: count simulated deliveries per date based on date string hash
                let simulatedCount = hasDelivery ? (dateStr.charCodeAt(dateStr.length-1) % 5) + 1 : 0;
                
                let tooltipHtml = '';
                if (hasDelivery) {
                    tooltipHtml = `<div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 w-max px-2.5 py-1.5 bg-slate-800 text-white text-xs font-bold rounded-lg opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 shadow-xl z-10 pointer-events-none flex items-center gap-1.5">
                        <i data-lucide="package" class="w-3.5 h-3.5"></i> ${simulatedCount} Deliver${simulatedCount > 1 ? 'ies' : 'y'}
                        <div class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-slate-800"></div>
                    </div>`;
                }

                if (isToday && hasDelivery) {
                    cls = 'group p-1 text-xs font-black text-white bg-gradient-to-br from-violet-600 to-indigo-700 relative cursor-pointer hover:scale-105 rounded-lg transition-all aspect-square flex items-center justify-center shadow-md shadow-indigo-500/30';
                } else if (isToday) {
                    cls = 'group p-1 text-xs font-black text-emerald-700 bg-emerald-100 border-2 border-emerald-400 relative cursor-default rounded-lg transition-all aspect-square flex items-center justify-center';
                } else if (isOverdue) {
                    cls = 'group p-1 text-xs font-black text-rose-700 bg-rose-100 border border-rose-300 relative cursor-pointer hover:bg-rose-200 hover:scale-105 rounded-lg transition-all aspect-square flex items-center justify-center';
                } else if (hasDelivery) {
                    cls = 'group p-1 text-xs font-black text-violet-700 bg-violet-100 border border-violet-200 relative cursor-pointer hover:bg-violet-200 hover:scale-105 rounded-lg transition-all aspect-square flex items-center justify-center';
                }
                
                html += `<div class="${cls}">${d}${tooltipHtml}</div>`;
            }

            const totalCells = firstDay + daysInMonth;
            const remaining = totalCells % 7 === 0 ? 0 : 7 - (totalCells % 7);
            for (let i = 1; i <= remaining; i++) {
                html += `<div class="p-1 text-xs font-medium text-slate-300 rounded-lg flex items-center justify-center">${i}</div>`;
            }

            grid.innerHTML = html;
        }

        // =============================================
        // Dashboard Charts
        // =============================================
        const COLORS = {
            violet: '#7132f5',
            indigo: '#5b1ecf',
            emerald: '#10b981',
            rose: '#f43f5e',
            sky: '#0ea5e9',
            amber: '#f59e0b',
            slate: '#94a3b8'
        };

        let apexSalesTrend = null;
        let trendMode = '7d';

        function peso(val) {
            const n = Number(val || 0);
            return '₱' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        async function initDashboardCharts() {
            // chartInstances must live here so heatmap & other inline refs work
            const chartInstances = {};
            window._chartRegistry = chartInstances;
            try {
                const res = await fetch('api/dashboard_charts.php');
                const json = await res.json();
                if (!json?.success) return;
                const d = json.data || {};

                // ─── Sales Trend with Comparison ───
                const trend7   = d.sales_trend    || { labels: [], data: [] };
                const trendM   = d.monthly_trend  || { labels: [], data: [] };
                const trendDay = { labels: (trend7.labels || []).slice(-7), data: (trend7.data || []).slice(-7) };
                
                const comp7    = d.sales_trend_comp || [];
                const compM    = d.monthly_trend_comp || [];
                const compDay  = comp7.slice(-7);

                const t7data  = (trend7.data  || []).map(Number);
                const t7comp  = comp7.map(Number);
                const t7cats  = trend7.labels || [];
                // Ensure no empty series that crash ApexCharts
                const safeT7  = t7data.length  ? t7data  : [0];
                const safeC7  = t7comp.length  ? t7comp  : [0];
                const safeCat = t7cats.length  ? t7cats  : ['—'];

                apexSalesTrend = new ApexCharts(document.querySelector('#apexSalesTrend'), {
                    chart: { type: 'area', height: 300, toolbar: { show: false }, fontFamily: 'Inter, sans-serif', animations: { enabled: true, easing: 'easeinout', speed: 600 } },
                    stroke: { curve: 'smooth', width: [4, 2], dashArray: [0, 6] },
                    fill: { 
                        type: 'gradient', 
                        gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.0, stops: [0, 100] } 
                    },
                    colors: [COLORS.violet, COLORS.slate],
                    xaxis: { categories: safeCat, labels: { style: { colors: COLORS.slate, fontWeight: 600, fontSize: '11px' } }, axisBorder: { show: false }, axisTicks: { show: false } },
                    series: [
                        { name: 'This Period', data: safeT7 },
                        { name: 'Last Period',  data: safeC7 }
                    ],
                    yaxis: { labels: { formatter: (v) => '₱' + Number(v || 0).toLocaleString(), style: { colors: COLORS.slate, fontWeight: 600 } } },
                    tooltip: { shared: true, intersect: false, y: { formatter: (v) => peso(v) }, theme: 'light' },
                    legend: { show: true, position: 'top', horizontalAlign: 'right', fontFamily: 'Inter', fontWeight: 700, fontSize: '12px',
                        markers: { width: 10, height: 10, radius: 6 }, itemMargin: { horizontal: 10 }
                    },
                    grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
                    dataLabels: { enabled: false }
                });
                setTimeout(() => {
                    if (document.querySelector('#apexSalesTrend')) {
                        apexSalesTrend.render();
                        chartInstances['apexSalesTrend'] = apexSalesTrend;
                        
                        // Default to 7 days view to trigger button styling
                        if (typeof setTrend === 'function') setTrend('7d');
                    }
                }, 100);

                const trendDayBtn   = document.getElementById('trendDayBtn');
                const trend7Btn     = document.getElementById('trend7Btn');
                const trendMonthBtn = document.getElementById('trendMonthBtn');
                const ACTIVE_BTN  = 'trend-btn px-4 py-2 rounded-xl text-xs font-bold border-2 border-violet-600 text-violet-700 bg-white shadow-sm transition-all focus:outline-none';
                const INACTIVE_BTN= 'trend-btn px-4 py-2 rounded-xl text-xs font-bold border-2 border-transparent text-slate-500 hover:text-slate-700 transition-all focus:outline-none';

                function setTrend(mode) {
                    trendMode = mode;
                    let src = trend7;
                    let compSrc = comp7;

                    if (mode === 'monthly') { 
                        src = trendM; 
                        compSrc = compM;
                    }
                    if (mode === 'daily') {
                        src = trendDay; 
                        compSrc = compDay;
                    }
                    if (!src || !src.labels) {
                        src = trend7;
                        compSrc = comp7;
                    }
                    
                    const safeData = (src.data || []).length ? (src.data || []) : [0];
                    const safeComp = compSrc.length ? compSrc : [0];
                    const safeCats = (src.labels || []).length ? (src.labels || []) : ['—'];

                    apexSalesTrend.updateOptions({ xaxis: { categories: safeCats } });
                    apexSalesTrend.updateSeries([
                        { name: 'This Period', data: safeData },
                        { name: 'Last Period', data: safeComp }
                    ]);
                    [trendDayBtn, trend7Btn, trendMonthBtn].forEach(b => { if(b) b.className = INACTIVE_BTN; });
                    const activeBtn = mode === 'daily' ? trendDayBtn : mode === 'monthly' ? trendMonthBtn : trend7Btn;
                    if (activeBtn) activeBtn.className = ACTIVE_BTN;
                }
                trendDayBtn?.addEventListener('click',   () => setTrend('daily'));
                trend7Btn?.addEventListener('click',     () => setTrend('7d'));
                trendMonthBtn?.addEventListener('click', () => setTrend('monthly'));
                
                // Initialize default view state
                setTrend('7d');

                // Top Selling Products
                const tp = d.top_products || { labels: [], revenues: [] };
                new ApexCharts(document.querySelector('#apexTopProducts'), {
                    chart: { type: 'bar', height: 320, toolbar: { show: false }, fontFamily: 'Inter, sans-serif' },
                    plotOptions: { bar: { horizontal: true, borderRadius: 8, barHeight: '50%' } },
                    colors: [COLORS.violet],
                    series: [{ name: 'Revenue', data: tp.revenues || [] }],
                    xaxis: { categories: tp.labels || [], labels: { formatter: (v) => '₱' + Number(v || 0).toLocaleString(), style: { colors: COLORS.slate, fontWeight:600 } } },
                    yaxis: { labels: { style: { colors: '#334155', fontWeight: 700 } } },
                    tooltip: { y: { formatter: (v) => peso(v) }, theme: 'light' },
                    grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
                    dataLabels: { enabled: false }
                }).render();

                // Top Customers
                const tc = d.top_customers || { labels: [], revenues: [] };
                new ApexCharts(document.querySelector('#apexTopCustomers'), {
                    chart: { type: 'bar', height: 320, toolbar: { show: false }, fontFamily: 'Inter, sans-serif' },
                    colors: [COLORS.sky],
                    series: [{ name: 'Purchases', data: tc.revenues || [] }],
                    xaxis: { categories: tc.labels || [], labels: { style: { colors: COLORS.slate, fontWeight: 600 } } },
                    yaxis: { labels: { formatter: (v) => '₱' + Number(v || 0).toLocaleString(), style: { colors: COLORS.slate, fontWeight: 600 } } },
                    tooltip: { y: { formatter: (v) => peso(v) }, theme: 'light' },
                    grid: { borderColor: '#f1f5f9', strokeDashArray: 4 },
                    plotOptions: { bar: { borderRadius: 8, columnWidth: '40%' } },
                    dataLabels: { enabled: false }
                }).render();

                // Damage Goods Trend
                const dt = d.damage_trend || { labels: [], data: [] };
                new ApexCharts(document.querySelector('#apexDamageTrend'), {
                    chart: { type: 'area', height: 160, toolbar: { show: false }, fontFamily: 'Inter, sans-serif', sparkline: { enabled: false } },
                    stroke: { curve: 'smooth', width: 3 },
                    fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.0, stops: [0, 100] } },
                    colors: [COLORS.rose],
                    series: [{ name: 'Damaged Items', data: dt.data || [] }],
                    xaxis: { categories: dt.labels || [], labels: { style: { colors: COLORS.slate, fontSize: '10px', fontWeight:600 } }, tooltip:{enabled:false}, axisBorder:{show:false}, axisTicks:{show:false} },
                    yaxis: { labels: { show: false }, min: 0 },
                    tooltip: { theme: 'light', y: { formatter: (v) => v + ' items' } },
                    grid: { show: false },
                    dataLabels: { enabled: false }
                }).render();
                // ─── Least Selling Products Bar ───
                const lp = d.least_products || { labels: [], revenues:[] };
                const leastLabels = (lp.labels || []).slice(0, 6);
                const leastSeries = (lp.revenues || []).slice(0, 6).map(Number);
                const leastQty = (lp.quantities || []).slice(0, 6).map(Number);
                if (leastLabels.length) {
                    new ApexCharts(document.querySelector('#apexLeastSelling'), {
                        chart: { type: 'bar', height: 260, fontFamily: 'Inter, sans-serif', toolbar:{show:false} },
                        series: [{ name: 'Revenue', data: leastSeries }],
                        labels: leastLabels,
                        colors: ['#dc2626'],
                        plotOptions: { bar: { horizontal: true, borderRadius: 4, dataLabels: { position: 'top' } } },
                        xaxis: { labels: { formatter: v => peso(Number(v) || 0) }, axisBorder: { show: false } },
                        yaxis: { labels: { style: { fontSize: '10px', fontWeight: 600, colors: '#475569' } } },
                        legend: { show: false },
                        grid: { borderColor: '#f1f5f9' },
                        dataLabels: { enabled: false },
                        tooltip: { y: { formatter: v => peso(v) }, theme: 'light' }
                    }).render();
                } else {
                    const el = document.querySelector('#apexLeastSelling');
                    if(el) el.innerHTML = '<div class="flex items-center justify-center h-full text-slate-400 text-sm font-medium pt-16">No product data yet</div>';
                }


                // ─── Revenue Heatmap by Weekday ───
                const mTrend = d.monthly_trend || { labels: [], data: [] };
                const dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                // Build weekday totals from the monthly trend (last 28 days simulated by day-of-week)
                const weekdayTotals = [0,0,0,0,0,0,0];
                const weekdayCounts = [0,0,0,0,0,0,0];
                (mTrend.labels || []).forEach((lbl, i) => {
                    const d2 = new Date(lbl);
                    if (!isNaN(d2)) {
                        const dow = d2.getDay();
                        weekdayTotals[dow] += Number((mTrend.data || [])[i] || 0);
                        weekdayCounts[dow]++;
                    }
                });
                const weekdayAvg = weekdayTotals.map((t,i) => weekdayCounts[i] ? Math.round(t / weekdayCounts[i]) : 0);

                // Build 4-week heatmap series (Sun-Sat rows, W1-W4 cols)
                const hmSeries = dayNames.map((dn, di) => ({
                    name: dn,
                    data: [1,2,3,4].map(w => ({
                        x: `W${w}`,
                        y: weekdayAvg[di] > 0 ? Math.max(0, weekdayAvg[di] * (0.75 + Math.random()*0.5)) : 0
                    }))
                }));

                const hmEl = document.querySelector('#apexRevenueHeatmap');
                if (hmEl) {
                    new ApexCharts(hmEl, {
                        chart: { type: 'heatmap', height: 200, toolbar: { show: false }, fontFamily: 'Inter, sans-serif', animations: { enabled: true, easing: 'easeinout', speed: 600 } },
                        series: hmSeries,
                        colors: ['#7c3aed'],
                        dataLabels: { enabled: false },
                        xaxis: { labels: { style: { colors: '#64748b', fontWeight: 700, fontSize: '11px' } }, axisBorder:{show:false}, axisTicks:{show:false} },
                        yaxis: { labels: { style: { colors: '#334155', fontWeight: 700, fontSize: '11px' } } },
                        tooltip: { y: { formatter: v => peso(v) }, theme: 'light' },
                        plotOptions: { heatmap: { shadeIntensity: 0.6, radius: 6, colorScale: { ranges: [
                            { from: 0, to: 0, color: '#f1f5f9', name: 'No Sales' },
                            { from: 1, to: 9999, color: '#a78bfa', name: 'Low' },
                            { from: 10000, to: 49999, color: '#7c3aed', name: 'Medium' },
                            { from: 50000, to: 9999999, color: '#4c1d95', name: 'High' }
                        ]}}},
                        grid: { padding: { top: 0, right: 0, bottom: 0, left: 0 } },
                        legend: { show: false }
                    }).render();
                    chartInstances['apexRevenueHeatmap'] = hmEl._chart;
                }

                // KPI pills
                const totalRevArr = weekdayAvg.filter(v => v > 0);
                const avgDailyVal = totalRevArr.length ? Math.round(totalRevArr.reduce((a,b)=>a+b,0)/totalRevArr.length) : 0;
                const bestDowIdx = weekdayAvg.indexOf(Math.max(...weekdayAvg));
                const slowDowIdx = weekdayAvg.indexOf(Math.min(...weekdayAvg.filter(v=>v>0)) || 0);
                const kpiAvg = document.getElementById('kpiAvgDaily');
                const kpiBest = document.getElementById('kpiBestDay');
                const kpiSlow = document.getElementById('kpiSlowestDay');
                if (kpiAvg) kpiAvg.textContent = avgDailyVal > 0 ? '₱'+avgDailyVal.toLocaleString() : '—';
                if (kpiBest) kpiBest.textContent = weekdayAvg[bestDowIdx] > 0 ? dayNames[bestDowIdx] : '—';
                if (kpiSlow) kpiSlow.textContent = weekdayAvg[slowDowIdx] > 0 ? dayNames[slowDowIdx] : '—';

            } catch(e) { console.error('Charts error', e); }
        }

        // =============================================
        // DOMContentLoaded
        // =============================================
        document.addEventListener('DOMContentLoaded', function () {
            // Low stock modal
            const lowStocksCard = document.getElementById('lowStocksCard');
            const closeBtn = document.getElementById('lowStockModalClose');
            const modal = document.getElementById('lowStockModal');
            const search = document.getElementById('lowStockSearch');

            lowStocksCard?.addEventListener('click', async (e) => {
                e.preventDefault();
                openLowStockModal();
                await loadLowStockAlertsIntoModal();
            });
            closeBtn?.addEventListener('click', closeLowStockModal);
            modal?.addEventListener('click', (e) => { if (e.target === modal) closeLowStockModal(); });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !modal?.classList.contains('hidden')) closeLowStockModal(); });

            search?.addEventListener('input', () => {
                const q = String(search.value || '').toLowerCase();
                document.querySelectorAll('#lowStockList .low-stock-item').forEach(el => {
                    const name = el.getAttribute('data-name') || '';
                    if(name.includes(q)) el.classList.remove('hidden'); else el.classList.add('hidden');
                });
            });

            // Calendar
            initCalendar();
            document.getElementById('calPrev')?.addEventListener('click', () => {
                calMonth--;
                if (calMonth < 0) { calMonth = 11; calYear--; }
                renderCalendar();
            });
            document.getElementById('calNext')?.addEventListener('click', () => {
                calMonth++;
                if (calMonth > 11) { calMonth = 0; calYear++; }
                renderCalendar();
            });

            // Charts
            initDashboardCharts();

            // ─────────────────────────────────────
            // WEATHER WIDGET  (Open-Meteo, no key)
            // ─────────────────────────────────────
            (function initWeather() {
                const WMO = {
                    0:['☀️','Clear sky'], 1:['🌤️','Mainly clear'], 2:['⛅','Partly cloudy'], 3:['☁️','Overcast'],
                    45:['🌫️','Foggy'], 48:['🌫️','Rime fog'],
                    51:['🌦️','Light drizzle'], 53:['🌦️','Drizzle'], 55:['🌧️','Heavy drizzle'],
                    61:['🌧️','Slight rain'], 63:['🌧️','Moderate rain'], 65:['🌧️','Heavy rain'],
                    71:['🌨️','Slight snow'], 73:['❄️','Moderate snow'], 75:['❄️','Heavy snow'],
                    80:['🌦️','Rain showers'], 81:['🌧️','Moderate showers'], 82:['⛈️','Violent showers'],
                    95:['⛈️','Thunderstorm'], 96:['⛈️','Thunderstorm w/ hail'], 99:['⛈️','Heavy thunderstorm']
                };
                function wmoInfo(code) { return WMO[code] || ['🌡️', 'Unknown']; }
                function setEl(id, val) { const e = document.getElementById(id); if (e) e.textContent = val; }

                function renderForecast(daily) {
                    const fc = document.getElementById('weatherForecast');
                    if (!fc) return;
                    const days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                    fc.innerHTML = '';
                    for (let i = 0; i < Math.min(6, (daily.time||[]).length); i++) {
                        const d = new Date(daily.time[i]);
                        const [ico] = wmoInfo(daily.weather_code?.[i] ?? 0);
                        const high = Math.round(daily.temperature_2m_max?.[i] ?? 0);
                        const low  = Math.round(daily.temperature_2m_min?.[i] ?? 0);
                        const card = document.createElement('div');
                        card.className = 'bg-slate-50 border border-slate-100 rounded-2xl p-3 flex flex-col items-center gap-1 min-w-[70px] shrink-0';
                        card.innerHTML = `<span class="text-[11px] font-black text-slate-400 uppercase tracking-widest">${i===0?'Today':days[d.getDay()]}</span>
                            <span class="text-2xl">${ico}</span>
                            <span class="text-sm font-black text-slate-800">${high}°</span>
                            <span class="text-[10px] font-bold text-slate-400">${low}°</span>`;
                        fc.appendChild(card);
                    }
                }

                async function fetchWeather(lat, lon) {
                    try {
                        const url = `https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lon}&current=temperature_2m,apparent_temperature,relative_humidity_2m,wind_speed_10m,weather_code&daily=weather_code,temperature_2m_max,temperature_2m_min&timezone=auto&forecast_days=6`;
                        const res = await fetch(url);
                        const data = await res.json();
                        const c = data.current || {};
                        const [ico, desc] = wmoInfo(c.weather_code ?? 0);

                        const iconEl = document.getElementById('weatherIconWrap');
                        if (iconEl) iconEl.textContent = ico;
                        setEl('weatherTemp', Math.round(c.temperature_2m ?? 0) + '°C');
                        setEl('weatherDesc', desc);
                        setEl('weatherHumidity', (c.relative_humidity_2m ?? '—') + '%');
                        setEl('weatherWind', Math.round(c.wind_speed_10m ?? 0) + ' km/h');
                        setEl('weatherFeels', Math.round(c.apparent_temperature ?? 0) + '°C');
                        setEl('weatherUpdatedAt', 'Updated ' + new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) + ' · High Accuracy');
                        renderForecast(data.daily || {});
                    } catch (e) {
                        console.error('Weather fetch error', e);
                        setEl('weatherDesc', 'Network error');
                    }
                }

                async function resolveLocation(lat, lon) {
                    try {
                        const r = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lon}`);
                        const j = await r.json();
                        const city = j.address?.city || j.address?.town || j.address?.municipality || j.address?.village || j.address?.suburb || 'Your Area';
                        const country = j.address?.country || '';
                        setEl('weatherLocation', `📍 ${city}${country ? ', '+country : ''}`);
                    } catch { setEl('weatherLocation', '📍 Detected Location'); }
                }

                function getLocation() {
                    setEl('weatherDesc', 'Locating...');
                    if (navigator.geolocation) {
                        navigator.geolocation.getCurrentPosition(
                            async pos => {
                                const {latitude: lat, longitude: lon} = pos.coords;
                                await fetchWeather(lat, lon);
                                await resolveLocation(lat, lon);
                            },
                            async err => {
                                console.warn('Geolocation error:', err.message);
                                // Fallback: User's IP or default Manila
                                await fetchWeather(14.5995, 120.9842);
                                setEl('weatherLocation', '📍 Manila (Default)');
                                setEl('weatherDesc', 'Location restricted');
                            },
                            { enableHighAccuracy: true, timeout: 10000 }
                        );
                    } else {
                        fetchWeather(14.5995, 120.9842);
                        setEl('weatherLocation', '📍 Manila (Default)');
                    }
                }

                // Initial load
                getLocation();

                // Add refresh listener if it exists
                document.getElementById('weatherWidget')?.addEventListener('dblclick', getLocation);
            })();

            // Count-up animation for stat cards
            function animateCountUps() {
                document.querySelectorAll('.count-up').forEach(function(el) {
                    var target = parseInt(el.getAttribute('data-target'), 10);
                    if (isNaN(target) || target <= 0) {
                        el.textContent = target || 0;
                        return;
                    }
                    var duration = 800, start = performance.now();
                    function step(now) {
                        var pct = Math.min((now - start) / duration, 1);
                        el.textContent = Math.floor(pct * target);
                        if (pct < 1) requestAnimationFrame(step);
                    }
                    requestAnimationFrame(step);
                });
            }
            animateCountUps();

            // Real-time notifications functionality
            const notifToggle = document.getElementById('notificationToggle');
            const notifDropdown = document.getElementById('notificationDropdown');
            const notifBadge = document.getElementById('notificationBadge');
            const notifList = document.getElementById('notificationList');
            if (!notifToggle || !notifDropdown || !notifBadge || !notifList) {
                console.warn('Dashboard notification UI elements missing — polling disabled.');
            } else {
            var userId = <?php echo json_encode((int)($_SESSION['user_id'] ?? 0)); ?>;
            var notifKey = 'notif_lastId_' + userId;
            var countKey = 'notif_count_' + userId;
            var lastLogId = parseInt(localStorage.getItem(notifKey) || '0', 10);
            var unreadCount = parseInt(localStorage.getItem(countKey) || '0', 10);
            var shownIds = JSON.parse(localStorage.getItem('notif_shown_' + userId) || '[]');
            var shownSet = {};
            shownIds.forEach(function(id) { shownSet[id] = true; });

            function persistShownIds() {
                localStorage.setItem('notif_shown_' + userId, JSON.stringify(Object.keys(shownSet)));
            }

            // Restore badge from previous session
            if (unreadCount > 0) {
                notifBadge.textContent = unreadCount > 9 ? '9+' : unreadCount;
                notifBadge.classList.remove('hidden');
            }

            function formatTimeAgo(dateString) {
                if (!dateString) return '';
                const date = new Date(dateString.replace(' ', 'T'));
                if (isNaN(date.getTime())) return '';
                const now = new Date();
                const secondsPast = (now.getTime() - date.getTime()) / 1000;
                if (secondsPast < 60) return parseInt(Math.max(0, secondsPast)) + 's ago';
                if (secondsPast < 3600) return parseInt(secondsPast / 60) + 'm ago';
                if (secondsPast <= 86400) return parseInt(secondsPast / 3600) + 'h ago';
                return parseInt(secondsPast / 86400) + 'd ago';
            }

            function fetchNotifications(isPolling = false) {
                fetch(`api/get_recent_activities.php?last_id=${isPolling ? lastLogId : 0}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'success') {
                            const logs = Array.isArray(data.logs) ? data.logs : [];
                            if (logs.length > 0) {
                                if (!isPolling) notifList.innerHTML = '';
                                
                                logs.forEach(log => {
                                    const pkField = data.pk || 'Log_ID';
                                    const logId = parseInt(log[pkField] ?? 0, 10);
                                    if (logId > lastLogId) {
                                        lastLogId = logId;
                                        localStorage.setItem(notifKey, lastLogId);
                                    }
                                    var neverShown = isPolling && !shownSet[logId];
                                    if (neverShown) {
                                        shownSet[logId] = true;
                                        persistShownIds();
                                        unreadCount++;
                                        localStorage.setItem(countKey, unreadCount);
                                        if (typeof playNotificationSound === 'function') {
                                            try { playNotificationSound(); } catch (soundErr) { console.warn('Notification sound failed', soundErr); }
                                        }
                                        showToast(log.Activity_Type || 'Activity', log.Action_Details);
                                    }
                                    
                                    const item = document.createElement('div');
                                    item.className = 'p-5 border-b border-slate-100 flex gap-4 items-start transition-colors hover:bg-slate-50';
                                    
                                    const activityDetails = log.Action_Details || log.Activity || '';
                                    const activityType = (log.Activity_Type || (activityDetails.toLowerCase().includes('cancel') ? 'CANCEL' : (activityDetails.toLowerCase().includes('confirm') ? 'UPDATE' : 'ACTIVITY'))).toUpperCase();
                                    
                                    let icon = 'info';
                                    let colorCls = 'text-violet-600 bg-violet-100';
                                    if (activityType.includes('DELETE') || activityType.includes('DAMAGE') || activityType.includes('ERROR') || activityType.includes('CANCEL')) { icon = 'alert-triangle'; colorCls = 'text-rose-600 bg-rose-100'; }
                                    else if (activityType.includes('ADD') || activityType.includes('CREATE') || activityType.includes('NEW')) { icon = 'plus-circle'; colorCls = 'text-emerald-600 bg-emerald-100'; }
                                    else if (activityType.includes('UPDATE') || activityType.includes('EDIT')) { icon = 'edit-2'; colorCls = 'text-amber-600 bg-amber-100'; }
                                    else if (activityType.includes('LOGIN') || activityType.includes('LOGOUT')) { icon = 'log-in'; colorCls = 'text-indigo-600 bg-indigo-100'; }
                                    
                                    item.innerHTML = `
                                        <div class="${colorCls} w-10 h-10 rounded-full flex items-center justify-center shrink-0">
                                            <i data-lucide="${icon}" class="w-5 h-5"></i>
                                        </div>
                                        <div class="flex-grow min-w-0">
                                            <div class="text-sm font-bold text-slate-800 mb-1 flex items-center gap-1.5 truncate">
                                                <i data-lucide="user-circle" class="w-4 h-4 text-slate-400"></i> ${escapeHtml(log.user_name || 'System')} <span class="text-slate-400 font-medium">|</span> <span class="text-[11px] font-black tracking-widest uppercase opacity-80">${escapeHtml(activityType)}</span>
                                            </div>
                                            <div class="text-sm font-medium text-slate-600 mb-1.5 leading-snug">
                                                ${escapeHtml(activityDetails)}
                                            </div>
                                            <div class="text-[11px] font-bold text-slate-400 flex items-center gap-1 uppercase tracking-widest">
                                                <i data-lucide="clock" class="w-3 h-3"></i> ${formatTimeAgo(log.Log_Time || log.Time)}
                                            </div>
                                        </div>
                                    `;
                                    if (isPolling) {
                                        notifList.insertBefore(item, notifList.firstChild);
                                    } else {
                                        notifList.appendChild(item);
                                    }
                                });
                                lucide.createIcons();
                                
                                if (unreadCount > 0 && notifDropdown.classList.contains('hidden')) {
                                    notifBadge.textContent = unreadCount > 9 ? '9+' : unreadCount;
                                    notifBadge.classList.remove('hidden');
                                }
                            } else if (!isPolling && notifList.innerHTML.includes('Loading')) {
                                notifList.innerHTML = '<div class="p-10 text-center text-slate-400 font-medium"><i data-lucide="check-circle-2" class="w-8 h-8 text-emerald-400 mx-auto mb-2 opacity-50"></i>You are caught up!</div>';
                                lucide.createIcons();
                            }
                        } else if (!isPolling) {
                            notifList.innerHTML = `<div class="p-6 text-center font-medium text-rose-500">${escapeHtml(data.message || 'Unable to load notifications.')}</div>`;
                        }
                    })
                    .catch(e => console.error('Error fetching notifications:', e));
            }

            // Toast setup
            const toastContainer = document.createElement('div');
            toastContainer.className = 'fixed bottom-6 right-6 z-[10000] flex flex-col gap-4 pointer-events-none';
            document.body.appendChild(toastContainer);

            window.showToast = function(title, message) {
                const toast = document.createElement('div');
                toast.className = 'bg-white rounded-2xl shadow-xl shadow-slate-900/10 p-5 w-80 border-l-[6px] border-l-violet-600 transform translate-x-[120%] transition-transform duration-300 pointer-events-auto flex flex-col';
                
                toast.innerHTML = `
                    <div class="flex justify-between items-start mb-2">
                        <strong class="text-sm font-bold text-slate-800 flex items-center gap-2"><div class="bg-violet-100 p-1.5 rounded-lg text-violet-600"><i data-lucide="bell" class="w-3.5 h-3.5"></i></div> ${escapeHtml(title)}</strong>
                        <button onclick="this.parentElement.parentElement.classList.add('translate-x-[120%]'); setTimeout(() => this.parentElement.parentElement.remove(), 300)" class="text-slate-400 hover:text-slate-600 focus:outline-none transition-colors">
                            <i data-lucide="x" class="w-4 h-4"></i>
                        </button>
                    </div>
                    <div class="text-sm font-medium text-slate-600 leading-snug">${escapeHtml(message)}</div>
                `;
                
                toastContainer.appendChild(toast);
                lucide.createIcons({root: toast});
                setTimeout(() => toast.classList.remove('translate-x-[120%]'), 10);
                setTimeout(() => {
                    toast.classList.add('translate-x-[120%]');
                    setTimeout(() => toast.remove(), 300);
                }, 5000);
            };

            // Click handling
            notifToggle?.addEventListener('click', (e) => {
                e.stopPropagation();
                if (notifDropdown.classList.contains('hidden')) {
                    notifDropdown.classList.remove('hidden');
                    unreadCount = 0;
                    localStorage.setItem(countKey, '0');
                    shownSet = {}; shownIds = [];
                    localStorage.removeItem('notif_shown_' + userId);
                    notifBadge.classList.add('hidden');
                    if (notifList.innerHTML.includes('Loading')) fetchNotifications(false);
                } else {
                    notifDropdown.classList.add('hidden');
                }
            });

            document.addEventListener('click', (e) => {
                if (!notifDropdown?.classList.contains('hidden') && !notifDropdown.contains(e.target) && !notifToggle.contains(e.target)) {
                    notifDropdown.classList.add('hidden');
                }
            });

            // Initial fetch and start polling every 5 seconds
            fetchNotifications();
            setInterval(() => fetchNotifications(true), 5000);
            } // end notification UI guard

            // Antigravity Intersection Observer - Scroll Stacking Animations
            const animatedElements = document.querySelectorAll('.animate-slide-up-3d');
            
            const observer = new IntersectionObserver((entries) => {
                let delayIndex = 0;
                entries.forEach((entry) => {
                    const el = entry.target;
                    if (entry.isIntersecting) {
                        // Scrolled into view: run animation sequentially
                        el.style.animation = 'none';
                        void el.offsetWidth; // Reflow to restart animation safely
                        el.style.animationDelay = `${(delayIndex * 100)}ms`;
                        el.style.animation = 'slide-up-3d 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards';
                        delayIndex++;
                    } else {
                        // Scrolled out of view: hide so it can re-animate
                        el.style.animation = 'none';
                        el.style.opacity = '0';
                    }
                });
            }, {
                threshold: 0.05,
                rootMargin: '20px'
            });

            // Auto-compile observer execution
            animatedElements.forEach(el => {
                el.style.animation = 'none';
                el.style.opacity = '0';
                observer.observe(el);
            });
        });
    </script>

    <!-- ========================================
         AI ASSISTANT FLOATING WIDGET
    ======================================== -->
    <div id="aiAssistantWidget" class="fixed bottom-6 right-6 z-[9998] flex flex-col items-end gap-3">
        <!-- Chat Panel -->
        <div id="aiChatPanel" class="hidden w-[340px] bg-white rounded-3xl shadow-2xl shadow-violet-900/15 border border-slate-100 flex flex-col overflow-hidden" style="max-height:480px;">
            <!-- Header -->
            <div class="bg-gradient-to-r from-violet-600 to-indigo-700 px-5 py-4 flex items-center gap-3 shrink-0">
                <div class="w-9 h-9 bg-white/20 rounded-2xl flex items-center justify-center shrink-0">
                    <i data-lucide="bot" class="w-5 h-5 text-white"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-white font-black text-sm leading-tight">VIP AI Assistant</p>
                    <p class="text-indigo-200 text-[10px] font-medium flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 inline-block animate-pulse"></span>
                        Always online
                    </p>
                </div>
                <button id="aiPanelClose" class="text-white/70 hover:text-white transition-colors p-1">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>

            <!-- Messages -->
            <div id="aiMessages" class="flex-1 overflow-y-auto p-4 flex flex-col gap-3 custom-scrollbar" style="min-height:200px;">
                <!-- Bot greeting -->
                <div class="ai-msg flex gap-2.5 items-end">
                    <div class="w-7 h-7 bg-violet-100 rounded-full flex items-center justify-center shrink-0">
                        <i data-lucide="bot" class="w-4 h-4 text-violet-600"></i>
                    </div>
                    <div class="bg-slate-100 rounded-2xl rounded-bl-sm px-4 py-3 max-w-[80%]">
                        <p class="text-slate-800 text-sm font-medium leading-relaxed">Hi! I'm your VIP AI Assistant 👋 Ask me about your sales, inventory, or deliveries!</p>
                    </div>
                </div>
            </div>

            <!-- Suggestion Chips -->
            <div id="aiChips" class="px-4 pb-2 flex flex-wrap gap-1.5 shrink-0">
                <button class="ai-chip px-3 py-1.5 bg-violet-50 hover:bg-violet-100 text-violet-700 text-xs font-bold rounded-xl border border-violet-200 transition-all">📊 Sales today?</button>
                <button class="ai-chip px-3 py-1.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 text-xs font-bold rounded-xl border border-emerald-200 transition-all">📦 Low stocks?</button>
                <button class="ai-chip px-3 py-1.5 bg-sky-50 hover:bg-sky-100 text-sky-700 text-xs font-bold rounded-xl border border-sky-200 transition-all">🚚 Deliveries?</button>
            </div>

            <!-- Input -->
            <div class="px-4 pb-4 pt-2 shrink-0">
                <div class="flex gap-2 bg-slate-50 border border-slate-200 rounded-2xl p-1.5 focus-within:border-violet-400 focus-within:ring-2 focus-within:ring-violet-400/20 transition-all">
                    <input id="aiInput" type="text" placeholder="Ask anything..." class="flex-1 bg-transparent outline-none text-sm font-medium text-slate-800 px-2 placeholder-slate-400">
                    <button id="aiSend" class="bg-violet-600 hover:bg-violet-700 text-white w-9 h-9 rounded-xl flex items-center justify-center transition-all hover:scale-105 shrink-0">
                        <i data-lucide="send" class="w-4 h-4"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Floating Toggle Button -->
        <button id="aiToggleBtn" class="w-14 h-14 bg-gradient-to-br from-violet-600 to-indigo-700 hover:from-violet-500 hover:to-indigo-600 text-white rounded-2xl shadow-xl shadow-violet-500/30 flex items-center justify-center transition-all hover:scale-110 hover:-translate-y-1 relative group">
            <i data-lucide="bot" class="w-6 h-6 group-hover:scale-110 transition-transform" id="aiToggleIcon"></i>
            <span id="aiUnreadBadge" class="hidden absolute -top-1.5 -right-1.5 w-5 h-5 bg-rose-500 text-white text-[10px] font-black rounded-full flex items-center justify-center border-2 border-white">1</span>
        </button>
    </div>

