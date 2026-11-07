(() => {
    'use strict';
    const delay = 1000;
    const jobs = new Map();
    let indicator;
    let finishNavigation;

    function show() {
        if (indicator) return;
        indicator = document.createElement('div');
        indicator.className = 'page-loading-indicator';
        indicator.setAttribute('role', 'status');
        if (indicator.showPopover) indicator.setAttribute('popover', 'manual');
        (document.body || document.documentElement).append(indicator);
        indicator.showPopover?.();
        indicator.textContent = 'Loading…';
    }

    function begin(elapsed = 0) {
        const job = {visible: false};
        const finish = () => {
            clearTimeout(job.timer);
            jobs.delete(finish);
            if (indicator && !Array.from(jobs.values()).some(other => other.visible)) {
                indicator.remove();
                indicator = null;
            }
        };
        job.timer = setTimeout(() => {
            job.visible = true;
            show();
        }, Math.max(0, delay - elapsed));
        jobs.set(finish, job);
        return finish;
    }

    // Page fragments share the indicator, even when a newer request cancels an older one.
    window.DnrPageLoading = Object.freeze({begin: () => begin()});
    if (document.readyState !== 'complete') {
        const loaded = begin(performance.now());
        window.addEventListener('load', loaded, {once: true});
    }
    const reset = () => {
        for (const finish of jobs.keys()) finish();
        finishNavigation = null;
    };
    window.addEventListener('pagehide', reset);
    window.addEventListener('pageshow', event => { if (event.persisted) reset(); });

    function pageDestination(url) {
        return url.origin === location.origin && /^https?:$/.test(url.protocol)
            && !url.searchParams.has('download') && !url.searchParams.has('export')
            && !url.searchParams.has('_download_feedback') && !window.DnrButtonFeedback?.isDownload?.(url.href);
    }
    function startNavigation() {
        finishNavigation?.();
        finishNavigation = begin();
        return finishNavigation;
    }
    if (window.navigation) {
        window.navigation.addEventListener('navigate', event => {
            if (event.defaultPrevented || event.destination.sameDocument || event.downloadRequest != null
                || !pageDestination(new URL(event.destination.url))) {
                finishNavigation?.();
                return;
            }
            const finish = startNavigation();
            event.signal.addEventListener('abort', finish, {once: true});
            // Another listener can cancel this navigation after ours has run.
            setTimeout(() => { if (event.defaultPrevented || event.signal.aborted) finish(); }, 0);
        });
    } else {
        // Older browsers expose ordinary same-tab links and form submissions.
        const sameTab = target => !target || target === '_self' || (window === window.top && (target === '_parent' || target === '_top'));
        const follow = (event, url) => {
            if (pageDestination(url)) setTimeout(() => { if (!event.defaultPrevented) startNavigation(); }, 0);
        };
        window.addEventListener('click', event => {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            const link = event.target.closest?.('a[href]');
            if (!link || link.hasAttribute('download') || !sameTab(link.getAttribute('target') || document.querySelector('base[target]')?.target)) return;
            const url = new URL(link.getAttribute('href'), document.baseURI);
            if (url.hash && url.pathname === location.pathname && url.search === location.search) return;
            follow(event, url);
        });
        window.addEventListener('submit', event => {
            const form = event.target;
            const target = event.submitter?.getAttribute('formtarget') ?? form.getAttribute('target') ?? document.querySelector('base[target]')?.target;
            const method = event.submitter?.getAttribute('formmethod') ?? form.getAttribute('method');
            if (event.defaultPrevented || !sameTab(target) || method?.toLowerCase() === 'dialog') return;
            const action = event.submitter?.getAttribute('formaction') ?? form.getAttribute('action');
            follow(event, new URL(action || location.href, document.baseURI));
        });
        window.addEventListener('keydown', event => { if (event.key === 'Escape') finishNavigation?.(); });
    }
})();
