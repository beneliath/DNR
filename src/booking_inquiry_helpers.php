<?php

declare(strict_types=1);

require_once __DIR__ . '/application_runtime.php';
require_once __DIR__ . '/email_helpers.php';
require_once __DIR__ . '/engagement_email_helpers.php';

/** @return array<string, string> */
function bookingInquiryStages(): array
{
    return [
        'new' => 'New',
        'contacted' => 'Contacted',
        'qualified' => 'Qualified',
        'awaiting_details' => 'Awaiting Details',
        'proposal_sent' => 'Proposal Sent',
        'booked' => 'Booked',
        'declined' => 'Declined',
    ];
}

/** @return list<string> */
function bookingInquiryActiveStages(): array
{
    return ['new', 'contacted', 'qualified', 'awaiting_details', 'proposal_sent'];
}

/** @return array<string, string> */
function bookingInquirySources(): array
{
    return [
        'email' => 'Email',
        'phone' => 'Phone',
        'website' => 'Website',
        'referral' => 'Referral',
        'mattermost' => 'Mattermost',
        'other' => 'Other',
    ];
}

/** @return array<string, string> */
function bookingInquirySelectableSources(): array
{
    $sources = bookingInquirySources();
    unset($sources['mattermost']);
    return $sources;
}

/** @return array<string, string> */
function bookingInquiryPriorities(): array
{
    return [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];
}

function canManageBookingInquiries(string $role): bool
{
    return in_array($role, ['admin', 'editor'], true);
}

/** @return list<array<string, mixed>> */
function bookingInquiryOwners(mysqli $conn): array
{
    $result = $conn->query(
        "SELECT id, username, role FROM users
         WHERE account_status = 'active' ORDER BY username, id"
    );
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

/** @return array<string, mixed>|null */
function fetchBookingInquiry(mysqli $conn, int $inquiryId, bool $lock = false): ?array
{
    if ($inquiryId < 1) {
        return null;
    }
    $sql = "SELECT inquiry.*,
                   organization.organization_name,
                   organization.is_deleted AS organization_deleted,
                   TRIM(CONCAT_WS(' ', contact.contact_first_name,
                                      contact.contact_last_name)) AS contact_name,
                   contact.contact_email, contact.contact_phone,
                   contact.organization_id AS contact_organization_id,
                   contact.is_deleted AS contact_deleted,
                   owner.username AS owner_username,
                   creator.username AS creator_username,
                   stage_actor.username AS stage_changed_by_username,
                   engagement.event_title AS converted_engagement_title
            FROM booking_inquiries inquiry
            LEFT JOIN organizations organization ON organization.id = inquiry.organization_id
            LEFT JOIN contacts contact ON contact.id = inquiry.primary_contact_id
            LEFT JOIN users owner ON owner.id = inquiry.owner_user_id
            LEFT JOIN users creator ON creator.id = inquiry.created_by
            LEFT JOIN users stage_actor ON stage_actor.id = inquiry.stage_changed_by
            LEFT JOIN engagements engagement ON engagement.id = inquiry.converted_engagement_id
            WHERE inquiry.id = ?";
    if ($lock) {
        $sql = 'SELECT * FROM booking_inquiries WHERE id = ? FOR UPDATE';
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the inquiry lookup.');
    }
    $stmt->bind_param('i', $inquiryId);
    $stmt->execute();
    $inquiry = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $inquiry;
}

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
    if ($start !== null && $end !== null && $end < $start) {
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
        } elseif ($organizationId !== null && $organizationId !== $contactOrganizationId) {
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
    ];
}

function insertBookingInquiryStageHistory(
    mysqli $conn,
    int $inquiryId,
    ?string $fromStage,
    string $toStage,
    ?string $reason,
    int $userId,
    string $username
): void {
    $stmt = $conn->prepare(
        'INSERT INTO booking_inquiry_stage_history
            (booking_inquiry_id, from_stage, to_stage, reason,
             changed_by, changed_by_username_snapshot)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the inquiry stage history.');
    }
    $stmt->bind_param('isssis', $inquiryId, $fromStage, $toStage, $reason, $userId, $username);
    $stmt->execute();
    $stmt->close();
}

/** @param array<string, mixed> $data */
function lockBookingInquiryRelationships(mysqli $conn, array $data): void
{
    $organizationId = (int) ($data['organization_id'] ?? 0);
    if ($organizationId > 0) {
        requireActiveOrganization($conn, $organizationId, true);
    }

    $contactId = (int) ($data['primary_contact_id'] ?? 0);
    if ($contactId < 1) {
        return;
    }
    $contact = $conn->prepare(
        'SELECT organization_id FROM contacts
         WHERE id = ? AND is_deleted = 0 FOR UPDATE'
    );
    if (!$contact) {
        throw new RuntimeException('Unable to prepare the primary Contact lock.');
    }
    $contact->bind_param('i', $contactId);
    $contact->execute();
    $row = $contact->get_result()->fetch_assoc() ?: null;
    $contact->close();
    if ($row === null) {
        throw new InvalidArgumentException('Select an active primary Contact.');
    }
    $contactOrganizationId = (int) ($row['organization_id'] ?? 0);
    if ($organizationId > 0 && $contactOrganizationId !== $organizationId) {
        throw new InvalidArgumentException(
            'The primary Contact must belong to the selected Organization.'
        );
    }
}

/** @param array<string, mixed> $data */
function createBookingInquiry(
    mysqli $conn,
    array $data,
    int $userId,
    string $username,
    ?int $inboundEmailMessageId = null,
    ?string $initialChron = null
): int {
    $conn->begin_transaction();
    try {
        lockBookingInquiryRelationships($conn, $data);
        $sourceMessage = null;
        if ($inboundEmailMessageId !== null) {
            $source = $conn->prepare(
                'SELECT id, status, received_at
                 FROM inbound_email_messages WHERE id = ? FOR UPDATE'
            );
            $source->bind_param('i', $inboundEmailMessageId);
            $source->execute();
            $sourceMessage = $source->get_result()->fetch_assoc() ?: null;
            $source->close();
            if ($sourceMessage === null) {
                throw new InvalidArgumentException('The source email is no longer available.');
            }
            if (!in_array((string) $sourceMessage['status'], ['pending', 'review', 'failed'], true)) {
                throw new InvalidArgumentException('That source email is no longer available for inquiry creation.');
            }
            if ($initialChron === null || trim($initialChron) === '') {
                throw new InvalidArgumentException('The source email must be preserved in the Inquiry Chron.');
            }
        }
        $stmt = $conn->prepare(
            "INSERT INTO booking_inquiries
                (title, organization_id, primary_contact_id, request_summary,
                 event_type, event_type_other,
                 preferred_start_date, preferred_end_date,
                 alternate_start_date, alternate_end_date,
                 event_address_line_1, event_address_line_2, event_city,
                 event_state, event_zipcode, event_country,
                 source, source_detail, inbound_email_message_id,
                 owner_user_id, priority, next_action, next_action_due_date,
                 stage_changed_by, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the inquiry.');
        }
        $types = 'sii' . str_repeat('s', 15) . 'iisssii';
        $stmt->bind_param(
            $types,
            $data['title'], $data['organization_id'], $data['primary_contact_id'],
            $data['request_summary'], $data['event_type'], $data['event_type_other'],
            $data['preferred_start_date'], $data['preferred_end_date'],
            $data['alternate_start_date'], $data['alternate_end_date'],
            $data['event_address_line_1'], $data['event_address_line_2'],
            $data['event_city'], $data['event_state'], $data['event_zipcode'],
            $data['event_country'], $data['source'], $data['source_detail'],
            $inboundEmailMessageId, $data['owner_user_id'], $data['priority'],
            $data['next_action'], $data['next_action_due_date'], $userId, $userId
        );
        $stmt->execute();
        $inquiryId = (int) $conn->insert_id;
        $stmt->close();
        insertBookingInquiryStageHistory(
            $conn, $inquiryId, null, 'new', 'Inquiry created.', $userId, $username
        );
        if ($sourceMessage !== null) {
            $chron = $conn->prepare(
                'INSERT INTO booking_inquiry_chron_entries
                    (booking_inquiry_id, inbound_email_message_id, entry_text,
                     created_by, created_by_username_snapshot, updated_by,
                     created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$chron) {
                throw new RuntimeException('Unable to prepare the source correspondence.');
            }
            $sourceReceivedAt = (string) $sourceMessage['received_at'];
            $chron->bind_param(
                'iisisiss', $inquiryId, $inboundEmailMessageId, $initialChron,
                $userId, $username, $userId, $sourceReceivedAt, $sourceReceivedAt
            );
            $chron->execute();
            $chron->close();

            $routingDetails = json_encode([
                'automatic' => false,
                'created_inquiry' => $inquiryId,
                'applied_contacts' => [],
                'applied_organizations' => [],
                'applied_engagements' => [],
                'applied_inquiries' => [$inquiryId],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $completeSource = $conn->prepare(
                "UPDATE inbound_email_messages
                 SET status = 'processed', routing_details = ?, review_reason = NULL,
                     processing_started_at = NULL, last_error = NULL,
                     processed_by = ?, processed_at = UTC_TIMESTAMP()
                 WHERE id = ?"
            );
            if (!$completeSource) {
                throw new RuntimeException('Unable to prepare the source-email completion.');
            }
            $completeSource->bind_param(
                'sii', $routingDetails, $userId, $inboundEmailMessageId
            );
            $completeSource->execute();
            $completeSource->close();
        }
        $conn->commit();
        return $inquiryId;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

/** @param array<string, mixed> $data */
function updateBookingInquiry(
    mysqli $conn,
    int $inquiryId,
    array $data,
    string $expectedVersion
): void {
    $conn->begin_transaction();
    try {
        $current = fetchBookingInquiry($conn, $inquiryId, true);
        if (!$current) {
            throw new InvalidArgumentException('That inquiry is no longer available.');
        }
        if ((string) $current['stage'] === 'booked') {
            throw new InvalidArgumentException('Booked inquiries are preserved as read-only source records.');
        }
        if ($expectedVersion === '' || !hash_equals((string) $current['updated_at'], $expectedVersion)) {
            throw new InvalidArgumentException('That inquiry changed in another session. Reload before saving.');
        }
        lockBookingInquiryRelationships($conn, $data);
        $stmt = $conn->prepare(
            'UPDATE booking_inquiries SET
                title = ?, organization_id = ?, primary_contact_id = ?, request_summary = ?,
                event_type = ?, event_type_other = ?,
                preferred_start_date = ?, preferred_end_date = ?,
                alternate_start_date = ?, alternate_end_date = ?,
                event_address_line_1 = ?, event_address_line_2 = ?, event_city = ?,
                event_state = ?, event_zipcode = ?, event_country = ?,
                source = ?, source_detail = ?, owner_user_id = ?, priority = ?,
                next_action = ?, next_action_due_date = ?
             WHERE id = ? AND updated_at = ?'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the inquiry update.');
        }
        $types = 'sii' . str_repeat('s', 15) . 'isssis';
        $stmt->bind_param(
            $types,
            $data['title'], $data['organization_id'], $data['primary_contact_id'],
            $data['request_summary'], $data['event_type'], $data['event_type_other'],
            $data['preferred_start_date'], $data['preferred_end_date'],
            $data['alternate_start_date'], $data['alternate_end_date'],
            $data['event_address_line_1'], $data['event_address_line_2'],
            $data['event_city'], $data['event_state'], $data['event_zipcode'],
            $data['event_country'], $data['source'], $data['source_detail'],
            $data['owner_user_id'], $data['priority'], $data['next_action'],
            $data['next_action_due_date'], $inquiryId, $expectedVersion
        );
        $stmt->execute();
        $stmt->close();
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function changeBookingInquiryStage(
    mysqli $conn,
    int $inquiryId,
    string $stage,
    ?string $reason,
    string $expectedVersion,
    int $userId,
    string $username
): void {
    if (!array_key_exists($stage, bookingInquiryStages()) || $stage === 'booked') {
        throw new InvalidArgumentException('Select a valid inquiry stage. Booking uses the conversion review.');
    }
    $reason = trim((string) $reason);
    if ($stage === 'declined' && $reason === '') {
        throw new InvalidArgumentException('Provide a reason before declining the inquiry.');
    }
    if (mb_strlen($reason, 'UTF-8') > 1000) {
        throw new InvalidArgumentException('The stage-change reason must use 1,000 characters or fewer.');
    }
    $conn->begin_transaction();
    try {
        $inquiry = fetchBookingInquiry($conn, $inquiryId, true);
        if (!$inquiry) {
            throw new InvalidArgumentException('That inquiry is no longer available.');
        }
        if ((string) $inquiry['stage'] === 'booked') {
            throw new InvalidArgumentException('Booked inquiries are read-only.');
        }
        if ($expectedVersion === '' || !hash_equals((string) $inquiry['updated_at'], $expectedVersion)) {
            throw new InvalidArgumentException('That inquiry changed in another session. Reload before updating its stage.');
        }
        $fromStage = (string) $inquiry['stage'];
        if ($fromStage === $stage) {
            throw new InvalidArgumentException('The inquiry is already in that stage.');
        }
        $declineReason = $stage === 'declined' ? $reason : null;
        $stmt = $conn->prepare(
            'UPDATE booking_inquiries
             SET stage = ?, decline_reason = ?, stage_changed_by = ?,
                 stage_changed_at = UTC_TIMESTAMP(6)
             WHERE id = ? AND updated_at = ?'
        );
        $stmt->bind_param('ssiis', $stage, $declineReason, $userId, $inquiryId, $expectedVersion);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new InvalidArgumentException('That inquiry changed in another session. Reload before updating its stage.');
        }
        $stmt->close();
        insertBookingInquiryStageHistory(
            $conn,
            $inquiryId,
            $fromStage,
            $stage,
            $reason === '' ? null : $reason,
            $userId,
            $username
        );
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

/** @return list<array<string, mixed>> */
function fetchBookingInquiryStageHistory(mysqli $conn, int $inquiryId): array
{
    $stmt = $conn->prepare(
        'SELECT history.*,
                COALESCE(user.username, history.changed_by_username_snapshot) AS changed_by_username
         FROM booking_inquiry_stage_history history
         LEFT JOIN users user ON user.id = history.changed_by
         WHERE history.booking_inquiry_id = ?
         ORDER BY history.changed_at DESC, history.id DESC'
    );
    $stmt->bind_param('i', $inquiryId);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $history;
}

/** @return array<string, bool> */
function bookingInquiryReadiness(array $inquiry): array
{
    return [
        'organization' => (int) ($inquiry['organization_id'] ?? 0) > 0
            && empty($inquiry['organization_deleted']),
        'title' => trim((string) ($inquiry['title'] ?? '')) !== '',
        'dates' => !empty($inquiry['preferred_start_date']) && !empty($inquiry['preferred_end_date']),
        'contact' => (int) ($inquiry['primary_contact_id'] ?? 0) > 0
            && empty($inquiry['contact_deleted'])
            && ((int) ($inquiry['organization_id'] ?? 0) === 0
                || (int) ($inquiry['contact_organization_id'] ?? 0)
                    === (int) $inquiry['organization_id']),
        'request' => trim((string) ($inquiry['request_summary'] ?? '')) !== '',
    ];
}

/** @return list<array<string, mixed>> */
function bookingInquiryDateConflicts(
    mysqli $conn,
    string $startDate,
    string $endDate,
    int $excludeInquiryId = 0,
    bool $lockRows = false
): array {
    if (!validIsoDate($startDate) || !validIsoDate($endDate) || $endDate < $startDate) {
        return [];
    }
    $conflicts = [];
    $engagements = $conn->prepare(
        "SELECT engagement.id, engagement.event_title AS title,
                engagement.event_start_date AS start_date,
                engagement.event_end_date AS end_date,
                organization.organization_name,
                engagement.lifecycle_status
         FROM engagements engagement
         INNER JOIN organizations organization ON organization.id = engagement.organization_id
         WHERE engagement.is_deleted = 0 AND organization.is_deleted = 0
           AND engagement.lifecycle_status NOT IN ('canceled', 'completed')
           AND engagement.event_start_date <= ?
           AND COALESCE(engagement.event_end_date, engagement.event_start_date) >= ?
         ORDER BY engagement.event_start_date, engagement.id"
            . ($lockRows ? ' FOR UPDATE' : '')
    );
    $engagements->bind_param('ss', $endDate, $startDate);
    $engagements->execute();
    foreach ($engagements->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $row['record_type'] = 'engagement';
        $row['url'] = 'view_engagement.php?id=' . (int) $row['id'];
        $conflicts[] = $row;
    }
    $engagements->close();

    $inquiries = $conn->prepare(
        "SELECT inquiry.id, inquiry.title,
                inquiry.preferred_start_date AS start_date,
                inquiry.preferred_end_date AS end_date,
                organization.organization_name, inquiry.stage
         FROM booking_inquiries inquiry
         LEFT JOIN organizations organization ON organization.id = inquiry.organization_id
         WHERE inquiry.id <> ?
           AND inquiry.stage IN ('qualified', 'awaiting_details', 'proposal_sent')
           AND inquiry.preferred_start_date <= ?
           AND inquiry.preferred_end_date >= ?
         ORDER BY inquiry.preferred_start_date, inquiry.id"
            . ($lockRows ? ' FOR UPDATE' : '')
    );
    $inquiries->bind_param('iss', $excludeInquiryId, $endDate, $startDate);
    $inquiries->execute();
    foreach ($inquiries->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $row['record_type'] = 'inquiry';
        $row['url'] = 'view_inquiry.php?id=' . (int) $row['id'];
        $conflicts[] = $row;
    }
    $inquiries->close();
    return $conflicts;
}

/**
 * @param list<int> $taskIds
 * @return array{engagement_id: int, moved_task_count: int, checklist_count: int}
 */
function convertBookingInquiry(
    mysqli $conn,
    int $inquiryId,
    string $expectedVersion,
    bool $acknowledgeConflicts,
    array $taskIds,
    int $userId,
    string $username
): array {
    $taskIds = array_values(array_unique(array_filter(array_map('intval', $taskIds),
        static fn(int $id): bool => $id > 0)));

    $conn->begin_transaction();
    try {
        $inquiry = fetchBookingInquiry($conn, $inquiryId, true);
        if (!$inquiry || !in_array((string) $inquiry['stage'], bookingInquiryActiveStages(), true)) {
            throw new InvalidArgumentException('Only an active inquiry can be booked.');
        }
        if ($expectedVersion === '' || !hash_equals((string) $inquiry['updated_at'], $expectedVersion)) {
            throw new InvalidArgumentException('That inquiry changed in another session. Reload before booking.');
        }
        $readiness = bookingInquiryReadiness($inquiry);
        foreach (['organization', 'title', 'dates'] as $required) {
            if (!$readiness[$required]) {
                throw new InvalidArgumentException(
                    'Add an organization, title, and preferred date range before booking.'
                );
            }
        }
        requireActiveOrganization($conn, (int) $inquiry['organization_id'], true);
        $conflicts = bookingInquiryDateConflicts(
            $conn,
            (string) $inquiry['preferred_start_date'],
            (string) $inquiry['preferred_end_date'],
            $inquiryId,
            true
        );
        if ($conflicts !== [] && !$acknowledgeConflicts) {
            throw new InvalidArgumentException(
                'Review and acknowledge the schedule warnings before booking.'
            );
        }

        $callerId = (int) ($inquiry['owner_user_id'] ?? 0) ?: $userId;
        $caller = \Dnr\Domain\EngagementInput::resolveCaller($conn, $callerId);
        $callerId = $caller['id'];
        $callerName = $caller['username'];
        $organizationId = (int) $inquiry['organization_id'];
        $eventTitle = (string) $inquiry['title'];
        $eventDescription = (string) ($inquiry['request_summary'] ?? '');
        $eventStart = (string) $inquiry['preferred_start_date'];
        $eventEnd = (string) $inquiry['preferred_end_date'];
        $eventType = (string) $inquiry['event_type'];
        $eventTypeOther = $inquiry['event_type_other'];
        $confirmation = 'work_in_progress';
        $lifecycle = 'active';
        $bookTable = 0;
        $brochures = 0;
        $address1 = $inquiry['event_address_line_1'];
        $address2 = $inquiry['event_address_line_2'];
        $city = $inquiry['event_city'];
        $state = $inquiry['event_state'];
        $zipcode = $inquiry['event_zipcode'];
        $country = $inquiry['event_country'];
        $travelCovered = 'unknown';
        $compensationType = 'Unknown';
        $housingType = 'Unknown';
        $stmt = $conn->prepare(
            'INSERT INTO engagements
                (organization_id, event_title, event_description,
                 event_start_date, event_end_date, event_type, event_type_other,
                 book_table, brochures, caller_name, caller_user_id,
                 confirmation_status, lifecycle_status, lifecycle_changed_by,
                 event_address_line_1, event_address_line_2, event_city, event_state,
                 event_zipcode, event_country, travel_covered,
                 compensation_type, housing_type)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the engagement conversion.');
        }
        $stmt->bind_param(
            'issssssiisississsssssss',
            $organizationId, $eventTitle, $eventDescription, $eventStart, $eventEnd,
            $eventType, $eventTypeOther, $bookTable, $brochures, $callerName, $callerId,
            $confirmation, $lifecycle, $userId, $address1, $address2, $city, $state,
            $zipcode, $country, $travelCovered, $compensationType, $housingType
        );
        $stmt->execute();
        $engagementId = (int) $conn->insert_id;
        $stmt->close();

        $contactId = (int) ($inquiry['primary_contact_id'] ?? 0);
        $activeContact = false;
        if ($contactId > 0) {
            $contact = $conn->prepare(
                'SELECT id FROM contacts
                 WHERE id = ? AND organization_id = ? AND is_deleted = 0'
            );
            $contact->bind_param('ii', $contactId, $organizationId);
            $contact->execute();
            $activeContact = $contact->get_result()->num_rows === 1;
            $contact->close();
        }
        if ($activeContact) {
            syncEngagementContacts(
                $conn,
                $engagementId,
                [['contact_id' => $contactId, 'contact_role' => 'primary_host']],
                $userId,
                false
            );
        }

        $chronText = 'BOOKED FROM INQUIRY #' . $inquiryId
            . "\nThe inquiry remains the source record for pre-booking history."
            . ($eventDescription !== '' ? "\n\nRequest summary:\n" . $eventDescription : '');
        $chron = $conn->prepare(
            'INSERT INTO engagement_chron_entries
                (engagement_id, entry_text, created_by,
                 created_by_username_snapshot, updated_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $chron->bind_param('isisi', $engagementId, $chronText, $userId, $username, $userId);
        $chron->execute();
        $chron->close();

        $moved = 0;
        if ($taskIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($taskIds), '?'));
            $move = $conn->prepare(
                "UPDATE follow_up_tasks
                 SET subject_type = 'engagement', engagement_id = ?, inquiry_id = NULL
                 WHERE inquiry_id = ?
                   AND status IN ('open', 'in_progress', 'waiting')
                   AND id IN ({$placeholders})"
            );
            $types = 'ii' . str_repeat('i', count($taskIds));
            $values = [$types, $engagementId, $inquiryId];
            foreach ($taskIds as &$taskId) {
                $values[] = &$taskId;
            }
            unset($taskId);
            $move->bind_param(...$values);
            $move->execute();
            $moved = $move->affected_rows;
            $move->close();
        }

        $checklist = generateEngagementFollowUpChecklist(
            $conn, $engagementId, $callerId, $userId, false
        );
        $mapAddress = engagementMapAddress([
            'event_address_line_1' => $address1,
            'event_address_line_2' => $address2,
            'event_city' => $city,
            'event_state' => $state,
            'event_zipcode' => $zipcode,
            'event_country' => $country,
        ]);
        if ($mapAddress !== '' && !queueEngagementMapAddress($conn, $mapAddress, true)) {
            throw new RuntimeException('Unable to queue the engagement location.');
        }

        $booked = 'booked';
        $update = $conn->prepare(
            'UPDATE booking_inquiries
             SET stage = ?, decline_reason = NULL, next_action = NULL,
                 next_action_due_date = NULL, converted_engagement_id = ?,
                 converted_by = ?, converted_at = UTC_TIMESTAMP(6),
                 stage_changed_by = ?, stage_changed_at = UTC_TIMESTAMP(6)
             WHERE id = ? AND updated_at = ?'
        );
        $update->bind_param(
            'siiiis', $booked, $engagementId, $userId, $userId, $inquiryId, $expectedVersion
        );
        $update->execute();
        if ($update->affected_rows !== 1) {
            $update->close();
            throw new InvalidArgumentException('That inquiry changed in another session. Reload before booking.');
        }
        $update->close();
        insertBookingInquiryStageHistory(
            $conn,
            $inquiryId,
            (string) $inquiry['stage'],
            'booked',
            'Converted to engagement #' . $engagementId . '.',
            $userId,
            $username
        );
        $conn->commit();
        return [
            'engagement_id' => $engagementId,
            'moved_task_count' => $moved,
            'checklist_count' => $checklist,
        ];
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

/** @return array<string, array{label: string, subject: string, body: string}> */
function bookingInquiryEmailTemplates(array $inquiry): array
{
    $title = trim((string) ($inquiry['title'] ?? 'your event inquiry'));
    $organization = trim((string) ($inquiry['organization_name'] ?? ''));
    $marker = applicationInquiryInboundMarker((int) $inquiry['id']);
    $date = bookingInquiryDateLabel($inquiry);
    $context = $organization !== '' ? $title . ' with ' . $organization : $title;
    return [
        'initial_response' => [
            'label' => 'Initial response',
            'subject' => 'Re: ' . $title . ' ' . $marker,
            'body' => "Hello,\n\nThank you for reaching out about {$context}. We have received your inquiry and will review the details. The dates currently noted are {$date}.\n\nThank you,",
        ],
        'request_details' => [
            'label' => 'Request Details',
            'subject' => 'Details needed: ' . $title . ' ' . $marker,
            'body' => "Hello,\n\nTo continue evaluating {$context}, please reply with the venue, audience, schedule, presentation expectations, and primary on-site contact.\n\nThank you,",
        ],
        'date_options' => [
            'label' => 'Date options',
            'subject' => 'Date options: ' . $title . ' ' . $marker,
            'body' => "Hello,\n\nWe are reviewing date options for {$context}. The current preferred range is {$date}. Please reply with any flexibility or alternate dates.\n\nThank you,",
        ],
        'proposal_follow_up' => [
            'label' => 'Proposal follow-up',
            'subject' => 'Following up: ' . $title . ' ' . $marker,
            'body' => "Hello,\n\nI am following up on the proposal for {$context}. Please let us know whether you have questions or are ready to confirm the booking details.\n\nThank you,",
        ],
        'custom' => [
            'label' => 'Custom message',
            'subject' => $marker,
            'body' => '',
        ],
    ];
}

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

function normalizeBookingInquiryEmailSubject(mixed $subject, int $inquiryId): string
{
    if (!is_scalar($subject) || preg_match('/[\r\n]/', (string) $subject) === 1) {
        throw new InvalidArgumentException('Enter a valid email subject.');
    }
    $subject = trim(preg_replace('/\s+/u', ' ', (string) $subject) ?? '');
    $prefixPattern = implode('|', array_map(
        static fn(mixed $prefix): string => preg_quote((string) $prefix, '/'),
        deploymentConfig()->list('inbound_email.accepted_marker_prefixes')
    ));
    $markerPattern = '/\[(?:' . $prefixPattern . ')-I#([^\]\r\n]*)\]/i';
    $engagementMarkerPattern = '/\[(?:' . $prefixPattern . ')#([^\]\r\n]*)\]/i';
    if (preg_match($engagementMarkerPattern, $subject) === 1) {
        throw new InvalidArgumentException(
            'Remove the Engagement routing marker before sending from an inquiry.'
        );
    }
    preg_match_all($markerPattern, $subject, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        if (preg_match('/\A([1-9][0-9]{0,9})(?:\.[A-Za-z0-9_-]+)?\z/D', (string) $match[1], $parts) !== 1
            || (int) $parts[1] !== $inquiryId
        ) {
            throw new InvalidArgumentException('Remove the routing marker for the other inquiry before sending.');
        }
    }
    $subject = trim(preg_replace($markerPattern, '', $subject) ?? $subject);
    $subject = trim($subject . ' ' . applicationInquiryInboundMarker($inquiryId));
    if ($subject === '' || mb_strlen($subject, 'UTF-8') > 255) {
        throw new InvalidArgumentException('Email subjects must be 255 characters or fewer, including the routing marker.');
    }
    return $subject;
}

function queueBookingInquiryEmail(
    mysqli $conn,
    array $inquiry,
    string $templateKey,
    mixed $subject,
    mixed $body,
    int $userId,
    string $username
): int {
    $transport = accountMailTransport();
    $inquiryId = (int) ($inquiry['id'] ?? 0);
    $contactId = (int) ($inquiry['primary_contact_id'] ?? 0);
    if ($inquiryId < 1 || $contactId < 1 || $userId < 1
        || !isset(bookingInquiryEmailTemplates($inquiry)[$templateKey])
    ) {
        throw new InvalidArgumentException('An active inquiry and primary contact are required.');
    }
    $subject = normalizeBookingInquiryEmailSubject($subject, $inquiryId);
    $body = normalizeEngagementEmailBody($body);
    $replyTo = engagementEmailReplyToAddress();
    $conn->begin_transaction();
    try {
        $locked = fetchBookingInquiry($conn, $inquiryId, true);
        if (!$locked || !in_array((string) $locked['stage'], bookingInquiryActiveStages(), true)) {
            throw new InvalidArgumentException('Email can be sent only for an active inquiry.');
        }
        if ((int) ($locked['primary_contact_id'] ?? 0) !== $contactId) {
            throw new InvalidArgumentException('The primary contact changed. Reload before sending.');
        }
        $contact = $conn->prepare(
            "SELECT organization_id,
                    TRIM(CONCAT_WS(' ', contact_first_name, contact_last_name)) AS contact_name,
                    contact_email
             FROM contacts WHERE id = ? AND is_deleted = 0"
        );
        $contact->bind_param('i', $contactId);
        $contact->execute();
        $activeContact = $contact->get_result()->fetch_assoc() ?: null;
        $contact->close();
        if ($activeContact === null) {
            throw new InvalidArgumentException('Select an active primary contact before sending.');
        }
        $email = normalizeAccountEmail($activeContact['contact_email'] ?? '');
        $name = trim((string) ($activeContact['contact_name'] ?? '')) ?: $email;
        $organizationId = (int) ($locked['organization_id'] ?? 0) ?: null;
        if ($organizationId !== null
            && (int) ($activeContact['organization_id'] ?? 0) !== $organizationId
        ) {
            throw new InvalidArgumentException(
                'The primary contact no longer belongs to the inquiry organization.'
            );
        }
        $message = $conn->prepare(
            'INSERT INTO engagement_email_messages
                (engagement_id, booking_inquiry_id, organization_id, template_key,
                 subject, body_text, reply_to, included_event_brief,
                 created_by, created_by_username_snapshot)
             VALUES (NULL, ?, ?, ?, ?, ?, ?, 0, ?, ?)'
        );
        $message->bind_param(
            'iissssis', $inquiryId, $organizationId, $templateKey, $subject,
            $body, $replyTo, $userId, $username
        );
        $message->execute();
        $messageId = (int) $conn->insert_id;
        $message->close();

        $payload = \Dnr\Security\ApplicationKey::seal(json_encode([
            'recipient' => $email,
            'subject' => $subject,
            'body' => $body,
            'reply_to' => $replyTo,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $roles = json_encode(['inquiry_primary_contact'], JSON_THROW_ON_ERROR);
        $delivery = $conn->prepare(
            'INSERT INTO engagement_email_deliveries
                (message_id, contact_id, recipient_name, recipient_email,
                 recipient_roles_json, payload_ciphertext)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $delivery->bind_param('iissss', $messageId, $contactId, $name, $email, $roles, $payload);
        $delivery->execute();
        $deliveryId = (int) $conn->insert_id;
        $delivery->close();

        $chronText = "OUTBOUND EMAIL\nTo: {$name} <{$email}>\nSubject: {$subject}\n\n{$body}\n\nDelivery record: Outbound message #{$messageId}";
        $chron = $conn->prepare(
            'INSERT INTO booking_inquiry_chron_entries
                (booking_inquiry_id, outbound_email_message_id, entry_text,
                 created_by, created_by_username_snapshot, updated_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $chron->bind_param('iisisi', $inquiryId, $messageId, $chronText, $userId, $username, $userId);
        $chron->execute();
        $chron->close();
        if ($organizationId !== null) {
            $orgChron = $conn->prepare(
                'INSERT INTO organization_chron_entries
                    (organization_id, outbound_email_message_id, entry_text,
                     created_by, created_by_username_snapshot)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $orgChron->bind_param('iisis', $organizationId, $messageId, $chronText, $userId, $username);
            $orgChron->execute();
            $orgChron->close();
        }
        $contactChron = $conn->prepare(
            'INSERT INTO contact_chron_entries
                (contact_id, outbound_email_message_id, entry_text,
                 created_by, created_by_username_snapshot)
             VALUES (?, ?, ?, ?, ?)'
        );
        $contactChron->bind_param('iisis', $contactId, $messageId, $chronText, $userId, $username);
        $contactChron->execute();
        $contactChron->close();
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
    if ($transport === 'log') {
        try {
            deliverApplicationEmail($email, $subject, $body, $replyTo);
            completeQueuedEngagementEmail($conn, $deliveryId);
        } catch (Throwable $exception) {
            failQueuedEngagementEmail($conn, $deliveryId, 1, $exception, true);
        }
    }
    return $messageId;
}

/** @return list<array<string, mixed>> */
function fetchBookingInquiryEmailMessages(mysqli $conn, int $inquiryId, int $limit = 20): array
{
    $limit = max(1, min(100, $limit));
    $stmt = $conn->prepare(
        "SELECT message.id, message.template_key, message.subject,
                message.body_text, message.created_at,
                COALESCE(creator.username, message.created_by_username_snapshot) AS created_by_username,
                delivery.recipient_name, delivery.recipient_email,
                delivery.status, delivery.sent_at, delivery.last_error
         FROM engagement_email_messages message
         LEFT JOIN users creator ON creator.id = message.created_by
         LEFT JOIN engagement_email_deliveries delivery ON delivery.message_id = message.id
         WHERE message.booking_inquiry_id = ?
         ORDER BY message.created_at DESC, message.id DESC LIMIT {$limit}"
    );
    $stmt->bind_param('i', $inquiryId);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $messages;
}
