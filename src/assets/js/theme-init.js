(function () {
    document.documentElement.classList.add('has-js');
    let savedTheme;
    try { savedTheme = localStorage.getItem('theme'); } catch { /* Follow the system preference if storage is unavailable. */ }
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    document.documentElement.classList.toggle('dark-mode', savedTheme === 'dark' || (!savedTheme && prefersDark));
})();
