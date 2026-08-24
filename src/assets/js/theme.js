(function () {
    function updateThemeControls() {
        const isDark = document.documentElement.classList.contains('dark-mode');
        document.querySelectorAll('[data-theme-logo]').forEach(function (logo) {
            const source = isDark ? logo.dataset.darkSrc : logo.dataset.lightSrc;
            if (source && logo.getAttribute('src') !== source) {
                logo.setAttribute('src', source);
            }
        });
        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            button.setAttribute('aria-pressed', isDark ? 'true' : 'false');
            button.setAttribute('aria-label', isDark ? 'Switch to Light Theme' : 'Switch to Dark Theme');
            const label = button.querySelector('.theme-label');
            if (label) label.textContent = isDark ? 'Light Theme' : 'Dark Theme';
        });
    }

    window.toggleTheme = function () {
        document.documentElement.classList.toggle('dark-mode');
        const isDark = document.documentElement.classList.contains('dark-mode');
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        updateThemeControls();
    };

    const savedTheme = localStorage.getItem('theme');
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    document.documentElement.classList.toggle('dark-mode', savedTheme === 'dark' || (!savedTheme && prefersDark));
    updateThemeControls();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
                button.addEventListener('click', window.toggleTheme);
            });
        });
    } else {
        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            button.addEventListener('click', window.toggleTheme);
        });
    }
})();
