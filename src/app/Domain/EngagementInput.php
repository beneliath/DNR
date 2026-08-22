<?php

declare(strict_types=1);

namespace Dnr\Domain;

final class EngagementInput
{
    /** @return array{id: int|null, username: string} */
    public static function resolveCaller(\mysqli $connection, ?int $caller_user_id): array
    {
        if ($caller_user_id === null || $caller_user_id < 1) {
            return ['id' => null, 'username' => ''];
        }
        $statement = $connection->prepare('SELECT id, username FROM users WHERE id = ?');
        if (!$statement) {
            throw new \RuntimeException('Unable to validate the selected caller.');
        }
        $statement->bind_param('i', $caller_user_id);
        $statement->execute();
        $caller = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!$caller) {
            throw new \InvalidArgumentException('Please select a valid caller.');
        }
        return ['id' => (int) $caller['id'], 'username' => (string) $caller['username']];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, float|int|string|null>
     */
    public static function normalize(array $input): array
    {
        [$event_type, $event_type_other] = \normalizeEventType(
            self::text($input, 'event_type'),
            self::text($input, 'event_type_other')
        );
        $data = [
            'organization_id' => max(0, (int) ($input['organization_id'] ?? 0)),
            'event_title' => self::text($input, 'event_title'),
            'event_description' => self::text($input, 'event_description'),
            'event_start_date' => self::text($input, 'event_start_date'),
            'event_end_date' => self::text($input, 'event_end_date'),
            'event_type' => $event_type,
            'event_type_other' => $event_type_other,
            'book_table' => isset($input['book_table']) ? 1 : 0,
            'brochures' => isset($input['brochures']) ? 1 : 0,
            'caller_user_id' => max(0, (int) ($input['caller_user_id'] ?? 0)) ?: null,
            'confirmation_status' => ReferenceData::choice(
                $input['confirmation_status'] ?? '',
                ReferenceData::engagementStatuses(),
                'work_in_progress'
            ),
            'travel_covered' => ReferenceData::choice(
                $input['travel_covered'] ?? '',
                ReferenceData::travelCoverage(),
                'unknown'
            ),
            'travel_amount' => \nullableNonNegativeAmount($input['travel_amount'] ?? '', 'travel'),
            'compensation_type' => ReferenceData::choice(
                $input['compensation_type'] ?? '',
                ReferenceData::compensationTypes(),
                'Unknown'
            ),
            'other_compensation' => self::text($input, 'other_compensation'),
            'housing_type' => ReferenceData::choice(
                $input['housing_type'] ?? '',
                ReferenceData::housingTypes(),
                'Unknown'
            ),
            'other_housing' => self::text($input, 'other_housing'),
            'housing_amount' => \nullableNonNegativeAmount($input['housing_amount'] ?? '', 'lodging'),
        ];
        foreach (['line_1', 'line_2', 'city', 'state', 'zipcode', 'country'] as $part) {
            $data['event_address_' . $part] = self::text($input, 'event_address_' . $part);
        }
        if ($data['organization_id'] < 1) {
            throw new \InvalidArgumentException('Please select an active organization.');
        }
        if ($data['event_title'] === '') {
            throw new \InvalidArgumentException('Event title is required.');
        }
        \requireValidDateRange($data['event_start_date'], $data['event_end_date']);
        if ($data['compensation_type'] === 'Other' && $data['other_compensation'] === '') {
            throw new \InvalidArgumentException('Describe the other compensation.');
        }
        if ($data['housing_type'] === 'Other' && $data['other_housing'] === '') {
            throw new \InvalidArgumentException('Describe the other lodging arrangement.');
        }
        return $data;
    }

    /** @param array<string, mixed> $input */
    private static function text(array $input, string $key): string
    {
        return is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
    }
}
