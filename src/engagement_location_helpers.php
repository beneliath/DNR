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
