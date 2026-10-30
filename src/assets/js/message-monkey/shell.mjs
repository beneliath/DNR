import {$, $$} from './dom.mjs';
import {audienceMobileQuery} from '../audience-mobile.mjs';

export function initializeMessageMonkeyShell() {
    // Match the shared shell's fixed account/preview stack, including wrapped mobile labels.
    const accountBanner = $('.account-identity-banner');
    const previewBanner = $('[data-role-preview-banner]');
    const deploymentBanner = $('[data-deployment-notice]');
    const adminBanner = $('[data-admin-unlock]');
    if (accountBanner || previewBanner || deploymentBanner || adminBanner) {
        const syncBanners = () => {
            document.body.style.setProperty('--audience-account-banner-height', `${accountBanner?.offsetHeight || 0}px`);
            document.body.style.setProperty('--audience-preview-banner-height', `${previewBanner?.offsetHeight || 0}px`);
            sidebarPosition();
        };
        const bannerObserver = new ResizeObserver(syncBanners);
        [deploymentBanner, accountBanner, previewBanner, adminBanner].filter(Boolean).forEach(banner => bannerObserver.observe(banner));
        window.addEventListener('resize', syncBanners);
        // Defer until the sidebar position helper has been initialized below.
        requestAnimationFrame(syncBanners);
    }
    $$('[data-audience-logo]').forEach(logo => {
        const dashboard = event => {
            if (!event.ctrlKey || (event.type === 'click' && event.button !== 0)) return;
            event.preventDefault();
            window.location.assign(logo.dataset.dashboardUrl);
        };
        logo.addEventListener('click', dashboard);
        // macOS emits contextmenu instead of click for Control-click.
        logo.addEventListener('contextmenu', dashboard);
    });
    // Never reuse browser form history from the rest of MOED. Explicit credential hints remain intact.
    $$('form:not([autocomplete]), input:not([autocomplete]), textarea:not([autocomplete])').forEach(control => control.setAttribute('autocomplete', 'off'));
    const systemTheme = window.matchMedia('(prefers-color-scheme: dark)');
    const syncTheme = () => {
        let savedTheme; try { savedTheme = localStorage.getItem('theme'); } catch (_) { /* Storage can be disabled. */ }
        document.documentElement.classList.toggle('dark-mode', savedTheme === 'dark' || (!savedTheme && systemTheme.matches));
    };
    window.addEventListener('storage', event => { if (event.key === 'theme' || event.key === null) syncTheme(); });
    window.addEventListener('pageshow', syncTheme);
    systemTheme.addEventListener('change', syncTheme);
    const sidebarToggle = $('[data-sidebar-toggle]');
    const sidebarKey = 'moed:audience:sidebar-collapsed';
    const mobileNavigation = window.matchMedia(audienceMobileQuery);
    let sidebarCollapsed = false;
    const sidebarPosition = () => {
        const item = $('.audience-nav-item.selected');
        if (item) document.documentElement.style.setProperty('--audience-subnav-top', `${item.getBoundingClientRect().top}px`);
    };
    const setSidebar = collapsed => {
        sidebarCollapsed = collapsed;
        document.body.classList.toggle('audience-sidebar-collapsed', collapsed && !mobileNavigation.matches);
        if (sidebarToggle) {
            sidebarToggle.setAttribute('aria-expanded', String(!collapsed));
            sidebarToggle.setAttribute('aria-label', collapsed ? 'Expand navigation' : 'Minimize navigation');
            sidebarToggle.title = collapsed ? 'Expand navigation' : 'Minimize navigation';
        }
        sidebarPosition();
    };
    try { setSidebar(localStorage.getItem(sidebarKey) === '1'); } catch (_) { setSidebar(false); }
    sidebarToggle?.addEventListener('click', () => {
        const collapsed = !sidebarCollapsed;
        setSidebar(collapsed);
        try { localStorage.setItem(sidebarKey, collapsed ? '1' : '0'); } catch (_) { /* Still works without storage. */ }
    });
    window.addEventListener('resize', sidebarPosition);
    $('.audience-sidebar nav')?.addEventListener('scroll', sidebarPosition);
    // Match the main MOED mobile drawer's dismissal and keyboard behavior.
    const sidebar = $('#audience-sidebar');
    const mobileToggle = $('[data-audience-nav-toggle]');
    const navigationClose = $('[data-audience-nav-close]');
    const navigationBackdrop = $('[data-audience-nav-backdrop]');
    if (sidebar && mobileToggle && navigationClose && navigationBackdrop) {
        let navigationOpen = false;
        const suspended = new Map();
        const setNavigationOpen = (requested, restoreFocus = true) => {
            const wasOpen = navigationOpen;
            navigationOpen = requested && mobileNavigation.matches;
            suspended.forEach((wasInert, element) => { element.inert = wasInert; });
            suspended.clear();
            document.body.classList.toggle('audience-navigation-open', navigationOpen);
            mobileToggle.setAttribute('aria-expanded', String(navigationOpen));
            mobileToggle.setAttribute('aria-label', `${navigationOpen ? 'Close' : 'Open'} MessageMonkey navigation`);
            navigationBackdrop.hidden = !navigationOpen;
            sidebar.inert = mobileNavigation.matches && !navigationOpen;
            if (navigationOpen) {
                sidebar.setAttribute('role', 'dialog');
                sidebar.setAttribute('aria-modal', 'true');
                Array.from(document.body.children).filter(element =>
                    element !== sidebar && element !== navigationBackdrop && !element.matches('script, style, link, dialog')
                ).forEach(element => {
                    suspended.set(element, element.inert);
                    element.inert = true;
                });
                navigationClose.focus();
            } else {
                sidebar.removeAttribute('role');
                sidebar.removeAttribute('aria-modal');
                if (wasOpen && restoreFocus && mobileNavigation.matches) mobileToggle.focus();
            }
        };
        mobileToggle.addEventListener('click', () => setNavigationOpen(!navigationOpen));
        navigationClose.addEventListener('click', () => setNavigationOpen(false));
        navigationBackdrop.addEventListener('click', () => setNavigationOpen(false));
        sidebar.addEventListener('click', event => {
            if (navigationOpen && event.target.closest('a[href]')) setNavigationOpen(false);
        });
        document.addEventListener('keydown', event => {
            if (!navigationOpen || $('dialog[open]')) return;
            if (event.key === 'Escape') {
                event.preventDefault();
                setNavigationOpen(false);
            } else if (event.key === 'Tab') {
                const controls = $$('a[href], button:not([disabled]), [tabindex="0"]', sidebar)
                    .filter(element => !element.closest('[hidden], [inert]') && element.getClientRects().length > 0);
                const first = controls[0], last = controls[controls.length - 1];
                if (event.shiftKey && (document.activeElement === first || !sidebar.contains(document.activeElement))) {
                    event.preventDefault(); last?.focus();
                } else if (!event.shiftKey && (document.activeElement === last || !sidebar.contains(document.activeElement))) {
                    event.preventDefault(); first?.focus();
                }
            }
        });
        mobileNavigation.addEventListener('change', () => {
            setSidebar(sidebarCollapsed);
            setNavigationOpen(false);
        });
        setNavigationOpen(false, false);
    }
}

