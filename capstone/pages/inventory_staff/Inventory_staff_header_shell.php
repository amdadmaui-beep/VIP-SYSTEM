<body class="text-slate-800 antialiased max-w-lg mx-auto relative shadow-xl min-h-screen bg-white md:bg-slate-50">

    <!-- Sticky Header -->
    <header class="glass-header sticky top-0 z-40 px-5 pt-6 pb-4 border-b border-slate-100 shadow-sm">
        <?php
        $navTab = 'dashboard';
        if (isset($_GET['tab'])) {
            if ($_GET['tab'] === 'inventory') $navTab = 'inventory';
            if ($_GET['tab'] === 'dashboard') $navTab = 'dashboard';
            if ($_GET['tab'] === 'history') $navTab = 'history';
        }
        $INV_CHROME = [
            'display_name' => $display_name,
            'display_role' => $display_role,
            'session_label' => 'Inventory Session',
            'nav_active' => $navTab,
            'inventory_href' => 'inventory_staff.php?tab=inventory',
            'dashboard_href' => 'inventory_staff.php?tab=dashboard',
            'history_href' => 'inventory_staff.php?tab=history',
            'history_nav_id' => 'invNavHistory',
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