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
expectMapHelper(engagementMapAddress(['event_country' => 'US']) === '', 'a default country is not an event address.');
$inverness = 'Calvary Chapel Inverness, 960 S. US Highway 41, Inverness, FL 34450, US';
expectMapHelper(engagementMapGeocodeQueries($inverness) === [
    $inverness,
    '960 S. US Highway 41, Inverness, FL 34450, US',
    '960 S. US 41, Inverness, FL 34450, US',
], 'fallback searches must preserve street number, south direction, city, region, and postcode.');
expectMapHelper(engagementMapGeocodeQueries($address) === [$address], 'do not discard suite details or repeat an identical lookup.');
$cityOnly = ['lat' => '28.8358176', 'lon' => '-82.3303715', 'place_rank' => 16,
    'address' => ['village' => 'Inverness', 'postcode' => '34450']];
$exactHouse = ['lat' => '28.8364987', 'lon' => '-82.3404196', 'place_rank' => 30,
    'address' => ['house_number' => '960', 'postcode' => '34450']];
expectMapHelper(parseEngagementMapGeocoderResponse(json_encode([$cityOnly]), $inverness) === null,
    'a city center must not become a numbered street-address pin.');
expectMapHelper(parseEngagementMapGeocoderResponse(json_encode([$cityOnly, $exactHouse]), $inverness)
    === ['latitude' => 28.8364987, 'longitude' => -82.3404196],
    'skip broad results and accept a precise matching house in the requested postcode.');
$wrongHouse = $exactHouse;
$wrongHouse['address']['house_number'] = '961';
expectMapHelper(parseEngagementMapGeocoderResponse(json_encode([$wrongHouse]), $inverness) === null,
    'a different house number is not a match.');
$wrongHouse['address'] = ['house_number' => '960', 'postcode' => '34451'];
expectMapHelper(parseEngagementMapGeocoderResponse(json_encode([$wrongHouse]), $inverness) === null,
    'a different postcode is not a match.');
$geoapify = ['results' => [['lat' => 48.8566, 'lon' => 2.3522, 'housenumber' => '11',
    'country_code' => 'fr', 'formatted' => '11 Avenue de la Bourdonnais, Paris, France',
    'result_type' => 'building', 'rank' => ['confidence' => 0.99, 'match_type' => 'full_match']]]];
$internationalAddress = '11 Avenue de la Bourdonnais, 75007 Paris, FR';
$international = parseGeoapifyGeocoderResponse(json_encode($geoapify), $internationalAddress);
expectMapHelper($international !== null && $international['provider'] === 'geoapify' && $international['confidence'] === 0.99,
    'a high confidence international building match should preserve quality and provider.');
$german = ['query' => ['parsed' => ['housenumber' => '77', 'postcode' => '10117']],
    'results' => [['lat' => 52.5163, 'lon' => 13.3805, 'housenumber' => '77', 'country_code' => 'de',
    'result_type' => 'building', 'rank' => ['confidence' => 1]]]];
expectMapHelper(parseGeoapifyGeocoderResponse(json_encode($german), 'Unter den Linden 77, 10117 Berlin, DE') !== null,
    'international postcodes before city names must not be mistaken for house numbers.');
$german['results'][0]['housenumber'] = '78';
expectMapHelper(parseGeoapifyGeocoderResponse(json_encode($german), 'Unter den Linden 77, 10117 Berlin, DE') === null,
    'international matches must still preserve the requested house number.');
$geoapify['results'][0]['rank']['confidence'] = 0.7;
expectMapHelper(parseGeoapifyGeocoderResponse(json_encode($geoapify), $internationalAddress) === null,
    'uncertain matches must not silently become venue pins.');
$geoapify['results'][0]['rank']['confidence'] = 1;
$geoapify['results'][0]['result_type'] = 'city';
expectMapHelper(parseGeoapifyGeocoderResponse(json_encode($geoapify), $internationalAddress) === null,
    'a high confidence city match is still not a venue.');
$geoapify['results'][0]['result_type'] = 'building';
$geoapify['results'][0]['country_code'] = 'us';
expectMapHelper(parseGeoapifyGeocoderResponse(json_encode($geoapify), $internationalAddress) === null,
    'a candidate in a different explicitly requested country must be rejected.');
try {
    parseGeoapifyGeocoderResponse('{"message":"unauthorized"}', $internationalAddress);
    expectMapHelper(false, 'provider errors must not be cached as an address miss.');
} catch (RuntimeException $exception) {
    expectMapHelper(true, 'provider errors must not be cached as an address miss.');
}
expectMapHelper(
    normalizeEngagementMapIds('7,8,7', 3) === [7, 8],
    'batched map IDs should be positive, ordered, and de-duplicated.'
);
try {
    normalizeEngagementMapIds('7,invalid', 3);
    expectMapHelper(false, 'invalid batched map IDs should be rejected.');
} catch (InvalidArgumentException $exception) {
    expectMapHelper(true, 'invalid batched map IDs should be rejected.');
}
try {
    normalizeEngagementMapIds('1,2,3', 2);
    expectMapHelper(false, 'oversized map batches should be rejected.');
} catch (LengthException $exception) {
    expectMapHelper(true, 'oversized map batches should be rejected.');
}
expectMapHelper(
    engagementMapAddressHash("  123 Main Street,  Dallas ")
        === engagementMapAddressHash('123 main street, Dallas'),
    'address hashes should ignore case and repeated whitespace.'
);

$default_filters = normalizeEngagementMapFilters(
    [],
    new DateTimeImmutable('2026-01-31 18:00:00', new DateTimeZone('UTC'))
);
expectMapHelper(
    $default_filters['date_from'] === '2026-01-31'
        && $default_filters['date_to'] === '2026-04-30'
        && $default_filters['lifecycle'] === 'active'
        && $default_filters['errors'] === [],
    'the default map window should begin today and end three calendar months later, clamped at month end.'
);

$from_only_filters = normalizeEngagementMapFilters([
    'date_from' => '2026-08-31',
]);
expectMapHelper(
    $from_only_filters['date_from'] === '2026-08-31'
        && $from_only_filters['date_to'] === '2026-11-30',
    'an omitted Through date should default to three calendar months after the selected From date.'
);

$filters = normalizeEngagementMapFilters([
    'status' => 'confirmed',
    'lifecycle' => 'postponed',
    'date_from' => '2026-08-01',
    'date_to' => '2026-08-31',
]);
expectMapHelper(
    $filters['status'] === 'confirmed'
        && $filters['lifecycle'] === 'postponed'
        && $filters['date_from'] === '2026-08-01'
        && $filters['date_to'] === '2026-08-31'
        && $filters['errors'] === [],
    'valid status and date-window filters should be preserved.'
);

$invalid_filters = normalizeEngagementMapFilters([
    'status' => 'not-a-status',
    'lifecycle' => 'not-a-lifecycle',
    'date_from' => '2026-09-01',
    'date_to' => '2026-08-01',
]);
expectMapHelper(
    $invalid_filters['status'] === ''
        && $invalid_filters['lifecycle'] === 'active'
        && validIsoDate($invalid_filters['date_from'])
        && validIsoDate($invalid_filters['date_to'])
        && $invalid_filters['date_to'] === engagementMapDateAfterMonths($invalid_filters['date_from'], 3)
        && count($invalid_filters['errors']) === 1,
    'invalid status values should fall back to all and reversed windows should be rejected in favor of the default window.'
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
    geocoderAddressIsPublic('8.8.8.8')
        && geocoderAddressIsPublic('2606:4700:4700::1111')
        && !geocoderAddressIsPublic('127.0.0.1')
        && !geocoderAddressIsPublic('10.0.0.1')
        && !geocoderAddressIsPublic('100.64.0.1')
        && !geocoderAddressIsPublic('169.254.169.254')
        && !geocoderAddressIsPublic('::1')
        && !geocoderAddressIsPublic('::ffff:127.0.0.1')
        && !geocoderAddressIsPublic('64:ff9b::c0a8:101')
        && !geocoderAddressIsPublic('2002:7f00:1::')
        && !geocoderAddressIsPublic('fc00::1'),
    'geocoder endpoints should reject loopback, private, shared, transition, reserved, and link-local addresses.'
);
expectMapHelper(
    geocoderAddressesMatch('2606:4700:4700::1111', '2606:4700:4700:0:0:0:0:1111')
        && !geocoderAddressesMatch('8.8.8.8', '1.1.1.1'),
    'connected geocoder addresses should be compared in canonical binary form.'
);
expectMapHelper(
    engagementMapDateLabel('2026-08-17', '2026-08-17') === 'Aug 17, 2026'
        && engagementMapDateLabel('2026-08-17', '2026-08-19') === 'Aug 17, 2026 – Aug 19, 2026',
    'event date labels should support single-day and multi-day engagements.'
);

echo "Map helper tests passed.\n";
