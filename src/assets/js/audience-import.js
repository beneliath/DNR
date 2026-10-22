(function () {
    'use strict';

    function wireImportFileDrop(zone) {
        const input = zone.querySelector('input[type="file"]');
        const browse = zone.querySelector('[data-import-browse]');
        const status = zone.querySelector('[data-import-file-status]');
        const error = zone.querySelector('[data-import-file-error]');
        let dragDepth = 0;

        const reject = message => {
            input.value = '';
            input.setCustomValidity(message);
            status.textContent = 'No file selected.';
            error.textContent = message;
            error.hidden = false;
        };
        const describeSelection = () => {
            const files = input.files;
            input.setCustomValidity('');
            error.hidden = true;
            error.textContent = '';
            if (!files.length) { status.textContent = 'No file selected.'; return; }
            const file = files[0];
            if (files.length !== 1) return reject('Choose one file at a time.');
            if (!/\.(csv|txt|tsv|xlsx)$/i.test(file.name)) return reject('Choose a CSV, TXT, TSV or XLSX file.');
            if (file.size > 20 * 1024 * 1024) return reject('Choose a file of 20 MB or less.');
            if (!file.size) return reject('Choose a file that is not empty.');
            const size = file.size < 1024 * 1024
                ? `${Math.max(1, Math.ceil(file.size / 1024))} KB`
                : `${(file.size / (1024 * 1024)).toFixed(1)} MB`;
            status.textContent = `${file.name} · ${size} — ready to continue.`;
        };
        browse.hidden = false;
        input.classList.add('sr-only');
        input.tabIndex = -1;
        browse.addEventListener('click', () => input.click());
        input.addEventListener('change', describeSelection);
        zone.addEventListener('dragenter', event => {
            event.preventDefault();
            if (!input.disabled) { dragDepth++; zone.classList.add('is-dragging'); }
        });
        zone.addEventListener('dragover', event => {
            event.preventDefault();
            if (event.dataTransfer) event.dataTransfer.dropEffect = input.disabled ? 'none' : 'copy';
        });
        zone.addEventListener('dragleave', event => {
            event.preventDefault();
            dragDepth = Math.max(0, dragDepth - 1);
            if (!dragDepth) zone.classList.remove('is-dragging');
        });
        zone.addEventListener('drop', event => {
            event.preventDefault();
            dragDepth = 0;
            zone.classList.remove('is-dragging');
            if (input.disabled) return;
            const files = event.dataTransfer?.files;
            if (!files?.length) return reject('Drop a file, or use Browse files to choose one.');
            if (files.length !== 1) return reject('Choose one file at a time.');
            try { input.files = files; }
            catch { return reject('Use Browse files to select this file in your browser.'); }
            input.dispatchEvent(new Event('change', {bubbles: true}));
        });
        describeSelection();
    }

    if (typeof module === 'object' && module.exports) module.exports = {wireImportFileDrop};
    if (typeof document !== 'undefined') document.querySelectorAll('[data-import-drop]').forEach(wireImportFileDrop);
})();
