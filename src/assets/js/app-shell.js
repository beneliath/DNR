(function () {
    const body = document.body;
    body.classList.add('has-app-shell');
    const toggle = document.querySelector('[data-nav-toggle]');
    const backdrop = document.querySelector('[data-nav-backdrop]');

    if (!toggle || !backdrop) return;

    function setNavigationOpen(open) {
        body.classList.toggle('navigation-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        const label = toggle.querySelector('.visually-hidden');
        if (label) label.textContent = open ? 'Close navigation' : 'Open navigation';
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

(function () {
    document.addEventListener('submit', function (event) {
        const form = event.target.closest('form[data-confirm]');
        if (form && !window.confirm(String(form.dataset.confirm || 'Continue?'))) {
            event.preventDefault();
        }
    });
})();
