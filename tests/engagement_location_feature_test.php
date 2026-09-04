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
$new_styles = file_get_contents($root . '/src/assets/css/pages/index.css');

expectEngagementLocationFeature(
    substr_count($edit_engagement, 'data-address-region-control') === 1
        && substr_count($edit_engagement, "addressCountryPicker(") === 1
        && str_contains($edit_engagement, 'data-address-region-for="event"')
        && str_contains($edit_engagement, 'id="address-region-data"'),
    'Edit Engagement should use the shared country and region dropdowns for Event Location.'
);

expectEngagementLocationFeature(
    str_contains($edit_engagement, '<body class="edit-engagement-body">')
        && str_contains($edit_engagement, '<div class="container edit-engagement-page" role="main">')
        && str_contains($edit_engagement, 'form-page-heading edit-engagement-heading')
        && preg_match('/\.edit-engagement-page\s*\{[^}]*width:\s*min\(100%,\s*var\(--app-content-max\)\);[^}]*max-width:\s*var\(--app-content-max\);/s', $edit_styles) === 1
        && preg_match('/\.edit-engagement-heading h1\s*\{[^}]*font-size:\s*clamp\(1\.8rem,\s*3vw,\s*2\.3rem\);/s', $edit_styles) === 1
        && preg_match('/\.edit-engagement-body \.app-footer\s*\{[^}]*max-width:\s*var\(--app-content-max\);/s', $edit_styles) === 1,
    'Edit Engagement should use the Dashboard canvas width, heading scale, and footer alignment.'
);

expectEngagementLocationFeature(
    preg_match(
        '/class="address-row event-address-row".*event_city.*event_state.*event_zipcode.*event_country/s',
        $edit_engagement
    ) === 1
        && str_contains($edit_styles, '.event-address-country-field .address-country-picker')
        && str_contains($edit_styles, '.event-address-row input[type="text"]')
        && str_contains($edit_styles, 'margin-bottom: 0;')
        && str_contains($edit_styles, 'width: 100%;')
        && str_contains($edit_styles, 'align-items: flex-start;')
        && str_contains($edit_styles, 'flex-direction: column;')
        && str_contains($edit_engagement, 'event-address-city-field')
        && str_contains($edit_engagement, 'event-address-state-field')
        && str_contains($edit_engagement, 'event-address-zipcode-field')
        && str_contains($edit_styles, '.event-address-row #event_city')
        && str_contains($edit_styles, 'flex: 2 1 220px;')
        && str_contains($edit_styles, 'min-width: 220px;')
        && str_contains($edit_styles, '.event-address-row [data-address-region-control]')
        && str_contains($edit_styles, 'min-width: 230px;')
        && str_contains($edit_styles, 'min-width: 340px;')
        && str_contains($edit_styles, '.event-address-country-field .address-country-trigger'),
    'City, state, Zipcode, and country should fill one aligned row and display their longest values.'
);

expectEngagementLocationFeature(
    substr_count($new_engagement, 'data-address-region-control') === 1
        && substr_count($new_engagement, "addressCountryPicker(") === 1
        && str_contains($new_engagement, 'data-address-region-for="event"')
        && str_contains($new_engagement, 'id="address-region-data"'),
    'New Engagement should use the shared country and region dropdowns for Event Location.'
);

expectEngagementLocationFeature(
    preg_match(
        '/class="address-row event-address-row".*event_city.*event_state.*event_zipcode.*event_country/s',
        $new_engagement
    ) === 1
        && str_contains($new_styles, '.event-address-country-field .address-country-picker')
        && str_contains($new_styles, '.event-address-row input[type="text"]')
        && str_contains($new_styles, 'margin-bottom: 0;')
        && str_contains($new_styles, 'width: 100%;')
        && str_contains($new_styles, 'align-items: flex-start;')
        && str_contains($new_styles, 'flex-direction: column;')
        && str_contains($new_engagement, 'event-address-city-field')
        && str_contains($new_engagement, 'event-address-state-field')
        && str_contains($new_engagement, 'event-address-zipcode-field')
        && str_contains($new_styles, '.event-address-row #event_city')
        && str_contains($new_styles, 'flex: 2 1 220px;')
        && str_contains($new_styles, 'min-width: 220px;')
        && str_contains($new_styles, '.event-address-row [data-address-region-control]')
        && str_contains($new_styles, 'min-width: 230px;')
        && str_contains($new_styles, 'min-width: 340px;')
        && str_contains($new_styles, '.event-address-country-field .address-country-trigger'),
    'New Engagement location fields should match the aligned Edit Engagement layout.'
);

echo "Engagement location feature tests passed.\n";
