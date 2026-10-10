(function () {
    if (typeof document === 'undefined') return;
    const body = document.body;
    body.classList.add('has-app-shell');
    if (document.querySelector('[data-role-preview-banner]')) body.classList.add('role-preview-active');
    const coachLayout = document.querySelector('[data-coach-layout-key]');
    if (coachLayout) {
        body.classList.add('has-coach');
        try {
            const saved = JSON.parse(window.DnrAccountContext.session.getItem('moed-coach-session') || '{}');
            const sameSession = saved.key === coachLayout.dataset.coachLayoutKey
                || (coachLayout.dataset.coachPaneKey && saved.paneKey === coachLayout.dataset.coachPaneKey);
            body.classList.toggle('coach-open', !!sameSession
                && saved.open === true && coachLayout.dataset.coachPage !== 'help.php');
        } catch (_) { /* Coach storage is optional. */ }
    }

    const sidebar = document.getElementById('app-sidebar');
    if (sidebar) {
        const preferenceUser = sidebar.getAttribute('data-nav-preference-user');
        const preferenceCookie = sidebar.getAttribute('data-nav-preference-cookie');
        const sections = Array.from(sidebar.querySelectorAll('[data-nav-group]'));
        function storageKey(section) {
            return 'dnr.sidebar.' + encodeURIComponent(preferenceUser) + '.' + section.getAttribute('data-nav-group');
        }
        function saveCookie() {
            if (!preferenceCookie) return;
            const state = sections.map(function (section) {
                return section.getAttribute('data-nav-group') + '=' + (section.open ? '1' : '0');
            }).join(',');
            document.cookie = preferenceCookie + '=' + encodeURIComponent(state)
                + '; Path=' + new URL('.', window.location.href).pathname + '; Max-Age=31536000; SameSite=Lax'
                + (window.location.protocol === 'https:' ? '; Secure' : '');
        }
        sections.forEach(function (section) {
            try {
                const saved = window.DnrAccountContext.local.getItem(storageKey(section));
                if (saved === 'open' || saved === 'closed') section.open = saved === 'open';
            } catch (_) { /* Keep the defaults when browser storage is unavailable. */ }
            section.addEventListener('toggle', function () {
                try {
                    window.DnrAccountContext.local.setItem(storageKey(section), section.open ? 'open' : 'closed');
                } catch (_) { /* The section still works without browser storage. */ }
                saveCookie();
            });
        });
        saveCookie();
        function collapseSections() {
            sections.forEach(function (section) {
                section.open = false;
                try {
                    window.DnrAccountContext.local.setItem(storageKey(section), 'closed');
                } catch (_) { /* The sections still collapse without browser storage. */ }
            });
            saveCookie();
        }
        sidebar.addEventListener('click', function (event) {
            if (event.target.closest('.site-navigation a[href]')) saveCookie();
        });
        document.querySelectorAll('.app-brand, .mobile-brand').forEach(function (brand) {
            brand.addEventListener('click', function (event) {
                if (event.defaultPrevented || event.button !== 0 || (!event.metaKey && !event.ctrlKey)) return;
                event.preventDefault();
                collapseSections();
            });
            brand.addEventListener('contextmenu', function (event) {
                if (event.defaultPrevented || !event.ctrlKey) return;
                event.preventDefault();
                collapseSections();
            });
        });
    }

    // This parser-blocking script runs before main content is parsed. Reserve
    // the complete banner stack now, rather than moving a painted page at DOMContentLoaded.
    const accountBanner = document.querySelector('.account-identity-banner');
    if (accountBanner) body.classList.add('account-context-active');
    if (accountBanner || document.querySelector('.role-preview-banner')
        || document.querySelector('.admin-unlock-banner') || document.querySelector('.deployment-notice-banner')
        || document.querySelector('.mobile-app-bar')) {
        const mobileBar = document.querySelector('.mobile-app-bar');
        const bars = [document.querySelector('.deployment-notice-banner'), accountBanner,
            document.querySelector('.role-preview-banner'), document.querySelector('.admin-unlock-banner')].filter(Boolean);
        const visibleHeight = function (element) {
            return element && !element.hidden && getComputedStyle(element).display !== 'none'
                ? element.getBoundingClientRect().height : 0;
        };
        const updateAccountStack = function () {
            let offset = visibleHeight(mobileBar);
            bars.forEach(function (bar) {
                bar.style.top = offset + 'px';
                offset += visibleHeight(bar);
            });
            document.documentElement.style.setProperty('--account-shell-height', Math.ceil(offset) + 'px');
        };
        updateAccountStack();
        const resize = new ResizeObserver(updateAccountStack);
        bars.concat(mobileBar ? [mobileBar] : []).forEach(function (bar) { resize.observe(bar); });
        const changes = new MutationObserver(updateAccountStack);
        bars.forEach(function (bar) { changes.observe(bar, {attributes: true, attributeFilter: ['hidden', 'class']}); });
        changes.observe(body, {attributes: true, attributeFilter: ['class']});
        window.addEventListener('resize', updateAccountStack);
    }

    function initialize() {
        const toggle = document.querySelector('[data-nav-toggle]');
        const closeButton = document.querySelector('[data-nav-close]');
        const backdrop = document.querySelector('[data-nav-backdrop]');
        const header = document.querySelector('.app-shell-header');
        const main = document.querySelector('main, [role="main"]');
        const skip = document.querySelector('[data-skip-link]');
        if (main && skip) {
            if (!main.id) main.id = 'app-main';
            main.setAttribute('tabindex', '-1');
            skip.href = '#' + main.id;
            skip.addEventListener('click', function () { main.focus(); });
        }
        if (!sidebar || !toggle || !backdrop || !header) return;

        let open = false;
        const suspended = new Map();
        const mobile = function () { return window.innerWidth <= 860; };
        const focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex="0"]';
        const focusable = function () {
            return Array.from(sidebar.querySelectorAll(focusableSelector)).filter(function (element) {
                return !element.closest('[hidden], [inert]') && element.getClientRects().length > 0;
            });
        };

        function restoreBackground() {
            suspended.forEach(function (wasInert, element) { element.inert = wasInert; });
            suspended.clear();
        }

        function suspendBackground() {
            const elements = Array.from(body.children).filter(function (element) {
                return element !== header && !element.matches('script, style, link, dialog');
            }).concat(Array.from(header.children).filter(function (element) {
                return element !== sidebar && element !== backdrop;
            }));
            elements.forEach(function (element) {
                suspended.set(element, element.inert);
                element.inert = true;
            });
        }

        function setNavigationOpen(requested, restoreFocus) {
            const wasOpen = open;
            open = requested && mobile();
            restoreBackground();
            body.classList.toggle('navigation-open', open);
            toggle.setAttribute('aria-expanded', String(open));
            const label = toggle.querySelector('.visually-hidden');
            if (label) label.textContent = open ? 'Close Navigation' : 'Open Navigation';
            sidebar.inert = mobile() && !open;
            if (open) {
                sidebar.setAttribute('role', 'dialog');
                sidebar.setAttribute('aria-modal', 'true');
                suspendBackground();
                (closeButton || focusable()[0])?.focus();
            } else {
                sidebar.removeAttribute('role');
                sidebar.removeAttribute('aria-modal');
                if (wasOpen && restoreFocus && mobile()) toggle.focus();
            }
        }

        toggle.addEventListener('click', function () { setNavigationOpen(!open, true); });
        closeButton?.addEventListener('click', function () { setNavigationOpen(false, true); });
        backdrop.addEventListener('click', function () { setNavigationOpen(false, true); });
        document.addEventListener('keydown', function (event) {
            if (!open || document.querySelector('dialog[open]')) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                setNavigationOpen(false, true);
            } else if (event.key === 'Tab') {
                const controls = focusable();
                const first = controls[0];
                const last = controls[controls.length - 1];
                if (!first) { event.preventDefault(); return; }
                if (event.shiftKey && (document.activeElement === first || !sidebar.contains(document.activeElement))) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && (document.activeElement === last || !sidebar.contains(document.activeElement))) {
                    event.preventDefault();
                    first.focus();
                }
            }
        });
        window.addEventListener('resize', function () {
            if (!mobile() || !open) setNavigationOpen(false, false);
        });
        setNavigationOpen(false, false);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
    else initialize();
})();
