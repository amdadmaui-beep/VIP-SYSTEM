function adjustQuantity(button, delta) {
    const input = button.parentElement.querySelector('input[type="number"]');
    let currentValue = parseFloat(input.value) || 0;
    currentValue += delta;
    input.value = currentValue.toFixed(2);
}
