<?php

declare(strict_types=1);

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

/** Defaults apply only when opening the review; an omitted POST checkbox list means none. */
function bookingInquirySelectedTaskIds(array $tasks, array $input, bool $submitted): array
{
    if (!$submitted) return array_map(static fn(array $task): int => (int) $task['id'], $tasks);
    $selected = is_array($input['task_ids'] ?? null) ? $input['task_ids'] : [];
    $available = array_fill_keys(array_map(static fn(array $task): int => (int) $task['id'], $tasks), true);
    return array_values(array_unique(array_filter(array_map(static fn($id): int => is_scalar($id) && ctype_digit((string) $id) ? (int) $id : 0, $selected), static fn(int $id): bool => isset($available[$id]))));
}

function bookingInquiryNextActionResolution(array $inquiry, string $decision, string $reason = ''): string
{
    $action = trim((string) ($inquiry['next_action'] ?? ''));
    if ($action === '') return '';
    if (!in_array($decision, ['carry_forward', 'resolved'], true)) {
        throw new InvalidArgumentException('Choose whether to carry forward or resolve the inquiry next action.');
    }
    $reason = trim($reason);
    if ($decision === 'resolved' && $reason === '') {
        throw new InvalidArgumentException('Explain why the next action is resolved or no longer needed.');
    }
    if (mb_strlen($reason, 'UTF-8') > 1000) throw new InvalidArgumentException('Use 1,000 characters or fewer for the resolution.');
    return 'Next action ' . ($decision === 'carry_forward' ? 'carried forward as an engagement task' : 'resolved at booking')
        . ': ' . $action . (!empty($inquiry['next_action_due_date']) ? ' (due ' . $inquiry['next_action_due_date'] . ')' : '')
        . ($reason !== '' ? "\nResolution: " . $reason : '');
}

/**
 * @param list<int> $taskIds
 * @return array{engagement_id: int, moved_task_count: int, checklist_count: int, next_action_task_id: int|null}
 */
function convertBookingInquiry(
    mysqli $conn,
    int $inquiryId,
    string $expectedVersion,
    bool $acknowledgeConflicts,
    array $taskIds,
    int $userId,
    string $username,
    string $nextActionDecision = '',
    string $nextActionReason = ''
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
        $nextActionResolution = bookingInquiryNextActionResolution($inquiry, $nextActionDecision, $nextActionReason);
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
                'SELECT c.id FROM contacts c
                 INNER JOIN contact_organizations co ON co.contact_id = c.id
                 WHERE c.id = ? AND co.organization_id = ? AND c.is_deleted = 0'
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

        $nextActionTaskId = null;
        if ($nextActionResolution !== '' && $nextActionDecision === 'carry_forward') {
            if (!empty($inquiry['next_action_task_id'])) {
                $nextActionTaskId = (int) $inquiry['next_action_task_id'];
                $conn->execute_query("UPDATE follow_up_tasks SET subject_type='engagement', engagement_id=?, inquiry_id=NULL WHERE id=?", [$engagementId, $nextActionTaskId]);
            } else $nextActionTaskId = insertFollowUpTask($conn, [
                'title' => (string) $inquiry['next_action'], 'details' => 'Carried forward from inquiry #' . $inquiryId,
                'status' => 'open', 'priority' => (string) $inquiry['priority'],
                'due_date' => $inquiry['next_action_due_date'], 'waiting_on' => null,
                'subject_type' => 'engagement', 'engagement_id' => $engagementId,
                'organization_id' => null, 'contact_id' => null, 'inquiry_id' => null,
                'assigned_to' => $callerId,
            ], $userId);
        }
        if ($nextActionDecision === 'resolved' && !empty($inquiry['next_action_task_id'])) {
            $conn->execute_query("UPDATE follow_up_tasks SET status='completed', waiting_on=NULL, completed_by=?, completed_at=UTC_TIMESTAMP() WHERE id=? AND inquiry_id=? AND status IN ('open','in_progress','waiting')", [$userId,$inquiry['next_action_task_id'],$inquiryId]);
        }
        $chronText = 'BOOKED FROM INQUIRY #' . $inquiryId
            . "\nThe inquiry remains the source record for pre-booking history."
            . ($nextActionResolution !== '' ? "\n\n" . $nextActionResolution : '')
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
                   AND status IN ('open', 'in_progress', 'waiting') AND is_archived = 0
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
            $moved = $move->affected_rows + ($nextActionTaskId !== null && in_array($nextActionTaskId, $taskIds, true) ? 1 : 0);
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

        // The locked inquiry's task summary may have changed as its linked task moved.
        $expectedVersion = (string) fetchBookingInquiry($conn, $inquiryId, true)['updated_at'];
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
            'Converted to engagement #' . $engagementId . '.' . ($nextActionResolution !== '' ? "\n" . $nextActionResolution : ''),
            $userId,
            $username
        );
        $conn->commit();
        return [
            'engagement_id' => $engagementId,
            'moved_task_count' => $moved,
            'checklist_count' => $checklist,
            'next_action_task_id' => $nextActionTaskId,
        ];
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
