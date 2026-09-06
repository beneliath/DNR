(function () {
    const digestSchedule = document.querySelector('[data-task-digest-schedule]');
    const digestEnabled = document.querySelector('[data-task-digest-enabled]');
    const digestPaused = document.querySelector('[data-task-digest-paused]');
    if (digestSchedule) {
        const dayInputs = Array.from(
            digestSchedule.querySelectorAll('input[name="task_digest_days[]"]')
        );
        const presetButtons = Array.from(
            digestSchedule.querySelectorAll('[data-task-digest-days]')
        );

        function selectedDaysMask() {
            return dayInputs.reduce(function (mask, dayInput) {
                return dayInput.checked ? mask | Number(dayInput.value) : mask;
            }, 0);
        }

        function updateDigestDayControls() {
            const enabled = Boolean(digestEnabled && digestEnabled.checked && !digestEnabled.disabled);
            digestSchedule.hidden = !enabled;
            if (digestPaused) digestPaused.hidden = enabled;
            digestSchedule.querySelectorAll('input, button, fieldset').forEach(function (control) {
                control.disabled = !enabled;
            });
            const selectedMask = selectedDaysMask();
            dayInputs.forEach(function (dayInput, index) {
                dayInput.setCustomValidity(
                    enabled && index === 0 && selectedMask === 0
                        ? 'Choose at least one daily work digest delivery day.'
                        : ''
                );
            });
            presetButtons.forEach(function (button) {
                button.setAttribute(
                    'aria-pressed',
                    Number(button.dataset.taskDigestDays) === selectedMask ? 'true' : 'false'
                );
            });
        }

        dayInputs.forEach(function (dayInput) {
            dayInput.addEventListener('change', updateDigestDayControls);
        });
        if (digestEnabled) digestEnabled.addEventListener('change', updateDigestDayControls);
        presetButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                const requestedMask = Number(button.dataset.taskDigestDays);
                dayInputs.forEach(function (dayInput) {
                    dayInput.checked = (requestedMask & Number(dayInput.value)) !== 0;
                });
                updateDigestDayControls();
            });
        });
        updateDigestDayControls();
    }

    const resendForm = document.getElementById('profile-resend-verification-form');
    const resendButton = document.querySelector('[data-resend-verification]');
    const resendStatus = document.querySelector('[data-verification-status]');
    if (resendForm && resendButton && resendStatus && window.fetch) {
        let resending = false;
        resendForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (resending) return;
            resending = true;
            resendButton.disabled = true;
            resendButton.setAttribute('aria-busy', 'true');
            resendStatus.hidden = false;
            resendStatus.className = 'profile-verification-status';
            resendStatus.textContent = 'Requesting a verification link…';
            try {
                const response = await fetch(resendForm.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    body: new FormData(resendForm)
                });
                if (response.redirected || !response.headers.get('content-type')?.includes('application/json')) {
                    throw new Error('Your session may have expired. Sign in in another tab and retry; your unsaved changes are still here.');
                }
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.message || 'The verification link could not be requested. Try again.');
                resendStatus.className = 'profile-verification-status success';
                resendStatus.textContent = result.message;
            } catch (error) {
                resendStatus.className = 'profile-verification-status error';
                resendStatus.textContent = error instanceof TypeError
                    ? 'The verification request could not be completed. Check your connection and retry; your unsaved changes are still here.'
                    : error.message;
            } finally {
                resending = false;
                resendButton.disabled = false;
                resendButton.removeAttribute('aria-busy');
            }
        });
    }

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
