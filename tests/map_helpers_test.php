<?php

require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/map_helpers.php';

function expectMapHelper($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "Map helper test failed: {$message}\n");
        exit(1);
    }
}

$address = engagementMapAddress([
    'event_address_line_1' => '123 Main Street',
    'event_address_line_2' => 'Suite 4',
    'event_city' => 'Dallas',
    'event_state' => 'TX',
    'event_zipcode' => '75201',
    'event_country' => 'USA',
]);
expectMapHelper(
    $address === '123 Main Street, Suite 4, Dallas, TX 75201, USA',
    'event address fields should produce a geocoder-ready address.'
);
expectMapHelper(
    engagementMapAddressHash("  123 Main Street,  Dallas ")
        === engagementMapAddressHash('123 main street, Dallas'),
    'address hashes should ignore case and repeated whitespace.'
);

$filters = normalizeEngagementMapFilters([
    'status' => 'confirmed',
    'date_from' => '2026-08-01',
    'date_to' => '2026-08-31',
]);
expectMapHelper(
    $filters['status'] === 'confirmed'
        && $filters['date_from'] === '2026-08-01'
        && $filters['date_to'] === '2026-08-31'
        && $filters['errors'] === [],
    'valid status and date-window filters should be preserved.'
);

$invalid_filters = normalizeEngagementMapFilters([
    'status' => 'not-a-status',
    'date_from' => '2026-09-01',
    'date_to' => '2026-08-01',
]);
expectMapHelper(
    $invalid_filters['status'] === ''
        && $invalid_filters['date_from'] === ''
        && $invalid_filters['date_to'] === ''
        && count($invalid_filters['errors']) === 1,
    'invalid status values should fall back to all and reversed date windows should be rejected.'
);

expectMapHelper(
    engagementMapCoordinatesAreValid('32.7767', '-96.7970')
        && !engagementMapCoordinatesAreValid('91', '0')
        && !engagementMapCoordinatesAreValid('0', '-181'),
    'map coordinates should stay inside latitude and longitude bounds.'
);

$coordinates = parseEngagementMapGeocoderResponse('[{"lat":"32.7767","lon":"-96.7970"}]');
expectMapHelper(
    $coordinates === ['latitude' => 32.7767, 'longitude' => -96.797],
    'valid geocoder results should normalize to numeric coordinates.'
);
expectMapHelper(
    parseEngagementMapGeocoderResponse('[]') === null,
    'an empty geocoder result should be treated as not found.'
);
expectMapHelper(
    engagementMapDateLabel('2026-08-17', '2026-08-17') === 'Aug 17, 2026'
        && engagementMapDateLabel('2026-08-17', '2026-08-19') === 'Aug 17, 2026 – Aug 19, 2026',
    'event date labels should support single-day and multi-day engagements.'
);

echo "Map helper tests passed.\n";
