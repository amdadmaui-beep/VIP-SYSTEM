    <?php if ($can_rider_history): ?>
    <div id="tab-cancelled" class="tab-content staggered-group" style="display:none;">
        <div class="bg-white rounded-[24px] p-6 shadow-xl border border-slate-100 relative overflow-hidden animate-fade-in-up">
            <div class="text-center pb-6 border-b border-slate-100 mb-5">
                <div class="text-5xl font-black text-rose-500 tracking-tight"><?= count($cancelled_deliveries) ?></div>
                <div class="text-xs font-bold text-rose-500 tracking-widest uppercase mt-2">Cancelled Orders Assigned To You</div>
            </div>

            <div class="text-xs font-black text-slate-800 uppercase tracking-widest mb-4">Recent Cancelled Orders</div>
            <div class="space-y-3 staggered-group" style="max-height: 500px; overflow-y: auto; padding-right: 0.5rem;">
                <?php if (!empty($cancelled_deliveries)): ?>
                    <?php foreach ($cancelled_deliveries as $d): ?>
                        <?php
                        $cancelled_reason = trim(implode(' - ', array_filter([
                            (string)($d['cancellation_reason'] ?? ''),
                            (string)($d['cancellation_remarks'] ?? '')
                        ])));
                        $cancelled_addr = trim((string)($d['delivery_address'] ?? $d['customer_address'] ?? ''));
                        ?>
                        <div class="py-3.5 border-b border-slate-100">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <h5 class="fw-bold mb-1 text-dark fs-6" style="font-family: 'Plus Jakarta Sans', sans-serif;"><?= htmlspecialchars($d['customer_name'] ?? 'Customer') ?></h5>
                                    <p class="text-muted small mb-1">Order #<?= (int)($d['Order_ID'] ?? 0) ?> · Delivery #<?= (int)($d['Delivery_ID'] ?? 0) ?></p>
                                    <?php if ($cancelled_reason !== ''): ?>
                                        <p class="mb-1 small" style="color:#be123c;"><strong>Reason:</strong> <?= htmlspecialchars($cancelled_reason) ?></p>
                                    <?php endif; ?>
                                    <?php if ($cancelled_addr !== ''): ?>
                                        <p class="mb-0 small text-muted"><?= htmlspecialchars($cancelled_addr) ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="text-end">
                                    <span class="d-inline-block px-2 py-0.5 rounded text-xs fw-bold text-rose-700 bg-rose-50 mb-1" style="font-size: 0.65rem; letter-spacing: 0.02em;">CANCELLED</span>
                                    <div class="fw-black text-rose-600 fs-5" style="font-family: 'Plus Jakarta Sans', sans-serif;">₱<?= number_format((float)($d['total_amount'] ?? 0), 0) ?></div>
                                    <div class="small text-muted"><?= !empty($d['updated_at']) ? date('M d, Y', strtotime((string)$d['updated_at'])) : '—' ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="py-5 text-center text-muted">
                        <i class="fas fa-ban mb-3" style="font-size: 2rem; opacity: 0.5;"></i>
                        <p class="mb-0 small">No cancelled orders yet</p>
                        <p class="mb-0 text-muted" style="font-size: 0.75rem;">Cancelled deliveries assigned to you will appear here.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
