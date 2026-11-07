const initialized = new WeakSet();

export function initializeInputModality(doc = document) {
    if (initialized.has(doc)) return;
    initialized.add(doc);
    // Clicking an already keyboard-focused control can leave :focus-visible set.
    doc.addEventListener('pointerdown', () => doc.body.classList.add('pointer-input'), true);
    doc.addEventListener('keydown', event => {
        if (!['Shift','Control','Alt','Meta'].includes(event.key)) doc.body.classList.remove('pointer-input');
    }, true);
}
