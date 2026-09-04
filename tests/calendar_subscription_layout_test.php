<?php

function expectCalendarSubscriptionLayout($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "Calendar subscription layout test failed: {$message}\n");
        exit(1);
    }
}

$page = file_get_contents(__DIR__ . '/../src/view_calendar.php');
$stylesheet = file_get_contents(__DIR__ . '/../src/assets/css/modern.css');
$pageStyles = file_get_contents(__DIR__ . '/../src/assets/css/pages/calendar_subscription.css');

expectCalendarSubscriptionLayout(
    str_contains($page, 'class="responsive-table-wrapper calendar-subscription-table-wrapper"')
        && str_contains($page, 'class="calendar-subscription-table data-table"')
        && substr_count($page, '<col class="calendar-subscription-') === 5,
    'the subscriptions table should expose a responsive wrapper and five explicit columns.'
);
expectCalendarSubscriptionLayout(
    str_contains($stylesheet, '.calendar-subscription-table {')
        && str_contains($stylesheet, 'width: 100%;')
        && str_contains($stylesheet, 'table-layout: fixed;')
        && str_contains($stylesheet, 'padding: 12px clamp(14px, 2vw, 28px);')
        && str_contains($stylesheet, 'white-space: nowrap;'),
    'subscription columns should fill the card with consistent spacing and stable single-line metadata.'
);
expectCalendarSubscriptionLayout(
    str_contains($page, '<div class="calendar-subscription-management-grid">')
        && preg_match('/\.calendar-subscription-management-grid\s*\{[^}]*grid-template-columns:\s*minmax\(320px,\s*0\.75fr\)\s*minmax\(0,\s*1\.25fr\);/s', $pageStyles) === 1
        && preg_match('/\.calendar-subscription-management-grid\s*\{[^}]*align-items:\s*stretch;/s', $pageStyles) === 1
        && preg_match('/\.calendar-subscription-management-grid > \.calendar-card\s*\{[^}]*height:\s*100%;/s', $pageStyles) === 1
        && preg_match('/@media \(max-width:\s*1100px\)\s*\{\s*\.calendar-subscription-management-grid\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\);/s', $pageStyles) === 1,
    'Create Subscription and Existing Subscriptions should share a responsive equal-height two-column row.'
);

echo "Calendar subscription layout tests passed.\n";
