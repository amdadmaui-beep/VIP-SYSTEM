<?php if (!empty($has_delivery_damage_reports)): ?>
<div id="tab-damage-reports" class="tab-content staggered-group" style="display:none;">
    <div class="row g-2 mb-4 staggered-group">
        <div class="col-6">
            <div class="stat-card bg-blue shadow-lg">
                <div class="stat-header">
                    <div class="stat-value"><?= wholeNumber($damage_report_total) ?></div>
                    <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
                </div>
                <div class="stat-label">Total Reports</div>
            </div>
        </div>
        <div class="col-6">
            <div class="stat-card bg-green shadow-lg">
                <div class="stat-header">
                    <div class="stat-value"><?= wholeNumber($damage_report_approved) ?></div>
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                </div>
                <div class="stat-label">Approved</div>
            </div>
        </div>
        <div class="col-6">
            <div class="stat-card bg-yellow shadow-lg">
                <div class="stat-header">
                    <div class="stat-value"><?= wholeNumber($damage_report_pending) ?></div>
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                </div>
                <div class="stat-label">Pending</div>
            </div>
        </div>
        <div class="col-6">
            <div class="stat-card bg-orange shadow-lg">
                <div class="stat-header">
                    <div class="stat-value"><?= wholeNumber($damage_report_rejected) ?></div>
                    <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
                </div>
                <div class="stat-label">Rejected</div>
            </div>
        </div>
    <div class="text-xs font-black text-slate-800 uppercase tracking-widest mb-4 flex items-center justify-between animate-fade-in-up">
        <span>Damage History</span>
        <span class="text-slate-400 font-bold"><?= count($my_delivery_damage_reports) ?> items</span>
    </div>
    
    <div id="damageList" class="space-y-4 pb-10 staggered-group">
        <?php if (empty($my_delivery_damage_reports)): ?>
            <div class="empty-state">
                <i class="fas fa-clipboard-check d-block"></i>
                <div class="lead">No reports found</div>
                <p>You haven't submitted any damage reports yet.</p>
            </div>
        <?php else: ?>
            <?php foreach ($my_delivery_damage_reports as $ddr): 
                $st = $ddr['status'] ?? '';
                $label = $st === 'pending_review' ? 'In Review' : ($st === 'approved' ? 'Approved' : 'Rejected');
                $statusColor = $st === 'pending_review' ? 'amber' : ($st === 'approved' ? 'emerald' : 'rose');
                $unitName = trim((string)($ddr['unit_name'] ?? ''));
            ?>
                <div class="up-next-item">
                    <div class="up-next-header">
                        <div class="customer">
                            <?= htmlspecialchars($ddr['product_name'] ?? 'Unknown product') ?>
                            <?php if (!empty($ddr['photo_path'])): ?>
                                <span class="badge rounded-pill bg-primary/10 text-primary text-[8px] px-2 py-0.5 ml-1" style="background: rgba(37, 99, 235, 0.1); border: 1px solid rgba(37, 99, 235, 0.2);">PHOTO</span>
                            <?php endif; ?>
                        </div>
                        <div class="status-badge-wrap">
                            <span class="badge-status <?= $statusColor === 'amber' ? 'transit' : ($statusColor === 'emerald' ? 'delivered' : 'cancelled') ?>">
                                <?= strtoupper($label) ?>
                            </span>
                        </div>
                    </div>
                    <div class="up-next-details">
                        <div class="up-next-info">
                            Order #<?= (int)($ddr['Order_ID'] ?? 0) ?><br>
                            <?= date('M j, Y', strtotime($ddr['submitted_at'] ?? 'now')) ?><br>
                            <span class="text-rose-600 font-bold"><?= wholeNumber($ddr['damaged_qty'] ?? 0) ?> <?= htmlspecialchars($unitName ?: 'Units') ?> Damaged</span>
                        </div>
                    </div>
                    <div class="mt-2 text-[0.75rem] color-muted border-t pt-2" style="border-color: var(--rider-border);">
                        <div class="mb-2">
                            <span class="text-slate-400 font-bold uppercase text-[9px] tracking-wider">Reason</span>
                            <div class="text-slate-700"><?= htmlspecialchars($ddr['reason'] ?? 'No reason provided') ?></div>
                        </div>

                        <?php if (!empty($ddr['staff_notes'])): ?>
                            <div class="mt-2 p-2.5 bg-slate-50 rounded-[12px] border border-slate-200/50">
                                <div class="text-[9px] uppercase font-bold text-primary tracking-widest mb-1 flex items-center gap-1">
                                    <i class="fas fa-comment-dots"></i> Staff Response
                                </div>
                                <div class="text-slate-600 italic">"<?= htmlspecialchars($ddr['staff_notes']) ?>"</div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($ddr['photo_path'])): ?>
                            <div class="mt-3">
                                <button type="button" class="btn btn-sm btn-outline-primary rounded-xl px-4 py-2 w-100 text-[11px] fw-bold shadow-sm hover:shadow-md transition-all active:scale-95 flex items-center justify-center gap-2" onclick="viewDamagePhoto('<?= htmlspecialchars($ddr['photo_path'], ENT_QUOTES, 'UTF-8') ?>')">
                                    <i class="fas fa-image"></i> VIEW DAMAGE EVIDENCE
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
