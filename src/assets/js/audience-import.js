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

    function wireImportTagging(panel) {
        const tags = Array.from(panel.querySelectorAll('input[name="tag_ids[]"]'));
        const groups = Array.from(panel.querySelectorAll('input[name="group_ids[]"]'));
        const search = panel.querySelector('[data-import-tag-search]');
        const selected = panel.querySelector('[data-import-selected-tags]');
        const tagCount = panel.querySelector('[data-import-tag-count]');
        const groupCount = panel.querySelector('[data-import-group-count]');
        const doc = panel.ownerDocument;
        const updateCount = (host, inputs) => {
            const text = `${inputs.filter(input => input.checked).length} selected`;
            if (host.textContent !== text) host.textContent = text;
        };
        const refreshSelected = () => {
            updateCount(tagCount, tags);updateCount(groupCount, groups);
            if (!selected) return;
            selected.replaceChildren();
            for (const input of tags.filter(input => input.checked)) {
                const name = input.parentElement.querySelector('span').textContent;
                const button = doc.createElement('button'), label = doc.createElement('span'), icon = doc.createElement('span');
                button.type = 'button';button.className = 'import-selected-tag';button.setAttribute('aria-label', `Remove ${name} tag`);
                label.textContent = name;icon.textContent = '×';icon.setAttribute('aria-hidden', 'true');button.append(label, icon);
                button.addEventListener('click', () => {
                    input.checked = false;input.dispatchEvent(new Event('change', {bubbles: true}));
                    if (!input.parentElement.hidden) input.focus();else search?.focus();
                });
                selected.append(button);
            }
            if (!selected.childElementCount) {
                const empty = doc.createElement('span');empty.className = 'import-selection-empty';empty.textContent = 'No tags selected';selected.append(empty);
            }
        };
        const filter = () => {
            const query = search.value.trim().toLocaleLowerCase();
            let visible = 0;
            for (const input of tags) {
                const option = input.parentElement;option.hidden = !option.textContent.toLocaleLowerCase().includes(query);
                if (!option.hidden) visible++;
            }
            panel.querySelector('[data-import-tags-empty]').hidden = visible > 0;
        };
        if (search) {panel.querySelector('[data-import-tag-search-wrap]').hidden = false;search.addEventListener('input', filter);search.addEventListener('search', filter);}
        panel.addEventListener('change', event => {if (tags.includes(event.target) || groups.includes(event.target)) refreshSelected();});
        refreshSelected();
    }

    if (typeof module === 'object' && module.exports) module.exports = {wireImportFileDrop, wireImportTagging};
    if (typeof document !== 'undefined') {
        document.querySelectorAll('[data-import-drop]').forEach(wireImportFileDrop);
        document.querySelectorAll('[data-import-tagging]').forEach(wireImportTagging);
    }
})();
