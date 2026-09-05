(function () {
    'use strict';

    function noticePresentation(notice, serverNow) {
        if (!notice || !['preparing', 'pending', 'deploying', 'failed'].includes(notice.phase)) return null;
        if (['preparing', 'pending'].includes(notice.phase) && notice.expires_at <= serverNow) return null;
        if (notice.phase === 'preparing') {
            return { title: 'An update is being prepared', detail: 'You can continue working. A five-minute save countdown will appear before maintenance starts.', timer: '' };
        }
        if (notice.phase === 'deploying') {
            return { title: 'Deployment in progress', detail: 'The system is being updated. Please wait before making changes.', timer: '' };
        }
        if (notice.phase === 'failed') {
            return { title: 'Update needs attention', detail: 'Please wait for an administrator before making changes.', timer: '' };
        }
        const remaining = Math.max(0, Math.min(300, Math.ceil(notice.not_before - serverNow)));
        if (!remaining) {
            return { title: 'Update pending', detail: 'Please save your work. Deployment will begin shortly.', timer: '' };
        }
        return {
            title: 'Scheduled update in',
            detail: 'Please save your work before the countdown ends.',
            timer: `${Math.floor(remaining / 60)}:${String(remaining % 60).padStart(2, '0')}`,
        };
    }

    if (typeof module !== 'undefined' && module.exports) module.exports = { noticePresentation };
    if (typeof document === 'undefined') return;
    const banner = document.querySelector('[data-deployment-notice]');
    if (!banner) return;
    const title = banner.querySelector('[data-deployment-title]');
    const detail = banner.querySelector('[data-deployment-detail]');
    const timer = banner.querySelector('[data-deployment-timer]');
    let notice = null;
    let clockOffset = 0;
    let pollTimer;
    let polling = false;

    function measure() {
        document.body.style.setProperty('--deployment-banner-height', `${banner.hidden ? 0 : banner.offsetHeight}px`);
    }
    function render() {
        const view = noticePresentation(notice, (Date.now() - clockOffset) / 1000);
        banner.hidden = !view;
        document.body.classList.toggle('deployment-notice-active', Boolean(view));
        if (view) {
            // Announce state changes politely, without reading every countdown tick.
            if (title.textContent !== view.title) title.textContent = view.title;
            if (detail.textContent !== view.detail) detail.textContent = view.detail;
            timer.textContent = view.timer;
            timer.hidden = !view.timer;
        }
        measure();
    }
    async function poll() {
        if (polling) return;
        polling = true;
        window.clearTimeout(pollTimer);
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 4000);
        try {
            const response = await fetch(banner.dataset.statusUrl, {
                cache: 'no-store', credentials: 'omit', signal: controller.signal,
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error('Deployment status unavailable');
            const payload = await response.json();
            if (!Number.isFinite(payload.server_now) || !Object.hasOwn(payload, 'notice')) throw new Error('Invalid deployment status');
            if (payload.notice && ((payload.notice.phase !== 'preparing' && !Number.isFinite(payload.notice.not_before))
                || !Number.isFinite(payload.notice.expires_at))) throw new Error('Invalid deployment deadline');
            clockOffset = Date.now() - payload.server_now * 1000;
            notice = payload.notice;
            render();
        } catch (_) {
            // Keep the last known warning through outages; never reload an unsaved form.
        } finally {
            window.clearTimeout(timeout);
            polling = false;
            pollTimer = window.setTimeout(poll, 5000);
        }
    }
    if (typeof ResizeObserver !== 'undefined') new ResizeObserver(measure).observe(banner);
    window.addEventListener('resize', measure);
    window.addEventListener('online', poll);
    window.addEventListener('pageshow', poll);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
    window.setInterval(render, 1000);
    poll();
})();
