(function () {
    const input = document.querySelector('[data-contact-photo-input]');
    const preview = document.querySelector('[data-contact-photo-preview]');
    const status = document.querySelector('[data-contact-photo-preview-status]');
    const removeCheckbox = document.querySelector('[data-remove-contact-photo]');

    if (!input || !preview || !status) return;

    const originalSource = preview.getAttribute('src');
    const originalAlt = preview.getAttribute('alt') || 'Current contact photo';
    const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    const maximumBytes = Number(input.dataset.maxBytes || 0);
    let selectionVersion = 0;
    let loadingSelectedPhoto = false;

    function restoreCurrentPhoto() {
        selectionVersion += 1;
        loadingSelectedPhoto = false;
        preview.src = originalSource;
        preview.alt = originalAlt;
        status.hidden = true;
        status.textContent = '';
    }

    input.addEventListener('change', function () {
        input.setCustomValidity('');
        const file = input.files && input.files[0];
        if (!file) {
            restoreCurrentPhoto();
            return;
        }

        if (!allowedTypes.includes(file.type)) {
            input.setCustomValidity('Choose a JPEG, PNG, or WebP contact photo.');
            restoreCurrentPhoto();
            input.reportValidity();
            return;
        }

        if (maximumBytes > 0 && file.size > maximumBytes) {
            input.setCustomValidity('Contact photos must be 5 MB or smaller.');
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
            preview.alt = 'Preview of selected contact photo';
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

    preview.addEventListener('load', function () {
        loadingSelectedPhoto = false;
    });

    preview.addEventListener('error', function () {
        if (!loadingSelectedPhoto) return;
        loadingSelectedPhoto = false;
        input.setCustomValidity('Choose a valid JPEG, PNG, or WebP contact photo.');
        preview.src = originalSource;
        preview.alt = originalAlt;
        status.textContent = 'That photo could not be previewed. Choose a different file.';
        status.hidden = false;
        input.reportValidity();
    });

    if (removeCheckbox) {
        removeCheckbox.addEventListener('change', function () {
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
})();
