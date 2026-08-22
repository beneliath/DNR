<?php

declare(strict_types=1);

namespace Dnr\Domain;

final class ReferenceData
{
    /** @return list<string> */
    public static function userRoles(): array
    {
        return ['admin', 'editor', 'reviewer'];
    }

    /** @return list<string> */
    public static function engagementStatuses(): array
    {
        return ['work_in_progress', 'under_review', 'confirmed'];
    }

    /** @return list<string> */
    public static function eventTypes(): array
    {
        return ['conference', 'service', 'study or teaching', 'Passover Seder', 'other'];
    }

    /** @return list<string> */
    public static function travelCoverage(): array
    {
        return ['unknown', 'yes', 'no'];
    }

    /** @return list<string> */
    public static function compensationTypes(): array
    {
        return ['Unknown', 'Honorarium', 'Offering', 'Honorarium and Offering', 'Other'];
    }

    /** @return list<string> */
    public static function housingTypes(): array
    {
        return ['Unknown', 'Provided', 'Not Provided', 'Other'];
    }

    /** @return list<string> */
    public static function contactRoles(): array
    {
        return ['pastor', 'admin', 'other'];
    }

    public static function label(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value));
    }

    /** @param list<string> $choices */
    public static function choice(mixed $value, array $choices, string $fallback): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        return in_array($value, $choices, true) ? $value : $fallback;
    }
}
