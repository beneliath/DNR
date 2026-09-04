<?php

function expectEngagementDateFormat($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "Engagement date format test failed: {$message}\n");
        exit(1);
    }
}

$source = file_get_contents(__DIR__ . '/../src/engagements.php');
$modern_styles = file_get_contents(__DIR__ . '/../src/assets/css/modern.css');
$engagement_styles = file_get_contents(__DIR__ . '/../src/assets/css/pages/engagements.css');

expectEngagementDateFormat(
    str_contains($source, 'class="engagements-body"')
        && str_contains($source, 'class="container engagements-page"')
        && preg_match('/\.engagements-page\s*\{[^}]*width:\s*min\(100%,\s*var\(--app-content-max\)\);[^}]*max-width:\s*var\(--app-content-max\);/s', $engagement_styles) === 1
        && preg_match('/\.engagements-heading h1\s*\{[^}]*font-size:\s*clamp\(1\.8rem,\s*3vw,\s*2\.3rem\);/s', $engagement_styles) === 1,
    'The Engagements page should use the Dashboard canvas width and heading scale.'
);
expectEngagementDateFormat(
    preg_match('/\.engagements-page \.summary-card small\s*\{[^}]*font-size:\s*\.9rem;/s', $engagement_styles) === 1
        && preg_match('/\.engagements-page \.summary-card strong\s*\{[^}]*font-size:\s*1\.75rem;/s', $engagement_styles) === 1,
    'Engagement summary cards should use the Dashboard summary typography.'
);

expectEngagementDateFormat(
    str_contains($source, "date('Y.m.d', \$start_timestamp)"),
    'Start dates should use YYYY.MM.DD.'
);
expectEngagementDateFormat(
    str_contains($source, "date('Y.m.d', \$end_timestamp)"),
    'End dates in ranges should use YYYY.MM.DD.'
);
expectEngagementDateFormat(
    str_contains($source, 'class="engagement-dates"'),
    'Engagement date cells should have a dedicated non-wrapping class.'
);
expectEngagementDateFormat(
    str_contains($source, '<th>Event Date(s)</th>')
        && !str_contains($source, '<th>Event Dates</th>'),
    'The Engagements table heading should accommodate single-day and multi-day events.'
);
expectEngagementDateFormat(
    preg_match('/\.engagement-dates\s*\{[^}]*white-space:\s*nowrap;/s', $modern_styles) === 1,
    'Engagement date cells should stay on one line.'
);
expectEngagementDateFormat(
    preg_match('/\.status-work-in-progress,[^{]*\{[^}]*white-space:\s*nowrap;/s', $modern_styles) === 1,
    'Engagement status badges should stay on one line.'
);
expectEngagementDateFormat(
    str_contains($source, 'class="engagement-status"')
        && preg_match('/\.engagement-status,[^{]*\{[^}]*white-space:\s*nowrap\s*!important;/s', $modern_styles) === 1,
    'The complete Engagement status cell should prevent line wrapping.'
);

echo "Engagement date format tests passed.\n";
