    <!-- Floating Action Button (FAB) -->
    <button onclick="openProductionModal()" class="fab" id="fabBtn">
        <i class="fas fa-plus"></i>
        <span>Record Stock In</span>
    </button>

    <!-- Tailwind Modal -->
    <div class="modal-overlay hidden fixed inset-0 z-50 flex items-end md:items-center justify-center" id="productionModal">
        <div class="modal-box w-full max-w-lg bg-white rounded-t-3xl md:rounded-3xl shadow-2xl relative transform transition-transform translate-y-full" id="prodModalContent">
            <!-- Header -->
            <div class="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-white rounded-t-3xl">
                <h3 class="font-black text-lg text-slate-800 flex items-center gap-2"><i class="fas fa-boxes-stacked text-indigo-500"></i> Record Stock In</h3>
                <button onclick="closeProductionModal()" class="w-8 h-8 flex items-center justify-center rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200 "><i class="fas fa-times"></i></button>
            </div>
            
            <!-- Body -->
            <div class="modal-body hide-scroll pb-10">
                <form method="POST" action="inventory_staff.php" onsubmit="return validateStockInForm(this)">
                    <?php echo csrfTokenField(); ?>
                    <input type="hidden" name="production_type" value="stockin">
                    
                    <div class="mb-5">
                        <label class="block text-xs font-bold text-slate-600 uppercase tracking-wide mb-2">Select Product <span class="text-red-500">*</span></label>
                        <div class="custom-product-picker relative" data-modal="production">
                            <input type="text" class="picker-search w-full px-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold text-slate-700 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none cursor-pointer" placeholder="-- Choose --" readonly autocomplete="off">
                            <input type="hidden" name="product_id" value="" required>
                            <div class="picker-dropdown hidden absolute left-0 right-0 z-20 mt-1 bg-white border border-slate-200 rounded-xl shadow-xl max-h-52 overflow-y-auto">
                            </div>
                            <div class="absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none text-slate-400"><i class="fas fa-chevron-down text-xs"></i></div>
                        </div>
                    </div>

                    <div class="mb-5 mt-6">
                        <label class="block text-xs font-bold text-slate-600 uppercase tracking-wide mb-2">Stock In Date <span class="text-red-500">*</span></label>
                        <input type="date" name="production_date" id="prodDate" required class="w-full px-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold text-slate-700 outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <div class="mb-8">
                        <label class="block text-xs font-bold text-slate-600 uppercase tracking-wide mb-2">Quantity / Packs <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <i class="fas fa-hashtag absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                            <input type="number" name="number_of_bags" min="1" step="1" required placeholder="e.g. 50" class="w-full pl-11 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xl font-black text-slate-800 outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                    </div>

                    <button type="submit" class="w-full py-4 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold text-sm shadow-lg shadow-indigo-200 transition-all flex items-center justify-center gap-2">
                        <i class="fas fa-save"></i> Save Stock In
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Manual Adjustment Modal -->
    <div class="modal-overlay hidden fixed inset-0 z-50 flex items-end md:items-center justify-center" id="manualAdjustmentModal">
        <div class="modal-box w-full max-w-lg bg-white rounded-t-3xl md:rounded-3xl shadow-2xl relative transform transition-transform translate-y-full" id="adjModalContent">
            <div class="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-white rounded-t-3xl">
                <h3 class="font-black text-lg text-slate-800 flex items-center gap-2"><i class="fas fa-edit text-indigo-500"></i> Manual Adjustment</h3>
                <button onclick="closeAdjustmentModal()" class="w-8 h-8 flex items-center justify-center rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200 "><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body hide-scroll pb-10">
                <form method="post" action="../api/manual_adjustment_backend.php" onsubmit="return validateStockInForm(this)">
                    <?php echo csrfTokenField(); ?>
                    <input type="hidden" name="save_adjustment" value="1">
                    <input type="hidden" name="redirect_url" value="../pages/inventory_staff.php">
                    <input type="hidden" name="adjustment_value" id="modal_adj_val_hidden" value="">
                    
                    <div class="mb-5">
                        <label class="block text-xs font-bold text-slate-600 uppercase tracking-wide mb-2">Product <span class="text-red-500">*</span></label>
                        <div class="custom-product-picker relative" data-modal="adjustment">
                            <input type="text" class="picker-search w-full px-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold text-slate-700 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none cursor-pointer" placeholder="-- Choose --" readonly autocomplete="off">
                            <input type="hidden" name="product_id" id="modal_product_id_adj" value="" required>
                            <div class="picker-dropdown hidden absolute left-0 right-0 z-20 mt-1 bg-white border border-slate-200 rounded-xl shadow-xl max-h-52 overflow-y-auto">
                            </div>
                            <div class="absolute right-4 top-1/2 -translate-y-1/2 pointer-events-none text-slate-400"><i class="fas fa-chevron-down text-xs"></i></div>
                        </div>
                    </div>

                    <!-- Adjustment Input -->
                    <div class="bg-indigo-50 border border-indigo-100 rounded-2xl p-4 mb-6 relative mt-6">
                        <div class="mb-6">
                            <label class="block text-xs font-bold text-indigo-700 uppercase tracking-wide mb-2">Adjustment (±) <span class="text-red-500">*</span></label>
                            <input type="number" id="modal_adj_val_adj" step="1" placeholder="+10 or -5" oninput="updateAdjQtyPreview()" class="w-full px-4 py-3 bg-white border border-indigo-200 rounded-xl text-xl font-black text-indigo-700 outline-none focus:ring-2 focus:ring-indigo-500 text-center">
                        </div>

                        <div class="mb-6 rounded-2xl bg-white/80 border border-indigo-100 px-4 py-3 shadow-sm">
                            <span class="block text-[11px] font-black uppercase tracking-[0.2em] text-indigo-500 mb-2">Current Qty</span>
                            <span id="modal_current_qty_label_adj" class="inline-flex items-center rounded-full bg-indigo-600 px-4 py-2 text-lg font-black text-white shadow-sm">Select Product</span>
                        </div>

                        <div class="border-t border-indigo-200 pt-5">
                            <div class="flex items-center justify-between rounded-2xl border border-indigo-100 bg-white px-4 py-3">
                                <span class="text-sm font-black text-slate-600">New Qty</span>
                                <span id="modal_result_qty_adj" class="inline-flex min-w-[4.5rem] items-center justify-center rounded-full bg-indigo-100 px-4 py-2 text-xl font-black text-indigo-700">0</span>
                            </div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-xs font-bold text-slate-600 uppercase tracking-wide mb-2">Reason <span class="text-red-500">*</span></label>
                        <select name="reason" id="modal_reason_adj" required onchange="toggleAdjustmentRemarksStaff()" class="w-full px-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold text-slate-700 focus:ring-2 focus:ring-indigo-500 outline-none appearance-none" <?php echo empty($reasons) ? 'disabled' : ''; ?>>
                            <option value=""><?php echo empty($reasons) ? 'No reasons configured in database' : 'Select Reason'; ?></option>
                            <?php foreach ($reasons as $reason): ?>
                                <option value="<?php echo htmlspecialchars($reason); ?>"><?php echo htmlspecialchars($reason); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="relative pointer-events-none -mt-9 mr-4 text-right text-slate-400"><i class="fas fa-chevron-down text-xs"></i></div>
                    </div>

                    <div class="mb-8">
                        <label class="block text-xs font-bold text-slate-600 uppercase tracking-wide mb-2">Remarks <span id="remarksRequiredBadgeAdj" class="text-red-500 hidden">*</span></label>
                        <textarea name="remarks" id="modal_remarks_adj" rows="3" maxlength="500" placeholder="Add details for this adjustment..." class="w-full px-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium text-slate-700 outline-none focus:ring-2 focus:ring-indigo-500 resize-none"></textarea>
                        <p id="remarksHelpTextAdj" class="mt-2 text-[11px] font-medium text-slate-400">
                            Optional for standard reasons. Required when you choose "Other (with remarks)".
                        </p>
                    </div>

                    <button type="submit" class="w-full py-4 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold text-sm shadow-lg shadow-indigo-200 transition-all flex items-center justify-center gap-2" <?php echo empty($reasons) ? 'disabled' : ''; ?>>
                        <i class="fas fa-save"></i> Save Adjustment
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Storage Limit Modal -->
    <div class="modal-overlay hidden fixed inset-0 z-50 flex items-end md:items-center justify-center" id="storageLimitModal">
        <div class="modal-box w-full max-w-lg bg-white rounded-t-3xl md:rounded-3xl shadow-2xl relative transform transition-transform translate-y-full" id="storageLimitModalContent">
            <div class="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-white rounded-t-3xl">
                <h3 class="font-black text-lg text-slate-800 flex items-center gap-2"><i class="fas fa-warehouse text-indigo-500"></i> Set Storage Limit</h3>
                <button onclick="closeStorageLimitModal()" class="w-8 h-8 flex items-center justify-center rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body hide-scroll pb-10">
                <form id="storageLimitForm" onsubmit="handleStorageLimitSubmit(event)">
                    <?php echo csrfTokenField(); ?>
                    <input type="hidden" id="storageLimitProductId" name="product_id">
                    
                    <!-- Product Info -->
                    <div class="mb-5 p-4 bg-slate-50 rounded-2xl border border-slate-100">
                        <span class="block text-[11px] font-bold text-slate-400 uppercase tracking-wide mb-1">Product</span>
                        <span id="storageLimitProductName" class="text-sm font-bold text-slate-800"></span>
                    </div>
                    
                    <!-- Current Quantity Display -->
                    <div class="mb-5 p-4 bg-indigo-50 rounded-2xl border border-indigo-100">
                        <div class="flex items-center justify-between">
                            <div>
                                <span class="block text-[11px] font-bold text-indigo-500 uppercase tracking-wide mb-1">Current Quantity</span>
                                <span id="storageLimitCurrentQty" class="text-2xl font-black text-indigo-700"></span>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center">
                                <i class="fas fa-boxes text-xl"></i>
                            </div>
                        </div>
                        <p class="mt-2 text-xs text-indigo-600 font-medium">
                            <i class="fas fa-info-circle mr-1"></i> Storage limit cannot be set below current quantity.
                        </p>
                    </div>

                    <!-- Storage Limit Input -->
                    <div class="mb-6">
                        <label class="block text-xs font-bold text-slate-600 uppercase tracking-wide mb-2">New Storage Limit <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <i class="fas fa-warehouse absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                            <input type="number" id="storageLimitInput" name="storage_limit" min="1" step="1" required 
                                   class="w-full pl-11 pr-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-xl font-black text-slate-800 outline-none focus:ring-2 focus:ring-indigo-500"
                                   oninput="validateStorageLimitInput()">
                        </div>
                        
                        <!-- Validation Message -->
                        <div id="storageLimitValidationMsg" class="hidden mt-2 p-3 bg-red-50 border border-red-200 rounded-xl">
                            <div class="flex items-start gap-2">
                                <i class="fas fa-exclamation-triangle text-red-500 mt-0.5"></i>
                                <div>
                                    <p class="text-sm font-bold text-red-700">Storage Limit Too Low</p>
                                    <p class="text-xs text-red-600 mt-1">Current quantity (<span id="validationCurrentQty"></span>) exceeds the new limit. Reduce inventory first or set a higher limit.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" id="storageLimitSubmitBtn" class="w-full py-4 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold text-sm shadow-lg shadow-indigo-200 transition-all flex items-center justify-center gap-2">
                        <i class="fas fa-save"></i> Save Storage Limit
                    </button>
                </form>
            </div>
        </div>
    </div>
