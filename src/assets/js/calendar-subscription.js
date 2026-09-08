(function () {
    const myWork = document.getElementById('calendar-content-my_work');
    const allWork = document.getElementById('calendar-content-all_work');
    if (myWork && allWork) {
        let restoreMyWork = myWork.checked;
        function syncWorkOptions() {
            if (allWork.checked) {
                restoreMyWork = myWork.checked;
                myWork.checked = false;
            } else if (myWork.disabled) {
                myWork.checked = restoreMyWork;
            }
            myWork.disabled = allWork.checked;
        }
        allWork.addEventListener('change', syncWorkOptions);
        syncWorkOptions();
    }

    const copyButton = document.getElementById('copy-calendar-url');
    const openLink = document.getElementById('open-calendar-app');
    let copyFeedbackTimer;
    let openFeedbackTimer;

    copyButton?.addEventListener('click', async function () {
        const input = document.getElementById('calendar-url');
        const status = document.getElementById('copy-calendar-status');
        if (!input || !status) return;
        try {
            await navigator.clipboard.writeText(input.value);
            status.textContent = 'Calendar URL copied.';
            copyButton.classList.add('is-copied');
            copyButton.textContent = 'Copied!';
            window.clearTimeout(copyFeedbackTimer);
            copyFeedbackTimer = window.setTimeout(function () {
                copyButton.classList.remove('is-copied');
                copyButton.textContent = 'Copy URL';
            }, 2000);
        } catch (error) {
            input.select();
            status.textContent = 'Select and copy the highlighted URL.';
        }
    });

    openLink?.addEventListener('click', function () {
        openLink.classList.add('is-opening');
        openLink.textContent = 'Opening…';
        window.clearTimeout(openFeedbackTimer);
        openFeedbackTimer = window.setTimeout(function () {
            openLink.classList.remove('is-opening');
            openLink.textContent = 'Open in Calendar App';
        }, 2000);
    });
})();
