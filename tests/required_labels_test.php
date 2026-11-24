<?php

function expectRequiredLabelStyle($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "Required label test failed: {$message}\n");
        exit(1);
    }
}

$modern_styles = file_get_contents(__DIR__ . '/../src/assets/css/modern.css');
$new_engagement_source = file_get_contents(__DIR__ . '/../src/index.php');
$edit_engagement_source = file_get_contents(__DIR__ . '/../src/edit_engagement.php');
$lifecycle_toolbar = file_get_contents(__DIR__ . '/../src/templates/engagement_lifecycle_form.php');
$schedule_form = file_get_contents(__DIR__ . '/../src/templates/engagement_schedule_form.php');
$presentation_form_source = file_get_contents(__DIR__ . '/../src/templates/presentation_form.php');

expectRequiredLabelStyle(
    preg_match('/\.required-fields-note\s*\{[^}]*color:\s*var\(--danger\);/s', $modern_styles) === 1,
    'The required-fields note should use the theme-aware danger color.'
);
expectRequiredLabelStyle(
    str_contains($modern_styles, 'label.required,')
        && str_contains($modern_styles, '.radio-group label.required,')
        && str_contains($modern_styles, 'label:has(> .required) {'),
    'Required labels should be red whether the marker is a class or a nested asterisk.'
);

expectRequiredLabelStyle(
    str_contains($new_engagement_source, 'templates/engagement_lifecycle_form.php')
        && str_contains($edit_engagement_source, 'templates/engagement_lifecycle_form.php')
        && str_contains($lifecycle_toolbar, 'engagement_schedule_form.php')
        && preg_match('/<label\b[^>]*\sfor="event_type_other"[^>]*>/', $schedule_form) === 1,
    'The shared schedule form should associate the required other-event label with its input.'
);

foreach (['Topic/Title', 'Date', 'Time', 'Duration (Minutes)'] as $presentation_label) {
    expectRequiredLabelStyle(
        !str_contains(
            $presentation_form_source,
            $presentation_label . '<span class="required">*</span></label>'
        ),
        "Optional presentation {$presentation_label} should not use the red required-label marker."
    );
}

echo "Required label tests passed.\n";
