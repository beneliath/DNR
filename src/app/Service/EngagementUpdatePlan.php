<?php

declare(strict_types=1);

namespace Dnr\Service;

final class EngagementUpdatePlan
{
    /**
     * @param array<string, mixed> $current
     * @param array<string, float|int|string|null> $submitted
     * @return array{
     *     assignments: list<string>,
     *     values: list<float|int|string|null>,
     *     types: string,
     *     date_range_changed: bool
     * }
     */
    public static function build(array $current, array $submitted): array
    {
        $assignments = [];
        $values = [];
        $types = '';

        self::addIntegerChange($assignments, $values, $types, $current, $submitted, 'organization_id');

        foreach ([
            'event_title',
            'event_description',
            'event_start_date',
            'event_end_date',
            'event_type',
            'event_type_other',
        ] as $field) {
            self::addStringChange($assignments, $values, $types, $current, $submitted, $field);
        }

        self::addIntegerChange($assignments, $values, $types, $current, $submitted, 'book_table');
        self::addIntegerChange($assignments, $values, $types, $current, $submitted, 'brochures');

        $submittedCallerId = $submitted['caller_user_id'] === null
            ? null
            : (int) $submitted['caller_user_id'];
        $currentCallerId = ($current['caller_user_id'] ?? null) === null
            ? null
            : (int) $current['caller_user_id'];
        $submittedCallerName = (string) ($submitted['caller_name'] ?? '');
        if ($submittedCallerId !== $currentCallerId
            || $submittedCallerName !== (string) ($current['caller_name'] ?? '')
        ) {
            $assignments[] = 'caller_name = ?, caller_user_id = ?';
            $values[] = $submittedCallerName;
            $values[] = $submittedCallerId;
            $types .= 'si';
        }

        foreach (['confirmation_status', 'lifecycle_status', 'travel_covered'] as $field) {
            self::addStringChange($assignments, $values, $types, $current, $submitted, $field);
        }
        self::addNullableStringChange(
            $assignments,
            $values,
            $types,
            $current,
            $submitted,
            'cancellation_reason'
        );
        self::addNullableIntegerChange(
            $assignments,
            $values,
            $types,
            $current,
            $submitted,
            'rescheduled_to_engagement_id'
        );
        self::addAmountChange($assignments, $values, $types, $current, $submitted, 'travel_amount');

        foreach (['compensation_type', 'other_compensation', 'housing_type', 'other_housing'] as $field) {
            self::addStringChange($assignments, $values, $types, $current, $submitted, $field);
        }
        self::addAmountChange($assignments, $values, $types, $current, $submitted, 'housing_amount');

        foreach ([
            'event_address_line_1',
            'event_address_line_2',
            'event_city',
            'event_state',
            'event_zipcode',
            'event_country',
        ] as $field) {
            self::addStringChange($assignments, $values, $types, $current, $submitted, $field);
        }

        return [
            'assignments' => $assignments,
            'values' => $values,
            'types' => $types,
            'date_range_changed' => (string) $submitted['event_start_date'] !== (string) $current['event_start_date']
                || (string) $submitted['event_end_date'] !== (string) $current['event_end_date'],
        ];
    }

    /**
     * @param list<string> $assignments
     * @param list<float|int|string|null> $values
     * @param array<string, mixed> $current
     * @param array<string, float|int|string|null> $submitted
     */
    private static function addStringChange(
        array &$assignments,
        array &$values,
        string &$types,
        array $current,
        array $submitted,
        string $field
    ): void {
        $value = (string) ($submitted[$field] ?? '');
        if ($value === (string) ($current[$field] ?? '')) {
            return;
        }
        $assignments[] = $field . ' = ?';
        $values[] = $value;
        $types .= 's';
    }

    /**
     * @param list<string> $assignments
     * @param list<float|int|string|null> $values
     * @param array<string, mixed> $current
     * @param array<string, float|int|string|null> $submitted
     */
    private static function addIntegerChange(
        array &$assignments,
        array &$values,
        string &$types,
        array $current,
        array $submitted,
        string $field
    ): void {
        $value = (int) ($submitted[$field] ?? 0);
        if ($value === (int) ($current[$field] ?? 0)) {
            return;
        }
        $assignments[] = $field . ' = ?';
        $values[] = $value;
        $types .= 'i';
    }

    /**
     * @param list<string> $assignments
     * @param list<float|int|string|null> $values
     * @param array<string, mixed> $current
     * @param array<string, float|int|string|null> $submitted
     */
    private static function addAmountChange(
        array &$assignments,
        array &$values,
        string &$types,
        array $current,
        array $submitted,
        string $field
    ): void {
        $value = $submitted[$field] === null ? null : (float) $submitted[$field];
        $currentValue = ($current[$field] ?? null) === null || $current[$field] === ''
            ? null
            : (float) $current[$field];
        if ($value === $currentValue) {
            return;
        }
        $assignments[] = $field . ' = ?';
        $values[] = $value;
        $types .= 'd';
    }

    /**
     * @param list<string> $assignments
     * @param list<float|int|string|null> $values
     * @param array<string, mixed> $current
     * @param array<string, float|int|string|null> $submitted
     */
    private static function addNullableIntegerChange(
        array &$assignments,
        array &$values,
        string &$types,
        array $current,
        array $submitted,
        string $field
    ): void {
        $value = ($submitted[$field] ?? null) === null
            ? null
            : (int) $submitted[$field];
        $currentValue = ($current[$field] ?? null) === null
            ? null
            : (int) $current[$field];
        if ($value === $currentValue) {
            return;
        }
        $assignments[] = $field . ' = ?';
        $values[] = $value;
        $types .= 'i';
    }

    /**
     * @param list<string> $assignments
     * @param list<float|int|string|null> $values
     * @param array<string, mixed> $current
     * @param array<string, float|int|string|null> $submitted
     */
    private static function addNullableStringChange(
        array &$assignments,
        array &$values,
        string &$types,
        array $current,
        array $submitted,
        string $field
    ): void {
        $value = ($submitted[$field] ?? null) === null
            ? null
            : (string) $submitted[$field];
        $currentValue = ($current[$field] ?? null) === null
            ? null
            : (string) $current[$field];
        if ($value === $currentValue) {
            return;
        }
        $assignments[] = $field . ' = ?';
        $values[] = $value;
        $types .= 's';
    }
}
