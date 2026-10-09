(function () {
    function initializePhotoField(field) {
        if (field.dataset.contactPhotoInitialized) return;
        const input = field.querySelector('[data-contact-photo-input]');
        const preview = field.querySelector('[data-contact-photo-preview]');
        const status = field.querySelector('[data-contact-photo-preview-status]');
        const removeCheckbox = field.querySelector('[data-remove-contact-photo]');

        if (!input || !preview || !status) return;
        field.dataset.contactPhotoInitialized = 'true';

        const photoLabel = input.dataset.photoLabel || 'contact';
        const photoTitle = photoLabel.charAt(0).toUpperCase() + photoLabel.slice(1);
        const originalSource = preview.getAttribute('src');
        const originalAlt = preview.getAttribute('alt') || 'Current ' + photoLabel + ' photo';
        const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        const maximumBytes = Number(input.dataset.maxBytes || 0);
        let selectionVersion = 0;
        let clipboardRead = 0;
        let loadingSelectedPhoto = false;

        function showStatus(message) {
            status.textContent = message;
            status.hidden = false;
        }

        function photoError(file) {
            if (!allowedTypes.includes(file.type)) {
                return 'Choose a JPEG, PNG, or WebP ' + photoLabel + ' photo.';
            }
            if (maximumBytes > 0 && file.size > maximumBytes) {
                return photoTitle + ' photos must be 5 MB or smaller.';
            }
            return '';
        }

        function restoreCurrentPhoto() {
            selectionVersion += 1;
            loadingSelectedPhoto = false;
            preview.src = originalSource;
            preview.alt = originalAlt;
            status.hidden = true;
            status.textContent = '';
        }

        input.addEventListener('change', function () {
            ++clipboardRead;
            input.setCustomValidity('');
            const file = input.files && input.files[0];
            if (!file) {
                restoreCurrentPhoto();
                return;
            }

            const error = photoError(file);
            if (error) {
                input.setCustomValidity(error);
                restoreCurrentPhoto();
                input.reportValidity();
                return;
            }

            const currentSelection = ++selectionVersion;
            const reader = new FileReader();
            status.textContent = 'Preparing photo preview…';
            status.hidden = false;
            if (removeCheckbox) removeCheckbox.checked = false;

            reader.addEventListener('load', function () {
                if (currentSelection !== selectionVersion || typeof reader.result !== 'string') return;
                loadingSelectedPhoto = true;
                preview.src = reader.result;
                preview.alt = 'Preview of selected ' + photoLabel + ' photo';
                status.textContent = 'Preview updated. Save changes to apply this photo.';
            });

            reader.addEventListener('error', function () {
                if (currentSelection !== selectionVersion) return;
                input.setCustomValidity('That photo could not be previewed. Choose a different file.');
                restoreCurrentPhoto();
                status.textContent = 'That photo could not be previewed. Choose a different file.';
                status.hidden = false;
                input.reportValidity();
            });

            reader.readAsDataURL(file);
        });

        const pasteButton = field.querySelector('[data-contact-photo-paste]');
        const pasteBox = field.querySelector('[data-contact-photo-paste-box]');
        const pasteTarget = field.querySelector('[data-contact-photo-paste-target]');

        function stageClipboardPhoto(files) {
            const image = files.find(function (file) { return file.type.startsWith('image/'); });
            if (!image) {
                showStatus('No image found. Copy the image itself, rather than its filename or link, then paste again.');
                return;
            }
            const error = photoError(image);
            if (error) {
                // An invalid paste must not replace a photo already selected for upload.
                showStatus(error);
                return;
            }
            try {
                const extension = image.type === 'image/jpeg' ? 'jpg' : image.type.split('/')[1];
                const transfer = new DataTransfer();
                transfer.items.add(new File([image], photoLabel + '-photo-' + Date.now() + '.' + extension, { type: image.type }));
                input.files = transfer.files;
            } catch (error) {
                showStatus('Please save the image and choose the file in this browser.');
                return;
            }
            pasteTarget.value = '';
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }

        if (pasteButton && pasteBox && pasteTarget) {
            pasteButton.addEventListener('click', async function () {
                pasteBox.hidden = false;
                pasteButton.setAttribute('aria-expanded', 'true');
                pasteTarget.focus();
                const read = ++clipboardRead;
                const keyboardHelp = 'Press Command+V or Ctrl+V in the paste box to add your image.';
                if (!navigator.clipboard || !navigator.clipboard.read) {
                    showStatus(keyboardHelp);
                    return;
                }
                showStatus('Choose Paste in your browser’s prompt, or press Command+V / Ctrl+V in the box below.');
                try {
                    const items = await navigator.clipboard.read();
                    if (read !== clipboardRead) return;
                    for (const item of items) {
                        const type = item.types.find(function (value) { return allowedTypes.includes(value); });
                        if (!type) continue;
                        const image = await item.getType(type);
                        if (read === clipboardRead) stageClipboardPhoto([image]);
                        return;
                    }
                    showStatus('Copy a JPEG, PNG, or WebP image first, then paste it here.');
                } catch (error) {
                    if (read === clipboardRead) showStatus(keyboardHelp);
                }
            });

            input.closest('.contact-photo-field').addEventListener('paste', function (event) {
                const clipboard = event.clipboardData;
                let files = Array.from(clipboard ? clipboard.files || [] : []);
                if (!files.length && clipboard) {
                    files = Array.from(clipboard.items || []).filter(function (item) { return item.kind === 'file'; })
                        .map(function (item) { return item.getAsFile(); }).filter(Boolean);
                }
                if (event.target !== pasteTarget && !files.some(function (file) { return file.type.startsWith('image/'); })) return;
                event.preventDefault();
                ++clipboardRead;
                stageClipboardPhoto(files);
            });
        }

        preview.addEventListener('load', function () {
            loadingSelectedPhoto = false;
        });

        preview.addEventListener('error', function () {
            if (!loadingSelectedPhoto) return;
            loadingSelectedPhoto = false;
            input.setCustomValidity('Choose a valid JPEG, PNG, or WebP ' + photoLabel + ' photo.');
            preview.src = originalSource;
            preview.alt = originalAlt;
            status.textContent = 'That photo could not be previewed. Choose a different file.';
            status.hidden = false;
            input.reportValidity();
        });

        if (removeCheckbox) {
            removeCheckbox.addEventListener('change', function () {
                ++clipboardRead;
                if (!removeCheckbox.checked) {
                    restoreCurrentPhoto();
                    return;
                }
                input.value = '';
                input.setCustomValidity('');
                restoreCurrentPhoto();
                status.textContent = 'The current photo will be removed when you save changes.';
                status.hidden = false;
            });
        }
    }

    function scan() {
        document.querySelectorAll('.contact-photo-field').forEach(initializePhotoField);
    }
    scan();
    new MutationObserver(scan).observe(document.body, { childList: true, subtree: true });
})();
