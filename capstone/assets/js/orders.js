// Orders Page JavaScript

// Show create order modal
function showCreateOrderModal() {
    document.getElementById('createOrderModal').style.display = 'block';
}

// Close create order modal
function closeCreateOrderModal() {
    document.getElementById('createOrderModal').style.display = 'none';
    document.getElementById('createOrderForm').reset();
    // Reset to single item row
    const itemsContainer = document.getElementById('orderItems');
    while (itemsContainer.children.length > 1) {
        itemsContainer.removeChild(itemsContainer.lastChild);
    }
}

// Add order item row
function addOrderItem() {
    const container = document.getElementById('orderItems');
    const firstRow = container.firstElementChild;
    const newRow = firstRow.cloneNode(true);
    
    // Clear values
    newRow.querySelector('.product-select').value = '';
    newRow.querySelector('input[name="quantities[]"]').value = '';
    newRow.querySelector('.price-type-select').value = 'wholesale';
    newRow.querySelector('input[name="unit_prices[]"]').value = '';
    
    container.appendChild(newRow);
    attachProductListeners(newRow);
}

// Remove order item row
function removeOrderItem(button) {
    const container = document.getElementById('orderItems');
    if (container.children.length > 1) {
        button.closest('.order-item-row').remove();
        // calculateTotal(); // Function not needed for now, can be added later if total display is required
    } else {
        alert('At least one item is required');
    }
}

// Calculate total (if needed for display)
function calculateTotal() {
    // This function can be implemented if you want to show a running total
    // For now, it's a placeholder to prevent errors
    return 0;
}

// Attach product selection listeners
function attachProductListeners(row) {
    const productSelect = row.querySelector('.product-select');
    const priceTypeSelect = row.querySelector('.price-type-select');
    const quantityInput = row.querySelector('input[name="quantities[]"]');
    const unitPriceInput = row.querySelector('input[name="unit_prices[]"]');
    
    function updatePrice() {
        const productId = productSelect.value;
        const priceType = priceTypeSelect.value;
        
        if (productId) {
            const product = productsData.find(p => p.Product_ID == productId);
            if (product) {
                const price = priceType === 'wholesale' ? product.wholesale_price : product.retail_price;
                unitPriceInput.value = parseFloat(price).toFixed(2);
            }
        } else {
            unitPriceInput.value = '';
        }
    }
    
    productSelect.addEventListener('change', updatePrice);
    priceTypeSelect.addEventListener('change', updatePrice);
}

// Initialize listeners on page load
document.addEventListener('DOMContentLoaded', function() {
    // Attach listeners to initial row
    const initialRows = document.querySelectorAll('.order-item-row');
    initialRows.forEach(row => attachProductListeners(row));
    
    // Handle form submission
    const form = document.getElementById('createOrderForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Collect order items
            const items = [];
            const rows = document.querySelectorAll('.order-item-row');
            
            rows.forEach(row => {
                const productSelect = row.querySelector('.product-select');
                const quantityInput = row.querySelector('input[name="quantities[]"]');
                const priceTypeSelect = row.querySelector('.price-type-select');
                const unitPriceInput = row.querySelector('input[name="unit_prices[]"]');
                
                if (productSelect.value && quantityInput.value) {
                    const product = productsData.find(p => p.Product_ID == productSelect.value);
                    const priceType = priceTypeSelect.value;
                    const unitPrice = priceType === 'wholesale' ? product.wholesale_price : product.retail_price;
                    
                    items.push({
                        product_id: productSelect.value,
                        quantity: parseFloat(quantityInput.value),
                        unit_price: parseFloat(unitPrice),
                        price_type: priceType
                    });
                }
            });
            
            if (items.length === 0) {
                alert('Please add at least one item to the order');
                return;
            }
            
            // Add items as hidden input
            const itemsInput = document.createElement('input');
            itemsInput.type = 'hidden';
            itemsInput.name = 'items';
            itemsInput.value = JSON.stringify(items);
            form.appendChild(itemsInput);
            
            // Submit form
            form.submit();
        });
    }
    
    // Close modal on outside click
    const modal = document.getElementById('createOrderModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeCreateOrderModal();
            }
        });
    }
});

// View order details
function viewOrderDetails(orderId) {
    // Redirect to order details page or show modal
    window.location.href = `order_details.php?id=${orderId}`;
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
        'confirmed': 'Confirmed',
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
        'pending': ['Confirmed', 'Scheduled for Delivery', 'cancelled'],
        'Confirmed': ['Scheduled for Delivery', 'out for delivery', 'cancelled'],
        'Scheduled for Delivery': ['out for delivery', 'delivered', 'cancelled'],
        'out for delivery': ['delivered', 'cancelled'],
        'Out for Delivery': ['delivered', 'Delivered (Pending Cash Turnover)', 'cancelled'],
        'delivered': ['Completed', 'cancelled'],
        'Delivered (Pending Cash Turnover)': ['Completed', 'cancelled'],
        'Completed': [], // Cannot be updated further
        'cancelled': [], // Cannot be updated further
        'Cancelled': [] // Cannot be updated further (legacy)
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
        Swal.fire({
            title: 'Update Order Status',
            html: `
                <select id="swal-status" class="swal2-input">
                    ${nextStatuses.map(s => `<option value="${s}">${s}</option>`).join('')}
                </select>
                <input type="date" id="swal-delivery-date" class="swal2-input" placeholder="Delivery Date (optional)" style="margin-top: 10px;">
                <input id="swal-delivery-person" class="swal2-input" placeholder="Delivery Person Name (optional)" style="margin-top: 10px;">
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
                form.action = '../api/orders_backend.php';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'update_status';
                form.appendChild(actionInput);
                
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
    Swal.fire({
        title: 'Assign Delivery',
        html: `
            <input id="swal-person" class="swal2-input" placeholder="Delivery Person Name *" required>
            <input id="swal-vehicle" class="swal2-input" placeholder="Vehicle Info (optional)" style="margin-top: 10px;">
            <textarea id="swal-notes" class="swal2-textarea" placeholder="Notes (optional)" style="margin-top: 10px;"></textarea>
        `,
        showCancelButton: true,
        confirmButtonText: 'Assign',
        preConfirm: () => {
            const person = document.getElementById('swal-person').value;
            if (!person) {
                Swal.showValidationMessage('Delivery person name is required');
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
            form.action = '../api/orders_backend.php';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'assign_delivery';
            form.appendChild(actionInput);
            
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
    Swal.fire({
        title: 'Cancel Order',
        input: 'textarea',
        inputLabel: 'Cancellation Reason *',
        inputPlaceholder: 'Enter reason for cancellation...',
        inputAttributes: {
            required: true
        },
        showCancelButton: true,
        confirmButtonText: 'Cancel Order',
        confirmButtonColor: '#ef4444',
        preConfirm: (reason) => {
            if (!reason || reason.trim() === '') {
                Swal.showValidationMessage('Cancellation reason is required');
                return false;
            }
            return reason;
        }
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '../api/orders_backend.php';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'cancel_order';
            form.appendChild(actionInput);
            
            const orderIdInput = document.createElement('input');
            orderIdInput.type = 'hidden';
            orderIdInput.name = 'order_id';
            orderIdInput.value = orderId;
            form.appendChild(orderIdInput);
            
            const reasonInput = document.createElement('input');
            reasonInput.type = 'hidden';
            reasonInput.name = 'cancellation_reason';
            reasonInput.value = result.value;
            form.appendChild(reasonInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    });
}
