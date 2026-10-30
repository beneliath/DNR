<?php

declare(strict_types=1);

function bookingInquiryDateLabel(array $inquiry): string
{
    $start = trim((string) ($inquiry['preferred_start_date'] ?? ''));
    $end = trim((string) ($inquiry['preferred_end_date'] ?? ''));
    if ($start === '') {
        return 'to be determined';
    }
    $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
    $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
    if (!$startDate instanceof DateTimeImmutable || $startDate->format('Y-m-d') !== $start) {
        return $end !== '' && $end !== $start ? $start . ' through ' . $end : $start;
    }
    if (!$endDate instanceof DateTimeImmutable || $endDate->format('Y-m-d') !== $end || $endDate < $startDate) {
        $endDate = $startDate;
    }
    if ($startDate == $endDate) {
        return $startDate->format('M j, Y');
    }
    if ($startDate->format('Y-m') === $endDate->format('Y-m')) {
        return $startDate->format('M j') . '–' . $endDate->format('j, Y');
    }
    if ($startDate->format('Y') === $endDate->format('Y')) {
        return $startDate->format('M j') . '–' . $endDate->format('M j, Y');
    }
    return $startDate->format('M j, Y') . '–' . $endDate->format('M j, Y');
}

function bookingInquirySingleDateLabel(mixed $value, string $fallback = 'Not set'): string
{
    $dateValue = trim((string) $value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $dateValue
        ? $date->format('M j, Y')
        : ($dateValue !== '' ? $dateValue : $fallback);
}

function bookingInquiryInitials(mixed $value): string
{
    $parts = preg_split('/\s+/u', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= function_exists('mb_substr') ? mb_substr($part, 0, 1) : substr($part, 0, 1);
    }
    return strtoupper($initials !== '' ? $initials : '?');
}

function bookingInquiryDisplayLabel(mixed $value): string
{
    return trim(preg_replace('/^\[DEMO PIPELINE\]\s*/u', '', trim((string) $value)) ?? '');
}
