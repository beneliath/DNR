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

foreach ([$new_engagement_source, $edit_engagement_source] as $engagement_source) {
    expectRequiredLabelStyle(
        !str_contains($engagement_source, '<div class="label-container">Other Event Type'),
        'The required other-event caption should be an accessible label.'
    );
    expectRequiredLabelStyle(
        str_contains($engagement_source, '<label class="label-container" for="event_type_other">Other Event Type'),
        'The other-event label should be associated with its input.'
    );
}

foreach (['Topic/Title', 'Date', 'Time'] as $presentation_required_label) {
    expectRequiredLabelStyle(
        str_contains(
            $presentation_form_source,
            $presentation_required_label . '<span class="required">*</span></label>'
        ),
        "Presentation {$presentation_required_label} should use the red required-label marker."
    );
}

echo "Required label tests passed.\n";
