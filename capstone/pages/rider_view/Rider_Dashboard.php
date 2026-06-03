    <!-- Dashboard Tab -->
    <?php if ($can_rider_dashboard): ?>
    <div id="tab-dashboard" class="tab-content staggered-group">
        <div class="row g-2 mb-3 staggered-group">
            <div class="col-6">
                <div class="stat-card bg-blue">
                    <div class="stat-header">
                        <div class="stat-value"><?= $completed_today ?></div>
                        <div class="stat-icon"><i class="fas fa-check"></i></div>
                    </div>
                    <div class="stat-label">Completed Today</div>
                </div>
            </div>
            <div class="col-6">
                <div class="stat-card bg-green">
                    <div class="stat-header">
                        <div class="stat-value">₱<?= number_format($expected_remittance, 0) ?></div>
                        <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
                    </div>
                    <div class="stat-label">Expected Remittance</div>
                    <span class="stat-sub">(Collected: ₱<?= number_format($collections_today, 0) ?>)</span>
                </div>
            </div>
            <div class="col-6">
                <div class="stat-card bg-yellow">
                    <div class="stat-header">
                        <div class="stat-value"><?= $count_pending ?></div>
                        <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
                    </div>
                    <div class="stat-label">Pending</div>
                </div>
            </div>
            <div class="col-6">
                <div class="stat-card bg-orange">
                    <div class="stat-header">
                        <div class="stat-value"><?= $count_transit ?></div>
                        <div class="stat-icon"><i class="fas fa-arrow-right"></i></div>
                    </div>
                    <div class="stat-label">On the Way</div>
                </div>
            </div>
            <div class="col-12 mt-2">
                <div class="stat-card <?php echo $rider_availability_status === 'Off Duty' ? 'bg-danger' : ($rider_availability_status === 'Available' ? 'bg-success' : 'bg-secondary'); ?>" style="padding:0.75rem 1rem;">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="stat-label" style="color:rgba(255,255,255,0.75); font-size:0.7rem; text-transform:uppercase; letter-spacing:0.05em;">Status</span>
                            <div style="font-weight:700; color:#fff; font-size:1.1rem;">
                                <i class="fas <?php echo $rider_availability_status === 'Available' ? 'fa-check-circle' : ($rider_availability_status === 'Off Duty' ? 'fa-clock' : 'fa-truck'); ?>" style="margin-right:0.35rem;"></i>
                                <?php echo htmlspecialchars($rider_availability_status); ?>
                            </div>
                        </div>
                        <?php if ($rider_availability_status === 'Off Duty'): ?>
                            <button type="button" onclick="backToDuty()" class="btn btn-light btn-sm" style="font-weight:700; border-radius:999px; padding:0.4rem 1.2rem;">
                                <i class="fas fa-undo-alt"></i> Back to Duty
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="delivery-queue-card mb-4" onclick="switchToTab('queue')">
            <div class="card-content">
                <h2 class="card-title">Ready for your next delivery?</h2>
                <p class="card-subtitle">You have <?= $count_pending ?> order<?= $count_pending != 1 ? 's' : '' ?> waiting in<br>your queue.</p>
                <button type="button" class="btn-view-queue">
                    View Queue <i class="fas fa-arrow-right"></i>
                </button>
            </div>
            <div class="card-illustration">
                <svg viewBox="0 0 160 140" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="120" cy="110" r="70" fill="rgba(255,255,255,0.12)" opacity="1"/>
                    <line x1="8" y1="72" x2="42" y2="72" stroke="white" stroke-width="4.5" stroke-linecap="round" opacity="0.95"/>
                    <line x1="12" y1="84" x2="35" y2="84" stroke="white" stroke-width="4.5" stroke-linecap="round" opacity="0.8"/>
                    <line x1="16" y1="96" x2="28" y2="96" stroke="white" stroke-width="4.5" stroke-linecap="round" opacity="0.7"/>
                    
                    <path d="M32 52 H 104 V 104 H 32 Z" fill="#6d28d9" rx="3"/>
                    <path d="M104 72 H 118 C 123 72 127 75 130 80 L 140 95 V 114 H 104 Z" fill="#4c1d95" />
                    <path d="M111 78 H 120 C 123 78 125 80 127 84 L 132 93 H 111 Z" fill="#FFFFFF" />
                    
                    <path d="M32 104 H 140 V 114 C 140 116.2 138.2 118 136 118 H 36 C 33.8 118 32 116.2 32 114 Z" fill="#4c1d95"/>
                    
                    <circle cx="56" cy="114" r="11.5" fill="#4c1d95" />
                    <circle cx="56" cy="114" r="4.8" fill="#FFFFFF" />
                    
                    <circle cx="112" cy="114" r="11.5" fill="#4c1d95" />
                    <circle cx="112" cy="114" r="4.8" fill="#FFFFFF" />
                </svg>
            </div>
        </div>
        <div class="section-heading text-muted mt-2 animate-fade-in-up"><i class="fas fa-list mt-1 me-1"></i> Pending — Assigned To You</div>
        <?php if (!empty($deliveries)): ?>
            <div class="up-next-list mb-4 staggered-group">
                <?php foreach (array_slice($deliveries, 0, 5) as $d):
                    $st = $d['delivery_status'] ?? '';
                    $st_label = $st === 'In Transit' ? 'Out for delivery' : ($st === 'Remitted' ? 'Remitted' : $st);
                    $badge_class = $st === 'In Transit' ? 'transit' : ($st === 'Scheduled' ? 'scheduled' : ($st === 'Delivered' ? 'delivered' : ($st === 'Remitted' ? 'remitted' : 'returning')));
                ?>
                <div class="up-next-item" role="button" onclick="openReadOnlyModal(<?= (int)$d['Delivery_ID'] ?>, <?= (int)($d['Order_ID'] ?? 0) ?>)">
                    <div class="up-next-header">
                        <div class="customer"><?= htmlspecialchars($d['customer_name'] ?? 'Customer') ?></div>
                        <div class="status-badge-wrap"><span class="badge-status <?= $badge_class ?>"><?= htmlspecialchars(strtoupper($st_label)) ?></span></div>
                    </div>
                    <div class="up-next-details">
                        <div class="up-next-info">
                            Order #<?= (int)($d['Order_ID'] ?? 0) ?><br>
                            <?= (float)($d['total_units'] ?? 0) ?> <?= htmlspecialchars($d['unit_label'] ?? 'Pieces') ?>
                        </div>
                        <div class="up-next-price">₱<?= number_format((float)($d['total_amount'] ?? 0), 0) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <svg width="88" height="88" viewBox="0 0 88 88" fill="none" xmlns="http://www.w3.org/2000/svg" style="margin-bottom:.75rem;">
                    <rect x="10" y="18" width="68" height="44" rx="10" fill="#EEF2FF"/>
                    <rect x="18" y="26" width="52" height="8" rx="4" fill="#C7D2FE"/>
                    <rect x="18" y="38" width="34" height="8" rx="4" fill="#A5B4FC"/>
                    <circle cx="28" cy="70" r="7" fill="#4F46E5"/>
                    <circle cx="60" cy="70" r="7" fill="#4F46E5"/>
                </svg>
                <div class="lead">No deliveries in queue</div>
                <p>You're all set for now.</p>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
