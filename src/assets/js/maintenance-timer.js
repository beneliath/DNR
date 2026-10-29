(function () {
    'use strict';

    const timer = document.querySelector('[data-maintenance-timer]');
    if (!timer) return;
    const initialSeconds = Number(timer.dataset.remainingSeconds);
    if (!Number.isFinite(initialSeconds) || initialSeconds < 0) return;
    const started = performance.now();
    // The HTML refresh remains a fallback when JavaScript is unavailable.
    document.querySelector('meta[http-equiv="refresh"]')?.remove();

    function display(seconds) {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const remainingSeconds = seconds % 60;
        return `${hours}:${String(minutes).padStart(2, '0')}:${String(remainingSeconds).padStart(2, '0')}`;
    }

    function tick() {
        const seconds = Math.max(0, Math.ceil(initialSeconds - (performance.now() - started) / 1000));
        timer.textContent = display(seconds);
        if (seconds === 0) {
            window.clearInterval(interval);
            timer.classList.add('timer-expired');
            timer.setAttribute('aria-label', 'Estimated return time has passed');
            const detail = document.querySelector('[data-maintenance-estimate]');
            if (detail) detail.textContent = 'The estimate has passed. Please check back soon.';
        }
    }

    const interval = window.setInterval(tick, 250);
    tick();

    const readyUrl = timer.dataset.readyUrl;
    if (!readyUrl) return;
    let checking = false;
    async function checkReady() {
        if (checking) return;
        checking = true;
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 4000);
        try {
            const response = await fetch(readyUrl, {cache: 'no-store', credentials: 'omit', signal: controller.signal});
            if (response.ok && (await response.json()).status === 'ready') {
                window.location.replace(window.location.href);
            }
        } catch (_) {
            // Keep the timer running while the backend is unavailable.
        } finally {
            window.clearTimeout(timeout);
            checking = false;
        }
    }
    window.setInterval(checkReady, 30000);
})();
