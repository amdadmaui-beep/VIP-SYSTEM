<body>
<div class="rider-wrapper">
    <div class="rider-header">
        <div class="d-flex align-items-center gap-3">
            <?php if (!empty($rider_has_profile_pic) && !empty($rider_profile_pic_src)): ?>
            <img class="rider-avatar rider-avatar--photo" src="<?= htmlspecialchars($rider_profile_pic_src, ENT_QUOTES, 'UTF-8') ?>" alt="">
            <?php else: ?>
            <div class="rider-avatar"><?= strtoupper(substr($full_name, 0, 1)) ?></div>
            <?php endif; ?>
            <div class="rider-info">
                <?php $hour = (int)date('G'); $greet = $hour < 12 ? 'Morning' : ($hour < 17 ? 'Afternoon' : 'Evening'); ?>
                <div class="greeting"><?= $greet ?></div>
                <h1 class="name"><?= htmlspecialchars($full_name) ?></h1>
                <p class="role">Delivery Rider · VIP Ice Plant</p>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="profile_settings.php" class="btn-notification" title="Profile Settings"><i class="fas fa-user-cog"></i></a>
            <div class="dropdown d-inline-block">
                <button class="btn-notification position-relative" type="button" id="notificationDropdown" aria-expanded="false" title="Notifications">
                    <i class="fas fa-bell"></i>
                    <span id="notificationBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="display: none; font-size: 0.6rem; transform: translate(-30%, -20%) !important;">
                        0
                    </span>
                </button>
                <div class="dropdown-menu dropdown-menu-rider" aria-labelledby="notificationDropdown">
                    <h6 class="dropdown-header">Notifications</h6>
                    <div id="notificationList" style="max-height: 250px; overflow-y: auto;">
                        <div id="noNotifItem" class="p-3 text-center text-muted small">No new notifications</div>
                    </div>
                </div>
            </div>
            <a href="../logout.php" class="btn-logout"><i class="fas fa-sign-out-alt me-1"></i> Logout</a>
        </div>
    </div>

    <nav class="nav-tabs-rider">
        <?php if ($can_rider_dashboard): ?>
        <button type="button" class="nav-tab-rider active" data-tab="dashboard"><i class="fas fa-th-large me-1"></i> Dashboard</button>
        <?php endif; ?>
        <?php if ($can_rider_queue): ?>
        <button type="button" class="nav-tab-rider" data-tab="queue"><i class="fas fa-truck me-1"></i> Queue</button>
        <?php endif; ?>
        <?php if ($can_rider_history): ?>
        <button type="button" class="nav-tab-rider" data-tab="history"><i class="fas fa-clock-rotate-left me-1"></i> Delivered History</button>
        <?php endif; ?>
        <?php if ($can_rider_history): ?>
        <button type="button" class="nav-tab-rider" data-tab="cancelled"><i class="fas fa-ban me-1"></i> Cancelled Orders</button>
        <?php endif; ?>
        <?php if (!empty($has_delivery_damage_reports)): ?>
        <button type="button" class="nav-tab-rider" data-tab="damage-reports"><i class="fas fa-clipboard-list me-1"></i> My Damage Reports</button>
        <?php endif; ?>
    </nav>


