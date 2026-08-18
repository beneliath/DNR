(function () {
    const input = document.querySelector('[data-profile-picture-input]');
    const preview = document.querySelector('[data-profile-picture-preview]');
    const status = document.querySelector('[data-profile-picture-preview-status]');
    const removeCheckbox = document.querySelector('[data-remove-profile-picture]');

    if (!input || !preview || !status) return;

    const originalSource = preview.getAttribute('src');
    const originalAlt = preview.getAttribute('alt') || 'Current profile picture';
    const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    const maximumBytes = Number(input.dataset.maxBytes || 0);
    let selectionVersion = 0;
    let loadingSelectedPicture = false;

    function restoreCurrentPicture() {
        selectionVersion += 1;
        loadingSelectedPicture = false;
        preview.src = originalSource;
        preview.alt = originalAlt;
        status.hidden = true;
        status.textContent = '';
    }

    input.addEventListener('change', function () {
        input.setCustomValidity('');
        const file = input.files && input.files[0];
        if (!file) {
            restoreCurrentPicture();
            return;
        }

        if (!allowedTypes.includes(file.type)) {
            input.setCustomValidity('Choose a JPEG, PNG, or WebP profile picture.');
            restoreCurrentPicture();
            input.reportValidity();
            return;
        }

        if (maximumBytes > 0 && file.size > maximumBytes) {
            input.setCustomValidity('Profile pictures must be 5 MB or smaller.');
            restoreCurrentPicture();
            input.reportValidity();
            return;
        }

        const currentSelection = ++selectionVersion;
        const reader = new FileReader();
        status.textContent = 'Preparing picture preview…';
        status.hidden = false;
        if (removeCheckbox) removeCheckbox.checked = false;

        reader.addEventListener('load', function () {
            if (currentSelection !== selectionVersion || typeof reader.result !== 'string') return;
            loadingSelectedPicture = true;
            preview.src = reader.result;
            preview.alt = 'Preview of selected profile picture';
            status.textContent = 'Preview updated. Save changes to apply this picture.';
        });

        reader.addEventListener('error', function () {
            if (currentSelection !== selectionVersion) return;
            input.setCustomValidity('That picture could not be previewed. Choose a different file.');
            restoreCurrentPicture();
            status.textContent = 'That picture could not be previewed. Choose a different file.';
            status.hidden = false;
            input.reportValidity();
        });

        reader.readAsDataURL(file);
    });

    preview.addEventListener('load', function () {
        loadingSelectedPicture = false;
    });

    preview.addEventListener('error', function () {
        if (!loadingSelectedPicture) return;
        loadingSelectedPicture = false;
        input.setCustomValidity('Choose a valid JPEG, PNG, or WebP profile picture.');
        preview.src = originalSource;
        preview.alt = originalAlt;
        status.textContent = 'That picture could not be previewed. Choose a different file.';
        status.hidden = false;
        input.reportValidity();
    });

    if (removeCheckbox) {
        removeCheckbox.addEventListener('change', function () {
            if (!removeCheckbox.checked) {
                restoreCurrentPicture();
                return;
            }
            input.value = '';
            input.setCustomValidity('');
            restoreCurrentPicture();
            status.textContent = 'The current picture will be removed when you save changes.';
            status.hidden = false;
        });
    }
})();
