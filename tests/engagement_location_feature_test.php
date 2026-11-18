<?php

declare(strict_types=1);

function expectEngagementLocationFeature(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Engagement location feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$edit_engagement = file_get_contents($root . '/src/edit_engagement.php');
$new_engagement = file_get_contents($root . '/src/index.php');
$edit_styles = file_get_contents($root . '/src/assets/css/pages/edit_engagement.css');
$form_styles = file_get_contents($root . '/src/assets/css/pages/engagement_form.css');

expectEngagementLocationFeature(
    str_contains($edit_engagement, '<body class="edit-engagement-body">')
        && str_contains($edit_engagement, '<div class="container edit-engagement-page" role="main">')
        && str_contains($edit_engagement, 'form-page-heading edit-engagement-heading')
        && preg_match('/\.edit-engagement-page\s*\{[^}]*width:\s*min\(100%,\s*var\(--app-content-max\)\);[^}]*max-width:\s*var\(--app-content-max\);/s', $edit_styles) === 1
        && preg_match('/\.edit-engagement-heading h1\s*\{[^}]*font-size:\s*clamp\(1\.8rem,\s*3vw,\s*2\.3rem\);/s', $edit_styles) === 1,
    'Edit Engagement should use the Dashboard canvas width and heading scale.'
);

foreach ([$edit_engagement, $new_engagement] as $form_source) {
    expectEngagementLocationFeature(
        substr_count($form_source, 'data-address-region-control') === 1
            && substr_count($form_source, "addressCountryPicker(") === 1
            && str_contains($form_source, 'data-address-region-for="event"')
            && str_contains($form_source, 'id="address-region-data"'),
        'Both engagement forms should use the shared country and region dropdowns.'
    );

    expectEngagementLocationFeature(
        preg_match(
            '/class="event-address-lines".*event_address_line_1.*event_address_line_2.*class="event-address-row".*event_city.*event_state.*class="event-address-row event-address-postal-row".*event_zipcode.*event_country/s',
            $form_source
        ) === 1
            && str_contains($form_source, 'assets/css/pages/engagement_form.min.css'),
        'Both engagement forms should use the shared street, city/state, and ZIP/country rows.'
    );
}

expectEngagementLocationFeature(
    str_contains($form_styles, 'container: event-address / inline-size;')
        && str_contains($form_styles, '@container event-address'),
    'The shared address layout should adapt to the pane width.'
);

echo "Engagement location feature tests passed.\n";
