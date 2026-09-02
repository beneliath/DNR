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

expectEngagementLocationFeature(
    substr_count($edit_engagement, 'data-address-region-control') === 1
        && substr_count($edit_engagement, "addressCountryPicker(") === 1
        && str_contains($edit_engagement, 'data-address-region-for="event"')
        && str_contains($edit_engagement, 'id="address-region-data"'),
    'Edit Engagement should use the shared country and region dropdowns for Event Location.'
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
    !str_contains($new_engagement, 'data-address-region-for="event"')
        && !str_contains($new_engagement, "addressCountryPicker(\n                            'event_country'"),
    'The requested dropdown change should remain scoped to Edit Engagement.'
);

echo "Engagement location feature tests passed.\n";
