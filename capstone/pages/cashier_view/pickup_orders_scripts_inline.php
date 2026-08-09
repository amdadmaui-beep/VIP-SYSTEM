<script>
(function () {
    let currentTab = 'open';
    let currentOrder = null;

    const fmt = (n) => '₱' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    async function loadPickupOrders(type) {
        currentTab = type === 'completed' ? 'completed' : 'open';
        document.getElementById('tabOpen').classList.toggle('active', currentTab === 'open');
        document.getElementById('tabCompleted').classList.toggle('active', currentTab === 'completed');
        const list = document.getElementById('ordersList');
        list.innerHTML = '<div class="empty-state"><i class="fas fa-spinner fa-spin"></i> Loading pickup orders...</div>';
        try {
            const response = await fetch(`../api/get_pickup_orders.php?type=${encodeURIComponent(currentTab)}`, {
                credentials: 'same-origin',
                cache: 'no-store'
            });
            const raw = await response.text();
            let data;
            try {
                data = JSON.parse(raw);
            } catch (e) {
                throw new Error('Invalid server response.');
            }
            if (!data.success) {
                list.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-triangle"></i> ${escapeHtml(data.message || 'Failed to load pickup orders.')}</div>`;
                return;
            }
            renderOrders(data.orders || []);
        } catch (error) {
            list.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-triangle"></i> ${escapeHtml(error.message || 'Failed to load pickup orders.')}</div>`;
        }
    }

    function renderOrders(orders) {
        const list = document.getElementById('ordersList');
        if (!orders.length) {
            list.innerHTML = `<div class="empty-state"><i class="fas fa-shopping-basket"></i> No ${currentTab === 'open' ? 'open pickup' : 'completed'} orders found.</div>`;
            return;
        }
        list.innerHTML = orders.map((o) => {
            const itemsHtml = (o.items || []).map((it) => `
                <tr>
                    <td>${escapeHtml(it.product_name)}${it.unit_name ? ' <span style="color:#94a3b8;">(' + escapeHtml(it.unit_name) + ')</span>' : ''}</td>
                    <td style="text-align:right;">${trimNum(it.ordered_qty)}</td>
                    <td style="text-align:right;font-weight:600;">${fmt(it.unit_price)}</td>
                </tr>`).join('');
            return `
            <div class="pickup-card">
                <div class="pickup-card-head">
                    <div class="order-id">#${o.order_id}</div>
                    <div class="order-meta">
                        <span><i class="fas fa-user"></i> ${escapeHtml(o.customer_name)}</span>
                        <span><i class="fas fa-phone"></i> ${escapeHtml(o.phone_number || '—')}</span>
                        <span><i class="fas fa-calendar"></i> ${escapeHtml((o.order_date || '').slice(0, 10))}</span>
                        <span class="order-status-pill" style="background:#e0e7ff;color:#3730a3;border:1px solid #a5b4fc;"><i class="fas fa-clock"></i> ${escapeHtml(o.order_status)}</span>
                    </div>
                </div>
                <table class="items-table">
                    <thead><tr><th>Item</th><th style="text-align:right;">Qty</th><th style="text-align:right;">Order Unit Price</th></tr></thead>
                    <tbody>${itemsHtml}</tbody>
                </table>
                <div class="pickup-card-foot">
                    <span class="pickup-total">Total: ${fmt(o.total_amount)}</span>
                    <button class="btn-settle" ${currentTab === 'open' ? '' : 'disabled'} onclick="window.openSettleModal(${o.order_id})">
                        <i class="fas fa-money-bill-wave"></i> Settle
                    </button>
                </div>
            </div>`;
        }).join('');
    }

    function openSettleModal(orderId) {
        fetch('../api/get_pickup_orders.php?type=open', { credentials: 'same-origin', cache: 'no-store' })
            .then((r) => r.json())
            .then((data) => {
                const order = (data.orders || []).find((o) => o.order_id === orderId);
                if (!order) {
                    Swal.fire('Error', 'Pickup order not found. Refresh the list and try again.', 'error');
                    return;
                }
                currentOrder = order;
                document.getElementById('settleOrderId').value = order.order_id;
                document.getElementById('settleOrderIdLabel').textContent = `#${order.order_id}`;
                document.getElementById('settleCustomerName').textContent = order.customer_name || '';
                document.getElementById('settleCustomerPhone').textContent = order.phone_number || '';

                const hasAr = !!document.getElementById('arToggleBtn');
                if (hasAr) resetArUi();

                const body = document.getElementById('settleItemsBody');
                body.innerHTML = (order.items || []).map((it, idx) => `
                    <tr>
                        <td>
                            ${escapeHtml(it.product_name)}${it.unit_name ? ' <span style="color:#94a3b8;">(' + escapeHtml(it.unit_name) + ')</span>' : ''}
                            ${it.is_discontinued ? ' <span style="color:#dc2626;font-size:0.7rem;font-weight:700;">DISCONTINUED</span>' : ''}
                            <input type="hidden" name="product_id[]" value="${it.product_id}">
                            <input type="hidden" name="order_detail_id[]" value="${it.order_detail_id}">
                            <input type="hidden" name="quantity[]" value="${it.ordered_qty}">
                        </td>
                        <td style="text-align:center;" class="pu-qty-plain">${trimNum(it.ordered_qty)}</td>
                        <td style="text-align:right;">
                            <input type="number" class="pu-price-input" name="unit_price[]" step="0.01" min="0"
                                   value="${Number(it.wholesale_price).toFixed(2)}" data-idx="${idx}" oninput="window.recalcSettle()">
                        </td>
                        <td class="pu-subtotal" data-idx="${idx}">${fmt(it.wholesale_price * it.ordered_qty)}</td>
                    </tr>`).join('');

                document.getElementById('settleRemarks').value = '';
                document.getElementById('cashReceivedInput').value = '';

                recalcSettle();
                if (hasAr) resetArUi();
                document.getElementById('settleModal').classList.add('open');
            })
            .catch(() => Swal.fire('Error', 'Failed to load pickup order details.', 'error'));
    }

    function closeSettleModal() {
        document.getElementById('settleModal').classList.remove('open');
        currentOrder = null;
    }

    function recalcSettle() {
        let subtotal = 0;
        document.querySelectorAll('#settleItemsBody tr').forEach((tr) => {
            const input = tr.querySelector('input[name="unit_price[]"]');
            const qtyInput = tr.querySelector('input[name="quantity[]"]');
            if (!input) return;
            const qty = parseFloat(qtyInput ? qtyInput.value : 0) || 0;
            const price = parseFloat(input.value) || 0;
            const line = qty * price;
            subtotal += line;
            const td = tr.querySelector('.pu-subtotal');
            if (td) td.textContent = fmt(line);
        });
        document.getElementById('settleSubtotal').textContent = fmt(subtotal);
        document.getElementById('settleGrandTotal').textContent = fmt(subtotal);
        document.getElementById('settleSaleTotal').value = subtotal.toFixed(2);
        if (document.getElementById('arToggleBtn')) syncArBalance();
    }

    function togglePickupAr() {
        const btn = document.getElementById('arToggleBtn');
        const active = btn.classList.toggle('active');
        document.getElementById('postToArHidden').value = active ? '1' : '0';
        document.getElementById('arPartialBox').style.display = active ? 'block' : 'none';
        if (active) syncArBalance();
    }

    function syncArBalance() {
        const total = parseFloat(document.getElementById('settleSaleTotal').value) || 0;
        const paid = parseFloat(document.getElementById('amountPaidInput').value) || 0;
        document.getElementById('arBalanceDisplay').value = fmt(Math.max(0, total - paid));
    }

    function resetArUi() {
        document.getElementById('postToArHidden').value = '0';
        document.getElementById('arToggleBtn').classList.remove('active');
        document.getElementById('arPartialBox').style.display = 'none';
        document.getElementById('amountPaidInput').value = '0';
        document.getElementById('arBalanceDisplay').value = '₱0.00';
    }

    function setPickupTab(type) {
        if (type === currentTab) return;
        loadPickupOrders(type);
    }

    document.getElementById('settleForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const btn = document.getElementById('confirmSettleBtn');
        btn.disabled = true;

        let hasInvalid = false;
        let invalidTotal = 0;
        document.querySelectorAll('#settleItemsBody input[name="unit_price[]"]').forEach((inp) => {
            const v = parseFloat(inp.value);
            if (!(v > 0)) {
                hasInvalid = true;
                invalidTotal += 1;
            }
        });

        if (!document.getElementById('settleItemsBody').children.length) {
            Swal.fire('Error', 'This order has no settled items.', 'error');
            btn.disabled = false;
            return;
        }
        if (hasInvalid) {
            Swal.fire('Error', `Item unit prices must be greater than zero (${invalidTotal} invalid).`, 'error');
            btn.disabled = false;
            return;
        }

        const csrf = document.getElementById('settleCsrf').value || (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
        const formData = new FormData(document.getElementById('settleForm'));
        formData.set('csrf_token', csrf);

        try {
            const response = await fetch('../api/sales_backend.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            });
            const data = await response.json();
            if (data.csrf_token) document.getElementById('settleCsrf').value = data.csrf_token;
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Pickup sale recorded',
                    text: data.message || 'Sale recorded successfully.',
                    confirmButtonColor: '#047857'
                }).then(() => {
                    closeSettleModal();
                    loadPickupOrders('open');
                });
            } else {
                Swal.fire('Error', data.message || 'Failed to record the pickup sale.', 'error');
                btn.disabled = false;
            }
        } catch (error) {
            Swal.fire('Error', error.message || 'Network error while recording the sale.', 'error');
            btn.disabled = false;
        }
    });

    document.addEventListener('input', function (e) {
        if (e.target && e.target.id === 'amountPaidInput') {
            syncArBalance();
        }
    });

    function trimNum(v) {
        const n = Number(v || 0);
        return Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/\.?0+$/, '');
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    window.loadPickupOrders = loadPickupOrders;
    window.setPickupTab = setPickupTab;
    window.openSettleModal = openSettleModal;
    window.closeSettleModal = closeSettleModal;
    window.recalcSettle = recalcSettle;
    window.togglePickupAr = togglePickupAr;

    document.addEventListener('DOMContentLoaded', function () {
        loadPickupOrders('open');
    });
})();