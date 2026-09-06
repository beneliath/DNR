(function () {
    if (typeof document === 'undefined') return;
    const body = document.body;
    body.classList.add('has-app-shell');
    if (document.querySelector('[data-role-preview-banner]')) body.classList.add('role-preview-active');

    function initialize() {
        const sidebar = document.getElementById('app-sidebar');
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
