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
        $statement = $connection->prepare(
            "SELECT id, username FROM users WHERE id = ? AND account_status = 'active'"
        );
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
            InputText::value($input, 'event_type'),
            InputText::value($input, 'event_type_other')
        );
        $data = [
            'organization_id' => max(0, (int) ($input['organization_id'] ?? 0)),
            'event_title' => InputText::value($input, 'event_title'),
            'event_description' => InputText::value($input, 'event_description'),
            'event_start_date' => InputText::value($input, 'event_start_date'),
            'event_end_date' => InputText::value($input, 'event_end_date'),
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
            'lifecycle_status' => ReferenceData::choice(
                $input['lifecycle_status'] ?? '',
                ReferenceData::engagementLifecycleStatuses(),
                'active'
            ),
            'cancellation_reason' => InputText::value($input, 'cancellation_reason'),
            'rescheduled_to_engagement_id' => max(
                0,
                (int) ($input['rescheduled_to_engagement_id'] ?? 0)
            ) ?: null,
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
            'other_compensation' => InputText::value($input, 'other_compensation'),
            'housing_type' => ReferenceData::choice(
                $input['housing_type'] ?? '',
                ReferenceData::housingTypes(),
                'Unknown'
            ),
            'other_housing' => InputText::value($input, 'other_housing'),
            'housing_amount' => \nullableNonNegativeAmount($input['housing_amount'] ?? '', 'lodging'),
        ];
        foreach (['line_1', 'line_2'] as $part) {
            $data['event_address_' . $part] = InputText::value($input, 'event_address_' . $part);
        }
        foreach (['city', 'state', 'zipcode', 'country'] as $part) {
            $data['event_' . $part] = InputText::value($input, 'event_' . $part);
        }
        if ($data['event_country'] !== '') {
            $event_country = \normalizeAddressCountryCode($data['event_country']);
            if ($event_country === null) {
                throw new \InvalidArgumentException('Select a valid event country.');
            }
            $data['event_country'] = $event_country;
        }
        if ($data['event_state'] !== '') {
            $event_state = \normalizeAddressRegion($data['event_country'], $data['event_state']);
            if ($event_state === null) {
                throw new \InvalidArgumentException(
                    $data['event_country'] === 'CA'
                        ? 'Select a valid event province.'
                        : 'Select a valid event state.'
                );
            }
            $data['event_state'] = $event_state;
        }
        if ($data['organization_id'] < 1) {
            throw new \InvalidArgumentException('Please select an active organization.');
        }
        if ($data['event_title'] === '') {
            throw new \InvalidArgumentException('Event title is required.');
        }
        $length_limits = [
            'event_title' => [255, 'Event title'],
            'event_address_line_1' => [255, 'Event address line 1'],
            'event_address_line_2' => [255, 'Event address line 2'],
            'event_city' => [100, 'Event city'],
            'event_state' => [100, 'Event state'],
            'event_zipcode' => [20, 'Event postal code'],
            'event_country' => [100, 'Event country'],
        ];
        foreach ($length_limits as $field => [$maximum, $label]) {
            $length_error = InputText::lengthError((string) $data[$field], $maximum, $label);
            if ($length_error !== null) {
                throw new \InvalidArgumentException($length_error);
            }
        }
        foreach ([
            'event_description' => 'Event description',
            'other_compensation' => 'Other compensation',
            'other_housing' => 'Other lodging arrangement',
        ] as $field => $label) {
            $storage_error = InputText::textStorageError((string) $data[$field], $label);
            if ($storage_error !== null) {
                throw new \InvalidArgumentException($storage_error);
            }
        }
        $cancellation_length_error = InputText::lengthError(
            (string) $data['cancellation_reason'],
            1000,
            'Cancellation reason'
        );
        if ($cancellation_length_error !== null) {
            throw new \InvalidArgumentException($cancellation_length_error);
        }
        if ($data['lifecycle_status'] === 'canceled') {
            if ($data['cancellation_reason'] === '') {
                throw new \InvalidArgumentException(
                    'Provide a cancellation reason for a canceled engagement.'
                );
            }
        } else {
            $data['cancellation_reason'] = null;
        }
        if (!in_array($data['lifecycle_status'], ['postponed', 'canceled'], true)) {
            $data['rescheduled_to_engagement_id'] = null;
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

}
