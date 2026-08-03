// Orders Page JavaScript - POS System Logic
// Safety: prevent crashes if accidentally included twice.
if (window.__ordersJsLoaded) {
    console.warn('orders.js loaded twice; skipping duplicate execution.');
} else {
    window.__ordersJsLoaded = true;

    let orderCart = [];
    let currentCategoryFilter = 0;
    let editingOrderId = null;

function getPageCsrfToken() {
    if (typeof window.csrfToken === 'string' && window.csrfToken) {
        return window.csrfToken;
    }
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) {
        window.csrfToken = meta.content;
        return meta.content;
    }
    const input = document.querySelector('input[name="csrf_token"]');
    if (input && input.value) {
        window.csrfToken = input.value;
        return input.value;
    }
    return '';
}

function getOrdersFormAction() {
    const parts = (window.location.pathname || '').split('/');
    const page = parts[parts.length - 1] || 'orders.php';
    return page;
}

function applyPageCsrfToken(token) {
    if (!token || typeof token !== 'string') return;
    window.csrfToken = token;
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) meta.setAttribute('content', token);
    document.querySelectorAll('input[name="csrf_token"]').forEach((input) => {
        input.value = token;
    });
}

function appendCsrfField(form) {
    const token = getPageCsrfToken();
    if (!token) return;
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = token;
    form.appendChild(csrfInput);
}

function getProductImageUrl(filename) {
    if (!filename || String(filename).trim() === '') return '';
    const base = (window.ORDERS_PRODUCT_IMAGE_BASE || '../uploads/products/').replace(/\/?$/, '/');
    const clean = String(filename).replace(/^\/+/, '').replace(/^uploads\/products\//, '');
    return base + encodeURIComponent(clean).replace(/%2F/g, '/');
}

// Initialize listeners on page load
document.addEventListener('DOMContentLoaded', function() {
    renderProductCatalog();
    
    const discInput = document.getElementById('discount_amount');
    if (discInput) {
        discInput.addEventListener('input', updateCartTotals);
        discInput.addEventListener('change', updateCartTotals);
    }
    
    // Handle form submission
    const form = document.getElementById('createOrderForm');
    if (form) {
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const customerId = document.getElementById('customer_id');
            if (!customerId || !customerId.value) {
                Swal.fire('Error', 'Please select a customer before confirming the order.', 'error');
                customerId.focus();
                return;
            }

            if (orderCart.length === 0) {
                Swal.fire('Error', 'Please add at least one item to the order.', 'error');
                return;
            }

            // checkCustomerCredit might still be running or was already run
            // Show credit advisory if customer has balance or is near limit
            if (window.customerHasBalance && window.customerIsOverLimit) {
                Swal.fire({
                    title: 'Credit Limit Fully Used',
                    text: `This customer's total balance (₱${window.customerRemainingBalance.toLocaleString(undefined, {minimumFractionDigits: 2})}) has reached their credit limit. You may still create orders, but consider Cash-Only terms until payment is received.`,
                    icon: 'warning',
                    confirmButtonText: 'Proceed anyway',
                    confirmButtonColor: '#f59e0b'
                });
            } else if (window.customerHasBalance && window.customerAvailableCredit !== undefined && window.customerAvailableCredit > 0) {
                const result = await Swal.fire({
                    title: 'Customer Has Outstanding Balance',
                    html: `<div style="text-align: left;">
                            <p>This customer has a remaining balance of <strong>₱${window.customerRemainingBalance.toLocaleString(undefined, {minimumFractionDigits: 2})}</strong> in Accounts Receivable.</p>
                            <p>Available credit: <strong>₱${window.customerAvailableCredit.toLocaleString(undefined, {minimumFractionDigits: 2})}</strong></p>
                            <p>Do you want to proceed with creating this new order?</p>
                           </div>`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, proceed',
                    cancelButtonText: 'No, stop',
                    confirmButtonColor: '#f59e0b'
                });

                if (!result.isConfirmed) {
                    return;
                }
            }
            
            // Format items for backend submission
            const itemsToSubmit = orderCart.map(item => ({
                product_id: item.id,
                quantity: parseFloat(item.quantity),
                unit_price: parseFloat(item.price)
            }));
            
            const existingItemsInput = form.querySelector('input[name="items"]');
            if (existingItemsInput) {
                existingItemsInput.remove();
            }

            const itemsInput = document.createElement('input');
            itemsInput.type = 'hidden';
            itemsInput.name = 'items';
            itemsInput.value = JSON.stringify(itemsToSubmit);
            form.appendChild(itemsInput);
            
            // Submit form
            form.submit();
        });
    }
    
    // Close modal on outside click (if clicking exactly on the overlay)
    const modal = document.getElementById('createOrderModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeCreateOrderModal();
            }
        });
    }
    
    // Close order details modal on outside click
    document.addEventListener('click', function(e) {
        const orderDetailsModal = document.getElementById('orderDetailsModal');
        if (orderDetailsModal && e.target === orderDetailsModal) {
            closeOrderDetailsModal();
        }
        
        const receiptModal = document.getElementById('receiptModal');
        if (receiptModal && e.target === receiptModal) {
            closeReceiptPreview();
        }
    });

    // Close preview on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeReceiptPreview();
        }
    });

    document.querySelectorAll('.mark-scheduled-btn').forEach((button) => {
        button.addEventListener('click', () => {
            const orderId = parseInt(button.dataset.orderId || '0', 10);
            if (!orderId) return;
            promptAndScheduleOrder(orderId, {
                deliveryDate: button.dataset.deliveryDate || '',
                riderId: button.dataset.riderId || '',
                riderName: button.dataset.riderName || '',
                notes: button.dataset.notes || ''
            });
        });
    });

    document.querySelectorAll('.edit-order-btn').forEach((button) => {
        button.addEventListener('click', () => {
            const orderId = parseInt(button.dataset.orderId || '0', 10);
            if (!orderId) return;
            openEditOrder(orderId);
        });
    });

    document.querySelectorAll('.reorder-btn').forEach((button) => {
        button.addEventListener('click', () => {
            const orderId = parseInt(button.dataset.orderId || '0', 10);
            if (!orderId) return;
            reorderOrder(orderId);
        });
    });
});

function submitOrderStatusUpdate(orderId, newStatus, extraFields = {}) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = getOrdersFormAction();

    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'update_status';
    form.appendChild(actionInput);

    appendCsrfField(form);

    const orderIdInput = document.createElement('input');
    orderIdInput.type = 'hidden';
    orderIdInput.name = 'order_id';
    orderIdInput.value = orderId;
    form.appendChild(orderIdInput);

    const statusInput = document.createElement('input');
    statusInput.type = 'hidden';
    statusInput.name = 'new_status';
    statusInput.value = newStatus;
    form.appendChild(statusInput);

    Object.keys(extraFields).forEach((key) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = extraFields[key];
        form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();
}

// Show create order POS modal
function showCreateOrderModal() {
    const m = document.getElementById('createOrderModal');
    if (m) {
        m.style.display = 'block';
        m.setAttribute('aria-hidden', 'false');
    }
    orderCart = [];
    editingOrderId = null;
    const form = document.getElementById('createOrderForm');
    if (form) {
        const actionInput = form.querySelector('input[name="action"]');
        if (actionInput) actionInput.value = 'create_order';
        const existingOrderIdInput = form.querySelector('input[name="order_id"]');
        if (existingOrderIdInput) existingOrderIdInput.remove();
    }
    const modalTitle = document.getElementById('createOrderTitle');
    if (modalTitle) {
        modalTitle.innerHTML = '<i class="fas fa-file-invoice"></i> New delivery order';
        modalTitle.style.color = '#ffffff';
    }
    renderCart();
    renderProductCatalog();
    document.getElementById('catalogSearchInput').value = '';
    updateLastOrderButtonState();
}

// Close create order POS modal
function closeCreateOrderModal() {
    const m = document.getElementById('createOrderModal');
    if (m) {
        m.style.display = 'none';
        m.setAttribute('aria-hidden', 'true');
    }
    document.getElementById('createOrderForm').reset();
    orderCart = [];
    editingOrderId = null;
    
    // Reset credit warning
    const warningDiv = document.getElementById('credit_warning');
    const submitBtn = document.getElementById('submitOrderBtn');
    if (warningDiv) warningDiv.style.display = 'none';
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.title = "";
    }
    updateLastOrderButtonState();
}

async function openEditOrder(orderId) {
    try {
        const response = await fetch(`../api/get_order_details.php?order_id=${orderId}`, {
            credentials: 'same-origin',
            cache: 'no-store'
        });
        const rawText = await response.text();
        let data;
        try {
            data = JSON.parse(rawText);
        } catch (parseErr) {
            throw new Error('Invalid server response while loading order details.');
        }
        if (!data.success) {
            Swal.fire('Error', data.message || data.error || 'Failed to load order for editing.', 'error');
            return;
        }

        showCreateOrderModal();
        editingOrderId = orderId;

        const form = document.getElementById('createOrderForm');
        const actionInput = form.querySelector('input[name="action"]');
        if (actionInput) actionInput.value = 'update_order';

        let orderIdInput = form.querySelector('input[name="order_id"]');
        if (!orderIdInput) {
            orderIdInput = document.createElement('input');
            orderIdInput.type = 'hidden';
            orderIdInput.name = 'order_id';
            form.appendChild(orderIdInput);
        }
        orderIdInput.value = String(orderId);

        const customerSelect = document.getElementById('customer_id');
        if (customerSelect && data.customer_id) {
            customerSelect.value = String(data.customer_id);
            onCustomerChange(customerSelect);
        }
        if (document.getElementById('delivery_address')) {
            document.getElementById('delivery_address').value = data.delivery_address || '';
        }
        if (document.getElementById('order_date')) {
            document.getElementById('order_date').value = (data.order_date || '').slice(0, 10);
        }
        if (document.getElementById('delivery_date')) {
            document.getElementById('delivery_date').value = (data.delivery_date || '').slice(0, 10);
        }
        if (document.getElementById('discount_amount')) {
            document.getElementById('discount_amount').value = data.discount_amount || 0;
        }
        const modalTitle = document.getElementById('createOrderTitle');
        if (modalTitle) {
            modalTitle.innerHTML = `<i class="fas fa-pen"></i> Edit order #${orderId}`;
            modalTitle.style.color = '#ffffff';
        }

        orderCart = (data.items || []).map((item) => ({
            id: item.product_id,
            name: item.product_name,
            unit: item.unit || 'unit',
            quantity: parseFloat(item.quantity || 0),
            price: parseFloat(item.unit_price || 0),
            wPrice: parseFloat(item.unit_price || 0),
            image_url: (productsData.find(p => p.Product_ID === item.product_id) || {}).image_url || null
        }));
        updateLastOrderButtonState();
        renderCart();
        Swal.fire({ icon: 'info', title: `Editing Order #${orderId}`, timer: 1500, showConfirmButton: false });
    } catch (error) {
        Swal.fire('Error', error.message || 'Failed to load order for editing.', 'error');
    }
}

function updateLastOrderButtonState() {
    const customerSelect = document.getElementById('customer_id');
    const button = document.getElementById('loadLastOrderBtn');
    const hint = document.getElementById('lastOrderHint');
    if (!button || !customerSelect) {
        return;
    }

    const hasCustomer = !!customerSelect.value;
    button.disabled = !hasCustomer;

    if (!hint) {
        return;
    }

    if (!hasCustomer) {
        hint.textContent = 'Select a customer to load their latest non-cancelled order items.';
        return;
    }

    hint.textContent = editingOrderId
        ? 'Load this customer’s previous order items into the current edit form.'
        : 'Load this customer’s latest order items into a new order.';
}

async function loadLastOrderForSelectedCustomer() {
    const customerSelect = document.getElementById('customer_id');
    if (!customerSelect || !customerSelect.value) {
        Swal.fire('Select Customer', 'Choose a customer first before loading the last order.', 'info');
        return;
    }

    const customerId = parseInt(customerSelect.value || '0', 10);
    if (!customerId) {
        return;
    }

    const button = document.getElementById('loadLastOrderBtn');
    const hint = document.getElementById('lastOrderHint');
    const originalButtonHtml = button ? button.innerHTML : '';
    if (button) {
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
    }
    if (hint) {
        hint.textContent = 'Loading customer order history...';
    }

    try {
        const params = new URLSearchParams({ customer_id: String(customerId) });
        if (editingOrderId) {
            params.set('exclude_order_id', String(editingOrderId));
        }

        const response = await fetch(`../api/get_customer_last_order.php?${params.toString()}`, {
            credentials: 'same-origin',
            cache: 'no-store'
        });
        const rawText = await response.text();
        let data;
        try {
            data = JSON.parse(rawText);
        } catch (parseErr) {
            throw new Error('Invalid server response while loading the customer order.');
        }

        if (!data.success) {
            throw new Error(data.message || data.error || 'No previous order found for this customer.');
        }

        const confirmResult = await Swal.fire({
            title: 'Load Last Order?',
            html: `This will replace the current cart with items from Order #${data.order_id}.`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Load Order'
        });
        if (!confirmResult.isConfirmed) {
            updateLastOrderButtonState();
            if (button) {
                button.innerHTML = originalButtonHtml;
            }
            return;
        }

        if (document.getElementById('delivery_address')) {
            document.getElementById('delivery_address').value = data.delivery_address || '';
        }
        if (document.getElementById('discount_amount')) {
            document.getElementById('discount_amount').value = data.discount_amount || 0;
        }
        if (document.getElementById('delivery_date')) {
            document.getElementById('delivery_date').value = '';
        }

        orderCart = (data.items || []).map((item) => ({
            id: parseInt(item.product_id || 0, 10),
            name: item.product_name || 'Unknown Product',
            unit: item.unit || 'unit',
            quantity: parseFloat(item.quantity || 0),
            price: parseFloat(item.unit_price || 0),
            wPrice: parseFloat(item.unit_price || 0),
            image_url: (productsData.find(p => p.Product_ID === parseInt(item.product_id || 0, 10)) || {}).image_url || null
        })).filter((item) => item.id > 0 && item.quantity > 0);

        onCustomerChange(customerSelect);
        renderCart();

        if (hint) {
            hint.textContent = `Loaded ${orderCart.length} item(s) from Order #${data.order_id}.`;
        }
        Swal.fire({
            icon: 'success',
            title: 'Last order loaded',
            text: `Order #${data.order_id} was loaded into the cart.`,
            timer: 1800,
            showConfirmButton: false
        });
    } catch (error) {
        if (hint) {
            hint.textContent = error.message || 'Unable to load the customer’s last order.';
        }
        Swal.fire('Unable to Load', error.message || 'Unable to load the customer’s last order.', 'error');
    } finally {
        if (button) {
            button.innerHTML = originalButtonHtml;
        }
        updateLastOrderButtonState();
    }
}

function reorderOrder(orderId) {
    Swal.fire({
        title: 'Create Reorder?',
        text: `This will duplicate Order #${orderId} as a new pending order.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Create Reorder'
    }).then((result) => {
        if (!result.isConfirmed) return;
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = getOrdersFormAction();

        const fields = {
            action: 'reorder_order',
            order_id: String(orderId),
            csrf_token: getPageCsrfToken()
        };
        Object.keys(fields).forEach((key) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = fields[key];
            form.appendChild(input);
        });
        document.body.appendChild(form);
        form.submit();
    });
}

// POS: Render Product Catalog
function renderProductCatalog() {
    const catalogContainer = document.getElementById('productCatalog');
    if (!catalogContainer || !productsData) return;
    
    const searchTerm = (document.getElementById('catalogSearchInput').value || '').toLowerCase();
    
    catalogContainer.innerHTML = '';
    
    let visibleCount = 0;
    
    productsData.forEach(product => {
        // Filter by text
        if (searchTerm && !product.product_name.toLowerCase().includes(searchTerm)) {
            return;
        }
        
        // Filter by category
        if (currentCategoryFilter !== 0) {
            if (product.category_id !== currentCategoryFilter) return;
        }
        
        const priceToDisplay = parseFloat(product.wholesale_price).toFixed(2);
        const currentQty = parseFloat(product.current_quantity || 0);
        const inCart = orderCart.filter(i => i.id === product.Product_ID).reduce((sum, i) => sum + i.quantity, 0);
        const remainingQty = Math.max(0, currentQty - inCart);
        
        // Define badges logic like in POS
        let badgeClass = 'badge-success';
        let badgeText = remainingQty > 0 ? 'QTY: ' + remainingQty : 'QTY: 0';
        if (remainingQty <= 0) {
            badgeClass = 'badge-danger';
            badgeText = 'OUT';
        } else if (remainingQty <= 5) {
            badgeClass = 'badge-warning';
            badgeText = 'LOW: ' + remainingQty;
        }

        const iconName = product.product_name.toLowerCase().includes('crush') ? 'fa-snowflake' : 'fa-cubes';
        
        const card = document.createElement('div');
        card.className = 'product-card-pos slide-in'; // Match POS CSS class
        card.style.position = 'relative';
        card.onclick = () => addToCart(product);
        
        // Generate image HTML
        let imageHtml = `<div class="product-card-icon"><i class="fas ${iconName}"></i></div>`;
        if (product.image_url && product.image_url.trim() !== '') {
            const imgSrc = getProductImageUrl(product.image_url);
            imageHtml = `<img src="${imgSrc}" alt="${product.product_name}" style="width:100%; height:120px; object-fit:cover; border-radius:8px; margin-bottom:0.5rem; background:#f8fafc;" onerror="this.style.display='none';this.parentElement.insertAdjacentHTML('afterbegin','<div class=\'product-card-icon\'><i class=\'fas fa-cubes\'></i></div>')">`;
        }
        
        card.innerHTML = `
            ${imageHtml}
            <div class="product-card-info">
                <h4>${product.product_name}</h4>
                <p>${product.unit || 'Unit'}</p>
            </div>
            <span class="product-card-price">₱${priceToDisplay}</span>
            <span class="qty-badge-pos ${badgeClass}">${badgeText}</span>
        `;
        
        catalogContainer.appendChild(card);
        visibleCount++;
    });
    
    if (visibleCount === 0) {
        catalogContainer.innerHTML = `<div style="grid-column: 1/-1; text-align: center; color: #94a3b8; padding: 2rem;">No products found in this category.</div>`;
    }
}

function filterCatalog() {
    renderProductCatalog();
}

function setCatalogFilter(filter, el) {
    currentCategoryFilter = parseInt(filter, 10);
    document.querySelectorAll('.catalog-filter-tab').forEach(b => b.classList.remove('active'));
    el.classList.add('active');
    renderProductCatalog();
}

function onCustomerChange(select) {
    updateLastOrderButtonState();
}

function addToCart(product) {
    const existingIndex = orderCart.findIndex(i => i.id === product.Product_ID);
    const isNew = existingIndex < 0;
    if (existingIndex >= 0) {
        orderCart[existingIndex].quantity++;
    } else {
        orderCart.push({
            id: product.Product_ID,
            name: product.product_name,
            unit: product.unit,
            price: product.wholesale_price,
            wPrice: product.wholesale_price,
            quantity: 1,
            image_url: product.image_url || null
        });
    }
    renderCart();
    renderProductCatalog();
    if (isNew) {
        const container = document.getElementById('cartItemsContainer');
        const lastItem = container?.lastElementChild;
        if (lastItem && lastItem.classList.contains('cart-item-row-pos')) {
            lastItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }
}

function updateCartItemQty(index, delta) {
    const newQty = orderCart[index].quantity + delta;
    if (newQty > 0) {
        orderCart[index].quantity = newQty;
    } else {
        removeFromCart(index);
    }
    renderCart();
    renderProductCatalog();
}

function removeFromCart(index) {
    orderCart.splice(index, 1);
    renderCart();
    renderProductCatalog();
}

function setCartItemQty(index, val) {
    const newQty = parseFloat(val);
    if (!isNaN(newQty) && newQty > 0) {
        orderCart[index].quantity = newQty;
    } else if (newQty === 0) {
        removeFromCart(index);
    }
    renderCart();
    renderProductCatalog();
}

function renderCart() {
    const container = document.getElementById('cartItemsContainer');
    const countBadge = document.getElementById('cartCount');
    if (!container || !countBadge) return;
    
    if (orderCart.length === 0) {
        container.innerHTML = `<div class="cart-empty-msg">No items in cart</div>`;
        countBadge.textContent = '0 items';
        updateCartTotals();
        return;
    }
    
    container.innerHTML = '';
    
    let totalItems = 0;
    
    orderCart.forEach((item, index) => {
        totalItems += item.quantity;
        const subtotal = item.price * item.quantity;
        
        const row = document.createElement('div');
        row.className = 'cart-item-row-pos';
        
        // Generate thumbnail for cart item
        const thumbHtml = item.image_url && item.image_url.trim() !== ''
            ? `<img src="${getProductImageUrl(item.image_url)}" alt="${item.name}" style="width:44px;height:44px;object-fit:cover;border-radius:8px;flex-shrink:0;background:#f8fafc;" onerror="this.outerHTML='<div style=\'width:44px;height:44px;background:#eff6ff;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;\'><i class=\'fas fa-cubes\' style=\'color:#3b82f6;font-size:1.1rem;\'></i></div>'">`
            : `<div style="width:44px;height:44px;background:#eff6ff;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fas fa-cubes" style="color:#3b82f6;font-size:1.1rem;"></i></div>`;
        
        row.innerHTML = `
            ${thumbHtml}
            <div class="cart-item-info">
                <h5>${item.name}</h5>
                <p>₱${parseFloat(item.price).toFixed(2)} × ${item.quantity}</p>
            </div>
            
            <div class="cart-item-controls">
                <button type="button" class="qty-btn" onclick="updateCartItemQty(${index}, -1)">-</button>
                <input type="number" class="qty-val" value="${item.quantity}" 
                    onchange="setCartItemQty(${index}, this.value)"
                    onclick="this.select()">
                <button type="button" class="qty-btn" onclick="updateCartItemQty(${index}, 1)">+</button>
            </div>
            
            <div class="cart-item-subtotal">₱${subtotal.toFixed(2)}</div>
            <div style="color:#ef4444;cursor:pointer;padding:0.25rem;" onclick="removeFromCart(${index})" title="Remove Item">
                <i class="fas fa-times" style="font-size:1rem;"></i>
            </div>
        `;
        
        container.appendChild(row);
    });
    
    countBadge.textContent = `${totalItems} items`;
    updateCartTotals();
}

function updateCartTotals() {
    const subEl = document.getElementById('co-live-subtotal');
    const grandEl = document.getElementById('co-live-grand');
    const discInput = document.getElementById('discount_amount');
    
    if (!subEl || !grandEl) return;
    
    let sub = 0;
    orderCart.forEach(item => {
        sub += item.price * item.quantity;
    });
    
    const disc = Math.max(0, parseFloat(discInput && discInput.value) || 0);
    const grand = Math.max(0, sub - disc);
    
    subEl.textContent = '₱' + sub.toFixed(2);
    grandEl.textContent = '₱' + grand.toFixed(2);
}

// Receipt Preview Logic
function showReceiptPreview() {
    const custSelect = document.getElementById('customer_id');
    if (!custSelect || !custSelect.value) {
        alert("Please select a customer first.");
        if(custSelect) custSelect.focus();
        return;
    }

    if (orderCart.length === 0) {
        alert("Cannot generate receipt for an empty order.");
        return;
    }
    
    const customerName = custSelect.options[custSelect.selectedIndex]?.text.split(' ·')[0] || 'Walk-in Customer';
    
    document.getElementById('receiptDate').textContent = new Date().toLocaleString() + ' | CASH ON DELIVERY';
    document.getElementById('receiptCustomer').textContent = 'CUSTOMER: ' + customerName;
    
    const tbody = document.getElementById('receiptItems');
    tbody.innerHTML = '';
    
    let sub = 0;
    orderCart.forEach(item => {
        const tr = document.createElement('tr');
        const st = item.price * item.quantity;
        sub += st;
        tr.innerHTML = `
            <td class="text-left">${item.name}</td>
            <td class="text-right">${item.quantity}</td>
            <td class="text-right">${parseFloat(item.price).toFixed(2)}</td>
            <td class="text-right">${st.toFixed(2)}</td>
        `;
        tbody.appendChild(tr);
    });
    
    const disc = parseFloat(document.getElementById('discount_amount').value || 0);
    const grand = Math.max(0, sub - disc);
    
    document.getElementById('receiptSubtotal').textContent = sub.toFixed(2);
    document.getElementById('receiptGrand').textContent = grand.toFixed(2);
    
    document.getElementById('receiptModal').style.display = 'flex';
}

function closeReceiptPreview() {
    document.getElementById('receiptModal').style.display = 'none';
}

// View order details
function viewOrderDetails(orderId) {
    if (!orderId) {
        alert('Invalid order ID');
        return;
    }
    
    // Show loading state
    const modalHtml = `
        <div id="orderDetailsModal" class="order-details-modal" style="display: block; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15,23,42,0.75); backdrop-filter: blur(8px); z-index: 1000; overflow-y: auto; font-family: 'Poppins', sans-serif;">
            <div style="max-width: 700px; width: 92vw; margin: 2rem auto; background: #f8fafc; border-radius: 20px; padding: 0; max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.4);">
                <!-- Brand Blue Header -->
                <div style="background: linear-gradient(135deg, #7350F5 0%, #5b3fd4 55%, #4338ca 100%); padding: 1.5rem 2rem; display: flex; align-items: center; justify-content: space-between; border-radius: 20px 20px 0 0;">
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <div style="width: 48px; height: 48px; background: rgba(255,255,255,0.18); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-shopping-cart" style="color: #ffffff; font-size: 1.25rem;"></i>
                        </div>
                        <div>
                            <h2 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: white;">Order Details</h2>
                            <p style="margin: 0.25rem 0 0 0; font-size: 0.875rem; color: rgba(255,255,255,0.82);">View complete order information</p>
                        </div>
                    </div>
                    <button onclick="closeOrderDetailsModal()" style="background: rgba(255,255,255,0.1); border: none; width: 40px; height: 40px; border-radius: 10px; cursor: pointer; color: #cbd5e1; display: flex; align-items: center; justify-content: center; transition: all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.2)';this.style.color='white';" onmouseout="this.style.background='rgba(255,255,255,0.1)';this.style.color='#cbd5e1';">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div id="orderDetailsBody" style="padding: 1.5rem;">
                    <div style="text-align: center; padding: 3rem; color: #64748b;">
                        <i class="fas fa-spinner fa-spin" style="font-size: 2rem; margin-bottom: 1rem; color: #6366f1;"></i>
                        <p style="margin: 0;">Loading order details...</p>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Remove existing modal if any
    const existingModal = document.getElementById('orderDetailsModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Add modal to body
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    
    // Fetch order details
    fetch(`../api/get_order_details.php?order_id=${orderId}`, {
        credentials: 'same-origin',
        cache: 'no-store'
    })
        .then(async (response) => {
            const rawText = await response.text();
            let data;
            try {
                data = JSON.parse(rawText);
            } catch (parseErr) {
                throw new Error('Invalid server response while loading order details.');
            }
            if (!response.ok || !data.success) {
                throw new Error(data.message || data.error || 'Failed to load order details.');
            }
            return data;
        })
        .then(data => {
            const bodyEl = document.getElementById('orderDetailsBody');
            if (!data.success) {
                bodyEl.innerHTML = `<div class="alert alert-danger">${data.message || 'Failed to load order details'}</div>`;
                return;
            }

            function escapeHtml(s) {
                if (s == null || s === '') return '';
                return String(s)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;');
            }
            
            const addr = (data.delivery_address || '').trim()
                ? escapeHtml(data.delivery_address)
                : '<span style="color:#94a3b8;">—</span>';
            const phone = (data.phone_number || '').trim()
                ? escapeHtml(data.phone_number)
                : '';
            const disc = Number(data.discount_amount || 0);
            const sub = Number(data.subtotal != null ? data.subtotal : 0);
            const grand = Number(data.grand_total != null ? data.grand_total : 0);
            const cancellationReason = (data.cancellation_reason || '').trim();
            const cancellationRemarks = (data.cancellation_remarks || '').trim();
            const isCancelledOrder = String(data.order_status || '').toLowerCase() === 'cancelled';

            const statusColors = {
                'pending': { bg: '#fef3c7', text: '#92400e', icon: 'fa-clock' },
                'requested': { bg: '#fef3c7', text: '#92400e', icon: 'fa-clock' },
                'confirmed': { bg: '#dbeafe', text: '#1e40af', icon: 'fa-check' },
                'scheduled for delivery': { bg: '#e0e7ff', text: '#3730a3', icon: 'fa-truck' },
                'completed': { bg: '#d1fae5', text: '#065f46', icon: 'fa-check-circle' },
                'cancelled': { bg: '#fee2e2', text: '#991b1b', icon: 'fa-times-circle' }
            };
            const statusKey = (data.order_status || '').toLowerCase();
            const statusStyle = statusColors[statusKey] || { bg: '#f3f4f6', text: '#374151', icon: 'fa-info-circle' };

            let html = `
                <!-- Two Column Info Cards -->
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <!-- Order Info Card -->
                    <div style="background: white; border-radius: 12px; padding: 1.25rem; border: 1px solid #e2e8f0;">
                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem;">
                            <div style="width: 32px; height: 32px; background: #dbeafe; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-info-circle" style="color: #3b82f6; font-size: 0.875rem;"></i>
                            </div>
                            <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Order Info</span>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <span style="font-size: 0.875rem; color: #64748b; width: 50px;">ID:</span>
                                <span style="background: #f1f5f9; padding: 0.25rem 0.75rem; border-radius: 6px; font-size: 0.875rem; font-weight: 600; color: #1e293b;">#${orderId}</span>
                            </div>
                            ${data.order_status ? `
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <span style="font-size: 0.875rem; color: #64748b; width: 50px;">Status:</span>
                                    <span style="display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.375rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; background: ${statusStyle.bg}; color: ${statusStyle.text};">
                                        <i class="fas ${statusStyle.icon}" style="font-size: 0.625rem;"></i>
                                        ${escapeHtml(data.order_status)}
                                    </span>
                                </div>
                            ` : ''}
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <span style="font-size: 0.875rem; color: #64748b; width: 50px;">Bags:</span>
                                <span style="font-size: 0.875rem; font-weight: 600; color: #1e293b;">${data.required_bags || 0} required</span>
                            </div>
                        </div>
                    </div>

                    <!-- Customer Info Card -->
                    <div style="background: white; border-radius: 12px; padding: 1.25rem; border: 1px solid #e2e8f0;">
                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem;">
                            <div style="width: 32px; height: 32px; background: #fce7f3; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-user" style="color: #ec4899; font-size: 0.875rem;"></i>
                            </div>
                            <span style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Customer & Delivery</span>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                            ${data.customer_name ? `
                                <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
                                    <span style="font-size: 0.875rem; color: #64748b; width: 60px; flex-shrink: 0;">Client:</span>
                                    <span style="font-size: 0.875rem; font-weight: 600; color: #1e293b;">${escapeHtml(data.customer_name)}</span>
                                </div>
                            ` : ''}
                            ${phone ? `
                                <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
                                    <span style="font-size: 0.875rem; color: #64748b; width: 60px; flex-shrink: 0;">Phone:</span>
                                    <span style="font-size: 0.875rem; font-weight: 600; color: #1e293b;">${phone}</span>
                                </div>
                            ` : ''}
                            <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
                                <span style="font-size: 0.875rem; color: #64748b; width: 60px; flex-shrink: 0;">Address:</span>
                                <span style="font-size: 0.875rem; color: #334155; line-height: 1.4;">${addr}</span>
                            </div>
                            ${data.delivery_rider ? `
                                <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
                                    <span style="font-size: 0.875rem; color: #64748b; width: 60px; flex-shrink: 0;">Rider:</span>
                                    <span style="font-size: 0.875rem; font-weight: 600; color: #0d9488; display: flex; align-items: center; gap: 0.35rem;">
                                        <i class="fas fa-motorcycle"></i>
                                        ${escapeHtml(data.delivery_rider)}
                                    </span>
                                </div>
                            ` : ''}
                        </div>
                    </div>
                </div>

                <!-- Order Items Section -->
                <div style="background: white; border-radius: 12px; padding: 1.25rem; border: 1px solid #e2e8f0; margin-bottom: 1rem;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <div style="width: 32px; height: 32px; background: #ede9fe; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-cube" style="color: #8b5cf6; font-size: 0.875rem;"></i>
                            </div>
                            <span style="font-size: 0.875rem; font-weight: 700; color: #1e293b;">Order Items</span>
                        </div>
                        <span style="background: #6366f1; color: white; padding: 0.375rem 0.875rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600;">
                            ${data.items ? data.items.length : 0} item${data.items && data.items.length !== 1 ? 's' : ''}
                        </span>
                    </div>
                    
                    <table style="width: 100%; border-collapse: separate; border-spacing: 0;">
                        <thead>
                            <tr style="background: #f8fafc;">
                                <th style="padding: 0.75rem; text-align: left; font-size: 0.7rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; border-radius: 6px 0 0 6px;">Product</th>
                                <th style="padding: 0.75rem; text-align: center; font-size: 0.7rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Qty</th>
                                <th style="padding: 0.75rem; text-align: right; font-size: 0.7rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Unit Price</th>
                                <th style="padding: 0.75rem; text-align: right; font-size: 0.7rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; border-radius: 0 6px 6px 0;">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            if (data.items && data.items.length > 0) {
                data.items.forEach((item, index) => {
                    const up = item.unit_price != null ? parseFloat(item.unit_price) : 0;
                    const ls = item.line_subtotal != null ? parseFloat(item.line_subtotal) : (parseFloat(item.quantity || 0) * up);
                    const isLast = index === data.items.length - 1;
                    html += `
                        <tr>
                            <td style="padding: 0.875rem 0.75rem; font-size: 0.875rem; color: #334155; ${isLast ? '' : 'border-bottom: 1px solid #f1f5f9;'}">
                                <div style="display: flex; align-items: center; gap: 0.625rem;">
                                    <div style="width: 28px; height: 28px; background: #ede9fe; border-radius: 6px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                        <i class="fas fa-box" style="color: #8b5cf6; font-size: 0.75rem;"></i>
                                    </div>
                                    <span style="font-weight: 500;">${escapeHtml(item.product_name || 'Unknown Product')}</span>
                                </div>
                            </td>
                            <td style="padding: 0.875rem 0.75rem; text-align: center; font-size: 0.875rem; font-weight: 600; color: #1e293b; ${isLast ? '' : 'border-bottom: 1px solid #f1f5f9;'}">${parseFloat(item.quantity || 0).toFixed(0)}</td>
                            <td style="padding: 0.875rem 0.75rem; text-align: right; font-size: 0.875rem; color: #64748b; ${isLast ? '' : 'border-bottom: 1px solid #f1f5f9;'}">₱${up.toFixed(2)}</td>
                            <td style="padding: 0.875rem 0.75rem; text-align: right; font-size: 0.875rem; font-weight: 600; color: #1e293b; ${isLast ? '' : 'border-bottom: 1px solid #f1f5f9;'}">₱${ls.toFixed(2)}</td>
                        </tr>
                    `;
                });
            } else {
                html += `
                    <tr>
                        <td colspan="4" style="padding: 2rem; text-align: center; color: #94a3b8; font-size: 0.875rem;">No items found for this order.</td>
                    </tr>
                `;
            }

            html += `
                        </tbody>
                    </table>
                    
                    <!-- Totals Section -->
                    <div style="margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid #e2e8f0;">
                        <div style="display: flex; flex-direction: column; gap: 0.5rem; max-width: 280px; margin-left: auto;">
                            <div style="display: flex; justify-content: space-between; font-size: 0.875rem; color: #64748b;">
                                <span>Subtotal</span>
                                <span style="font-weight: 500; color: #334155;">₱${sub.toFixed(2)}</span>
                            </div>
                            ${disc > 0.0001 ? `
                                <div style="display: flex; justify-content: space-between; font-size: 0.875rem; color: #dc2626;">
                                    <span>Discount</span>
                                    <span style="font-weight: 500;">−₱${disc.toFixed(2)}</span>
                                </div>
                            ` : ''}
                            <div style="display: flex; justify-content: space-between; font-weight: 700; font-size: 1.125rem; color: #1e293b; padding-top: 0.5rem; border-top: 1px solid #e2e8f0;">
                                <span>Grand total</span>
                                <span style="color: #6366f1;">₱${grand.toFixed(2)}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cancellation Info (if applicable) -->
                ${(isCancelledOrder && (cancellationReason || cancellationRemarks)) ? `
                    <div style="background: #fef2f2; border-radius: 12px; padding: 1.25rem; margin-bottom: 1rem; border: 1px solid #fecaca;">
                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem;">
                            <div style="width: 32px; height: 32px; background: #fee2e2; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-exclamation-triangle" style="color: #dc2626; font-size: 0.875rem;"></i>
                            </div>
                            <span style="font-size: 0.875rem; font-weight: 700; color: #dc2626;">Cancellation Information</span>
                        </div>
                        ${cancellationReason ? `<div style="font-size: 0.875rem; color: #7f1d1d; margin-bottom: 0.5rem;"><strong>Reason:</strong> ${escapeHtml(cancellationReason)}</div>` : ''}
                        ${cancellationRemarks ? `<div style="font-size: 0.875rem; color: #991b1b;"><strong>Remarks:</strong> ${escapeHtml(cancellationRemarks)}</div>` : ''}
                    </div>
                ` : ''}

                <!-- Close Button -->
                <div style="display: flex; justify-content: flex-end;">
                    <button onclick="closeOrderDetailsModal()" style="background: #f1f5f9; border: none; padding: 0.75rem 1.5rem; border-radius: 10px; font-size: 0.875rem; font-weight: 600; color: #475569; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; transition: all 0.2s;" onmouseover="this.style.background='#e2e8f0';" onmouseout="this.style.background='#f1f5f9';">
                        <i class="fas fa-times" style="font-size: 0.75rem;"></i> Close
                    </button>
                </div>
            `;
            
            bodyEl.innerHTML = html;
        })
        .catch(error => {
            console.error('Error:', error);
            const bodyEl = document.getElementById('orderDetailsBody');
            if (bodyEl) {
                bodyEl.innerHTML = `<div class="alert alert-danger">Error loading order details: ${error.message}</div>`;
            }
        });
}

// Close order details modal
function closeOrderDetailsModal() {
    const modal = document.getElementById('orderDetailsModal');
    if (modal) {
        modal.remove();
    }
}

// Update order status
function updateOrderStatus(orderId, currentStatus) {
    // Trim and normalize the status - handle various formats
    if (!currentStatus) {
        console.error('No status provided for order:', orderId);
        alert('Unable to determine order status. Please refresh the page and try again.');
        return;
    }
    
    // Debug logging
    console.log('updateOrderStatus called with:', { orderId, currentStatus, type: typeof currentStatus });
    
    // Check if SweetAlert2 is available
    if (typeof Swal === 'undefined') {
        console.error('SweetAlert2 is not loaded!');
        alert('Error: SweetAlert2 library is not loaded. Please refresh the page.');
        return;
    }
    
    // Clean and normalize the status string
    currentStatus = String(currentStatus).trim().replace(/\s+/g, ' ');
    
    // Normalize status values (handle case variations and extra spaces)
    const statusMap = {
        'pending': 'pending',
        'requested': 'Requested',
        'pending order': 'pending',
        'order pending': 'pending',
        'confirmed': 'pending',
        'scheduled for delivery': 'Scheduled for Delivery',
        'scheduled': 'Scheduled for Delivery',
        'out for delivery': 'out for delivery',
        'outfordelivery': 'out for delivery',
        'out for delivery': 'out for delivery',
        'delivered': 'delivered',
        'delivered (pending cash turnover)': 'delivered',
        'pending cash turnover': 'delivered',
        'pending turnover': 'delivered',
        'completed': 'Completed',
        'cancelled': 'cancelled',
        'canceled': 'cancelled'
    };
    
    // Try to find normalized status - normalize to lowercase and trim
    const statusLower = currentStatus.toLowerCase().trim();
    let normalizedStatus = statusMap[statusLower];
    
    // If not found, try without extra spaces
    if (!normalizedStatus) {
        const statusNoSpaces = statusLower.replace(/\s+/g, ' ').trim();
        normalizedStatus = statusMap[statusNoSpaces];
    }
    
    // If still not found, use original (but this should not happen)
    if (!normalizedStatus) {
        normalizedStatus = currentStatus;
    }
    
    // Debug: Show what we're working with
    console.log('Status normalization:', {
        original: currentStatus,
        lower: statusLower,
        normalized: normalizedStatus,
        inMap: statusLower in statusMap
    });
    
    const statusFlow = {
        'pending': ['Scheduled for Delivery'],
        'Requested': ['Scheduled for Delivery'],
        'Scheduled for Delivery': [],
        'out for delivery': [],
        'Out for Delivery': [],
        'delivered': [],
        'Delivered (Pending Cash Turnover)': [],
        'Completed': [],
        'cancelled': [],
        'Cancelled': []
    };
    
    const nextStatuses = statusFlow[normalizedStatus] || [];
    
    if (nextStatuses.length === 0) {
        if (normalizedStatus === 'Completed' || normalizedStatus === 'Cancelled' || normalizedStatus === 'cancelled') {
            alert('This order is already ' + normalizedStatus + ' and cannot be updated further.');
        } else {
            console.error('Unknown status details:', {
                original: currentStatus,
                normalized: normalizedStatus,
                statusLower: statusLower,
                availableStatuses: Object.keys(statusFlow)
            });
            alert('Unknown order status: "' + currentStatus + '". Normalized to: "' + normalizedStatus + '". Please contact support with this information.');
        }
        return;
    }
    
    try {
        const ridersOptions = typeof ridersData !== 'undefined' && ridersData.length > 0 
            ? `<option value="">— Select rider (optional) —</option>${ridersData.map(r => `<option value="${r.User_ID}">${r.name}</option>`).join('')}`
            : `<option value="">No riders available</option>`;
        
        Swal.fire({
            title: 'Update Order Status',
            html: `
                <select id="swal-status" class="swal2-input">
                    ${nextStatuses.map(s => `<option value="${s}">${s}</option>`).join('')}
                </select>
                <input type="date" id="swal-delivery-date" class="swal2-input" placeholder="Delivery Date (optional)" style="margin-top: 10px;">
                <select id="swal-delivery-person" class="swal2-input" style="margin-top: 10px;">
                    ${ridersOptions}
                </select>
                <textarea id="swal-notes" class="swal2-textarea" placeholder="Notes (optional)" style="margin-top: 10px;"></textarea>
            `,
            showCancelButton: true,
            confirmButtonText: 'Update',
            preConfirm: () => {
                return {
                    status: document.getElementById('swal-status').value,
                    delivery_date: document.getElementById('swal-delivery-date').value,
                    delivery_person: document.getElementById('swal-delivery-person').value,
                    notes: document.getElementById('swal-notes').value
                };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = getOrdersFormAction();
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'update_status';
                form.appendChild(actionInput);
                
                appendCsrfField(form);
                
                const orderIdInput = document.createElement('input');
                orderIdInput.type = 'hidden';
                orderIdInput.name = 'order_id';
                orderIdInput.value = orderId;
                form.appendChild(orderIdInput);
                
                const statusInput = document.createElement('input');
                statusInput.type = 'hidden';
                statusInput.name = 'new_status';
                statusInput.value = result.value.status;
                form.appendChild(statusInput);
                
                const deliveryDateInput = document.createElement('input');
                deliveryDateInput.type = 'hidden';
                deliveryDateInput.name = 'delivery_date';
                deliveryDateInput.value = result.value.delivery_date;
                form.appendChild(deliveryDateInput);
                
                const deliveryPersonInput = document.createElement('input');
                deliveryPersonInput.type = 'hidden';
                deliveryPersonInput.name = 'delivery_person';
                deliveryPersonInput.value = result.value.delivery_person;
                form.appendChild(deliveryPersonInput);
                
                const notesInput = document.createElement('input');
                notesInput.type = 'hidden';
                notesInput.name = 'notes';
                notesInput.value = result.value.notes;
                form.appendChild(notesInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }).catch((error) => {
            console.error('Error in updateOrderStatus:', error);
            alert('An error occurred while updating the order status. Please try again.');
        });
    } catch (error) {
        console.error('Error in updateOrderStatus:', error);
        alert('An error occurred: ' + error.message);
    }
}

// Assign delivery
function assignDelivery(orderId) {
    const ridersOptions = typeof ridersData !== 'undefined' && ridersData.length > 0 
        ? `<option value="">— Select rider —</option>${ridersData.map(r => `<option value="${r.User_ID}">${r.name}</option>`).join('')}`
        : `<option value="">No riders available</option>`;
    
    Swal.fire({
        title: 'Assign Delivery',
        html: `
            <select id="swal-person" class="swal2-input" required>
                ${ridersOptions}
            </select>
            <input id="swal-vehicle" class="swal2-input" placeholder="Vehicle Info (optional)" style="margin-top: 10px;">
            <textarea id="swal-notes" class="swal2-textarea" placeholder="Notes (optional)" style="margin-top: 10px;"></textarea>
        `,
        showCancelButton: true,
        confirmButtonText: 'Assign',
        preConfirm: () => {
            const person = document.getElementById('swal-person').value;
            if (!person) {
                Swal.showValidationMessage('Please select a delivery rider');
                return false;
            }
            return {
                person: person,
                vehicle: document.getElementById('swal-vehicle').value,
                notes: document.getElementById('swal-notes').value
            };
        }
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = getOrdersFormAction();
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'assign_delivery';
            form.appendChild(actionInput);
            
            appendCsrfField(form);
            
            const orderIdInput = document.createElement('input');
            orderIdInput.type = 'hidden';
            orderIdInput.name = 'order_id';
            orderIdInput.value = orderId;
            form.appendChild(orderIdInput);
            
            const personInput = document.createElement('input');
            personInput.type = 'hidden';
            personInput.name = 'delivery_person';
            personInput.value = result.value.person;
            form.appendChild(personInput);
            
            const vehicleInput = document.createElement('input');
            vehicleInput.type = 'hidden';
            vehicleInput.name = 'vehicle_info';
            vehicleInput.value = result.value.vehicle;
            form.appendChild(vehicleInput);
            
            const notesInput = document.createElement('input');
            notesInput.type = 'hidden';
            notesInput.name = 'notes';
            notesInput.value = result.value.notes;
            form.appendChild(notesInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    });
}

// Cancel order
function cancelOrder(orderId) {
    const reasonOptions = Array.isArray(window.orderCancellationReasons) ? window.orderCancellationReasons : [];
    const optionsHtml = reasonOptions.map((reason) => {
        const escaped = String(reason)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
        return `<option value="${escaped}">${escaped}</option>`;
    }).join('');

    Swal.fire({
        title: 'Cancel Order',
        html: `
            <div style="text-align:left;">
                <label for="cancelOrderReason" style="display:block;font-size:0.82rem;font-weight:700;color:#475569;margin-bottom:0.45rem;">Cancellation Reason</label>
                <select id="cancelOrderReason" class="swal2-select" style="display:flex;width:100%;margin:0 0 0.85rem 0;">
                    <option value="">Select a reason</option>
                    ${optionsHtml}
                </select>
                <label for="cancelOrderRemarks" style="display:block;font-size:0.82rem;font-weight:700;color:#475569;margin-bottom:0.45rem;">Remarks <span style="font-weight:500;color:#94a3b8;">(optional)</span></label>
                <textarea id="cancelOrderRemarks" class="swal2-textarea" style="display:flex;width:100%;margin:0;" placeholder="Add extra details if needed..."></textarea>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Cancel Order',
        confirmButtonColor: '#ef4444',
        focusConfirm: false,
        preConfirm: () => {
            const reason = document.getElementById('cancelOrderReason')?.value?.trim() || '';
            const remarks = document.getElementById('cancelOrderRemarks')?.value?.trim() || '';
            if (!reason || reason.trim() === '') {
                Swal.showValidationMessage('Please select a cancellation reason.');
                return false;
            }
            if (reason === 'Others' && !remarks) {
                Swal.showValidationMessage('Please enter a reason in the Remarks field for "Others".');
                return false;
            }
            return { reason, remarks };
        }
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = getOrdersFormAction();
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'cancel_order';
            form.appendChild(actionInput);
            
            appendCsrfField(form);
            
            const orderIdInput = document.createElement('input');
            orderIdInput.type = 'hidden';
            orderIdInput.name = 'order_id';
            orderIdInput.value = orderId;
            form.appendChild(orderIdInput);
            
            const reasonInput = document.createElement('input');
            reasonInput.type = 'hidden';
            reasonInput.name = 'cancellation_reason';
            reasonInput.value = result.value.reason;
            form.appendChild(reasonInput);

            const remarksInput = document.createElement('input');
            remarksInput.type = 'hidden';
            remarksInput.name = 'cancellation_remarks';
            remarksInput.value = result.value.remarks || '';
            form.appendChild(remarksInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    });
}

window.showCreateOrderModal = showCreateOrderModal;
window.closeCreateOrderModal = closeCreateOrderModal;
window.openEditOrder = openEditOrder;
window.reorderOrder = reorderOrder;
window.onCustomerChange = onCustomerChange;
window.loadLastOrderForSelectedCustomer = loadLastOrderForSelectedCustomer;
window.viewOrderDetails = viewOrderDetails;
window.cancelOrder = cancelOrder;
window.updateCartItemQty = updateCartItemQty;
window.removeFromCart = removeFromCart;
window.setCartItemQty = setCartItemQty;

function ordersEscapeHtml(value) {
    if (value == null || value === '') return '';
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function resolveScheduleRiderId(prefill) {
    const pre = prefill || {};
    const riderId = String(pre.riderId || '').trim();
    if (riderId && typeof ridersData !== 'undefined' && ridersData.some((r) => String(r.User_ID) === riderId)) {
        return riderId;
    }
    const riderName = String(pre.riderName || '').trim().toLowerCase();
    if (riderName && typeof ridersData !== 'undefined') {
        const match = ridersData.find((r) => String(r.name || '').trim().toLowerCase() === riderName);
        if (match) return String(match.User_ID);
    }
    return '';
}

function buildScheduleRiderOptions(selectedRiderId) {
    let html = '<option value="">Assign later / no rider yet</option>';
    if (typeof ridersData !== 'undefined' && ridersData.length > 0) {
        ridersData.forEach((r) => {
            const id = String(r.User_ID);
            const selected = selectedRiderId && id === String(selectedRiderId) ? ' selected' : '';
            html += `<option value="${ordersEscapeHtml(id)}"${selected}>${ordersEscapeHtml(r.name || ('User #' + id))}</option>`;
        });
    }
    return html;
}

function buildScheduleDeliveryModalHtml(selectedRiderId) {
    return `
        <div class="schedule-delivery-shell">
            <div class="schedule-delivery-header">
                <div class="schedule-delivery-header-icon"><i class="fas fa-calendar-check"></i></div>
                <div>
                    <h3 class="schedule-delivery-title">Schedule Delivery</h3>
                    <p class="schedule-delivery-subtitle">Set delivery date and rider assignment</p>
                </div>
            </div>
            <div class="schedule-delivery-body">
                <div class="schedule-delivery-field">
                    <label for="swal-delivery-date"><i class="fas fa-calendar-day"></i> Delivery date</label>
                    <input type="date" id="swal-delivery-date" class="schedule-delivery-input" required>
                </div>
                <div class="schedule-delivery-field">
                    <label for="swal-delivery-person"><i class="fas fa-motorcycle"></i> Assign rider</label>
                    <select id="swal-delivery-person" class="schedule-delivery-input schedule-delivery-select">
                        ${buildScheduleRiderOptions(selectedRiderId)}
                    </select>
                    <p class="schedule-delivery-hint">You can schedule first and assign a rider later in Delivery Management.</p>
                </div>
                <div class="schedule-delivery-field">
                    <label for="swal-notes"><i class="fas fa-sticky-note"></i> Notes <span>(optional)</span></label>
                    <textarea id="swal-notes" class="schedule-delivery-textarea" rows="3" placeholder="Add delivery notes…"></textarea>
                </div>
            </div>
        </div>
    `;
}

function promptAndScheduleOrder(orderId, prefill) {
    const pre = prefill || {};
    const selectedRiderId = resolveScheduleRiderId(pre);

    Swal.fire({
        title: 'Schedule Delivery',
        html: buildScheduleDeliveryModalHtml(selectedRiderId),
        showCancelButton: true,
        confirmButtonText: 'Mark Scheduled',
        cancelButtonText: 'Cancel',
        focusConfirm: false,
        customClass: {
            popup: 'schedule-delivery-popup',
            confirmButton: 'schedule-delivery-btn schedule-delivery-btn-primary',
            cancelButton: 'schedule-delivery-btn schedule-delivery-btn-secondary',
            htmlContainer: 'schedule-delivery-html-wrap'
        },
        didOpen: () => {
            const dateEl = document.getElementById('swal-delivery-date');
            const notesEl = document.getElementById('swal-notes');
            const riderEl = document.getElementById('swal-delivery-person');
            if (dateEl && pre.deliveryDate) {
                dateEl.value = pre.deliveryDate;
            }
            if (notesEl && pre.notes) {
                notesEl.value = pre.notes;
            }
            if (riderEl && selectedRiderId) {
                riderEl.value = selectedRiderId;
            }
        },
        preConfirm: () => {
            const deliveryDate = document.getElementById('swal-delivery-date').value;
            const deliveryPerson = document.getElementById('swal-delivery-person').value;
            if (!deliveryDate) {
                Swal.showValidationMessage('Delivery date is required.');
                return false;
            }
            return {
                delivery_date: deliveryDate,
                delivery_person: deliveryPerson,
                notes: document.getElementById('swal-notes').value
            };
        }
    }).then((result) => {
        if (!result.isConfirmed) return;
        submitOrderStatusUpdate(orderId, 'Scheduled for Delivery', result.value);
    });
}
window.promptAndScheduleOrder = promptAndScheduleOrder;

} // end orders.js double-load guard
