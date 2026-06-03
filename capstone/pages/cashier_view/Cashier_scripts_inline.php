<script>
let cart = [];

function addItemFromGrid(product) {
    const existing = cart.find(i => i.product_id === product.Product_ID);
    if (existing) {
        existing.quantity = (parseFloat(existing.quantity) || 0) + 1;
    } else {
        cart.push({
            product_id: product.Product_ID,
            product_name: product.product_name,
            unit_price: parseFloat(product.retail_price) || 0,
            quantity: 1,
            reserved_stock: parseFloat(product.reserved_stock) || 0
        });
    }
    renderCart();
}

function renderCart() {
    const tbody = document.getElementById('cartBody');
    const emptyState = document.getElementById('cartEmptyState');
    const orderList = document.getElementById('orderItemsList');
    const emptyModern = document.getElementById('cartEmptyStateModern');

    let subtotal = 0;
    let totalQty = 0;

    if (cart.length === 0) {
        if (tbody) tbody.innerHTML = '';
        if (emptyState) emptyState.style.display = 'block';
        if (orderList) orderList.style.display = 'none';
        if (emptyModern) emptyModern.style.display = 'block';
        updateTotals(0, 0);
        document.getElementById('btnCheckout')?.setAttribute('disabled', 'disabled');
        return;
    }

    if (emptyState) emptyState.style.display = 'none';
    if (emptyModern) emptyModern.style.display = 'none';

    const rows = cart.map((item, idx) => {
        const qty = parseFloat(item.quantity) || 0;
        const price = parseFloat(item.unit_price) || 0;
        const sub = qty * price;
        subtotal += sub;
        totalQty += qty;
        return `<tr>
            <td>${item.product_name}</td>
            <td style="text-align: center;">
                <div class="qty-controls" style="display:inline-flex;align-items:center;gap:0.25rem;">
                    <button class="btn-qty" onclick="adjustQty(${idx}, -1)" style="padding:2px 8px;">−</button>
                    <input type="number" class="qty-input" value="${qty}" min="0.01" step="0.01" style="width:60px;text-align:center;padding:2px 4px;border:1px solid #ddd;border-radius:4px;font-size:0.85rem;" onchange="setQty(${idx}, this.value)">
                    <button class="btn-qty" onclick="adjustQty(${idx}, 1)" style="padding:2px 8px;">+</button>
                </div>
            </td>
            <td style="text-align: right;">₱${price.toFixed(2)}</td>
            <td style="text-align: right; font-weight: 700;">₱${sub.toFixed(2)}</td>
            <td><button class="btn-qty" onclick="removeItem(${idx})" style="color:#dc2626;border:none;background:none;cursor:pointer;"><i class="fas fa-times"></i></button></td>
        </tr>`;
    }).join('');

    if (tbody) tbody.innerHTML = rows;

    if (orderList) {
        const modernItems = cart.map((item, idx) => {
            const qty = parseFloat(item.quantity) || 0;
            const price = parseFloat(item.unit_price) || 0;
            const sub = qty * price;
            return `<div class="order-item-card">
                <div class="order-item-img">
                    <i class="fas fa-cube"></i>
                </div>
                <div class="order-item-info">
                    <h4>${item.product_name}</h4>
                    <div class="item-unit">₱${price.toFixed(2)} × ${qty}</div>
                </div>
                <div class="order-item-qty">
                    <button onclick="adjustQty(${idx}, -1)">−</button>
                    <input type="number" value="${qty}" min="0.01" step="0.01" onchange="setQty(${idx}, this.value)">
                    <button onclick="adjustQty(${idx}, 1)">+</button>
                </div>
                <div class="order-item-price">₱${sub.toFixed(2)}</div>
                <div class="order-item-remove" onclick="removeItem(${idx})"><i class="fas fa-times"></i></div>
            </div>`;
        }).join('');
        orderList.innerHTML = modernItems;
        orderList.style.display = 'block';
    }

    updateTotals(subtotal, totalQty);
    document.getElementById('btnCheckout')?.removeAttribute('disabled');
}

function updateTotals(subtotal, qty) {
    document.getElementById('subtotalDisplay').textContent = '₱' + subtotal.toFixed(2);
    document.getElementById('totalDueDisplay').textContent = '₱' + subtotal.toFixed(2);
    document.getElementById('totalCount').textContent = qty;
    const details = document.getElementById('itemCountDetails');
    if (details) details.textContent = cart.length + ' type(s)';
    updateChange();
}

function adjustQty(idx, delta) {
    if (!cart[idx]) return;
    let qty = parseFloat(cart[idx].quantity) || 0;
    qty = Math.max(0.01, qty + delta);
    cart[idx].quantity = qty;
    renderCart();
}

function setQty(idx, val) {
    if (!cart[idx]) return;
    const qty = Math.max(0.01, parseFloat(val) || 0);
    cart[idx].quantity = qty;
    renderCart();
}

function removeItem(idx) {
    cart.splice(idx, 1);
    renderCart();
}

function clearCart() {
    cart = [];
    renderCart();
}

function addCashAmount(amount) {
    const input = document.getElementById('amountTendered');
    if (input) {
        const current = parseFloat(input.value) || 0;
        input.value = (current + amount).toFixed(2);
        updateChange();
    }
}

function setExactAmount() {
    const totalText = document.getElementById('totalDueDisplay')?.textContent || '₱0.00';
    const total = parseFloat(totalText.replace(/[₱,]/g, '')) || 0;
    const input = document.getElementById('amountTendered');
    if (input) {
        input.value = total.toFixed(2);
        updateChange();
    }
}

function updateChange() {
    const totalText = document.getElementById('totalDueDisplay')?.textContent || '₱0.00';
    const total = parseFloat(totalText.replace(/[₱,]/g, '')) || 0;
    const tendered = parseFloat(document.getElementById('amountTendered')?.value) || 0;
    const change = Math.max(0, tendered - total);
    document.getElementById('changeDisplay').textContent = '₱' + change.toFixed(2);
}

function formatCurrency(amount) {
    return '₱' + parseFloat(amount || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function processSale() {
    if (cart.length === 0) return;

    const totalText = document.getElementById('totalDueDisplay')?.textContent || '₱0.00';
    const total = parseFloat(totalText.replace(/[₱,]/g, '')) || 0;
    const tendered = parseFloat(document.getElementById('amountTendered')?.value) || 0;

    if (tendered < total && tendered > 0) {
        if (!confirm('Amount tendered (₱' + tendered.toFixed(2) + ') is less than total (₱' + total.toFixed(2) + '). Post as partial payment?')) {
            return;
        }
    }

    const items = cart.map(i => ({
        product_id: i.product_id,
        quantity: parseFloat(i.quantity) || 0,
        unit_price: parseFloat(i.unit_price) || 0
    }));

    const formData = new FormData();
    formData.append('action', 'create_walkin_sale');
    formData.append('items', JSON.stringify(items));
    formData.append('amount_paid', tendered.toFixed(2));
    formData.append('sale_total', total.toFixed(2));
    formData.append('discount_amount', '0');
    formData.append('remarks', '');
    formData.append('ajax', 1);

    const csrfInput = document.querySelector('input[name="csrf_token"]');
    if (csrfInput) formData.append('csrf_token', csrfInput.value);

    const btn = document.getElementById('btnCheckout');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    }

    fetch('../api/sales_backend.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            const saleId = result.sale_id || result.data?.sale_id;
            cart = [];
            renderCart();
            document.getElementById('amountTendered').value = '';
            updateChange();
            if (saleId && typeof showReceipt === 'function') {
                showReceipt(saleId);
            } else {
                Swal.fire({ icon: 'success', title: 'Sale Complete', text: 'Sale recorded successfully.', confirmButtonText: 'OK' });
            }
        } else {
            Swal.fire({ icon: 'error', title: 'Sale Failed', text: result.message || 'An error occurred.', confirmButtonText: 'OK' });
        }
    })
    .catch(err => {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to process sale: ' + err.message, confirmButtonText: 'OK' });
    })
    .finally(() => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check-circle"></i> Complete Sale';
        }
    });
}

function toggleCatalog() {
    const catalog = document.querySelector('.pos-catalog-panel');
    const main = document.getElementById('posMain');
    if (catalog) {
        catalog.classList.toggle('collapsed');
        if (main) main.classList.toggle('catalog-collapsed');
    }
}

function openShiftModal() {
    if (typeof CAN_CASHIER_ZREAD === 'undefined') window.CAN_CASHIER_ZREAD = true;
    document.getElementById('shiftModal').style.display = 'block';
    const body = document.getElementById('shiftModalBody');
    if (!body) return;
    body.innerHTML = '<div style="text-align: center; padding: 3rem;"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Loading...</div>';
    const fd = new FormData();
    fd.append('action', 'get_current_shift');
    fetch('../api/shift_management.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(result => {
            if (!result.success) {
                body.innerHTML = renderNoShift(result.message);
                return;
            }
            body.innerHTML = renderShiftDashboard(result.shift, result.totals);
        })
        .catch(() => {
            body.innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--pos-danger);"><i class="fas fa-exclamation-circle"></i> Failed to load shift data.</div>';
        });
}

function renderNoShift(msg) {
    return `
        <div style="padding: 2.5rem; text-align: center;">
            <div style="width: 80px; height: 80px; border-radius: 50%; background: #f1f5f9; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem;">
                <i class="fas fa-clock" style="font-size: 2rem; color: var(--pos-text-muted);"></i>
            </div>
            <h3 style="margin: 0 0 0.5rem; font-size: 1.15rem;">No Open Shift</h3>
            <p style="color: var(--pos-text-muted); font-size: 0.9rem; margin: 0 0 1.5rem;">${msg || 'You have no open cash shift. Start one to begin recording sales.'}</p>
            <button class="btn" onclick="openCreateShiftForm()" style="padding: 0.75rem 2rem; background: var(--pos-primary); color: white; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 0.95rem;">
                <i class="fas fa-plus-circle"></i> Open Shift
            </button>
        </div>`;
}

function renderShiftDashboard(shift, totals) {
    const startDate = new Date(shift.shift_start_time).toLocaleString();
    const t = totals || {};
    return `
        <style>
            @keyframes shiftPulse {
                0% { box-shadow: 0 0 0 0 rgba(52, 211, 153, 0.6); }
                70% { box-shadow: 0 0 0 8px rgba(52, 211, 153, 0); }
                100% { box-shadow: 0 0 0 0 rgba(52, 211, 153, 0); }
            }
        </style>
        <div style="padding: 1.5rem; font-family: 'Poppins', sans-serif;">
            <!-- Hero Expected Cash Card -->
            <div style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-radius: 16px; padding: 1.75rem 2rem; color: white; margin-bottom: 1.5rem; box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.25); display: flex; justify-content: space-between; align-items: center; position: relative; overflow: hidden;">
                <div style="position: absolute; right: -20px; bottom: -20px; opacity: 0.12; font-size: 8rem; color: white; pointer-events: none;">
                    <i class="fas fa-cash-register"></i>
                </div>
                <div style="position: relative; z-index: 1;">
                    <div style="font-size: 0.85rem; opacity: 0.9; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 0.5rem;">Expected Cash in Drawer</div>
                    <div style="font-size: 2.6rem; font-weight: 850; letter-spacing: -0.02em; line-height: 1.1;">${formatCurrency(t.expected_cash)}</div>
                </div>
                <div style="text-align: right; position: relative; z-index: 1;">
                    <div style="background: rgba(255, 255, 255, 0.2); backdrop-filter: blur(8px); padding: 0.5rem 1.1rem; border-radius: 99px; font-size: 0.8rem; font-weight: 700; border: 1px solid rgba(255, 255, 255, 0.3); display: inline-flex; align-items: center; gap: 0.4rem;">
                        <span style="width: 8px; height: 8px; border-radius: 50%; background: #34d399; display: inline-block; animation: shiftPulse 2s infinite;"></span>
                        Active Shift
                    </div>
                </div>
            </div>

            <!-- Reconciliation Formula & Shift Meta info -->
            <div style="display: grid; grid-template-columns: 1.2fr 1fr; gap: 1.25rem; margin-bottom: 1.5rem;">
                <!-- Drawer Reconciliation Formula Card -->
                <div style="background: white; border: 1px solid var(--pos-border); border-radius: 16px; padding: 1.5rem; display: flex; flex-direction: column; justify-content: space-between; box-shadow: 0 4px 12px rgba(0,0,0,0.02);">
                    <div style="font-size: 0.75rem; color: var(--pos-text-muted); text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 0.85rem; border-bottom: 1px solid var(--pos-border); padding-bottom: 0.5rem;">
                        <i class="fas fa-calculator" style="margin-right: 0.25rem; color: var(--pos-primary);"></i> Drawer Reconciliation
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                            <span style="color: var(--pos-text-muted);">Starting Cash</span>
                            <span style="font-weight: 700; color: var(--pos-text);">${formatCurrency(shift.starting_cash)}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                            <span style="color: var(--pos-text-muted);">+ Walk-in Cash Sales</span>
                            <span style="font-weight: 700; color: var(--pos-success);">${formatCurrency(t.walk_in_cash || 0)}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                            <span style="color: var(--pos-text-muted);">+ Delivery Remittance Cash</span>
                            <span style="font-weight: 700; color: var(--pos-success);">${formatCurrency(t.delivery_remittance_cash || 0)}</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 0.9rem; padding-bottom: 0.5rem; border-bottom: 1px dashed var(--pos-border);">
                            <span style="color: var(--pos-text-muted);">+ AR Payments Received</span>
                            <span style="font-weight: 700; color: var(--pos-success);">${formatCurrency(t.ar_collection_cash || 0)}</span>
                        </div>
                        ${(t.cash_in_total || 0) > 0 ? `
                        <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                            <span style="color: var(--pos-text-muted);">+ Cash Movements (In)</span>
                            <span style="font-weight: 700; color: var(--pos-success);">${formatCurrency(t.cash_in_total)}</span>
                        </div>
                        ` : ''}
                        ${(t.cash_out_total || 0) > 0 ? `
                        <div style="display: flex; justify-content: space-between; font-size: 0.9rem;">
                            <span style="color: var(--pos-text-muted);">- Cash Movements (Out)</span>
                            <span style="font-weight: 700; color: #dc2626;">${formatCurrency(t.cash_out_total)}</span>
                        </div>
                        ` : ''}
                        <div style="display: flex; justify-content: space-between; font-size: 1rem; font-weight: 800; padding-top: 0.4rem;">
                            <span style="color: var(--pos-text);">Expected Total Cash</span>
                            <span style="color: #059669;">${formatCurrency(t.expected_cash)}</span>
                        </div>
                    </div>
                </div>

                <!-- Shift Details Card -->
                <div style="background: white; border: 1px solid var(--pos-border); border-radius: 16px; padding: 1.5rem; display: flex; flex-direction: column; justify-content: space-between; box-shadow: 0 4px 12px rgba(0,0,0,0.02);">
                    <div style="font-size: 0.75rem; color: var(--pos-text-muted); text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 1rem; border-bottom: 1px solid var(--pos-border); padding-bottom: 0.5rem;">
                        <i class="fas fa-info-circle" style="margin-right: 0.25rem; color: var(--pos-primary);"></i> Shift Info
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 0.85rem;">
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                            <div style="width: 32px; height: 32px; border-radius: 50%; background: #eff6ff; color: #3b82f6; display: flex; align-items: center; justify-content: center; font-size: 0.85rem; flex-shrink: 0;">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div>
                                <span style="font-size: 0.65rem; color: var(--pos-text-muted); display: block; text-transform: uppercase; font-weight: 600;">Shift Started</span>
                                <span style="font-size: 0.85rem; font-weight: 700; color: var(--pos-text);">${startDate}</span>
                            </div>
                        </div>
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                            <div style="width: 32px; height: 32px; border-radius: 50%; background: #fdf2f8; color: #ec4899; display: flex; align-items: center; justify-content: center; font-size: 0.85rem; flex-shrink: 0;">
                                <i class="fas fa-shopping-bag"></i>
                            </div>
                            <div>
                                <span style="font-size: 0.65rem; color: var(--pos-text-muted); display: block; text-transform: uppercase; font-weight: 600;">Total Transactions</span>
                                <span style="font-size: 0.85rem; font-weight: 700; color: var(--pos-text);">${t.total_count || 0} Invoice(s)</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Breakdown Grid -->
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 2rem;">
                <!-- Gross Sales -->
                <div style="background: #f8fafc; border: 1px solid var(--pos-border); border-radius: 14px; padding: 1.25rem; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.01);">
                    <div style="font-size: 0.7rem; color: var(--pos-text-muted); text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 0.5rem;">Gross Sales</div>
                    <div style="font-size: 1.35rem; font-weight: 850; color: var(--pos-primary);">${formatCurrency(t.gross_sales)}</div>
                    <div style="font-size: 0.7rem; color: var(--pos-text-muted); margin-top: 0.25rem;">Total invoices issued</div>
                </div>

                <!-- Accounts Receivable -->
                <div style="background: #f8fafc; border: 1px solid var(--pos-border); border-radius: 14px; padding: 1.25rem; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.01);">
                    <div style="font-size: 0.7rem; color: var(--pos-text-muted); text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 0.5rem;">Credit / AR</div>
                    <div style="font-size: 1.35rem; font-weight: 850; color: #6366f1;">${formatCurrency(t.credit_sales)}</div>
                    <div style="font-size: 0.7rem; color: var(--pos-text-muted); margin-top: 0.25rem;">Credit terms extended</div>
                </div>

                <!-- Voids -->
                <div style="background: #f8fafc; border: 1px solid var(--pos-border); border-radius: 14px; padding: 1.25rem; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.01);">
                    <div style="font-size: 0.7rem; color: var(--pos-text-muted); text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 0.5rem;">Voids</div>
                    <div style="font-size: 1.35rem; font-weight: 850; color: #dc2626;">${t.void_count || 0} <span style="font-size: 0.95rem; font-weight: 600;">(${formatCurrency(t.void_amount)})</span></div>
                    <div style="font-size: 0.7rem; color: var(--pos-text-muted); margin-top: 0.25rem;">Voided transactions</div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div style="display: flex; gap: 1rem;">
                <button class="btn" onclick="requestXRead()" style="flex: 1; padding: 1rem 1.5rem; background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); color: white; border: none; border-radius: 12px; font-weight: 700; cursor: pointer; font-size: 0.95rem; display: flex; align-items: center; justify-content: center; gap: 0.6rem; transition: all 0.2s ease; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(59, 130, 246, 0.3)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(59, 130, 246, 0.2)';">
                    <i class="fas fa-file-invoice" style="font-size: 1.1rem;"></i> X-Read Summary
                </button>
                <button class="btn" onclick="openClosingCount()" style="flex: 1; padding: 1rem 1.5rem; background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%); color: white; border: none; border-radius: 12px; font-weight: 700; cursor: pointer; font-size: 0.95rem; display: flex; align-items: center; justify-content: center; gap: 0.6rem; transition: all 0.2s ease; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(239, 68, 68, 0.3)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(239, 68, 68, 0.2)';">
                    <i class="fas fa-lock" style="font-size: 1.1rem;"></i> Close Cash Shift
                </button>
            </div>
        </div>`;
}

function openCreateShiftForm() {
    const body = document.getElementById('shiftModalBody');
    if (!body) return;
    body.innerHTML = `
        <div style="max-width: 420px; margin: 0 auto; padding: 2rem;">
            <div style="text-align: center; margin-bottom: 1.5rem;">
                <div style="width: 64px; height: 64px; border-radius: 50%; background: #e0f2fe; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem;">
                    <i class="fas fa-cash-register" style="font-size: 1.5rem; color: #0284c7;"></i>
                </div>
                <h3 style="margin: 0 0 0.25rem;">Open New Shift</h3>
                <p style="margin: 0; font-size: 0.85rem; color: var(--pos-text-muted);">Enter starting cash to begin your shift</p>
            </div>
            <div class="form-group" style="margin-bottom: 1.25rem;">
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.5rem; color: var(--pos-text);">Starting Cash (₱)</label>
                <input type="number" id="shiftStartingCash" class="payment-input" value="0" min="0" step="0.01" style="width: 100%; text-align: center; font-size: 1.5rem; padding: 0.75rem;">
            </div>
            <div style="display: flex; gap: 0.75rem;">
                <button onclick="openShiftModal()" style="flex: 1; padding: 0.75rem; border: 1px solid var(--pos-border); border-radius: 10px; background: white; font-weight: 600; cursor: pointer; font-size: 0.9rem;">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
                <button onclick="submitOpenShift()" style="flex: 1; padding: 0.75rem; background: var(--pos-primary); color: white; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 0.9rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                    <i class="fas fa-play-circle"></i> Start Shift
                </button>
            </div>
        </div>`;
}

function submitOpenShift() {
    const startingCash = parseFloat(document.getElementById('shiftStartingCash')?.value) || 0;
    const body = document.getElementById('shiftModalBody');
    if (!body) return;
    body.innerHTML = '<div style="text-align: center; padding: 3rem;"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Opening shift...</div>';
    const fd = new FormData();
    fd.append('action', 'open_shift');
    fd.append('starting_cash', startingCash.toString());
    fd.append('denominations', '[]');
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) fd.append('csrf_token', meta.getAttribute('content'));
    fetch('../api/shift_management.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                body.innerHTML = `
                    <div style="text-align: center; padding: 2.5rem;">
                        <div style="width: 72px; height: 72px; border-radius: 50%; background: #f0fdf4; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem;">
                            <i class="fas fa-check-circle" style="font-size: 2rem; color: var(--pos-success);"></i>
                        </div>
                        <h3 style="margin: 0 0 0.5rem;">Shift Opened</h3>
                        <p style="color: var(--pos-text-muted); margin: 0 0 1.5rem;">${result.message || 'Your shift has started.'}</p>
                        <button onclick="openShiftModal()" style="padding: 0.75rem 2rem; background: var(--pos-primary); color: white; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 0.9rem;">
                            <i class="fas fa-redo"></i> View Shift
                        </button>
                    </div>`;
            } else {
                body.innerHTML = `
                    <div style="text-align: center; padding: 2.5rem;">
                        <div style="width: 72px; height: 72px; border-radius: 50%; background: #fef2f2; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem;">
                            <i class="fas fa-exclamation-circle" style="font-size: 2rem; color: var(--pos-danger);"></i>
                        </div>
                        <h3 style="margin: 0 0 0.5rem;">Failed to Open Shift</h3>
                        <p style="color: var(--pos-text-muted); margin: 0 0 1.5rem;">${result.message || 'An error occurred.'}</p>
                        <button onclick="openCreateShiftForm()" style="padding: 0.75rem 2rem; background: var(--pos-primary); color: white; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 0.9rem;">
                            <i class="fas fa-redo"></i> Try Again
                        </button>
                    </div>`;
            }
        })
        .catch(() => {
            body.innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--pos-danger);">Network error. Please try again.</div>';
        });
}

function requestXRead() {
    closeModal('shiftModal');
    document.getElementById('managerPinAction').value = 'x_read';
    document.getElementById('managerPinData').value = '';
    document.getElementById('managerPinModal').style.display = 'flex';
}

function openClosingCount() {
    closeModal('shiftModal');
    const modal = document.getElementById('closingCountModal');
    const body = document.getElementById('closingCountBody');
    if (!modal || !body) return;
    modal.style.display = 'block';
    body.innerHTML = `
        <div style="padding: 1.75rem;">
            <!-- Header bar -->
            <div style="background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%); border-radius: 14px; padding: 1.25rem 1.5rem; color: white; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 1rem; box-shadow: 0 6px 16px rgba(239,68,68,0.2);">
                <div style="width: 44px; height: 44px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-size: 1.3rem; flex-shrink: 0;">
                    <i class="fas fa-lock"></i>
                </div>
                <div>
                    <div style="font-size: 0.7rem; opacity: 0.85; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em;">End of Shift</div>
                    <div style="font-size: 1.1rem; font-weight: 800;">Physical Cash Count</div>
                </div>
            </div>
            <!-- Instructions -->
            <p style="color: var(--pos-text-muted); font-size: 0.9rem; margin: 0 0 1.25rem; line-height: 1.6;">Count all physical bills and coins in the drawer, then enter the total below. A <strong>Manager PIN</strong> will be required to confirm the closing.</p>
            <!-- Input -->
            <div style="background: #f8fafc; border: 1px solid var(--pos-border); border-radius: 14px; padding: 1.5rem; margin-bottom: 1.5rem; text-align: center;">
                <label style="display: block; font-size: 0.75rem; font-weight: 700; color: var(--pos-text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.75rem;">Actual Cash in Drawer (₱)</label>
                <input type="number" id="closingCashAmount" class="payment-input" value="0" min="0" step="0.01" style="width: 100%; max-width: 280px; text-align: center; font-size: 2rem; font-weight: 800; padding: 0.75rem; border-radius: 10px; border: 2px solid var(--pos-border); outline: none;" onfocus="this.style.borderColor='#ef4444'" onblur="this.style.borderColor='var(--pos-border)'">
            </div>
            <!-- Buttons -->
            <div style="display: flex; gap: 0.75rem;">
                <button onclick="closeModal('closingCountModal'); openShiftModal();" style="flex: 1; padding: 0.85rem; border: 1px solid var(--pos-border); border-radius: 12px; background: white; font-weight: 600; cursor: pointer; font-size: 0.9rem; color: var(--pos-text);">
                    <i class="fas fa-arrow-left"></i> Cancel
                </button>
                <button onclick="submitCloseShift()" style="flex: 1.5; padding: 0.85rem; background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%); color: white; border: none; border-radius: 12px; font-weight: 700; cursor: pointer; font-size: 0.95rem; box-shadow: 0 4px 12px rgba(239,68,68,0.2);">
                    <i class="fas fa-lock"></i> Close Shift & Confirm
                </button>
            </div>
        </div>`;
}

function submitCloseShift() {
    const endingCash = parseFloat(document.getElementById('closingCashAmount')?.value) || 0;
    if (endingCash < 0) {
        Swal.fire({ icon: 'error', title: 'Invalid Amount', text: 'Cash amount cannot be negative.' });
        return;
    }
    closeModal('closingCountModal');
    document.getElementById('managerPinAction').value = 'close_shift';
    document.getElementById('managerPinData').value = endingCash.toString();
    document.getElementById('managerPinModal').style.display = 'flex';
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}

function validateManagerPins(event) {
    event.preventDefault();
    const pin = document.getElementById('managerPin')?.value;
    const action = document.getElementById('managerPinAction')?.value;
    const data = document.getElementById('managerPinData')?.value;

    if (!pin) {
        Swal.fire({ icon: 'warning', title: 'PIN Required', text: 'Please enter a manager PIN.' });
        return;
    }

    const formData = new FormData();
    formData.append('action', 'validate_manager_pins');
    formData.append('pin', pin);
    formData.append('pin_action', action || '');
    formData.append('pin_data', data || '');
    formData.append('ajax', 1);

    const csrfInput = document.querySelector('input[name="csrf_token"]');
    if (csrfInput) formData.append('csrf_token', csrfInput.value);

    fetch('../api/shift_management.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            closeModal('managerPinModal');
            document.getElementById('managerPin').value = '';
            if (action === 'close_shift') {
                const endingCash = parseFloat(data) || 0;
                doCloseShift(endingCash);
                return;
            }
            if (action === 'x_read') {
                doXRead();
                return;
            }
            if (result.message) {
                Swal.fire({ icon: 'success', title: 'Verified', text: result.message, confirmButtonText: 'OK' });
            }
            if (result.redirect) {
                window.location.href = result.redirect;
            }
        } else {
            Swal.fire({ icon: 'error', title: 'Verification Failed', text: result.message || 'Invalid PIN.', confirmButtonText: 'OK' });
        }
    })
    .catch(err => {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to validate PIN.', confirmButtonText: 'OK' });
    });
}

function doCloseShift(endingCash) {
    const body = document.getElementById('shiftModalBody');
    if (!body) return;
    body.innerHTML = '<div style="text-align: center; padding: 3rem;"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Closing shift...</div>';
    const fd = new FormData();
    fd.append('action', 'close_shift');
    fd.append('ending_cash', endingCash.toString());
    fd.append('manager_pins', document.getElementById('managerPin')?.value || '');
    fd.append('denominations', '[]');
    fd.append('tolerance_amount', '50');
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) fd.append('csrf_token', meta.getAttribute('content'));
    fetch('../api/shift_management.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                body.innerHTML = `
                    <div style="text-align: center; padding: 2.5rem;">
                        <div style="width: 72px; height: 72px; border-radius: 50%; background: #f0fdf4; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem;">
                            <i class="fas fa-check-circle" style="font-size: 2rem; color: var(--pos-success);"></i>
                        </div>
                        <h3 style="margin: 0 0 0.5rem;">Shift Closed</h3>
                        <p style="color: var(--pos-text-muted); margin: 0 0 1.5rem;">${result.message || 'Shift closed successfully.'}</p>
                        <button onclick="openShiftModal()" style="padding: 0.75rem 2rem; background: var(--pos-primary); color: white; border: none; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 0.9rem;">
                            <i class="fas fa-redo"></i> New Shift
                        </button>
                    </div>`;
            } else {
                Swal.fire({ icon: 'error', title: 'Close Failed', text: result.message || 'Could not close shift.', confirmButtonText: 'OK' });
                openShiftModal();
            }
        })
        .catch(() => {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Network error. Please try again.', confirmButtonText: 'OK' });
            openShiftModal();
        });
}

function doXRead() {
    const body = document.getElementById('shiftModalBody');
    if (!body) return;
    document.getElementById('shiftModal').style.display = 'block';
    body.innerHTML = '<div style="text-align: center; padding: 3rem;"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Generating X-Read...</div>';
    const fd = new FormData();
    fd.append('action', 'x_read');
    fd.append('manager_pins', document.getElementById('managerPin')?.value || '');
    fetch('../api/shift_management.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(result => {
            if (!result.success) {
                body.innerHTML = renderNoShift(result.message || 'No open shift found.');
                return;
            }
            const shift = result.shift;
            const totals = result.totals || {};
            const startDate = new Date(shift.shift_start_time).toLocaleString();
            body.innerHTML = `
                <div style="padding: 1.5rem;">
                    <!-- X-Read Header -->
                    <div style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); border-radius: 16px; padding: 1.5rem 2rem; color: white; margin-bottom: 1.5rem; box-shadow: 0 8px 20px -4px rgba(59,130,246,0.25); display: flex; justify-content: space-between; align-items: center; position: relative; overflow: hidden;">
                        <div style="position: absolute; right: -15px; bottom: -15px; opacity: 0.1; font-size: 7rem; pointer-events: none;"><i class="fas fa-file-invoice"></i></div>
                        <div style="position: relative; z-index: 1;">
                            <div style="font-size: 0.75rem; opacity: 0.85; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 0.35rem;">X-Read Summary — Mid-Shift Report</div>
                            <div style="font-size: 1rem; font-weight: 600;">Since ${startDate}</div>
                        </div>
                        <div style="background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.3); border-radius: 99px; padding: 0.4rem 1rem; font-size: 0.8rem; font-weight: 700; position: relative; z-index: 1;">
                            ${totals.total_count || 0} Invoices
                        </div>
                    </div>

                    <!-- Expected Cash Hero -->
                    <div style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-radius: 14px; padding: 1.25rem 1.75rem; color: white; margin-bottom: 1.25rem; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 6px 16px rgba(16,185,129,0.25);">
                        <div>
                            <div style="font-size: 0.75rem; opacity: 0.85; text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 0.25rem;">Expected Cash in Drawer</div>
                            <div style="font-size: 2rem; font-weight: 800; letter-spacing: -0.02em;">${formatCurrency(totals.expected_cash)}</div>
                        </div>
                        <i class="fas fa-cash-register" style="font-size: 2.5rem; opacity: 0.3;"></i>
                    </div>

                    <!-- Reconciliation Breakdown -->
                    <div style="background: white; border: 1px solid var(--pos-border); border-radius: 14px; padding: 1.25rem; margin-bottom: 1.25rem; box-shadow: 0 4px 12px rgba(0,0,0,0.02);">
                        <div style="font-size: 0.75rem; color: var(--pos-text-muted); text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 0.85rem; border-bottom: 1px solid var(--pos-border); padding-bottom: 0.5rem;">
                            <i class="fas fa-calculator" style="margin-right: 0.25rem; color: var(--pos-primary);"></i> Cash Reconciliation
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 0.5rem; font-size: 0.9rem;">
                            <div style="display: flex; justify-content: space-between;">
                                <span style="color: var(--pos-text-muted);">Starting Cash</span>
                                <span style="font-weight: 700; color: var(--pos-text);">${formatCurrency(shift.starting_cash)}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between;">
                                <span style="color: var(--pos-text-muted);">+ Walk-in Cash Sales</span>
                                <span style="font-weight: 700; color: var(--pos-success);">${formatCurrency(totals.walk_in_cash || 0)}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between;">
                                <span style="color: var(--pos-text-muted);">+ Delivery Remittance Cash</span>
                                <span style="font-weight: 700; color: var(--pos-success);">${formatCurrency(totals.delivery_remittance_cash || 0)}</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; padding-bottom: 0.5rem; border-bottom: 1px dashed var(--pos-border);">
                                <span style="color: var(--pos-text-muted);">+ AR Payments Received</span>
                                <span style="font-weight: 700; color: var(--pos-success);">${formatCurrency(totals.ar_collection_cash || 0)}</span>
                            </div>
                            ${(totals.cash_in_total || 0) > 0 ? `
                            <div style="display: flex; justify-content: space-between;">
                                <span style="color: var(--pos-text-muted);">+ Cash Movements (In)</span>
                                <span style="font-weight: 700; color: var(--pos-success);">${formatCurrency(totals.cash_in_total)}</span>
                            </div>
                            ` : ''}
                            ${(totals.cash_out_total || 0) > 0 ? `
                            <div style="display: flex; justify-content: space-between;">
                                <span style="color: var(--pos-text-muted);">- Cash Movements (Out)</span>
                                <span style="font-weight: 700; color: #dc2626;">${formatCurrency(totals.cash_out_total)}</span>
                            </div>
                            ` : ''}
                            <div style="display: flex; justify-content: space-between; font-weight: 800; padding-top: 0.4rem;">
                                <span style="color: var(--pos-text);">Expected Total Cash</span>
                                <span style="color: #059669;">${formatCurrency(totals.expected_cash)}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Sales Breakdown: 3-col -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.85rem; margin-bottom: 1.25rem;">
                        <div style="background: #f8fafc; border: 1px solid var(--pos-border); border-radius: 12px; padding: 1rem; text-align: center;">
                            <div style="font-size: 0.65rem; color: var(--pos-text-muted); text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 0.4rem;">Gross Sales</div>
                            <div style="font-size: 1.2rem; font-weight: 800; color: var(--pos-primary);">${formatCurrency(totals.gross_sales)}</div>
                            <div style="font-size: 0.65rem; color: var(--pos-text-muted); margin-top: 0.2rem;">All invoices</div>
                        </div>
                        <div style="background: #f8fafc; border: 1px solid var(--pos-border); border-radius: 12px; padding: 1rem; text-align: center;">
                            <div style="font-size: 0.65rem; color: var(--pos-text-muted); text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 0.4rem;">Cash Sales</div>
                            <div style="font-size: 1.2rem; font-weight: 800; color: var(--pos-success);">${formatCurrency(totals.cash_sales)}</div>
                            <div style="font-size: 0.65rem; color: var(--pos-text-muted); margin-top: 0.2rem;">Walk-in + Delivery</div>
                        </div>
                        <div style="background: #f8fafc; border: 1px solid var(--pos-border); border-radius: 12px; padding: 1rem; text-align: center;">
                            <div style="font-size: 0.65rem; color: var(--pos-text-muted); text-transform: uppercase; font-weight: 700; letter-spacing: 0.05em; margin-bottom: 0.4rem;">Credit / AR</div>
                            <div style="font-size: 1.2rem; font-weight: 800; color: #6366f1;">${formatCurrency(totals.credit_sales)}</div>
                            <div style="font-size: 0.65rem; color: var(--pos-text-muted); margin-top: 0.2rem;">Credit terms extended</div>
                        </div>
                    </div>

                    <!-- Voids row -->
                    <div style="background: #fff5f5; border: 1px solid #fecaca; border-radius: 12px; padding: 0.85rem 1.25rem; margin-bottom: 1.5rem; display: flex; justify-content: space-between; align-items: center;">
                        <div style="display: flex; align-items: center; gap: 0.6rem;">
                            <div style="width: 28px; height: 28px; border-radius: 50%; background: #fef2f2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 0.8rem;"><i class="fas fa-ban"></i></div>
                            <div>
                                <span style="font-size: 0.7rem; color: var(--pos-text-muted); display: block; text-transform: uppercase; font-weight: 600;">Voided Transactions</span>
                                <span style="font-size: 0.85rem; font-weight: 700; color: var(--pos-text);">${totals.void_count || 0} void(s)</span>
                            </div>
                        </div>
                        <span style="font-size: 1rem; font-weight: 800; color: #dc2626;">${formatCurrency(totals.void_amount)}</span>
                    </div>

                    <!-- Back Button -->
                    <button onclick="openShiftModal()" style="width: 100%; padding: 0.85rem; background: #f1f5f9; border: 1px solid var(--pos-border); border-radius: 12px; font-weight: 600; cursor: pointer; font-size: 0.9rem; color: var(--pos-text); display: flex; align-items: center; justify-content: center; gap: 0.5rem;">
                        <i class="fas fa-arrow-left"></i> Back to Shift Dashboard
                    </button>
                </div>`;
        })
        .catch(() => {
            body.innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--pos-danger);">Failed to load X-Read data.</div>';
        });
}

function submitPayment(event) {
    event.preventDefault();
    const form = document.getElementById('paymentForm');
    if (!form) return;
    const formData = new FormData(form);
    formData.append('action', 'record_ar_payment');
    formData.append('ajax', 1);

    fetch('../api/sales_backend.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            Swal.fire({ icon: 'success', title: 'Payment Recorded', text: result.message || 'Payment recorded successfully.', confirmButtonText: 'OK' });
            closeModal('paymentModal');
        } else {
            Swal.fire({ icon: 'error', title: 'Payment Failed', text: result.message || 'Failed to record payment.', confirmButtonText: 'OK' });
        }
    })
    .catch(err => {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Network error.', confirmButtonText: 'OK' });
    });
}

function togglePostToAR() {
    const btn = document.getElementById('post_to_ar_btn');
    const details = document.getElementById('ar_payment_details');
    const hidden = document.getElementById('post_to_ar_hidden');
    if (!btn || !details || !hidden) return;
    const isActive = btn.classList.toggle('active');
    details.style.display = isActive ? 'block' : 'none';
    hidden.value = isActive ? '1' : '0';
    if (isActive) {
        btn.innerHTML = '<i class="fas fa-check-circle"></i> Post to AR (Active)';
        btn.style.background = '#dbeafe';
        btn.style.borderColor = '#3b82f6';
    } else {
        btn.innerHTML = '<i class="fas fa-file-invoice-dollar"></i> Post to Accounts Receivable (AR)';
        btn.style.background = '';
        btn.style.borderColor = '';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('productSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const term = this.value.toLowerCase().trim();
            document.querySelectorAll('.catalog-card').forEach(card => {
                const name = card.getAttribute('data-product-name') || '';
                card.style.display = name.includes(term) ? '' : 'none';
            });
        });
    }
});
</script>
