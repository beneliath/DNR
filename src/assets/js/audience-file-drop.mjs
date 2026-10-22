import {imageFileSize} from './audience-image-upload.mjs';

export function setFileDropStatus(input, message = 'No file selected.', error = false) {
    const zone = input.closest('[data-file-drop]');
    if (!zone) return;
    const status = zone.querySelector('[data-file-drop-status]');
    status.textContent = message;
    status.classList.toggle('is-error', error);
    input.setCustomValidity(error ? message : '');
}

export function selectedUploadFile(input) {
    const files = input.files;
    if (!files.length) return null;
    if (files.length !== 1) throw new Error('Choose one file at a time.');
    const file = files[0];
    if (!file.size) throw new Error('Choose a file that is not empty.');
    const accepted = input.accept.split(',').map(value => value.trim().toLowerCase()).filter(Boolean);
    if (accepted.length && !accepted.some(type => type.startsWith('.')
        ? file.name.toLowerCase().endsWith(type)
        : type.endsWith('/*') ? file.type.startsWith(type.slice(0, -1)) : file.type === type)) {
        throw new Error(input.dataset.fileTypes || 'Choose one of the supported file types.');
    }
    return file;
}

// Keep the native input and its existing preparation/save flow for both paths.
export function wireAudienceFileDrop(zone) {
    const input = zone.querySelector('input[type="file"]');
    const browse = zone.querySelector('[data-file-browse]');
    let dragDepth = 0;
    const disabled = () => input.matches(':disabled');
    const clearDrag = () => { dragDepth = 0; zone.classList.remove('is-dragging'); };
    browse.hidden = false;
    input.classList.add('sr-only');
    input.tabIndex = -1;
    browse.addEventListener('click', () => { if (!disabled()) input.click(); });
    input.addEventListener('change', () => {
        const file = input.files[0];
        setFileDropStatus(input, file ? `${file.name} · ${imageFileSize(file.size)}` : undefined);
    });
    input.form?.addEventListener('reset', () => { clearDrag(); setFileDropStatus(input); });
    zone.addEventListener('dragenter', event => {
        event.preventDefault();
        if (!disabled() && Array.from(event.dataTransfer?.types || []).includes('Files')) {
            dragDepth++;
            zone.classList.add('is-dragging');
        }
    });
    zone.addEventListener('dragover', event => {
        event.preventDefault();
        if (event.dataTransfer) event.dataTransfer.dropEffect = disabled() ? 'none' : 'copy';
    });
    zone.addEventListener('dragleave', event => {
        event.preventDefault();
        dragDepth = Math.max(0, dragDepth - 1);
        if (!dragDepth) clearDrag();
    });
    zone.addEventListener('drop', event => {
        event.preventDefault();
        event.stopPropagation();
        clearDrag();
        if (disabled()) return;
        const files = event.dataTransfer?.files;
        if (!files?.length) { setFileDropStatus(input, 'Drop a file, or use Browse files to choose one.', true); return; }
        try { input.files = files; }
        catch { setFileDropStatus(input, 'Use Browse files to select this file in your browser.', true); return; }
        input.dispatchEvent(new Event('change', {bubbles: true}));
    });
}
