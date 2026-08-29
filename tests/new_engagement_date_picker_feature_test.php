<?php

function expectNewEngagementDatePicker(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "New Engagement date picker feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$new_engagement_page = file_get_contents($root . '/src/index.php');
$page_actions = file_get_contents($root . '/src/assets/js/page-actions.js');

expectNewEngagementDatePicker(
    str_contains($new_engagement_page, 'id="new-engagement-form"'),
    'the create form should have a stable hook distinct from the edit form.'
);

expectNewEngagementDatePicker(
    str_contains($page_actions, "form.id === 'new-engagement-form'")
        && str_contains($page_actions, 'endDate.min = startDate.value;')
        && str_contains($page_actions, "startDate.addEventListener('input', synchronizeEndDateCalendar);"),
    'choosing a start date should advance and constrain the blank end-date calendar to that date.'
);

echo "New Engagement date picker feature tests passed.\n";
