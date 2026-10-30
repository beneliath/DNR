export function setConditionalField(select, expectedValue, container, input) {
    if (!select || !container || !input) return;
    const visible = select.value === expectedValue;
    container.hidden = !visible;
    input.required = visible;
    if (!visible) input.value = '';
}
