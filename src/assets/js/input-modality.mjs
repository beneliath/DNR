const initialized = new WeakSet();

export function initializeInputModality(doc = document) {
    if (initialized.has(doc)) return;
    initialized.add(doc);
    // Mouse validation can focus a checkbox that still matches :focus-visible.
    doc.addEventListener('pointerdown', () => doc.body.classList.add('audience-pointer-input'), true);
    doc.addEventListener('keydown', event => {
        if (!['Shift','Control','Alt','Meta'].includes(event.key)) doc.body.classList.remove('audience-pointer-input');
    }, true);
}
