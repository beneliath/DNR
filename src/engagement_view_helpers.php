<?php

declare(strict_types=1);

/**
 * Display the recorded workflow only; dates, finances, and archiving do not advance it.
 * Exceptions leave the linear stages neutral and have their own current marker.
 *
 * @param array<string, mixed> $engagement
 * @return array{
 *     steps: list<array{key: string, label: string, state: 'pending'|'current'|'complete'}>,
 *     exception: array{key: string, label: string}|null
 * }
 */
function engagementViewProgress(array $engagement): array
{
    $labels = [
        'work_in_progress' => 'Work in Progress',
        'under_review' => 'Under Review',
        'confirmed' => 'Confirmed',
        'completed' => 'Completed',
    ];
    $lifecycle = engagementViewText($engagement['lifecycle_status'] ?? 'active');
    $confirmation = engagementViewText($engagement['confirmation_status'] ?? '');
    $current_index = null;
    $exception = null;

    if ($lifecycle === 'completed') {
        $current_index = 3;
    } elseif (in_array($lifecycle, ['postponed', 'canceled'], true)) {
        $exception = ['key' => $lifecycle, 'label' => ucfirst($lifecycle)];
    } elseif ($lifecycle !== 'active') {
        $exception = ['key' => 'unknown_lifecycle', 'label' => 'Lifecycle not set'];
    } else {
        $confirmation_index = array_search(
            $confirmation,
            ['work_in_progress', 'under_review', 'confirmed'],
            true
        );
        if ($confirmation_index === false) {
            $exception = ['key' => 'unknown_confirmation', 'label' => 'Confirmation not set'];
        } else {
            $current_index = $confirmation_index;
        }
    }

    $steps = [];
    foreach ($labels as $key => $label) {
        $index = count($steps);
        $state = 'pending';
        if ($current_index !== null) {
            $state = $index < $current_index ? 'complete' : ($index === $current_index ? 'current' : 'pending');
        }
        $steps[] = ['key' => $key, 'label' => $label, 'state' => $state];
    }

    return ['steps' => $steps, 'exception' => $exception];
}

/**
 * Informational preparation indicators, not validation or confirmation requirements.
 * A partial presentation counts because its remaining details can be added later.
 *
 * @param array<string, mixed> $engagement
 * @param array<array<string, mixed>> $contacts
 * @param array<array<string, mixed>> $presentations
 * @return list<array{label: string, ready: bool}>
 */
function engagementViewReadiness(array $engagement, array $contacts, array $presentations): array
{
    $organization_id = engagementViewText($engagement['org_id'] ?? $engagement['organization_id'] ?? '');
    $organization_name = engagementViewText($engagement['organization_name'] ?? '');
    $start = engagementViewDate($engagement['event_start_date'] ?? null);
    $end = engagementViewDate($engagement['event_end_date'] ?? null);
    $location_recorded = false;
    foreach (['event_address_line_1', 'event_city', 'event_state'] as $field) {
        if (engagementViewText($engagement[$field] ?? '') !== '') {
            $location_recorded = true;
            break;
        }
    }

    return [
        ['label' => 'Organization Linked', 'ready' => (int) $organization_id > 0 && $organization_name !== ''],
        ['label' => 'Event Dates Set', 'ready' => $start !== null && $end !== null && $start <= $end],
        ['label' => 'Event Contacts Assigned', 'ready' => $contacts !== []],
        ['label' => 'Presentation Added', 'ready' => $presentations !== []],
        ['label' => 'Location Recorded', 'ready' => $location_recorded],
    ];
}

/** Format stored calendar dates without interpreting invalid values or missing dates as today. */
function engagementViewDateRange(mixed $start_date, mixed $end_date): string
{
    $start = engagementViewDate($start_date);
    $end = engagementViewDate($end_date);
    if (($start === null && engagementViewText($start_date) !== '')
        || ($end === null && engagementViewText($end_date) !== '')
        || ($start !== null && $end !== null && $start > $end)
    ) {
        return 'Dates need review';
    }
    if ($start === null && $end === null) {
        return 'Dates not set';
    }
    if ($start === null) {
        return 'Ends ' . $end->format('M j, Y');
    }
    if ($end === null) {
        return 'Starts ' . $start->format('M j, Y');
    }
    if ($start == $end) {
        return $start->format('M j, Y');
    }
    if ($start->format('Y-m') === $end->format('Y-m')) {
        return $start->format('M j') . '–' . $end->format('j, Y');
    }
    if ($start->format('Y') === $end->format('Y')) {
        return $start->format('M j') . '–' . $end->format('M j, Y');
    }
    return $start->format('M j, Y') . '–' . $end->format('M j, Y');
}

/** Parse only real YYYY-MM-DD calendar dates, without strtotime's date normalization. */
function engagementViewDate(mixed $value): ?DateTimeImmutable
{
    $value = engagementViewText($value);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) !== 1
        || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
    ) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date === false ? null : $date;
}

/** Normalize optional scalar display values without array-to-string warnings. */
function engagementViewText(mixed $value): string
{
    return is_scalar($value) ? trim((string) $value) : '';
}
