<?php

declare(strict_types=1);

function bookingInquiryNullableDate(mixed $value, string $label): ?string
{
    $value = is_scalar($value) ? trim((string) $value) : '';
    if ($value === '') {
        return null;
    }
    if (!validIsoDate($value)) {
        throw new InvalidArgumentException('Enter a valid ' . $label . '.');
    }
    return $value;
}

/** @return array{0: ?string, 1: ?string} */
function bookingInquiryDateRange(
    mixed $startValue,
    mixed $endValue,
    string $label
): array {
    $start = bookingInquiryNullableDate($startValue, strtolower($label) . ' start date');
    $end = bookingInquiryNullableDate($endValue, strtolower($label) . ' end date');
    if ($start === null && $end !== null) {
        throw new InvalidArgumentException($label . ' needs a start date.');
    }
    if ($start !== null && $end === null) {
        $end = $start;
    }
    if ($start !== null && $end < $start) {
        throw new InvalidArgumentException($label . ' end date cannot be before its start date.');
    }
    return [$start, $end];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function normalizeBookingInquiryInput(mysqli $conn, array $input): array
{
    $text = static function (string $key) use ($input): string {
        $value = $input[$key] ?? '';
        return is_scalar($value) ? trim((string) $value) : '';
    };
    $title = $text('title');
    $summary = $text('request_summary');
    $sourceDetail = $text('source_detail');
    $nextAction = $text('next_action');
    if ($title === '' || mb_strlen($title, 'UTF-8') > 255) {
        throw new InvalidArgumentException('Enter an inquiry title using 255 characters or fewer.');
    }
    if (mb_strlen($summary, 'UTF-8') > 100000) {
        throw new InvalidArgumentException('The request summary must use 100,000 characters or fewer.');
    }
    if (mb_strlen($sourceDetail, 'UTF-8') > 255) {
        throw new InvalidArgumentException('Source details must use 255 characters or fewer.');
    }
    if (mb_strlen($nextAction, 'UTF-8') > 255) {
        throw new InvalidArgumentException('The next action must use 255 characters or fewer.');
    }

    $organizationId = filter_var($input['organization_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $contactId = filter_var($input['primary_contact_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    if ($organizationId !== null) {
        $stmt = $conn->prepare(
            'SELECT id FROM organizations WHERE id = ? AND is_deleted = 0'
        );
        $stmt->bind_param('i', $organizationId);
        $stmt->execute();
        $active = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        if (!$active) {
            throw new InvalidArgumentException('Select an active organization.');
        }
    }
    if ($contactId !== null) {
        $stmt = $conn->prepare(
            'SELECT id, organization_id FROM contacts WHERE id = ? AND is_deleted = 0'
        );
        $stmt->bind_param('i', $contactId);
        $stmt->execute();
        $contact = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$contact) {
            throw new InvalidArgumentException('Select an active primary contact.');
        }
        $contactOrganizationId = $contact['organization_id'] !== null
            ? (int) $contact['organization_id']
            : null;
        if ($organizationId === null && $contactOrganizationId !== null) {
            $organizationId = $contactOrganizationId;
        } elseif ($organizationId !== null && !contactBelongsToOrganization($conn, $contactId, $organizationId)) {
            throw new InvalidArgumentException('The primary contact must belong to the selected organization.');
        }
    }

    [$preferredStart, $preferredEnd] = bookingInquiryDateRange(
        $input['preferred_start_date'] ?? '',
        $input['preferred_end_date'] ?? '',
        'Preferred date range'
    );
    [$alternateStart, $alternateEnd] = bookingInquiryDateRange(
        $input['alternate_start_date'] ?? '',
        $input['alternate_end_date'] ?? '',
        'Alternate date range'
    );

    [$eventType, $eventTypeOther] = normalizeEventType(
        $text('event_type'),
        $text('event_type_other')
    );
    $source = $text('source');
    if (!array_key_exists($source, bookingInquirySelectableSources())) {
        throw new InvalidArgumentException('Select a valid inquiry source.');
    }
    $priority = $text('priority');
    if (!array_key_exists($priority, bookingInquiryPriorities())) {
        throw new InvalidArgumentException('Select a valid inquiry priority.');
    }
    $ownerId = filter_var($input['owner_user_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    if ($ownerId !== null) {
        $stmt = $conn->prepare(
            "SELECT id FROM users WHERE id = ? AND account_status = 'active'"
        );
        $stmt->bind_param('i', $ownerId);
        $stmt->execute();
        $active = $stmt->get_result()->num_rows === 1;
        $stmt->close();
        if (!$active) {
            throw new InvalidArgumentException('Select an active inquiry owner.');
        }
    }
    $nextActionDue = bookingInquiryNullableDate(
        $input['next_action_due_date'] ?? '',
        'next-action due date'
    );

    $address = [];
    foreach (['line_1' => 255, 'line_2' => 255, 'city' => 100, 'state' => 100,
              'zipcode' => 20, 'country' => 100] as $part => $maximum) {
        $value = $text('event_' . ($part === 'line_1' || $part === 'line_2'
            ? 'address_' . $part
            : $part));
        if (mb_strlen($value, 'UTF-8') > $maximum) {
            throw new InvalidArgumentException('The event location contains a value that is too long.');
        }
        $address[$part] = $value === '' ? null : $value;
    }
    if ($address['country'] !== null) {
        $country = normalizeAddressCountryCode($address['country']);
        if ($country === null) {
            throw new InvalidArgumentException('Select a valid event country.');
        }
        $address['country'] = $country;
    }
    if ($address['state'] !== null) {
        $region = normalizeAddressRegion((string) ($address['country'] ?? ''), $address['state']);
        if ($region === null) {
            throw new InvalidArgumentException('Select a valid event state or province.');
        }
        $address['state'] = $region;
    }
    return [
        'title' => $title,
        'organization_id' => $organizationId,
        'primary_contact_id' => $contactId,
        'request_summary' => $summary === '' ? null : $summary,
        'event_type' => $eventType,
        'event_type_other' => $eventTypeOther === '' ? null : $eventTypeOther,
        'preferred_start_date' => $preferredStart,
        'preferred_end_date' => $preferredEnd,
        'alternate_start_date' => $alternateStart,
        'alternate_end_date' => $alternateEnd,
        'event_address_line_1' => $address['line_1'],
        'event_address_line_2' => $address['line_2'],
        'event_city' => $address['city'],
        'event_state' => $address['state'],
        'event_zipcode' => $address['zipcode'],
        'event_country' => $address['country'],
        'source' => $source,
        'source_detail' => $sourceDetail === '' ? null : $sourceDetail,
        'owner_user_id' => $ownerId,
        'priority' => $priority,
        'next_action' => $nextAction === '' ? null : $nextAction,
        'next_action_due_date' => $nextActionDue,
        'next_action_task_choice' => ctype_digit($text('next_action_task_choice')) ? (int) $text('next_action_task_choice') : 0,
    ];
}
