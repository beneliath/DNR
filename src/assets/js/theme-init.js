(function () {
    document.documentElement.classList.add('has-js');
    const savedTheme = localStorage.getItem('theme');
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    document.documentElement.classList.toggle('dark-mode', savedTheme === 'dark' || (!savedTheme && prefersDark));
})();
