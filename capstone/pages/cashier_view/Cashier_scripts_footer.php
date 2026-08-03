

    <script src="../assets/js/script.js<?= $cbScript ?>"></script>
    <script>
        const CAN_EDIT_SALE_CUSTOMER = false;

        function closeSaleFromDeliveryModal() {
            document.getElementById('saleFromDeliveryModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const deliveryModal = document.getElementById('saleFromDeliveryModal');
            const paymentModal = document.getElementById('paymentModal');
            const historyModal = document.getElementById('historyModal');
            const zReadModal = document.getElementById('zReadModal');
            const shiftModal = document.getElementById('shiftModal');
            const managerPinModal = document.getElementById('managerPinModal');
            if (event.target == deliveryModal) deliveryModal.style.display = 'none';
            if (event.target == paymentModal) paymentModal.style.display = 'none';
            if (event.target == historyModal) historyModal.style.display = 'none';
            if (event.target == zReadModal) zReadModal.style.display = 'none';
            if (event.target == shiftModal) shiftModal.style.display = 'none';
            const closingCountModal = document.getElementById('closingCountModal');
            if (event.target == closingCountModal) closingCountModal.style.display = 'none';
            if (event.target == managerPinModal) closeModal('managerPinModal');
            if (event.target == document.getElementById('receiptModal')) document.getElementById('receiptModal').style.display = 'none';
        }

        async function showReceipt(saleId) {
            if (!saleId) return;
            
            // If we are in another modal, don't close it yet, but show receipt on top
            const receiptModal = document.getElementById('receiptModal');
            const content = document.getElementById('receiptContent');
            
            content.innerHTML = '<div style="text-align: center; padding: 2rem;"><i class="fas fa-spinner fa-spin"></i> Generating Receipt...</div>';
            receiptModal.style.display = 'block';
            
            try {
                const response = await fetch(`../api/sales_backend.php?action=get_sale_details&sale_id=${saleId}`);
                if (!response.ok) {
                    content.innerHTML = `<div class="alert alert-danger">Server error (${response.status}). Please try again.</div>`;
                    return;
                }
                const result = await response.json();
                
                if (result.success) {
                    const sale = result.data.sale;
                    const items = result.data.items;
                    const date = new Date(sale.created_at).toLocaleString();

                    // Backend may not have total_amount/amount_paid stored in `sales`,
                    // so we compute totals from line-item subtotals to avoid ₱NaN.
                    const computedSubtotal = Array.isArray(items)
                        ? items.reduce((acc, item) => acc + (Number(item?.subtotal) || 0), 0)
                        : 0;
                    const computedTotalDue = (sale.total_amount !== undefined && sale.total_amount !== null)
                        ? Number(sale.total_amount) || computedSubtotal
                        : computedSubtotal;
                    const amountPaid = (sale.amount_paid !== undefined && sale.amount_paid !== null)
                        ? (Number(sale.amount_paid) || computedTotalDue)
                        : computedTotalDue;
                    // BIR compliance: always show a VAT/Tax-Exempt line even if this transaction is 0%/zero-rated.
                    const vatOrTaxExemptAmount = 0;
                    
                    const receiptItemsHtml = (items || []).map(item => {
                        const qty = item.quantity ?? '';
                        const unitPrice = Number(item?.unit_price);
                        const itemSubtotal = Number(item?.subtotal);
                        return `
                            <tr>
                                <td>${item.product_name} @ ${formatCurrency(unitPrice || 0)}</td>
                                <td style="text-align: center;">${qty}</td>
                                <td style="text-align: right;">${formatCurrency(itemSubtotal || 0)}</td>
                            </tr>
                        `;
                    }).join('');

                    content.innerHTML = `
                        <div class="receipt-header">
                            <h3>VIP Villanueva Ice Plant</h3>
                            <p>Official Receipt</p>
                            <p>San Martin, Villanueva, Misamis Oriental</p>
                            <p>Tel: (043) 784-XXXX</p>
                        </div>
                        
                        <div class="receipt-info">
                            <div>
                                <strong>Date:</strong> ${date}<br>
                                <strong>Cashier:</strong> ${sale.cashier_name || 'System'}<br>
                            </div>
                            <div style="text-align: right;">
                                <strong>Sale #:</strong> ${sale.Sale_ID}<br>
                                <strong>Type:</strong> ${sale.sale_type || 'Walk-in'}
                            </div>
                        </div>

                        <div style="margin-bottom: 0.5rem; font-size: 0.85rem;">
                            <strong>Customer:</strong> ${sale.customer_name || 'Walk-in Client'}
                        </div>

                        <table class="receipt-table">
                            <thead>
                                <tr>
                                    <th>Item Description</th>
                                    <th style="text-align: center;">Qty</th>
                                    <th style="text-align: right;">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${receiptItemsHtml}
                            </tbody>
                        </table>

                        <div class="receipt-totals">
                            <div class="receipt-total-row">
                                <span>Subtotal:</span>
                                <span>${formatCurrency(computedSubtotal)}</span>
                            </div>
                            <div class="receipt-total-row">
                                <span>VAT / Tax-Exempt (0%):</span>
                                <span>${formatCurrency(vatOrTaxExemptAmount)}</span>
                            </div>
                            <div class="receipt-total-row grand">
                                <span>TOTAL DUE:</span>
                                <span>${formatCurrency(computedTotalDue)}</span>
                            </div>
                            <div class="receipt-total-row" style="margin-top: 8px;">
                                <span>Amount Paid:</span>
                                <span>${formatCurrency(amountPaid)}</span>
                            </div>
                            ${sale.payment === 'AR' ? `
                                <div class="receipt-total-row">
                                    <span>Balance (AR):</span>
                                    <span>${formatCurrency(Math.max(0, computedTotalDue - amountPaid))}</span>
                                </div>
                            ` : ''}
                        </div>

                        <div class="receipt-footer">
                            <p>Thank you for choosing VIP!</p>
                            <p>This is a system-generated receipt.</p>
                        </div>
                    `;
                } else {
                    content.innerHTML = `<div class="alert alert-danger">${result.message}</div>`;
                }
            } catch (err) {
                content.innerHTML = `<div class="alert alert-danger">Failed to load receipt details.</div>`;
            }
        }

        // --- Management Logic ---

        let historySearchTimeout = null;
        function debounceHistorySearch() {
            clearTimeout(historySearchTimeout);
            historySearchTimeout = setTimeout(() => {
                openHistoryModal();
            }, 300); // 300ms delay
        }

        async function openHistoryModal() {
            document.getElementById('historyModal').style.display = 'block';
            const body = document.getElementById('historyTableBody');
            body.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 2rem;"><i class="fas fa-spinner fa-spin"></i> Loading transactions...</td></tr>';
            
            const filter = document.getElementById('historyFilter') ? document.getElementById('historyFilter').value : 'all';
            const search = document.getElementById('historySearch') ? document.getElementById('historySearch').value : '';
            
            try {
                const response = await fetch(`../api/sales_backend.php?action=get_sales_history&filter=${encodeURIComponent(filter)}&search=${encodeURIComponent(search)}`, { credentials: 'same-origin' });
                const responseText = await response.text();
                
                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (e) {
                    console.error('Invalid JSON response:', responseText);
                    throw new Error('Server returned invalid response format');
                }
                
                if (result.success) {
                    if (result.data.length === 0) {
                        body.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 2rem;">No transactions found.</td></tr>';
                        return;
                    }

                    body.innerHTML = result.data.map(sale => {
                        const date = new Date(sale.created_at).toLocaleString();
                        const isVoided = sale.status === 'Voided';
                        const statusClass = isVoided ? 'status-voided' : 'status-completed';
                        const details = sale.details || [];
                        const computedAmount = (sale.total_amount !== undefined && sale.total_amount !== null && isFinite(Number(sale.total_amount)))
                            ? Number(sale.total_amount)
                            : details.reduce((acc, d) => acc + (Number(d?.subtotal) || 0), 0);
                        
                        return `
                            <tr>
                                <td><strong>#${sale.Sale_ID}</strong></td>
                                <td>${date}</td>
                                <td>${sale.customer_name || 'Walk-in'}</td>
                                <td><span style="font-size: 0.75rem; color: var(--pos-text-muted);">${sale.sale_type || 'N/A'}</span></td>
                                <td style="text-align: right; font-weight: 700;">${formatCurrency(computedAmount)}</td>
                                <td style="text-align: center;">
                                    <span class="status-badge ${statusClass}">${sale.status}</span>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <button class="btn-mgmt-reprint" onclick="showReceipt(${sale.Sale_ID})" title="Reprint Receipt">
                                        <i class="fas fa-print"></i> Receipt
                                    </button>
                                    ${!CAN_EDIT_SALE_CUSTOMER ? '' : `
                                        <button class="btn-mgmt-void" onclick="editSaleCustomer(${sale.Sale_ID}, '${sale.customer_name || 'Walk-in'}')" title="Change Customer">
                                            <i class="fas fa-user-edit"></i> Customer
                                        </button>
                                    `}
                                </td>
                            </tr>
                        `;
                    }).join('');
                } else {
                    body.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--pos-danger); padding: 2rem;">${result.message || 'Failed to load history'}</td></tr>`;
                }
            } catch (err) {
                console.error('History fetch error:', err);
                body.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--pos-danger); padding: 2rem;"><i class="fas fa-exclamation-circle"></i> ${err.message || 'Failed to load history'}</td></tr>`;
            }
        }

        async function editSaleCustomer(saleId, currentCustomer) {
            if (!CAN_EDIT_SALE_CUSTOMER) {
                Swal.fire('Access Restricted', "You can't access this module right now.", 'warning');
                return;
            }

            const { value: customerId } = await Swal.fire({
                title: 'Change Customer',
                html: `
                    <p style="margin-bottom: 1rem;">Reassign Sale #${saleId} to a different customer.</p>
                    <p style="font-size: 0.85rem; color: #64748b; margin-bottom: 0.5rem;">Current: <strong>${currentCustomer}</strong></p>
                    <select id="customerSelect" class="swal2-input" style="width: 100%; display: none;">
                        <option value="">Select a customer...</option>
                    </select>
                    <div id="customerSelectLoading" style="text-align:center;padding:0.5rem;"><i class="fas fa-spinner fa-spin"></i> Loading customers...</div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Update Customer',
                confirmButtonColor: '#6366f1',
                didOpen: async () => {
                    try {
                        const resp = await fetch('../api/sales_backend.php?action=list_customers');
                        const result = await resp.json();
                        const select = document.getElementById('customerSelect');
                        const loading = document.getElementById('customerSelectLoading');
                        if (result.success && result.data) {
                            select.innerHTML = '<option value="">Select a customer...</option>' +
                                result.data.map(c => `<option value="${c.Customer_ID}">${c.customer_name}</option>`).join('');
                        }
                        loading.style.display = 'none';
                        select.style.display = 'block';
                    } catch(e) {
                        document.getElementById('customerSelectLoading').innerHTML = 'Failed to load customers.';
                    }
                },
                preConfirm: () => {
                    const val = document.getElementById('customerSelect').value;
                    if (!val) {
                        Swal.showValidationMessage('Please select a customer');
                        return false;
                    }
                    return val;
                }
            });

            if (customerId) {
                closeModal('historyModal');
                window.__editCustomerInProgress = true;
                document.getElementById('managerPinAction').value = 'edit_sale_customer';
                document.getElementById('managerPinData').value = JSON.stringify({ sale_id: saleId, customer_id: parseInt(customerId) });
                document.getElementById('managerPinModal').style.display = 'flex';
            }
        }

        async function voidSale(saleId) {
            const { value: reason } = await Swal.fire({
                title: 'Void Sale #' + saleId,
                text: 'Are you sure you want to void this sale? This will restore inventory.',
                icon: 'warning',
                input: 'text',
                inputLabel: 'Reason (optional)',
                inputPlaceholder: 'Enter reason for void...',
                showCancelButton: true,
                confirmButtonText: 'Yes, Void Sale',
                confirmButtonColor: '#dc2626',
                cancelButtonText: 'Cancel'
            });
            if (reason === undefined) return;

            const formData = new FormData();
            formData.append('action', 'void_sale');
            formData.append('sale_id', saleId);
            formData.append('reason', reason || '');
            formData.append('ajax', 1);
            const meta = document.querySelector('meta[name="csrf-token"]');
            if (meta) formData.append('csrf_token', meta.getAttribute('content'));

            try {
                const resp = await fetch('../api/sales_backend.php', { method: 'POST', body: formData, credentials: 'same-origin' });
                const result = await resp.json();
                if (result.success) {
                    Swal.fire({ icon: 'success', title: 'Voided', text: 'Sale #' + saleId + ' has been voided.', confirmButtonText: 'OK' });
                    openHistoryModal();
                } else {
                    Swal.fire({ icon: 'error', title: 'Void Failed', text: result.message || 'Could not void sale.', confirmButtonText: 'OK' });
                }
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to void sale: ' + err.message, confirmButtonText: 'OK' });
            }
        }

        async function openZReadModal() {
            if (!CAN_CASHIER_ZREAD) {
                Swal.fire({ icon: 'warning', title: 'Access Restricted', text: "Z-Read is not enabled for your account. Ask a manager to enable 'cashier_z_read' permission.", confirmButtonText: 'OK' });
                return;
            }
            document.getElementById('zReadModal').style.display = 'block';
            const container = document.getElementById('zReadContent');
            container.innerHTML = '<div style="text-align: center; padding: 3rem;"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Generating Daily Report...</div>';
            
            const controller = new AbortController();
            const timeoutId = setTimeout(function () { controller.abort(); }, 15000);
            
            try {
                const response = await fetch('../api/sales_backend.php?action=get_z_read', {
                    credentials: 'same-origin',
                    signal: controller.signal
                });
                clearTimeout(timeoutId);
                const result = await response.json();
                
                if (result.success) {
                    const data = result.data;
                    const noShiftNote = data.message ? `<div style="background:#fef9c3;border:1px solid #fde047;border-radius:8px;padding:0.75rem 1rem;margin-bottom:1.5rem;color:#713f12;font-size:0.875rem;"><i class="fas fa-info-circle"></i> ${data.message}</div>` : '';
                    container.innerHTML = `
                        ${noShiftNote}
                        <div class="z-read-summary">
                            <div class="z-stat-card">
                                <div class="z-stat-label">Transactions</div>
                                <div class="z-stat-value">${data.total_count}</div>
                            </div>
                            <div class="z-stat-card">
                                <div class="z-stat-label">Gross Sales</div>
                                <div class="z-stat-value">${formatCurrency(data.gross_sales)}</div>
                            </div>
                            <div class="z-stat-card" style="border-color: #fee2e2;">
                                <div class="z-stat-label" style="color: #991b1b;">Voids (${data.void_count})</div>
                                <div class="z-stat-value" style="color: #991b1b;">-${formatCurrency(data.void_amount)}</div>
                            </div>
                        </div>
                        
                        <div class="z-stat-card" style="margin-bottom: 2rem; background: #eff6ff; border-color: #bfdbfe;">
                            <div class="z-stat-label" style="color: #1e40af;">Today's Net Collections</div>
                            <div class="z-stat-value" style="color: #1e40af; font-size: 2.5rem;">${formatCurrency(data.net_sales)}</div>
                        </div>

                        <h4 style="margin-bottom: 1rem; color: var(--pos-text-muted);">Item Breakdown (Sold)</h4>
                        <table class="mgmt-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th style="text-align: center;">Qty Sold</th>
                                    <th style="text-align: right;">Total Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${data.items.length === 0 ? '<tr><td colspan="3" style="text-align: center;">No items sold today.</td></tr>' : data.items.map(item => `
                                    <tr>
                                        <td>${item.product_name}</td>
                                        <td style="text-align: center;">${item.total_qty}</td>
                                        <td style="text-align: right; font-weight: 600;">${formatCurrency(item.total_amount)}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    `;
                } else {
                    container.innerHTML = `<div style="text-align: center; padding: 2rem;"><i class="fas fa-exclamation-triangle" style="font-size:2rem;color:var(--pos-warning);margin-bottom:1rem;"></i><p style="color:var(--pos-text-muted);">${result.message || 'Unable to load Z-Read report.'}</p></div>`;
                }
            } catch (err) {
                clearTimeout(timeoutId);
                if (err.name === 'AbortError') {
                    container.innerHTML = '<div style="text-align: center; color: var(--pos-danger); padding: 2rem;"><i class="fas fa-clock" style="font-size:2rem;margin-bottom:1rem;"></i><p>Request timed out. Please check your connection and try again.</p></div>';
                } else {
                    container.innerHTML = '<div style="text-align: center; color: var(--pos-danger); padding: 2rem;"><i class="fas fa-times-circle" style="font-size:2rem;margin-bottom:1rem;"></i><p>Failed to generate Z-Read report. Please try again.</p></div>';
                }
            }
        }

        function initRealtimeRemittanceSocket() {
            const protocol = location.protocol === 'https:' ? 'wss' : 'ws';
            const socketUrl = `${protocol}://${location.hostname}:8090`;
            let socket = null;
            let reconnectDelay = 1000;
            let retryCount = 0;
            const MAX_RETRIES = 5;

            const connect = () => {
                if (retryCount >= MAX_RETRIES) {
                    if (retryCount === MAX_RETRIES) {
                        console.warn('[WS] WebSocket server unavailable after ' + MAX_RETRIES + ' attempts. Real-time updates disabled.');
                        retryCount++;
                    }
                    return;
                }
                try {
                    socket = new WebSocket(socketUrl);
                } catch (error) {
                    retryCount++;
                    setTimeout(connect, reconnectDelay);
                    reconnectDelay = Math.min(reconnectDelay * 2, 15000);
                    return;
                }

                socket.addEventListener('open', () => {
                    reconnectDelay = 1000;
                    retryCount = 0;
                });

                socket.addEventListener('message', (event) => {
                    let payload;
                    try {
                        payload = JSON.parse(event.data);
                    } catch (e) {
                        return;
                    }
                    if (!payload || !payload.event) return;

                    if (payload.event === 'delivery.remitted' || payload.event === 'delivery.remittance_recorded') {
                        Swal.fire({
                            icon: 'info',
                            title: 'Delivery Update',
                            text: 'Remittance status updated in real time.',
                            timer: 1800,
                            showConfirmButton: false
                        });
                        const q = (document.getElementById('orderSearch')?.value || '').trim();
                        if (q.length > 0) {
                            document.getElementById('orderSearch').dispatchEvent(new Event('input'));
                        }
                    }
                });

                socket.addEventListener('close', () => {
                    retryCount++;
                    setTimeout(connect, reconnectDelay);
                    reconnectDelay = Math.min(reconnectDelay * 2, 15000);
                });
                socket.addEventListener('error', () => {
                    try { socket.close(); } catch (e) {}
                });
            };
            connect();
        }

        document.addEventListener('DOMContentLoaded', initRealtimeRemittanceSocket);
    </script>
</body>
</html>
