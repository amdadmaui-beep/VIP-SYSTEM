function updateCurrentQuantity() {
    const select = document.getElementById('product_id');
    const selectedOption = select.options[select.selectedIndex];
    const currentQuantity = selectedOption.getAttribute('data-quantity') || '0';
    document.getElementById('current_quantity').value = Math.round(parseFloat(currentQuantity));
    updateResultingQuantity();
}

function updateResultingQuantity() {
    const currentQuantity = parseFloat(document.getElementById('current_quantity').value) || 0;
    const adjustmentValue = parseFloat(document.getElementById('adjustment_value').value) || 0;
    const resultingQuantity = currentQuantity + adjustmentValue;
    document.getElementById('resulting_quantity').value = Math.round(resultingQuantity);
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updateCurrentQuantity();
});
