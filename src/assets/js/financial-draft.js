(function () {
    'use strict';
    function receiptSummary(values) {
        let cents = 0n;
        let entered = 0;
        for (const value of values) {
            const amount = String(value).trim();
            if (!amount) continue;
            if (!/^\d{1,10}(?:\.\d{1,2})?$/.test(amount)) return { valid: false, entered: entered };
            const parts = amount.split('.');
            cents += BigInt(parts[0]) * 100n + BigInt((parts[1] || '').padEnd(2, '0'));
            entered++;
        }
        return { valid: true, entered: entered, total: String(cents / 100n) + '.' + String(cents % 100n).padStart(2, '0') };
    }
    if (typeof module === 'object' && module.exports) module.exports = { receiptSummary: receiptSummary };
    if (typeof document === 'undefined') return;
    const fields = Array.from(document.querySelectorAll('[data-receipt-amount]'));
    const total = document.querySelector('[data-receipt-total]');
    const completion = document.querySelector('[data-receipt-completion]');
    if (!total || !completion) return;
    function update() {
        const result = receiptSummary(fields.map(function (field) { return field.value; }));
        total.textContent = result.valid ? 'Total entered: $' + result.total : 'Check receipt amounts';
        completion.textContent = result.valid ? result.entered + ' of ' + fields.length + ' categories entered' : 'Use non-negative amounts with up to two decimal places';
    }
    fields.forEach(function (field) { field.addEventListener('input', update); });
    update();
})();
