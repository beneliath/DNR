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
    const expiresAt = Number(banner.dataset.expiresAt);
    // Anchor to the server clock, so a differently configured device clock does not restart the unlock.
    const clockOffset = Date.now() - Number(banner.dataset.serverNow) * 1000;
    let tick;

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

    if (typeof ResizeObserver !== 'undefined') new ResizeObserver(measure).observe(banner);
    window.addEventListener('resize', measure);
    window.addEventListener('pageshow', render);
    document.addEventListener('visibilitychange', render);
    tick = window.setInterval(render, 250);
    render();
})();
