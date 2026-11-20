<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/engagement_location_helpers.php';

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

$physical_address = [
    'physical_address_line_1' => ' 123 Main Street ',
    'physical_address_line_2' => 'Suite 2',
    'physical_city' => 'Toronto',
    'physical_state' => 'Ontario',
    'physical_zipcode' => 'M5V 2T6',
    'physical_country' => 'Canada',
    'mailing_address_line_1' => 'PO Box 123',
];
$event_address = organizationEventLocation($physical_address);
expectEngagementLocationFeature($event_address === [
    'event_address_line_1' => '123 Main Street',
    'event_address_line_2' => 'Suite 2',
    'event_city' => 'Toronto',
    'event_state' => 'ON',
    'event_zipcode' => 'M5V 2T6',
    'event_country' => 'CA',
], 'The physical address should map to all six event fields with canonical country and region codes.');

$normalized = \Dnr\Domain\EngagementInput::normalize($event_address + [
    'organization_id' => 1,
    'event_title' => 'Address copy test',
    'event_type' => 'conference',
    'event_start_date' => '2099-09-10',
    'event_end_date' => '2099-09-11',
]);
expectEngagementLocationFeature(
    array_intersect_key($normalized, $event_address) === $event_address,
    'Copied values should survive the normal engagement save validation.'
);
expectEngagementLocationFeature(
    organizationEventLocation([]) === null
        && organizationEventLocation(['physical_country' => 'US', 'mailing_city' => 'Chicago']) === null,
    'A country default or a mailing address alone should not count as a physical event location.'
);
expectEngagementLocationFeature(
    organizationEventLocation(['physical_city' => 'Paris', 'physical_state' => 'Île-de-France', 'physical_country' => 'France'])['event_state'] === 'Île-de-France',
    'Countries with free-text regions should retain the saved region.'
);
expectEngagementLocationFeature(
    organizationEventLocation(['physical_city' => 'Chicago'])['event_country'] === applicationDefaultCountry(),
    'A legacy address without a country should use the same application default as the form.'
);
foreach ([['physical_country' => 'Invalid country'], ['physical_state' => 'Invalid province']] as $invalid) {
    try {
        organizationEventLocation(array_replace($physical_address, $invalid));
        expectEngagementLocationFeature(false, 'Invalid saved countries and regions must not be silently dropped.');
    } catch (InvalidArgumentException $exception) {
        expectEngagementLocationFeature(str_contains($exception->getMessage(), 'physical country and state'), 'The user should be told how to repair an invalid saved address.');
    }
}

echo "Engagement location feature tests passed.\n";
