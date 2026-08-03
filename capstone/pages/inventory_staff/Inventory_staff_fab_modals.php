    <!-- Floating Action Button (FAB) -->
    <button onclick="openBatchStockInModal()" class="fab" id="fabBtn">
        <i class="fas fa-plus"></i>
        <span>Stock In</span>
    </button>

    <!-- Batch Stock In Modal (Tabular Style) -->
    <div class="modal-overlay hidden fixed inset-0 z-50 flex items-end md:items-center justify-center" id="batchStockInModal">
        <div class="modal-box w-full max-w-lg bg-white rounded-t-3xl md:rounded-3xl shadow-2xl relative transform transition-transform translate-y-full" id="batchStockInModalContent">
            <div class="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-white rounded-t-3xl">
                <h3 class="font-black text-lg text-slate-800 flex items-center gap-2"><i class="fas fa-boxes-stacked text-indigo-500"></i> Batch Stock In</h3>
                <button onclick="closeBatchStockInModal()" class="w-8 h-8 flex items-center justify-center rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body hide-scroll pb-4 px-0">
                <form method="POST" action="inventory_staff.php" onsubmit="return validateBatchStockInForm(this)">
                    <?php echo csrfTokenField(); ?>
                    <input type="hidden" name="batch_stockin" value="1">

                    <!-- Shared Date Field -->
                    <div class="px-4 pb-4 border-b border-slate-100">
                        <label class="block text-xs font-bold text-slate-600 uppercase tracking-wide mb-2">Stock In Date <span class="text-red-500">*</span></label>
                        <input type="date" name="batch_stockin_date" id="batchStockInDate" required class="w-full px-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold text-slate-700 outline-none focus:ring-2 focus:ring-indigo-500" value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <?php if (count($products) > 0): ?>
                    <div class="overflow-x-auto max-h-[50vh] overflow-y-auto px-4 pt-4">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-white z-10">
                                <tr class="border-b-2 border-slate-200">
                                    <th class="text-left px-2 py-3 text-[10px] font-bold text-slate-500 uppercase tracking-wider">Product</th>
                                    <th class="text-right px-2 py-3 text-[10px] font-bold text-slate-500 uppercase tracking-wider whitespace-nowrap">Current</th>
                                    <th class="text-right px-2 py-3 text-[10px] font-bold text-slate-500 uppercase tracking-wider whitespace-nowrap">Qty to Add</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($products as $p):
                                    $pid = (int)$p['Product_ID'];
                                    $qty = (float)$p['current_quantity'];
                                ?>
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="px-2 py-2.5">
                                        <span class="text-sm font-semibold text-slate-800"><?php echo htmlspecialchars($p['product_name']); ?></span>
                                        <?php if (!empty($p['unit_name']) && $p['unit_name'] !== '-'): ?>
                                            <span class="text-[10px] font-medium text-slate-400 ml-1"><?php echo htmlspecialchars($p['unit_name']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-2 py-2.5 text-right">
                                        <span class="text-sm font-bold text-indigo-600"><?php echo number_format($qty, 0); ?></span>
                                    </td>
                                    <td class="px-2 py-2.5 text-right">
                                        <input type="number" name="stockin_qty[<?php echo $pid; ?>]" value="0" min="0" step="1"
                                               class="w-20 text-right px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm font-bold text-slate-800 outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                               oninput="highlightBatchStockInRow(this)">
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-10 px-4">
                        <i class="fas fa-box-open text-4xl text-slate-200 mb-3"></i>
                        <p class="text-sm font-semibold text-slate-400">No products found.</p>
                    </div>
                    <?php endif; ?>

                    <div class="px-4 pt-4 border-t border-slate-100 mt-4">
                        <button type="submit" class="w-full py-4 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold text-sm shadow-lg shadow-indigo-200 transition-all flex items-center justify-center gap-2">
                            <i class="fas fa-save"></i> Save All Stock In
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Manual Adjustment Modal (Physical Count) -->
    <div class="modal-overlay hidden fixed inset-0 z-50 flex items-end md:items-center justify-center" id="manualAdjustmentModal">
        <div class="modal-box w-full max-w-lg bg-white rounded-t-3xl md:rounded-3xl shadow-2xl relative transform transition-transform translate-y-full" id="adjModalContent">
            <div class="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-white rounded-t-3xl">
                <h3 class="font-black text-lg text-slate-800 flex items-center gap-2"><i class="fas fa-table text-indigo-500"></i> Manual Adjustment (Physical Count)</h3>
                <button onclick="printAdjustmentTable()" class="w-8 h-8 flex items-center justify-center rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200 mr-2" title="Print physical count sheet"><i class="fas fa-print text-sm"></i></button>
                <button onclick="closeAdjustmentModal()" class="w-8 h-8 flex items-center justify-center rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200 "><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body hide-scroll pb-4 px-0">
                <form method="post" action="../api/manual_adjustment_backend.php" onsubmit="return validateAdjustmentForm(this)">
                    <?php echo csrfTokenField(); ?>
                    <input type="hidden" name="save_adjustment" value="1">
                    <input type="hidden" name="redirect_url" value="../pages/inventory_staff.php">

                    <?php if (count($products) > 0): ?>
                    <div class="print-only print-header px-4 pt-4 pb-2">
                        <h2 class="text-2xl font-black text-slate-900">Physical Count Sheet</h2>
                        <p class="text-sm text-slate-500 mt-1"><?php echo date('F j, Y'); ?></p>
                    </div>
                    <div class="overflow-x-auto max-h-[55vh] overflow-y-auto px-4">
                        <table class="w-full text-sm adj-table">
                            <thead class="sticky top-0 bg-slate-50 z-10">
                                <tr class="border-b-2 border-slate-200">
                                    <th class="text-left px-2 py-3 text-[10px] font-bold text-slate-500 uppercase tracking-wider">Product Name</th>
                                    <th class="text-right px-2 py-3 text-[10px] font-bold text-slate-500 uppercase tracking-wider whitespace-nowrap">Current Qty<br><span class="text-slate-400 font-normal normal-case">(System)</span></th>
                                    <th class="text-right px-2 py-3 text-[10px] font-bold text-slate-500 uppercase tracking-wider whitespace-nowrap">Actual Qty<br><span class="text-slate-400 font-normal normal-case">(Physical)</span></th>
                                    <th class="text-center px-2 py-3 text-[10px] font-bold text-slate-500 uppercase tracking-wider whitespace-nowrap">Reason</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach ($products as $p):
                                    $pid = (int)$p['Product_ID'];
                                    $qty = (float)$p['current_quantity'];
                                ?>
                                <tr class="hover:bg-slate-50 transition-colors adj-row" data-product-id="<?php echo $pid; ?>" data-current-qty="<?php echo $qty; ?>">
                                    <td class="px-2 py-2.5">
                                        <span class="text-sm font-semibold text-slate-800"><?php echo htmlspecialchars($p['product_name']); ?></span>
                                        <?php if (!empty($p['unit_name']) && $p['unit_name'] !== '-'): ?>
                                            <span class="text-[10px] font-medium text-slate-400 ml-1"><?php echo htmlspecialchars($p['unit_name']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-2 py-2.5 text-right">
                                        <span class="text-sm font-bold text-indigo-600"><?php echo number_format($qty, 0); ?></span>
                                    </td>
                                    <td class="px-2 py-2.5 text-right">
                                        <input type="number" name="adjustments[<?php echo $pid; ?>]" value="<?php echo $qty; ?>" min="0" step="1"
                                               class="adj-actual-input w-20 text-right px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm font-bold text-slate-800 outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                               oninput="markAdjusted(this)">
                                        <input type="hidden" name="current_qty[<?php echo $pid; ?>]" value="<?php echo $qty; ?>">
                                    </td>
                                    <td class="px-2 py-2.5 text-center">
                                        <select name="adjustment_reason[<?php echo $pid; ?>]" class="adj-reason-select w-full min-w-[100px] px-2 py-2 bg-white border border-slate-200 rounded-lg text-xs font-semibold text-slate-700 outline-none focus:ring-2 focus:ring-indigo-500 appearance-none" onchange="markAdjustedReason(this)">
                                            <option value="">Select</option>
                                            <?php foreach ($reasons as $reason_opt): ?>
                                                <option value="<?php echo htmlspecialchars($reason_opt); ?>"><?php echo htmlspecialchars($reason_opt); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="print-only print-footer px-4 pb-2 pt-6">
                        <div class="flex justify-between items-end">
                            <div>
                                <span class="text-sm font-bold text-slate-700">Counted by:</span>
                                <span class="text-sm font-bold text-slate-800 ml-2"><?php echo htmlspecialchars($display_name); ?></span>
                            </div>
                            <div>
                                <span class="text-sm font-bold text-slate-700">Date:</span>
                                <span class="text-sm font-bold text-slate-800 ml-2"><?php echo date('F j, Y'); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-10 px-4">
                        <i class="fas fa-box-open text-4xl text-slate-200 mb-3"></i>
                        <p class="text-sm font-semibold text-slate-400">No products found.</p>
                    </div>
                    <?php endif; ?>

                    <div class="px-4 pt-4 border-t border-slate-100 mt-4 space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-600 uppercase tracking-wide mb-2">Remarks <span class="text-slate-400 font-normal normal-case">(optional)</span></label>
                            <textarea name="remarks" id="modal_remarks_adj" rows="2" maxlength="500" placeholder="Add notes for this adjustment..." class="w-full px-4 py-3.5 bg-slate-50 border border-slate-200 rounded-xl text-sm font-medium text-slate-700 outline-none focus:ring-2 focus:ring-indigo-500 resize-none"></textarea>
                        </div>

                        <button type="submit" class="w-full py-4 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-bold text-sm shadow-lg shadow-indigo-200 transition-all flex items-center justify-center gap-2" <?php echo empty($reasons) ? 'disabled' : ''; ?>>
                            <i class="fas fa-save"></i> Save All Adjustments
                        </button>
                    </div>
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
