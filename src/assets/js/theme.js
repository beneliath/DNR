(function () {
    function updateThemeControls() {
        const isDark = document.documentElement.classList.contains('dark-mode');
        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            button.setAttribute('aria-pressed', isDark ? 'true' : 'false');
            button.setAttribute('aria-label', isDark ? 'Switch to light theme' : 'Switch to dark theme');
            const label = button.querySelector('.theme-label');
            if (label) label.textContent = isDark ? 'Light theme' : 'Dark theme';
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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            updateThemeControls();
            document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
                button.addEventListener('click', window.toggleTheme);
            });
        });
    } else {
        updateThemeControls();
        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            button.addEventListener('click', window.toggleTheme);
        });
    }
})();
