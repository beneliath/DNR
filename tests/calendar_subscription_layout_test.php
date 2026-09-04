<?php

function expectCalendarSubscriptionLayout($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "Calendar subscription layout test failed: {$message}\n");
        exit(1);
    }
}

$page = file_get_contents(__DIR__ . '/../src/calendar_subscription.php');
$stylesheet = file_get_contents(__DIR__ . '/../src/assets/css/modern.css');

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

echo "Calendar subscription layout tests passed.\n";
