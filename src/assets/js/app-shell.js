(function () {
    const body = document.body;
    body.classList.add('has-app-shell');
    if (document.querySelector('[data-role-preview-banner]')) {
        body.classList.add('role-preview-active');
    }
    const toggle = document.querySelector('[data-nav-toggle]');
    const backdrop = document.querySelector('[data-nav-backdrop]');

    if (!toggle || !backdrop) return;

    function setNavigationOpen(open) {
        body.classList.toggle('navigation-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        const label = toggle.querySelector('.visually-hidden');
        if (label) label.textContent = open ? 'Close Navigation' : 'Open Navigation';
    }

    toggle.addEventListener('click', function () {
        setNavigationOpen(!body.classList.contains('navigation-open'));
    });
    backdrop.addEventListener('click', function () { setNavigationOpen(false); });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') setNavigationOpen(false);
    });
    window.addEventListener('resize', function () {
        if (window.innerWidth > 860) setNavigationOpen(false);
    });
})();
