function toggleTheme() {
    document.querySelector('html').classList.toggle('dark-mode');
    const isDarkMode = document.querySelector('html').classList.contains('dark-mode');
    localStorage.setItem('theme', isDarkMode ? 'dark' : 'light');
}

// On page load, set the theme based on local storage
document.addEventListener('DOMContentLoaded', () => {
    const savedTheme = localStorage.getItem('theme');
    const htmlElement = document.querySelector('html');
    if (savedTheme === 'dark') {
        htmlElement.classList.add('dark-mode');
    } else {
        htmlElement.classList.remove('dark-mode');
    }
});
