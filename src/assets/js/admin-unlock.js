(function () {
    'use strict';

    function adminUnlockCountdown(expiresAt, serverNow) {
        if (!Number.isFinite(expiresAt) || !Number.isFinite(serverNow)) return '';
        const remaining = Math.max(0, Math.min(300, Math.ceil(expiresAt - serverNow)));
        return remaining ? `${Math.floor(remaining / 60)}:${String(remaining % 60).padStart(2, '0')}` : '';
    }

    if (typeof module !== 'undefined' && module.exports) module.exports = { adminUnlockCountdown };
    if (typeof document === 'undefined') return;
    const banner = document.querySelector('[data-admin-unlock]');
    if (!banner) return;
    const timer = banner.querySelector('[data-admin-unlock-timer]');
    const lockForm = banner.querySelector('[data-admin-lock-form]');
    let expiresAt = Number(banner.dataset.expiresAt);
    // Anchor to the server clock, so a differently configured device clock does not restart the unlock.
    let clockOffset = Date.now() - Number(banner.dataset.serverNow) * 1000;
    let tick;
    let statusRevision = 0;

    function measure() {
        document.body.style.setProperty('--admin-unlock-banner-height', `${banner.hidden ? 0 : banner.offsetHeight}px`);
    }

    function render() {
        const countdown = adminUnlockCountdown(expiresAt, (Date.now() - clockOffset) / 1000);
        timer.textContent = countdown;
        banner.hidden = !countdown;
        document.body.classList.toggle('admin-unlock-active', Boolean(countdown));
        measure();
        if (!countdown) window.clearInterval(tick);
    }

    async function refresh() {
        render();
        const revision = statusRevision;
        try {
            const response = await fetch('admin_unlock_status.php', { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok || response.redirected) return;
            const status = await response.json();
            if (revision !== statusRevision) return;
            expiresAt = status.unlocked === true ? Number(status.expires_at) : 0;
            clockOffset = Date.now() - Number(status.server_now) * 1000;
            if (lockForm && typeof status.csrf_token === 'string') {
                lockForm.querySelector('[name="csrf_token"]').value = status.csrf_token;
            }
            window.clearInterval(tick);
            tick = window.setInterval(render, 250);
            render();
        } catch (error) {
            // Keep the server-anchored countdown if a background refresh fails.
        }
    }

    if (lockForm) {
        const button = lockForm.querySelector('button');
        const errorMessage = lockForm.querySelector('[data-admin-lock-error]');
        lockForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (button.disabled) return;
            statusRevision++;
            button.disabled = true;
            errorMessage.hidden = true;
            try {
                const response = await fetch(lockForm.action, {
                    method: 'POST', credentials: 'same-origin', cache: 'no-store',
                    headers: { Accept: 'application/json' }, body: new URLSearchParams(new FormData(lockForm))
                });
                if (!response.ok || response.redirected || (await response.json()).locked !== true) {
                    throw new Error('Lock request failed');
                }
                expiresAt = 0;
                statusRevision++;
                render();
            } catch (error) {
                errorMessage.textContent = 'Unable to lock admin actions. Please try again.';
                errorMessage.hidden = false;
            } finally {
                button.disabled = false;
            }
        });
    }

    if (typeof ResizeObserver !== 'undefined') new ResizeObserver(measure).observe(banner);
    window.addEventListener('resize', measure);
    window.addEventListener('pageshow', refresh);
    document.addEventListener('visibilitychange', refresh);
    tick = window.setInterval(render, 250);
    render();
})();
