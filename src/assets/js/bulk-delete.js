(function () {
    'use strict';
    const form = document.querySelector('[data-bulk-delete]');
    if (!form) return;
    // Checkboxes belong to the bulk form, outside the individual row action forms.
    const items = Array.from(document.querySelectorAll('[data-bulk-item]'));
    const selectAll = form.querySelector('[data-bulk-select-all]');
    const clear = form.querySelector('[data-bulk-clear]');
    const count = form.querySelector('[data-bulk-count]');
    const submit = form.querySelector('[data-bulk-submit]');
    function update() {
        const selected = items.filter(item => item.checked).length;
        count.textContent = `${selected} selected`;
        submit.disabled = selected === 0;
        submit.textContent = selected ? `Delete selected (${selected})` : 'Delete selected';
        selectAll.checked = items.length > 0 && selected === items.length;
        selectAll.indeterminate = selected > 0 && selected < items.length;
        selectAll.disabled = items.length === 0;
        clear.hidden = selected === 0;
    }
    items.forEach(item => item.addEventListener('change', update));
    selectAll.addEventListener('change', function () {
        items.forEach(item => { item.checked = selectAll.checked; });
        update();
    });
    clear.addEventListener('click', function () {
        items.forEach(item => { item.checked = false; });
        update();
    });
    form.addEventListener('submit', function (event) {
        if (!items.some(item => item.checked)) event.preventDefault();
    });
    window.addEventListener('pageshow', update);
    update();
})();
