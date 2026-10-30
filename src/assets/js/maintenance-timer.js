(function () {
    'use strict';

    const page = document.querySelector('[data-maintenance-page]');
    if (!page) return;

    function display(seconds) {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const remainingSeconds = seconds % 60;
        return `${hours}:${String(minutes).padStart(2, '0')}:${String(remainingSeconds).padStart(2, '0')}`;
    }

    const timer = document.querySelector('[data-maintenance-timer]');
    const initialSeconds = timer ? Number(timer.dataset.remainingSeconds) : NaN;
    const started = performance.now();
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

    let interval;
    if (Number.isFinite(initialSeconds) && initialSeconds >= 0) {
        interval = window.setInterval(tick, 250);
        tick();
    }

    const {readyUrl, statusUrl, waitForDeployment} = page.dataset;
    if (!readyUrl || !statusUrl) return;
    let checking = false;
    let returning = false;
    async function checkReady() {
        if (checking || returning) return;
        checking = true;
        try {
            async function readStatus(url) {
                // Each request gets its own budget. Three valid but slow mobile
                // responses must not repeatedly exceed a shared four-second limit.
                const controller = new AbortController();
                const timeout = window.setTimeout(() => controller.abort(), 10000);
                try {
                    const response = await fetch(url, {cache: 'no-store', credentials: 'omit', signal: controller.signal});
                    return response.ok ? await response.json() : null;
                } finally {
                    window.clearTimeout(timeout);
                }
            }
            async function deploymentFinished() {
                const status = await readStatus(statusUrl);
                return status !== null && Number.isFinite(status.server_now) && status.notice === null
                    && (waitForDeployment !== 'true' || status.deployment_complete === true);
            }
            // web can become ready before Compose replaces ingress and Traefik
            // restores its routes. Only the host's completed transaction releases
            // this page; missing notices, proxy 404s and failed deployments do not.
            if (!await deploymentFinished()) return;
            const readiness = await readStatus(readyUrl);
            if (readiness?.status === 'ready' && await deploymentFinished()) {
                returning = true;
                // Always request the original page/query with GET; reload() could
                // replay an interrupted POST. Drop any anchor so this cannot be
                // treated as a same-document scroll instead of loading content.
                window.location.replace(window.location.href.split('#')[0]);
            }
        } catch (_) {
            // Leave the rendered page in place through routing/transport failures.
        } finally {
            checking = false;
        }
    }
    window.setInterval(checkReady, 30000);
    // Background/suspended mobile tabs may pause intervals. Resume checking as
    // soon as the page is visible or the connection returns, even after zero.
    window.addEventListener('online', checkReady);
    window.addEventListener('pageshow', checkReady);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) checkReady(); });
    checkReady();
})();
