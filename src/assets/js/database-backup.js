(function () {
    'use strict';

    const form = document.getElementById('database-backup-form');
    const status = document.getElementById('database-backup-status');
    const tokenInput = document.getElementById('database-backup-token');
    const lastCreated = document.getElementById('database-backup-last-created');
    const button = form?.querySelector('button[type="submit"]');
    if (!form || !status || !tokenInput || !button || !window.crypto?.getRandomValues) return;

    const buttonLabel = button.textContent;
    const fields = Array.from(form.querySelectorAll('input:not([type="hidden"])'));
    let pending = false;
    let timer;

    function showStatus(state, message) {
        status.dataset.state = state;
        status.hidden = false;
        status.textContent = message;
    }

    function finish() {
        window.clearInterval(timer);
        pending = false;
        button.disabled = false;
        button.textContent = buttonLabel;
        form.removeAttribute('aria-busy');
        fields.forEach(function (field) { field.readOnly = false; });
    }

    form.addEventListener('submit', function (event) {
        if (pending) {
            event.preventDefault();
            return;
        }
        if (event.defaultPrevented) return;

        const token = Array.from(window.crypto.getRandomValues(new Uint8Array(16)), function (byte) {
            return byte.toString(16).padStart(2, '0');
        }).join('');
        tokenInput.value = token;
        const cookieName = 'dnr_backup_' + token;
        const started = Date.now();
        pending = true;
        button.disabled = true;
        button.textContent = 'Creating Encrypted Backup…';
        form.setAttribute('aria-busy', 'true');
        fields.forEach(function (field) { field.readOnly = true; });
        document.getElementById('database-backup-error')?.remove();
        showStatus('pending', 'Creating and encrypting your backup. Your download will start when it is ready. Keep this page open.');

        // Keep the native form download: large archives stream directly to the browser.
        // Failed requests navigate to the existing server-rendered error page.
        timer = window.setInterval(function () {
            const cookie = document.cookie.split('; ').find(function (entry) {
                return entry.startsWith(cookieName + '=');
            });
            if (cookie) {
                let result;
                try {
                    result = JSON.parse(decodeURIComponent(cookie.slice(cookieName.length + 1)));
                } catch (error) { /* Ignore invalid or unrelated completion data. */ }
                if (result && /^dnr-database-\d{8}-\d{6}Z\.dnrbackup$/.test(result.filename)
                    && typeof result.createdAt === 'string') {
                    document.cookie = cookieName + '=; Max-Age=0; Path=/; SameSite=Strict';
                    finish();
                    fields.forEach(function (field) { field.value = ''; });
                    if (lastCreated) lastCreated.textContent = result.createdAt;
                    showStatus('success', 'Backup created successfully. Download started: ' + result.filename
                        + '. Check your browser’s Downloads to confirm the file finished saving. Store the file and its password securely.');
                    return;
                }
            }
            // Allow more than the exporter's 310-second timeout before offering a retry.
            if (Date.now() - started >= 360000) {
                finish();
                showStatus('warning', 'Download confirmation was not received. Check your browser’s Downloads before trying again with a fresh authenticator or recovery code.');
            }
        }, 500);
    });

    window.addEventListener('pageshow', function (event) {
        if (event.persisted && pending) {
            finish();
            showStatus('warning', 'Check your browser’s Downloads for the backup before starting another export.');
        }
    });
})();
