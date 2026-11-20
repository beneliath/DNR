<?php

declare(strict_types=1);

/** A country default alone does not identify an engagement venue. */
function engagementHasVenueLocation(array $engagement): bool
{
    foreach (['event_address_line_1', 'event_address_line_2', 'event_city', 'event_state', 'event_zipcode'] as $field) {
        if (trim((string) ($engagement[$field] ?? '')) !== '') {
            return true;
        }
    }
    return false;
}

/** Copy the physical address into the engagement fields, including legacy country and state names. */
function organizationEventLocation(array $organization): ?array
{
    $address = [];
    foreach (['address_line_1', 'address_line_2', 'city', 'state', 'zipcode', 'country'] as $part) {
        $address['event_' . $part] = trim((string) ($organization['physical_' . $part] ?? ''));
    }
    if (!engagementHasVenueLocation($address)) {
        return null;
    }
    $country = normalizeAddressCountryCode($address['event_country'] ?: applicationDefaultCountry());
    $state = normalizeAddressRegion($country, $address['event_state']);
    if ($country === null || $state === null) {
        throw new InvalidArgumentException('Update the organization’s physical country and state before using its address.');
    }
    $address['event_country'] = $country;
    $address['event_state'] = $state;
    return $address;
}
