const ADJUSTMENT_OTHER_REASON = (window.INVENTORY_STAFF_CONFIG && window.INVENTORY_STAFF_CONFIG.adjustmentOtherReasonLabel) || '';
const DAMAGE_TYPE_REASONS = (window.INVENTORY_STAFF_CONFIG && window.INVENTORY_STAFF_CONFIG.damageTypeReasons) || [];

// Storage Limit Modal State
let storageLimitState = {
    productId: null,
    productName: '',
    currentQty: 0,
    currentLimit: 0
};

// Tab system
        function switchTab(id, btn) {
            document.querySelectorAll('.nav-tab').forEach(b => {
                b.classList.remove('bg-indigo-600', 'text-white', 'shadow-md', 'shadow-indigo-200');
                b.classList.add('text-slate-500', 'hover:bg-slate-100');
            });
            btn.classList.remove('text-slate-500', 'hover:bg-slate-100');
            btn.classList.add('bg-indigo-600', 'text-white', 'shadow-md', 'shadow-indigo-200');
            
            document.querySelectorAll('.tab-content').forEach(p => {
                p.classList.remove('active', 'staggered-group');
                p.classList.add('hidden');
                void p.offsetWidth; // Force reflow
            });

            const activePane = document.getElementById('pane-' + id);
            activePane.classList.remove('hidden');
            activePane.classList.add('active', 'staggered-group');

            // Hide/show FAB based on tab
            document.getElementById('fabBtn').style.display = (id === 'inventory') ? 'flex' : 'none';
        }

        let currentHistoryTab = 'productions';
        let currentHistoryPage = 1;
        const historyItemsPerPage = 10;

        // Sub-tabs
        function switchSubTab(id, btn) {
            document.querySelectorAll('.sub-tab').forEach(b => {
                b.classList.remove('bg-white', 'text-indigo-600', 'shadow-sm');
                b.classList.add('text-slate-500', 'hover:text-slate-700');
            });
            btn.classList.remove('text-slate-500', 'hover:text-slate-700');
            btn.classList.add('bg-white', 'text-indigo-600', 'shadow-sm');
            
            document.querySelectorAll('.sub-pane').forEach(p => { 
                p.classList.remove('block', 'staggered-group'); 
                p.classList.add('hidden'); 
                void p.offsetWidth; // Force reflow
            });
            
            const activeSub = document.getElementById('sub-' + id);
            activeSub.classList.remove('hidden'); 
            activeSub.classList.add('block', 'staggered-group');

            currentHistoryTab = id;
            const searchInput = document.getElementById('historySearchInput');
            if (searchInput) {
                searchInput.value = '';
            }
            filterHistory();
        }

        function filterHistory() {
            currentHistoryPage = 1;
            renderHistoryPagination();
        }

        function renderHistoryPagination() {
            const term = (document.getElementById('historySearchInput')?.value || '').toLowerCase().trim();
            const container = document.getElementById('sub-' + currentHistoryTab);
            if (!container) return;

            const items = Array.from(container.querySelectorAll('.history-item'));
            
            let visibleItems = items.filter(card => {
                const text = card.textContent.toLowerCase();
                const match = text.includes(term);
                card.style.display = 'none'; // hide all first
                return match;
            });

            const totalPages = Math.max(1, Math.ceil(visibleItems.length / historyItemsPerPage));
            if (currentHistoryPage > totalPages) currentHistoryPage = totalPages;
            if (currentHistoryPage < 1) currentHistoryPage = 1;
            
            const startIndex = (currentHistoryPage - 1) * historyItemsPerPage;
            const endIndex = startIndex + historyItemsPerPage;
            
            visibleItems.slice(startIndex, endIndex).forEach(card => {
                card.style.display = ''; // restore to stylesheet default (flex/block)
            });

            // Update pagination UI
            const pagContainer = document.getElementById('historyPagination');
            if (pagContainer) {
                if (visibleItems.length > historyItemsPerPage) {
                    pagContainer.classList.remove('hidden');
                    pagContainer.classList.add('flex');
                    document.getElementById('historyPageNum').textContent = currentHistoryPage;
                    document.getElementById('historyPageTotal').textContent = totalPages;
                } else {
                    pagContainer.classList.add('hidden');
                    pagContainer.classList.remove('flex');
                }
            }
        }

        function prevHistoryPage() {
            if (currentHistoryPage > 1) {
                currentHistoryPage--;
                renderHistoryPagination();
                document.getElementById('sub-' + currentHistoryTab).querySelector('.overflow-y-auto').scrollTop = 0;
            }
        }

        function nextHistoryPage() {
            const term = (document.getElementById('historySearchInput')?.value || '').toLowerCase().trim();
            const container = document.getElementById('sub-' + currentHistoryTab);
            if (!container) return;
            const visibleCount = Array.from(container.querySelectorAll('.history-item')).filter(card => card.textContent.toLowerCase().includes(term)).length;
            const totalPages = Math.ceil(visibleCount / historyItemsPerPage);
            
            if (currentHistoryPage < totalPages) {
                currentHistoryPage++;
                renderHistoryPagination();
                document.getElementById('sub-' + currentHistoryTab).querySelector('.overflow-y-auto').scrollTop = 0;
            }
        }

        // Filtering
        let currentStatusFilter = 'all';

        // Product Pagination
        const PRODUCTS_PER_PAGE = 10;
        let currentProductPage = 1;

        function toggleFilters() {
            const p = document.getElementById('filterPanel');
            p.classList.toggle('hidden');
        }

        function applyFilter(status) {
            currentStatusFilter = status;
            document.querySelectorAll('.filter-btn').forEach(b => {
                b.classList.remove('bg-indigo-100', 'text-indigo-700', 'border-transparent');
                b.classList.add('bg-white', 'text-slate-600', 'border-slate-200');
            });
            event.target.classList.remove('bg-white', 'text-slate-600', 'border-slate-200');
            event.target.classList.add('bg-indigo-100', 'text-indigo-700', 'border-transparent');
            filterProducts();
        }

        function filterProducts() {
            const term = document.getElementById('searchInput').value.toLowerCase().trim();
            const allCards = Array.from(document.querySelectorAll('.product-item'));

            let visibleCards = allCards.filter(card => {
                const name = card.getAttribute('data-name');
                const stat = card.getAttribute('data-status');

                let matchesName = name.includes(term);
                let matchesStat = (currentStatusFilter === 'all') ||
                                  (currentStatusFilter === 'low' && stat === 'low') ||
                                  (currentStatusFilter === 'in' && stat === 'in');

                return matchesName && matchesStat;
            });

            const totalPages = Math.max(1, Math.ceil(visibleCards.length / PRODUCTS_PER_PAGE));
            if (currentProductPage > totalPages) currentProductPage = totalPages;
            if (currentProductPage < 1) currentProductPage = 1;

            const startIndex = (currentProductPage - 1) * PRODUCTS_PER_PAGE;
            const endIndex = startIndex + PRODUCTS_PER_PAGE;
            const pageItems = visibleCards.slice(startIndex, endIndex);

            allCards.forEach(card => { card.style.display = 'none'; });
            pageItems.forEach(card => { card.style.display = 'block'; });

            renderProductPagination(totalPages);
        }

        function renderProductPagination(totalPages) {
            const pagContainer = document.getElementById('productPagination');
            if (!pagContainer) return;

            const visibleCount = Array.from(document.querySelectorAll('.product-item')).filter(
                c => c.style.display !== 'none'
            ).length;

            if (totalPages > 1) {
                pagContainer.classList.remove('hidden');
                pagContainer.classList.add('flex');
                document.getElementById('productPageNum').textContent = currentProductPage;
                document.getElementById('productPageTotal').textContent = totalPages;
            } else {
                pagContainer.classList.add('hidden');
                pagContainer.classList.remove('flex');
            }
        }

        function prevProductPage() {
            if (currentProductPage > 1) {
                currentProductPage--;
                filterProducts();
                const container = document.getElementById('productScrollContainer');
                if (container) container.scrollTop = 0;
            }
        }

        function nextProductPage() {
            const term = (document.getElementById('searchInput')?.value || '').toLowerCase().trim();
            const visibleCount = Array.from(document.querySelectorAll('.product-item')).filter(card => {
                const name = card.getAttribute('data-name');
                const stat = card.getAttribute('data-status');
                let matchesName = name.includes(term);
                let matchesStat = (currentStatusFilter === 'all') ||
                                  (currentStatusFilter === 'low' && stat === 'low') ||
                                  (currentStatusFilter === 'in' && stat === 'in');
                return matchesName && matchesStat;
            }).length;
            const totalPages = Math.max(1, Math.ceil(visibleCount / PRODUCTS_PER_PAGE));
            if (currentProductPage < totalPages) {
                currentProductPage++;
                filterProducts();
                const container = document.getElementById('productScrollContainer');
                if (container) container.scrollTop = 0;
            }
        }

        // Generic modal setup function
        function setupModal(modalId, contentId) {
            const modal = document.getElementById(modalId);
            const modalContent = document.getElementById(contentId);

            return {
                init: function() {
                    modal.addEventListener('click', (e) => {
                        if (e.target === modal) this.close();
                    });
                },
                open: function() {
                    modal.classList.remove('hidden');
                    setTimeout(() => {
                        modalContent.classList.remove('translate-y-full');
                    }, 10);
                },
                close: function() {
                    modalContent.classList.add('translate-y-full');
                    setTimeout(() => {
                        modal.classList.add('hidden');
                    }, 300);
                }
            };
        }

        // Modal triggers with animation
        const batchStockInModal = setupModal('batchStockInModal', 'batchStockInModalContent');
        const adjModal = setupModal('manualAdjustmentModal', 'adjModalContent');
        batchStockInModal.init(); adjModal.init();

        function openBatchStockInModal() {
            batchStockInModal.open();
            const d = document.getElementById('batchStockInDate');
            if (d && !d.value) {
                const now = new Date();
                d.value = now.getFullYear() + '-' + String(now.getMonth()+1).padStart(2,'0') + '-' + String(now.getDate()).padStart(2,'0');
            }
        }
        function closeBatchStockInModal() { batchStockInModal.close(); }

        function highlightBatchStockInRow(input) {
            const row = input.closest('tr');
            if (!row) return;
            const val = parseInt(input.value) || 0;
            if (val > 0) {
                row.classList.add('bg-indigo-50');
                row.classList.remove('hover:bg-slate-50');
            } else {
                row.classList.remove('bg-indigo-50');
                row.classList.add('hover:bg-slate-50');
            }
        }

        function validateBatchStockInForm(form) {
            const dateInput = form.querySelector('input[name="batch_stockin_date"]');
            if (!dateInput || !dateInput.value) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Date Required',
                    text: 'Please select a stock in date.',
                    confirmButtonColor: '#6366f1',
                    customClass: { popup: 'rounded-[28px] shadow-2xl' }
                });
                return false;
            }

            const inputs = form.querySelectorAll('input[name^="stockin_qty"]');
            let hasAny = false;
            for (const input of inputs) {
                if (parseInt(input.value) > 0) {
                    hasAny = true;
                    break;
                }
            }

            if (!hasAny) {
                Swal.fire({
                    icon: 'info',
                    title: 'No Quantities Entered',
                    text: 'Please enter at least one product with a quantity greater than 0.',
                    confirmButtonColor: '#6366f1',
                    customClass: { popup: 'rounded-[28px] shadow-2xl' }
                });
                return false;
            }

            return true;
        }

        function openAdjustmentModal() {
            adjModal.open();
            toggleAdjustmentRemarksStaff();
        }
        function closeAdjustmentModal() { adjModal.close(); }

        function printAdjustmentTable() {
            window.print();
        }

        // Storage Limit Modal Functions
        let storageLimitModal;
        
        function openStorageLimitModal(productId, productName, currentQty, currentLimit) {
            storageLimitState = { productId, productName, currentQty, currentLimit };
            
            // Populate modal
            document.getElementById('storageLimitProductId').value = productId;
            document.getElementById('storageLimitProductName').textContent = productName;
            document.getElementById('storageLimitCurrentQty').textContent = currentQty.toLocaleString();
            document.getElementById('validationCurrentQty').textContent = currentQty.toLocaleString();
            
            // Set default value to current limit
            const input = document.getElementById('storageLimitInput');
            input.value = currentLimit;
            input.min = currentQty; // HTML5 validation
            
            // Clear validation
            document.getElementById('storageLimitValidationMsg').classList.add('hidden');
            document.getElementById('storageLimitSubmitBtn').disabled = false;
            document.getElementById('storageLimitSubmitBtn').classList.remove('opacity-50', 'cursor-not-allowed');
            
            storageLimitModal.open();
            
            // Validate immediately
            validateStorageLimitInput();
        }

        function closeStorageLimitModal() {
            if (storageLimitModal) storageLimitModal.close();
        }

        // Initialize storage limit modal after functions are defined
        storageLimitModal = setupModal('storageLimitModal', 'storageLimitModalContent');
        if (storageLimitModal) storageLimitModal.init();

        function validateStorageLimitInput() {
            const input = document.getElementById('storageLimitInput');
            const newLimit = parseFloat(input.value) || 0;
            const validationMsg = document.getElementById('storageLimitValidationMsg');
            const submitBtn = document.getElementById('storageLimitSubmitBtn');
            
            // Block if new limit is less than current quantity
            if (newLimit < storageLimitState.currentQty) {
                validationMsg.classList.remove('hidden');
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                input.classList.add('border-red-300', 'bg-red-50');
                input.classList.remove('border-slate-200', 'bg-slate-50');
                return false;
            } else {
                validationMsg.classList.add('hidden');
                submitBtn.disabled = false;
                submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                input.classList.remove('border-red-300', 'bg-red-50');
                input.classList.add('border-slate-200', 'bg-slate-50');
                return true;
            }
        }

        async function handleStorageLimitSubmit(event) {
            event.preventDefault();
            
            // Final validation check
            if (!validateStorageLimitInput()) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Storage Limit',
                    text: `Storage limit cannot be less than current quantity (${storageLimitState.currentQty}).`,
                    confirmButtonColor: '#ef4444',
                    confirmButtonText: 'OK',
                    customClass: { popup: 'rounded-[28px] shadow-2xl' }
                });
                return;
            }
            
            const formData = new FormData(event.target);
            if (window.INVENTORY_STAFF_CONFIG && window.INVENTORY_STAFF_CONFIG.csrfToken && !formData.has('csrf_token')) {
                formData.append('csrf_token', window.INVENTORY_STAFF_CONFIG.csrfToken);
            }
            const newLimit = parseFloat(formData.get('storage_limit'));
            
            // Show loading
            Swal.fire({
                title: 'Saving...',
                text: 'Updating storage limit',
                allowOutsideClick: false,
                showConfirmButton: false,
                willOpen: () => Swal.showLoading(),
                customClass: { popup: 'rounded-[28px]' }
            });
            
            try {
                const response = await fetch('../api/update_storage_limit.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                Swal.close();
                
                if (result.success) {
                    showBlueSuccessModal('Storage Limit Updated', 
                        `Storage limit for <strong>${result.product_name}</strong> has been set to <strong>${result.storage_limit}</strong>.`);
                    closeStorageLimitModal();
                    
                    // Reload after short delay to show updated values
                    setTimeout(() => location.reload(), 1500);
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Update Failed',
                        text: result.message || 'Failed to update storage limit.',
                        confirmButtonColor: '#ef4444',
                        customClass: { popup: 'rounded-[28px] shadow-2xl' }
                    });
                }
            } catch (error) {
                Swal.close();
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'An error occurred while updating the storage limit. Please try again.',
                    confirmButtonColor: '#ef4444',
                    customClass: { popup: 'rounded-[28px] shadow-2xl' }
                });
            }
        }

        function validateAdjustmentForm(form) {
            const inputs = form.querySelectorAll('.adj-actual-input');
            let hasChanges = false;
            const missingReasons = [];

            for (const input of inputs) {
                const row = input.closest('.adj-row');
                if (!row) continue;
                const currentQty = parseFloat(row.dataset.currentQty) || 0;
                const actualQty = parseFloat(input.value) || 0;

                if (actualQty !== currentQty) {
                    hasChanges = true;
                    const reasonSelect = row.querySelector('.adj-reason-select');
                    const productName = row.querySelector('.text-sm.font-semibold')?.textContent?.trim() || 'Unknown';
                    if (!reasonSelect || !reasonSelect.value) {
                        missingReasons.push(productName);
                    }
                }
            }

            if (!hasChanges) {
                Swal.fire({
                    icon: 'info',
                    title: 'No Changes',
                    text: 'The actual quantities match the system quantities. No adjustments needed.',
                    confirmButtonColor: '#6366f1',
                    customClass: { popup: 'rounded-[28px] shadow-2xl' }
                });
                return false;
            }

            if (missingReasons.length > 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Reason Required',
                    html: `<p class="text-sm font-medium text-slate-600 mb-3">Please select a reason for the following product(s):</p>
                           <div class="text-left text-sm font-semibold text-slate-800 space-y-1">${missingReasons.map(r => `<div class="flex items-center gap-2"><i class="fas fa-exclamation-circle text-amber-500 text-xs"></i> ${r}</div>`).join('')}</div>`,
                    confirmButtonColor: '#6366f1',
                    customClass: { popup: 'rounded-[28px] shadow-2xl' }
                });
                return false;
            }

            return true;
        }

        function markAdjusted(input) {
            const row = input.closest('.adj-row');
            if (!row) return;
            const currentQty = parseFloat(row.dataset.currentQty) || 0;
            const actualQty = parseFloat(input.value) || 0;
            const reasonSelect = row.querySelector('.adj-reason-select');
            if (actualQty !== currentQty) {
                row.classList.add('bg-amber-50', 'border-l-4', 'border-l-amber-400');
                row.classList.remove('hover:bg-slate-50');
                if (reasonSelect) reasonSelect.required = true;
            } else {
                row.classList.remove('bg-amber-50', 'border-l-4', 'border-l-amber-400');
                row.classList.add('hover:bg-slate-50');
                if (reasonSelect) reasonSelect.required = false;
            }
        }

        function markAdjustedReason(select) {
            const row = select.closest('.adj-row');
            if (!row) return;
            const currentQty = parseFloat(row.dataset.currentQty) || 0;
            const actualInput = row.querySelector('.adj-actual-input');
            const actualQty = actualInput ? parseFloat(actualInput.value) || 0 : 0;
            if (actualQty !== currentQty && select.value !== '') {
                row.classList.add('bg-amber-50', 'border-l-4', 'border-l-amber-400');
                row.classList.remove('hover:bg-slate-50');
            } else if (actualQty === currentQty) {
                row.classList.remove('bg-amber-50', 'border-l-4', 'border-l-amber-400');
                row.classList.add('hover:bg-slate-50');
            }
        }

        function escapeAdjHtml(value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        function viewAdjDetail(data) {
            const remarks = (data.notes || '').trim();
            const showRemarks = remarks !== '' && remarks !== 'Manual inventory adjustment';
            Swal.fire({
                title: data.product_name,
                html: `<div class="text-left text-sm">
                    <p class="mb-1"><strong class="text-slate-700">Reason:</strong> ${escapeAdjHtml(data.reason)}</p>
                    ${showRemarks ? `<p class="mb-1"><strong class="text-slate-700">Remarks:</strong> ${escapeAdjHtml(remarks)}</p>` : ''}
                    <p class="mb-1"><strong class="text-slate-700">Handler:</strong> ${escapeAdjHtml(data.handled_by)}</p>
                    <p><strong class="text-slate-700">Qty Changed:</strong> ${data.new_quantity - data.old_quantity}</p>
                </div>`,
                confirmButtonColor: '#4f46e5',
                confirmButtonText: 'Understood',
                customClass: { popup: 'rounded-2xl border-none shadow-xl' }
            });
        }

        function showBlueSuccessModal(title, message, details) {
            const hasDetails = details && details.product_name && details.quantity;
            Swal.fire({
                title: title,
                html: hasDetails ? `
                    <div class="pt-2 pb-1">
                        <div class="mx-auto mb-3 flex h-16 w-16 items-center justify-center rounded-full bg-emerald-50 text-3xl text-emerald-500 animate-bounce [animation-duration:0.6s]">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">${message}</p>
                        <div class="bg-white border border-slate-100 rounded-2xl p-4 mx-1 mt-2 space-y-2 text-left">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-medium text-slate-500">Product</span>
                                <span class="text-sm font-bold text-slate-800">${details.product_name}</span>
                            </div>
                            <div class="flex items-center justify-between border-t border-slate-100 pt-2">
                                <span class="text-xs font-medium text-slate-500">Added</span>
                                <span class="text-sm font-black text-emerald-600">+${details.quantity}</span>
                            </div>
                            <div class="flex items-center justify-between border-t border-slate-100 pt-2">
                                <span class="text-xs font-medium text-slate-500">New Balance</span>
                                <span class="text-sm font-black text-indigo-600">${details.balance}</span>
                            </div>
                        </div>
                    </div>
                ` : `
                    <div class="pt-2 pb-1">
                        <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-blue-50 text-3xl text-blue-600">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <p class="text-sm font-medium text-slate-500">${message}</p>
                    </div>
                `,
                confirmButtonText: 'Continue',
                confirmButtonColor: hasDetails ? '#059669' : '#2563eb',
                customClass: {
                    popup: hasDetails ? 'rounded-[28px] border border-emerald-100 shadow-2xl' : 'rounded-[28px] border border-blue-100 shadow-2xl',
                    title: 'text-slate-800 text-2xl font-black pt-2',
                    confirmButton: 'rounded-xl px-6 py-3 text-sm font-bold'
                }
            });
        }

        /* ── Custom Product Picker ── */
        function initProductPickers() {
            const pickers = document.querySelectorAll('.custom-product-picker');
            const products = (window.INVENTORY_STAFF_CONFIG || {}).products || [];
            if (!pickers.length || !products.length) return;

            pickers.forEach(function (picker) {
                const searchInput = picker.querySelector('.picker-search');
                const hiddenInput = picker.querySelector('input[type="hidden"]');
                const dropdown = picker.querySelector('.picker-dropdown');
                const modalType = picker.dataset.modal || '';

                dropdown.innerHTML = products.map(function (p) {
                    return '<button type="button" class="picker-dropdown-item" data-id="' + p.id + '" data-qty="' + p.qty + '">' +
                        '<span>' + escHtml(p.name) + (p.unit ? ' (' + escHtml(p.unit) + ')' : '') + '</span>' +
                        '<span class="item-qty' + (p.qty === 0 ? ' is-zero' : '') + '">' + Number(p.qty).toLocaleString() + '</span>' +
                    '</button>';
                }).join('');

                searchInput.addEventListener('click', function (e) {
                    e.stopPropagation();
                    if (picker.classList.contains('is-open')) return;
                    closeAllPickers();
                    picker.classList.add('is-open');
                    dropdown.classList.remove('hidden');
                    searchInput.removeAttribute('readonly');
                    searchInput.value = '';
                    searchInput.focus();
                    filterPickerItems(picker, '');
                });

                searchInput.addEventListener('input', function () {
                    filterPickerItems(picker, this.value);
                });

                dropdown.addEventListener('click', function (e) {
                    var item = e.target.closest('.picker-dropdown-item');
                    if (!item) return;
                    var id = item.dataset.id;
                    var name = item.querySelector('span') ? item.querySelector('span').textContent : '';
                    var qty = parseFloat(item.dataset.qty) || 0;

                    hiddenInput.value = id;
                    hiddenInput.setAttribute('data-qty', qty);
                    searchInput.value = name;

                    picker.classList.remove('is-open');
                    dropdown.classList.add('hidden');
                    searchInput.setAttribute('readonly', '');


                });
            });
        }

        function closeAllPickers() {
            document.querySelectorAll('.custom-product-picker.is-open').forEach(function (p) {
                p.classList.remove('is-open');
                var dd = p.querySelector('.picker-dropdown');
                if (dd) dd.classList.add('hidden');
                var si = p.querySelector('.picker-search');
                if (si) si.setAttribute('readonly', '');
            });
        }

        function filterPickerItems(picker, query) {
            var dropdown = picker.querySelector('.picker-dropdown');
            var items = dropdown.querySelectorAll('.picker-dropdown-item');
            var q = query.toLowerCase().trim();
            var visibleCount = 0;

            items.forEach(function (item) {
                var text = item.textContent.toLowerCase();
                if (!q || text.indexOf(q) !== -1) {
                    item.style.display = 'flex';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });

            var noResults = dropdown.querySelector('.picker-no-results');
            if (visibleCount === 0) {
                if (!noResults) {
                    noResults = document.createElement('div');
                    noResults.className = 'picker-no-results';
                    noResults.textContent = 'No products found';
                    dropdown.appendChild(noResults);
                }
                noResults.style.display = 'block';
            } else if (noResults) {
                noResults.style.display = 'none';
            }
        }

        function escHtml(str) {
            var d = document.createElement('div');
            d.textContent = str;
            return d.innerHTML;
        }

        function validateAdjustmentForm(form) {
            const reasonSelect = form.querySelector('select[name="reason"]');
            if (reasonSelect && !reasonSelect.value) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Reason Required',
                    text: 'Please select a reason for the adjustment.',
                    confirmButtonColor: '#6366f1',
                    customClass: { popup: 'rounded-[28px] shadow-2xl' }
                });
                return false;
            }

            const inputs = form.querySelectorAll('.adj-actual-input');
            let hasChanges = false;
            for (const input of inputs) {
                const row = input.closest('.adj-row');
                if (!row) continue;
                const currentQty = parseFloat(row.dataset.currentQty) || 0;
                const actualQty = parseFloat(input.value) || 0;
                if (actualQty !== currentQty) {
                    hasChanges = true;
                    break;
                }
            }

            if (!hasChanges) {
                Swal.fire({
                    icon: 'info',
                    title: 'No Changes',
                    text: 'The actual quantities match the system quantities. No adjustments needed.',
                    confirmButtonColor: '#6366f1',
                    customClass: { popup: 'rounded-[28px] shadow-2xl' }
                });
                return false;
            }

            return true;
        }

        function validateStockInForm(form) {
            var hidden = form.querySelector('input[name="product_id"]');
            if (!hidden || !hidden.value) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Select Product',
                    text: 'Please select a product before saving.',
                    confirmButtonColor: '#6366f1',
                    customClass: { popup: 'rounded-[28px] shadow-2xl' }
                });
                return false;
            }

            var reasonSelect = form.querySelector('select[name="reason"]');
            var photoInput = form.querySelector('input[name="damage_photo"]');
            if (reasonSelect && photoInput && DAMAGE_TYPE_REASONS.includes(reasonSelect.value)) {
                if (!photoInput.files || photoInput.files.length === 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Photo Required',
                        text: 'A damage photo is required for the selected reason.',
                        confirmButtonColor: '#6366f1',
                        customClass: { popup: 'rounded-[28px] shadow-2xl' }
                    });
                    return false;
                }
            }
            return true;
        }

        document.addEventListener('DOMContentLoaded', function () {
            initProductPickers();

            const p = new URLSearchParams(window.location.search);
            if (p.get('tab') === 'history') {
                const btn = document.getElementById('invNavHistory');
                if (btn) switchTab('history', btn);
            }
            toggleAdjustmentRemarksStaff();
            filterHistory();

            const cfg = window.INVENTORY_STAFF_CONFIG || {};
            if (cfg.flashSuccess) {
                showBlueSuccessModal('Stock In Recorded', cfg.flashSuccess, cfg.flashSuccessDetails || null);
            }
            if (cfg.flashError) {
                Swal.fire({
                    icon: 'error',
                    title: 'Unable to Save',
                    text: cfg.flashError,
                    confirmButtonColor: '#ef4444',
                    customClass: { popup: 'rounded-[28px] shadow-2xl' }
                });
            }
        });
