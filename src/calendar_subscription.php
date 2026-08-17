<?php

include 'config.php';
include 'functions.php';
include 'calendar_helpers.php';
startSecureSession();
requireLogin();

$calendar_url = calendarSubscriptionUrl($_SERVER);
$webcal_url = preg_replace('/^https?:\/\//i', 'webcal://', $calendar_url);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Subscribe to DNR Calendar</title>
    <link rel="stylesheet" href="assets/css/style.css?v=0.0.15">
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container calendar-subscription">
    <div class="page-heading"><div><h1>Calendar subscription</h1><p class="page-intro">Keep every active DNR engagement in your calendar app.</p></div></div>
    <section class="security-card calendar-card"><p>This shared calendar contains every active (non-archived) DNR engagement. Changes appear when the subscriber's calendar app next refreshes the feed.</p>

    <label for="calendar-url"><strong>Calendar subscription URL</strong></label>
    <div class="calendar-url-row">
        <input type="url" id="calendar-url" readonly value="<?php echo htmlspecialchars($calendar_url, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="button" id="copy-calendar-url">Copy URL</button>
    </div>
    <p id="copy-calendar-status" class="calendar-copy-status" aria-live="polite"></p>

    <p><a class="security-button" href="<?php echo htmlspecialchars($webcal_url, ENT_QUOTES, 'UTF-8'); ?>">Open in calendar app</a></p>
    <p class="calendar-privacy-note"><strong>Public feed:</strong> anyone with this URL can view organization names, event titles, event types, statuses, all-day date ranges, and event locations. DNR does not include contacts, notes, travel, lodging, or compensation.</p>
    </section>
</div>
<?php include 'templates/footer.php'; ?>
<script>
document.getElementById('copy-calendar-url').addEventListener('click', async function () {
    const url = document.getElementById('calendar-url').value;
    const status = document.getElementById('copy-calendar-status');

    try {
        await navigator.clipboard.writeText(url);
        status.textContent = 'Calendar URL copied.';
    } catch (error) {
        document.getElementById('calendar-url').select();
        status.textContent = 'Select and copy the highlighted URL.';
    }
});
</script>
</body>
</html>
