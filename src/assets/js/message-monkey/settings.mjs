import {$, $$} from './dom.mjs';

export function initializeAudienceDefaults() {
    const form = $('[data-audience-defaults-form]');
    if (!form) return;

    const controls = Array.from(form.elements).filter(input => input.name && !input.disabled && !['hidden', 'submit', 'button', 'reset', 'file'].includes(input.type));
    const value = input => ['checkbox', 'radio'].includes(input.type) ? input.checked : input.value;
    const saved = new Map(controls.map(input => [input, value(input)]));
    const buttons = $$('[data-audience-defaults-save]', form);
    const status = $('[data-audience-defaults-status]', form);
    let previous = null;

    const update = () => {
        const dirty = form.dataset.audienceDefaultsRecovery === '1' || controls.some(input => value(input) !== saved.get(input));
        buttons.forEach(button => button.classList.toggle('audience-defaults-save-unsaved', dirty));
        if (status && dirty !== previous) status.textContent = dirty ? 'Unsaved audience defaults.' : 'All audience defaults saved.';
        previous = dirty;
    };

    form.addEventListener('input', update);
    form.addEventListener('change', update);
    form.addEventListener('reset', () => queueMicrotask(update));
    window.addEventListener('pageshow', update);
    update();
}
