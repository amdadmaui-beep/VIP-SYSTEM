    <?php if ($can_rider_queue): ?>
    <div id="tab-queue" class="tab-content staggered-group" style="display:<?= $activeTab === 'queue' ? 'block' : 'none' ?>">
        <div class="section-heading animate-fade-in-up">Active deliveries</div>
        <?php if ($count_pending > 0): ?>
        <div class="vehicle-issue-report-card mb-3">
            <div class="vehicle-issue-report-copy">
                <strong><i class="fas fa-truck-monster"></i> Vehicle breakdown?</strong>
                <p>Report once to flag all active deliveries (Scheduled / In Transit). Delivered and remitted orders stay with you for cashier remittance. You will be set to <strong>Off Duty</strong>.</p>
            </div>
            <button type="button" class="btn-vehicle-issue" onclick="promptReportVehicleIssue()">
                <i class="fas fa-exclamation-triangle"></i> Report Vehicle Issue
            </button>
        </div>
        <?php endif; ?>
        <?php if (!empty($deliveries)): ?>
            <div id="queueList" class="staggered-group" style="max-height: 600px; overflow-y: auto; padding-right: 0.5rem;">
                <style>
                    #queueList::-webkit-scrollbar { width: 6px; }
                    #queueList::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 3px; }
                    #queueList::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
                    #queueList::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
                </style>
            <?php foreach ($deliveries as $d):
                $addr = trim((string)($d['delivery_address'] ?? ''));
                if ($addr === '') {
                    $addr = trim((string)($d['customer_address'] ?? ''));
                }
                $st = $d['delivery_status'] ?? '';
                $is_transit = $st === 'In Transit';
                $is_delivered = $st === 'Delivered';
                $is_returning = $st === 'Returning';
                $is_remitted = $st === 'Remitted';
                $is_completed = $st === 'Completed';
                $is_cancelled = $st === 'Cancelled';
                $badge_class = $is_transit ? 'transit' : ($is_delivered ? 'delivered' : ($is_returning ? 'returning' : ($is_remitted ? 'remitted' : ($is_completed ? 'delivered' : ($is_cancelled ? 'cancelled' : 'scheduled')))));
            ?>
            <div class="delivery-card <?= $is_transit ? 'in-transit' : '' ?>">
                <div class="status-pill-corner <?= $badge_class ?>"><?= $st === 'In Transit' ? 'Out for delivery' : htmlspecialchars($st) ?></div>
                <div class="card-small-header">Active Deliveries</div>
                <span class="delivery-id">Delivery #<?= (int)$d['Delivery_ID'] ?> - Order #<?= (int)($d['Order_ID'] ?? 0) ?></span>
                <div class="customer-name"><i class="far fa-user"></i><?= htmlspecialchars($d['customer_name'] ?? 'Customer') ?></div>
                <?php if ($addr): ?>
                <div class="delivery-address"><i class="fas fa-location-dot"></i><?= htmlspecialchars($addr) ?></div>
                <?php endif; ?>
                
                <?php if ($is_transit): ?>
                    <?php if (!empty($rider_maps_enabled)): ?>
                    <div class="inline-map-wrap">
                        <div class="inline-map-overlay-top">Live rider: 0.04 km away &bull; ETA 1-4 min &bull; 1 route option(s)</div>
                        <div
                            class="inline-map"
                            data-delivery-id="<?= (int)$d['Delivery_ID'] ?>"
                            data-address="<?= htmlspecialchars($addr) ?>"
                            data-customer-name="<?= htmlspecialchars((string)($d['customer_name'] ?? 'Customer')) ?>"
                            data-destination-lat="<?= htmlspecialchars((string)($d['destination_lat'] ?? '')) ?>"
                            data-destination-lng="<?= htmlspecialchars((string)($d['destination_lng'] ?? '')) ?>"
                            id="inline-map-<?= (int)$d['Delivery_ID'] ?>"
                        ></div>
                        <div class="inline-map-meta">
                            <span id="inline-map-meta-<?= (int)$d['Delivery_ID'] ?>" style="display:none;"></span>
                        </div>
                        <button type="button" class="btn-full-map" onclick="openFullMapTracking(<?= (int)$d['Delivery_ID'] ?>)">Full view</button>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <div class="payment-info">
                    <span class="payment-label">To deliver</span>
                    <span class="cod-amount">₱<?= number_format((float)($d['total_amount'] ?? 0), 0) ?></span>
                </div>
                <?php if (!empty($d['is_ar'])): ?>
                <div style="margin-top: 6px;">
                    <span style="display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 999px; font-size: 0.72rem; font-weight: 700; background: #e0f2fe; color: #0369a1; border: 1px solid #7dd3fc;">
                        <i class="fas fa-file-invoice-dollar"></i> Accounts Receivable
                    </span>
                </div>
                <?php endif; ?>
                <div class="meta">Order #<?= (int)($d['Order_ID'] ?? 0) ?>... &bull; <?= (float)($d['total_units'] ?? 0) ?> <?= htmlspecialchars($d['unit_label'] ?? 'Pieces') ?><?php if ($is_transit): ?> <i class="fas fa-clock text-purple mx-1" style="color:#0082ff;"></i> <?= (int)($d['transit_minutes'] ?? 0) ?>m out<?php endif; ?></div>
                
                <div class="action-buttons">
                    <?php if ($st === 'Scheduled'): ?>
                    <?php
                        $prep_status = (string)($d['prep_status'] ?? 'not_started');
                        $prep_status_key = strtolower(str_replace(' ', '_', trim($prep_status)));
                        $prep_ready = in_array($prep_status_key, ['ready', 'ready_for_pickup', 'ready_to_pickup'], true);
                        $prep_hint_label = 'Not prepared yet';
                        $prep_hint_class = 'bg-amber-50 text-amber-700 border border-amber-200';
                        if ($prep_status_key === 'preparing') {
                            $prep_hint_label = 'Preparing now';
                            $prep_hint_class = 'bg-blue-50 text-blue-700 border border-blue-200';
                        } elseif ($prep_ready) {
                            $prep_hint_label = 'Ready for pickup';
                            $prep_hint_class = 'bg-emerald-50 text-emerald-700 border border-emerald-200';
                        }
                    ?>
                    <div class="w-100 mb-2">
                        <span class="d-inline-flex align-items-center gap-2 px-3 py-2 rounded-pill text-[11px] fw-bold <?= $prep_hint_class ?>">
                            <i class="fas <?= $prep_ready ? 'fa-check-circle' : ($prep_status_key === 'preparing' ? 'fa-person-digging' : 'fa-lock') ?>"></i>
                            <?= htmlspecialchars($prep_hint_label, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>
                    <form method="POST" action="../api/delivery_backend.php" class="start-trip-form w-100">
                        <?php echo csrfTokenField(); ?>
                        <input type="hidden" name="action" value="update_delivery_status">
                        <input type="hidden" name="delivery_id" value="<?= (int)$d['Delivery_ID'] ?>">
                        <input type="hidden" name="new_status" value="In Transit">
                        <input type="hidden" name="redirect_to" value="../pages/rider_view.php">
                        <button
                            type="submit"
                            class="btn-rider w-100 <?= $prep_ready ? 'btn-start-trip' : 'btn-view-details' ?>"
                            <?= $prep_ready ? '' : 'disabled title="Inventory staff must mark this order as Ready to Pickup first."' ?>
                        >
                            <i class="fas <?= $prep_ready ? 'fa-motorcycle' : 'fa-lock' ?>"></i>
                            <?= $prep_ready ? 'Start Delivery' : 'Waiting for Ready' ?>
                        </button>
                    </form>
                    <?php endif; ?>
                    
                    <?php if ($is_transit): ?>
                    <button type="button" class="btn-rider-outline w-100" onclick="sendOnTheWaySms(<?= (int)$d['Delivery_ID'] ?>, '<?= htmlspecialchars(addslashes($d['customer_name'] ?? 'Customer')) ?>', '<?= htmlspecialchars(addslashes($d['phone_number'] ?? '')) ?>')">
                        <i class="fas fa-comment-dots text-primary"></i> <strong>Send On-the-Way SMS</strong>
                    </button>

                    <?php if (!empty($d['phone_number'])): ?>
                    <a href="tel:<?= htmlspecialchars(trim($d['phone_number'])) ?>" class="btn-rider-outline w-100 mt-2 text-decoration-none" style="display: flex; justify-content: center; align-items: center; gap: 0.5rem; border-color: #10b981; color: #10b981;">
                        <i class="fas fa-phone"></i> <strong>Call Customer</strong>
                    </a>
                    <?php endif; ?>
                    
                    <button type="button" class="btn-rider-outline w-100" style="color: #dc2626; border-color: #dc2626;" onclick="promptCancelDelivery(<?= (int)$d['Delivery_ID'] ?>)" title="Cancel this delivery">
                        <i class="fas fa-times-circle"></i> Cancel Delivery
                    </button>
                    
                    <div class="slide-wrap js-slide-complete mt-2" data-delivery-id="<?= (int)$d['Delivery_ID'] ?>" data-order-id="<?= (int)($d['Order_ID'] ?? 0) ?>">
                        <div class="slide-text">Slide to mark delivered</div>
                        <button type="button" class="slide-thumb" aria-label="Slide to complete"><i class="fas fa-arrow-right"></i></button>
                    </div>
                    <?php elseif ($is_delivered): ?>
                    <form method="POST" action="../api/delivery_backend.php" class="w-100">
                        <?php echo csrfTokenField(); ?>
                        <input type="hidden" name="action" value="update_delivery_status">
                        <input type="hidden" name="delivery_id" value="<?= (int)$d['Delivery_ID'] ?>">
                        <input type="hidden" name="new_status" value="Remitted">
                        <input type="hidden" name="redirect_to" value="../pages/rider_view.php">
                        <button type="submit" class="btn-rider btn-start-trip w-100"><i class="fas fa-wallet"></i> Remitted (Finishing)</button>
                    </form>
                    <button type="button" class="btn-rider btn-view-details w-100" onclick="openReadOnlyModal(<?= (int)$d['Delivery_ID'] ?>, <?= (int)($d['Order_ID'] ?? 0) ?>)"><i class="fas fa-list-ul"></i> View Details</button>
                    <?php elseif ($is_returning): ?>
                    <button type="button" class="btn-rider btn-complete w-100" onclick="acknowledgeReturnToStore(<?= (int)$d['Delivery_ID'] ?>)"><i class="fas fa-box-open"></i> Return to Store (Finishing)</button>
                    <?php elseif ($is_remitted): ?>
                    <button type="button" class="btn-rider btn-view-details w-100" disabled style="opacity: 0.7;">
                        <i class="fas fa-clock"></i> Waiting for Cashier...
                    </button>
                    <?php elseif ($is_completed): ?>
                    <button type="button" class="btn-rider btn-view-details w-100" disabled>
                        <i class="fas fa-check-double"></i> Completed (Recorded)
                    </button>
                    <?php else: ?>
                    <button type="button" class="btn-rider btn-view-details w-100" onclick="openReadOnlyModal(<?= (int)$d['Delivery_ID'] ?>, <?= (int)($d['Order_ID'] ?? 0) ?>)"><i class="fas fa-list-ul"></i> View Details</button>
                    <?php endif; ?>
                    <?php if (!empty($has_delivery_damage_reports) && !$is_cancelled): ?>
                    <button type="button" class="btn-rider-outline w-100" onclick="openDamageReportModal(<?= (int)$d['Delivery_ID'] ?>)">
                        <i class="fas fa-exclamation-triangle" style="color:#f59e0b"></i> Report delivery damage
                    </button>
                    <?php endif; ?>
                    <?php if ($st === 'Scheduled'): ?>
                    <button type="button" class="btn-rider-outline w-100" style="color: #dc2626; border-color: #dc2626;" onclick="promptCancelDelivery(<?= (int)$d['Delivery_ID'] ?>)" title="Cancel this scheduled delivery">
                        <i class="fas fa-times-circle"></i> Cancel Delivery
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
            <div id="queuePagination" style="display: flex; justify-content: center; align-items: center; gap: 0.5rem; padding: 1rem; border-top: 1px solid var(--rider-border); margin-top: 1rem;">
                <button id="queuePrev" class="btn btn-sm btn-outline-secondary" onclick="changeQueuePage(-1)"><i class="fas fa-chevron-left"></i></button>
                <span id="queuePageInfo" style="font-size: 0.85rem; color: var(--rider-muted);">Page 1 of 1</span>
                <button id="queueNext" class="btn btn-sm btn-outline-secondary" onclick="changeQueuePage(1)"><i class="fas fa-chevron-right"></i></button>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-box-open d-block"></i>
                <div class="lead">No active deliveries</div>
                <p>Check back later for new assignments.</p>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
