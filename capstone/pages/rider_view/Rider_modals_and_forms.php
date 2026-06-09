
<!-- Delivery Detail Modal -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content rounded-[24px] border-0">
            <div class="modal-header border-0 bg-white px-6 pt-6 pb-0">
                <h5 class="modal-title font-bold text-dark flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center text-blue-500">
                        <i class="fas fa-truck-ramp-box"></i>
                    </div>
                    Confirm Delivery
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-6 pt-4 pb-6">
                <div class="space-y-5">
                    <!-- Photo Section -->
                    <div class="bg-slate-50 rounded-[20px] p-4 border border-slate-100 text-center">
                        <label class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-3 block">Proof of Delivery (Photo) <span class="text-rose-500">*</span></label>
                        <div class="relative group">
                            <input type="file" id="proofPhoto" class="hidden" accept="image/*" capture="environment" multiple>
                            <button type="button" class="w-full py-8 border-2 border-dashed border-slate-200 rounded-[16px] bg-white text-slate-400 hover:border-blue-400 hover:text-blue-500 transition-all flex flex-col items-center gap-2" onclick="document.getElementById('proofPhoto').click()">
                                <i class="fas fa-camera text-2xl"></i>
                                <span class="text-xs font-bold uppercase tracking-wider">Take Photo / Upload (Multiple)</span>
                            </button>
                            <div id="proofPreview" class="mt-3 hidden">
                                <div id="proofPreviewGrid" class="grid grid-cols-2 md:grid-cols-3 gap-2"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Customer Info -->
                    <div class="bg-white rounded-[20px] p-4 border border-slate-100 shadow-sm">
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 rounded-full bg-violet-50 flex items-center justify-center text-violet-500 flex-shrink-0">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="flex-grow">
                                <h6 class="font-bold text-dark mb-0.5" id="detailCustomerName"></h6>
                                <div class="text-xs text-slate-400 flex items-center gap-1 mb-2" id="detailCustomerPhone"></div>
                                <div class="text-xs text-slate-600 bg-slate-50 p-2 rounded-lg border border-slate-100" id="detailAddress"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Items Checklist -->
                    <div>
                        <h6 class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-3 flex items-center gap-2">
                            <i class="fas fa-clipboard-list"></i> Item Checklist
                        </h6>
                        <div id="detailItems" class="space-y-2"></div>
                    </div>

                    <!-- Payment Info -->
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-blue-50/50 rounded-[20px] p-4 border border-blue-100/50">
                            <label class="text-[10px] font-black text-blue-400 uppercase tracking-widest mb-1 block">Expected</label>
                            <div id="detailTotalDisplay" class="text-xl font-black text-blue-600 tracking-tight">₱0.00</div>
                        </div>
                        <div class="bg-white rounded-[20px] p-4 border border-slate-200" id="collectInputGroup">
                            <label id="collectLabel" class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Amount to Collect</label>
                            <input type="number" step="0.01" min="0" id="amountToCollect" class="form-control border-0 p-0 font-black text-xl text-dark focus:ring-0" placeholder="0.00">
                        </div>
                    </div>
                    <div id="arInfo">
                        <div id="arCollectNote" class="bg-amber-50/50 rounded-[12px] p-3 border border-amber-200/50 text-xs text-amber-700 font-medium flex items-start gap-2">
                            <i class="fas fa-file-invoice-dollar mt-0.5"></i>
                            <span>AR order — collect only if customer pays upfront or partially</span>
                        </div>
                        <div id="arRevealLink" style="margin-top:8px; text-align:center; display:none;">
                            <a href="#" class="text-xs text-blue-600 font-semibold" onclick="toggleArCollectInput(); return false;">
                                <i class="fas fa-hand-holding-dollar"></i> Customer paid? Enter amount
                            </a>
                        </div>
                    </div>

                    <!-- Recipient & Remarks -->
                    <div class="space-y-4">
                        <div>
                            <label class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-1.5 block">Delivered To <span class="text-rose-500">*</span></label>
                            <select id="deliveredTo" class="form-select rounded-xl border-slate-200 py-2.5 font-medium"></select>
                            <input type="text" id="deliveredToOther" class="form-control mt-2 rounded-xl border-slate-200 hidden" placeholder="Enter recipient name">
                        </div>
                        <div>
                            <label class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-1.5 block">Remarks (optional)</label>
                            <textarea id="deliveryRemarks" class="form-control rounded-xl border-slate-200 font-medium" rows="2" placeholder="Any delivery notes..."></textarea>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 px-6 pb-6 pt-2 gap-3 flex-nowrap">
                <button type="button" id="btnCancelDeliveryModal" class="btn btn-danger rounded-xl font-bold px-4 py-3 text-white border-0 bg-rose-600 hover:bg-rose-700 shadow-lg shadow-rose-600/20 hover:shadow-rose-600/30 active:scale-95 transition-all flex-1 flex items-center justify-center gap-2" onclick="promptCancelDelivery(currentDeliveryId); detailModal.hide();">
                    <i class="fas fa-times-circle"></i> Cancel Delivery
                </button>
                <button type="button" class="btn btn-primary rounded-xl font-bold px-6 py-3 bg-blue-600 text-white border-0 shadow-lg shadow-blue-600/20 hover:shadow-blue-600/30 active:scale-95 transition-all flex-1 flex items-center justify-center gap-2" id="btnConfirmDelivery">
                    <i class="fas fa-check-circle"></i> Confirm Delivery
                </button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/Rider_Damage_report.php'; ?>
</div> <!-- rider-wrapper end -->

<!-- COD Confirmation Modal -->
<div class="modal fade" id="codModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-[24px] border-0">
            <div class="modal-header border-0 bg-white px-6 pt-6 pb-0">
                <h5 class="modal-title font-bold text-dark flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center text-emerald-500">
                        <i class="fas fa-hand-holding-dollar"></i>
                    </div>
                    Confirm Collection
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-6 pt-4 pb-6 text-center">
                <div class="bg-emerald-50/50 rounded-[24px] p-6 border border-emerald-100/50 mb-4">
                    <p class="text-emerald-600/70 text-[11px] font-black uppercase tracking-widest mb-2">Total Amount to Collect</p>
                    <div class="text-4xl font-black text-emerald-600 tracking-tight" id="codAmount">₱0.00</div>
                </div>
                <p class="text-slate-500 text-sm font-medium">Please confirm that you have received this exact amount in cash from the customer.</p>
            </div>
            <div class="modal-footer border-0 px-6 pb-6 pt-0">
                <button type="button" class="btn btn-light rounded-xl font-bold px-4 py-3 text-slate-500 border border-slate-100 flex-grow" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success rounded-xl font-bold px-6 py-3 bg-emerald-600 text-white border-0 shadow-lg shadow-emerald-600/20 hover:shadow-emerald-600/30 active:scale-95 transition-all flex-[2] flex items-center justify-center gap-2" id="btnCodConfirmed">
                    <i class="fas fa-check-circle"></i> Yes, I collected it
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Fullscreen tracking modal -->
<div class="modal fade" id="fullMapModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-sm-down modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-map-location-dot me-2"></i>Live Delivery Tracking</h5>
                <div class="full-map-actions">
                    <button type="button" class="btn-map-action" onclick="locateRiderNow()"><i class="fas fa-location-crosshairs me-1"></i>Locate rider</button>
                    <button type="button" class="btn-map-action" onclick="fitRiderAndDestination()"><i class="fas fa-up-right-and-down-left-from-center me-1"></i>Fit rider + destination</button>
                    <button type="button" class="btn-map-action" id="btnMapStyleToggle" onclick="toggleMapStyle()"><i class="fas fa-layer-group me-1"></i>Realistic</button>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2 full-map-body">
                <div class="full-map-header">
                    <div>
                        <div class="title">Live Route Monitor</div>
                        <div class="subtitle">Rider truck, destination store, and route ETA</div>
                    </div>
                    <span class="badge text-bg-light">Real-time</span>
                </div>
                <div class="full-map-tools">
                    <input type="text" id="fullMapSearchInput" class="full-map-search" placeholder="Search destination (e.g. Uno Halo Halo Tablon)">
                    <button type="button" class="btn-map-search" onclick="searchDestinationInFullMap()"><i class="fas fa-search me-1"></i>Search</button>
                    <button type="button" class="btn-map-pin" id="btnPinMode" onclick="togglePinMode()"><i class="fas fa-map-pin me-1"></i>Pin</button>
                </div>
                <div class="map-hint" id="fullMapHintText">Tip: Search location or tap Pin then click exact store location on map.</div>
                <div class="full-map-shell">
                    <div id="fullTrackingMap"></div>
                    <div class="map-overlay-card map-status" id="fullMapStatusChip">Waiting for rider and destination...</div>
                    <div class="map-overlay-card map-legend">
                        <div class="legend-item"><span class="legend-dot rider"></span> Rider Truck</div>
                        <div class="legend-item"><span class="legend-dot destination"></span> Destination</div>
                        <div class="legend-item"><span class="legend-line"></span> Delivery Route</div>
                    </div>
                </div>
                <div class="small text-muted mt-2 px-1" id="fullMapMeta">Waiting for rider and destination...</div>
                <div class="small text-muted px-1">ETA is an estimate only. Actual arrival may vary due to traffic, weather, road conditions, and stop delays.</div>
                <div class="route-panel">
                    <div class="route-title">Routing Guidance</div>
                    <div class="route-summary" id="routeSummaryText">Waiting for route details...</div>
                    <ol class="route-steps" id="routeStepsList"></ol>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- View Only Delivery Details Modal -->
<div class="modal fade" id="viewOnlyModal" tabindex="-1" data-bs-backdrop="false">
    <div class="modal-dialog modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content border-0 rounded-[24px]">
            <div class="modal-header border-b border-light bg-light/50 px-5 py-4">
                <h5 class="modal-title font-bold text-dark flex items-center gap-2">
                    <i data-lucide="info" class="w-5 h-5 text-indigo-500"></i> Delivery Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-5">
                <div class="bg-indigo-50/50 rounded-[16px] p-4 mb-4 border border-indigo-100/50">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="w-10 h-10 rounded-full bg-white text-indigo-600 flex items-center justify-center shrink-0 shadow-sm">
                            <i data-lucide="user" class="w-5 h-5"></i>
                        </div>
                        <div>
                            <strong id="voCustomerName" class="text-dark d-block"></strong>
                            <small id="voCustomerPhone" class="text-muted"></small>
                        </div>
                    </div>
                    <div class="d-flex align-items-start gap-3">
                        <i data-lucide="map-pin" class="w-5 h-5 text-indigo-400 mt-1 shrink-0"></i>
                        <span id="voAddress" class="text-secondary small"></span>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center bg-emerald-50 rounded-[16px] p-4 mb-4 border border-emerald-100/50">
                    <div>
                        <span class="d-block small text-emerald-600 font-semibold mb-1 uppercase tracking-wider">Total Amount</span>
                        <span id="voTotalAmount" class="font-black text-2xl text-emerald-700">₱0.00</span>
                    </div>
                    <div class="w-12 h-12 bg-white rounded-full flex items-center justify-center text-emerald-500 shadow-sm shadow-emerald-500/10">
                        <i data-lucide="banknote" class="w-6 h-6"></i>
                    </div>
                </div>

                <div id="voProofBlock" style="display:none;" class="mb-4">
                    <h6 class="font-bold text-sm text-slate-500 uppercase tracking-widest mb-3 flex items-center gap-2">
                        <i data-lucide="camera" class="w-4 h-4"></i> Proof of Delivery
                    </h6>
                    <div id="voProofGallery" class="grid grid-cols-2 md:grid-cols-3 gap-2"></div>
                </div>

                <h6 class="font-bold text-sm text-slate-500 uppercase tracking-widest mb-3 flex items-center gap-2">
                    <i data-lucide="package-check" class="w-4 h-4"></i> Delivered Items
                </h6>
                <div id="voItems" class="space-y-3"></div>
            </div>
            <div class="modal-footer border-t border-light bg-light/30 px-5 py-4">
                <button type="button" class="btn btn-primary rounded-xl font-semibold px-4 py-2" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- History Detail Modal -->
<div class="modal fade" id="collectionDetailModal" tabindex="-1" data-bs-backdrop="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-[24px]" style="box-shadow: none !important;">
            <div class="modal-header border-0 px-5 py-4">
                <h5 class="modal-title font-bold text-dark flex items-center gap-2">
                    <i data-lucide="receipt" class="w-5 h-5 text-violet-500"></i> History Record <span id="cdDeliveryId" class="text-muted text-sm font-normal ml-2"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-5 space-y-4">
                
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-muted small flex items-center gap-1"><i data-lucide="calendar" class="w-4 h-4"></i> Delivered on</span>
                    <strong id="cdDate" class="text-dark"></strong>
                </div>
                
                <div class="bg-violet-50/50 rounded-[16px] p-4 border border-violet-100/50">
                    <div class="mb-3">
                        <label class="text-xs text-violet-500 uppercase font-bold tracking-wider mb-1 block">Customer</label>
                        <span id="cdCustomer" class="text-dark font-medium block"></span>
                    </div>
                    <div id="cdDeliveredToRow" class="mb-3" style="display:none;">
                        <label class="text-xs text-violet-500 uppercase font-bold tracking-wider mb-1 block">Received By</label>
                        <span id="cdDeliveredTo" class="text-dark font-medium block"></span>
                    </div>
                    <div>
                        <label class="text-xs text-violet-500 uppercase font-bold tracking-wider mb-1 block"><i data-lucide="map-pin" class="w-3 h-3 inline"></i> Location</label>
                        <span id="cdAddress" class="text-dark font-medium block text-sm"></span>
                    </div>
                </div>

                <div class="bg-gray-50 rounded-[16px] p-4 border border-gray-100 text-center">
                    <label class="text-xs text-gray-500 uppercase font-bold tracking-wider mb-1 block">Amount Paid/Collected</label>
                    <span id="cdAmount" class="font-black text-3xl text-dark">₱0.00</span>
                </div>

            </div>
            <div class="modal-footer border-t border-light bg-light/30 px-5 py-4">
                <button type="button" class="btn btn-light rounded-xl font-semibold px-4 py-2 w-100" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<form id="statusForm" method="POST" action="../api/delivery_backend.php" style="display:none;">
    <?php echo csrfTokenField(); ?>
    <input type="hidden" name="action" value="update_delivery_status">
    <input type="hidden" name="delivery_id" id="form_delivery_id">
    <input type="hidden" name="new_status" id="form_new_status">
    <input type="hidden" name="redirect_to" value="../pages/rider_view.php">
</form>

<?php if (!empty($has_delivery_damage_reports)): ?>
<div class="modal fade" id="damageReportModal" tabindex="-1" aria-labelledby="damageReportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-[24px] border-0">
            <div class="modal-header border-0 bg-white px-6 pt-6 pb-0">
                <h5 class="modal-title font-bold text-dark flex items-center gap-3" id="damageReportModalLabel">
                    <div class="w-10 h-10 rounded-xl bg-orange-50 flex items-center justify-center text-orange-500">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    Report delivery damage
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body px-6 pt-4 pb-6">
                <input type="hidden" id="ddr_delivery_id" value="">
                
                <div class="space-y-4">
                    <div>
                        <label class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-1.5 block">Item (order line)</label>
                        <select class="form-select rounded-xl border-slate-200 focus:border-orange-400 focus:ring-orange-400/10 transition-all font-medium py-2.5" id="ddr_order_detail_id"></select>
                    </div>
                    
                    <div>
                        <label class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-1.5 block">Damaged quantity</label>
                        <div class="relative">
                            <input type="number" class="form-control rounded-xl border-slate-200 focus:border-orange-400 focus:ring-orange-400/10 transition-all font-bold text-lg py-2.5 pl-4" id="ddr_qty" min="1" step="1" inputmode="numeric" placeholder="0">
                            <div class="absolute right-4 top-1/2 -translate-y-1/2 text-xs font-bold text-slate-400" id="ddr_qty_unit">UNITS</div>
                        </div>
                        <div class="text-[10px] text-slate-400 mt-1.5 font-medium ml-1" id="ddr_qty_hint"></div>
                    </div>
                    
                    <div>
                        <label class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-1.5 block">Reason for Damage</label>
                        <textarea class="form-control rounded-xl border-slate-200 focus:border-orange-400 focus:ring-orange-400/10 transition-all font-medium" id="ddr_reason" rows="3" placeholder="Describe what happened to the item..."></textarea>
                    </div>
                    
                    <div class="bg-slate-50 rounded-[20px] p-4 border border-slate-100">
                        <label class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2 block flex items-center gap-2">
                            <i class="fas fa-camera text-slate-400"></i> Photo Evidence (optional)
                        </label>
                        <input type="file" class="form-control form-control-sm rounded-lg border-slate-200 bg-white" id="ddr_photo" accept="image/jpeg,image/png,image/webp" multiple onchange="previewDamagePhoto(this)">
                        <div class="text-[10px] text-slate-400 mt-1.5 font-medium">You can select multiple photos</div>
                        <div id="ddr_photo_preview_wrap" class="mt-3 hidden">
                            <div class="relative inline-block w-full">
                                <img id="ddr_photo_preview" src="" alt="Preview" class="w-full h-40 object-cover rounded-[16px] shadow-sm border border-slate-200">
                                <button type="button" class="absolute -top-2 -right-2 w-8 h-8 rounded-full bg-rose-500 text-white shadow-lg flex items-center justify-center hover:bg-rose-600 transition-colors" onclick="removeDamagePhoto()">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 px-6 pb-6 pt-0">
                <button type="button" class="btn btn-light rounded-xl font-bold px-4 py-2.5 text-slate-500 border border-slate-100 flex-grow" data-bs-dismiss="modal">Discard</button>
                <button type="button" class="btn btn-dark rounded-xl font-bold px-6 py-2.5 bg-slate-900 text-white border-0 shadow-lg shadow-slate-900/20 hover:shadow-slate-900/30 active:scale-95 transition-all flex-[2] flex items-center justify-center gap-2" id="ddr_submit_btn" onclick="submitDamageReport()">
                    <i class="fas fa-paper-plane text-xs"></i> Submit Report
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
