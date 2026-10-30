<?php

declare(strict_types=1);

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
    if ($organizationId > 0 && !contactBelongsToOrganization($conn, $contactId, $organizationId)) {
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
    ?string $initialChron = null,
    ?string $operationToken = null
): int {
    $conn->begin_transaction();
    try {
        if ($operationToken !== null) {
            require_once dirname(__DIR__, 2) . '/record_creation_helpers.php';
            $existing = beginRecordCreation($conn, $userId, $operationToken, 'inquiry');
            if ($existing !== null) { $conn->commit(); return $existing; }
        }
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
        require_once dirname(__DIR__, 2) . '/workflow_task_helpers.php';
        saveInquiryNextActionTask($conn, $inquiryId, $data, null, $userId);
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
        if ($operationToken !== null) completeRecordCreation($conn, $userId, $operationToken, $inquiryId);
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
        if ((string) $current['stage'] === 'booked' || !empty($current['archived_at'])) {
            throw new InvalidArgumentException('Booked and archived inquiries are preserved as read-only source records.');
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
        require_once dirname(__DIR__, 2) . '/workflow_task_helpers.php';
        saveInquiryNextActionTask($conn, $inquiryId, $data, (int) ($current['next_action_task_id'] ?? 0), (int) ($_SESSION['user_id'] ?? $current['created_by']));
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
        if ((string) $inquiry['stage'] === 'booked' || !empty($inquiry['archived_at'])) {
            throw new InvalidArgumentException('Booked and archived inquiries are read-only.');
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
