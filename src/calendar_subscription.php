<?php

include 'config.php';
include 'functions.php';
include 'calendar_helpers.php';
startSecureSession();
requireLogin();

$calendar_url = calendarSubscriptionUrl($_SERVER);
$webcal_url = preg_replace('/^https?:\/\//i', 'webcal://', $calendar_url);
$calendar_enabled = calendarAccessToken() !== null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Subscribe to DNR Calendar</title>
    <link rel="stylesheet" href="assets/css/style.min.css?v=0.0.20">
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container calendar-subscription">
    <div class="page-heading"><div><h1>Calendar Subscription</h1><p class="page-intro">Keep every active DNR engagement in your calendar app.</p></div></div>
    <section class="security-card calendar-card"><p>This shared calendar contains every active (non-archived) DNR engagement as an all-day block, plus timed blocks for presentations that have both a date and time. Changes appear when the subscriber's calendar app next refreshes the feed.</p>

    <?php if ($calendar_enabled): ?>
    <label for="calendar-url"><strong>Private calendar subscription URL</strong></label>
    <div class="calendar-url-row">
        <input type="url" id="calendar-url" readonly value="<?php echo htmlspecialchars($calendar_url, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="button" id="copy-calendar-url">Copy URL</button>
    </div>
    <p id="copy-calendar-status" class="calendar-copy-status" aria-live="polite"></p>

    <p><a class="security-button" id="open-calendar-app" href="<?php echo htmlspecialchars($webcal_url, ENT_QUOTES, 'UTF-8'); ?>">Open in Calendar App</a></p>
    <p class="calendar-privacy-note"><strong>Keep this URL private:</strong> its revocable token grants access to organization names, event titles, event types, statuses, all-day date ranges, presentation topics, speakers, presentation dates and times, and event locations. DNR does not include contacts, notes, travel, lodging, or compensation.</p>
    <?php else: ?>
    <p class="error">Calendar subscriptions are disabled until an administrator configures a secret <code>DNR_CALENDAR_TOKEN</code> of at least 32 characters.</p>
    <?php endif; ?>
    </section>
</div>
<?php include 'templates/footer.php'; ?>
<script>
const copyCalendarButton = document.getElementById('copy-calendar-url');
const openCalendarLink = document.getElementById('open-calendar-app');
let copyFeedbackTimer;
let openFeedbackTimer;

copyCalendarButton?.addEventListener('click', async function () {
    const url = document.getElementById('calendar-url').value;
    const status = document.getElementById('copy-calendar-status');

    try {
        await navigator.clipboard.writeText(url);
        status.textContent = 'Calendar URL copied.';
        copyCalendarButton.classList.add('is-copied');
        copyCalendarButton.textContent = 'Copied!';

        window.clearTimeout(copyFeedbackTimer);
        copyFeedbackTimer = window.setTimeout(function () {
            copyCalendarButton.classList.remove('is-copied');
            copyCalendarButton.textContent = 'Copy URL';
        }, 2000);
    } catch (error) {
        window.clearTimeout(copyFeedbackTimer);
        copyCalendarButton.classList.remove('is-copied');
        copyCalendarButton.textContent = 'Copy URL';
        document.getElementById('calendar-url').select();
        status.textContent = 'Select and copy the highlighted URL.';
    }
});

openCalendarLink?.addEventListener('click', function () {
    openCalendarLink.classList.add('is-opening');
    openCalendarLink.textContent = 'Opening…';

    window.clearTimeout(openFeedbackTimer);
    openFeedbackTimer = window.setTimeout(function () {
        openCalendarLink.classList.remove('is-opening');
        openCalendarLink.textContent = 'Open in Calendar App';
    }, 2000);
});
</script>
</body>
</html>
