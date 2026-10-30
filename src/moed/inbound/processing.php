<?php

declare(strict_types=1);

/** @param array<string, mixed> $message */
function formatInboundEmailChronEntry(array $message): string
{
    $senderAddress = (string) ($message['sender_address'] ?? '');
    $senderName = trim((string) ($message['sender_name'] ?? ''));
    $from = $senderName !== '' ? "{$senderName} <{$senderAddress}>" : $senderAddress;
    $to = implode(', ', inboundEmailDecodeAddressList($message['to_addresses'] ?? '[]'));
    $cc = implode(', ', inboundEmailDecodeAddressList($message['cc_addresses'] ?? '[]'));
    $attachments = inboundEmailDecodeStringList($message['attachment_names'] ?? '[]');

    $brandName = applicationBrandName();
    $lines = ['Email captured by ' . $brandName, '', 'From: ' . $from];
    if ($to !== '') {
        $lines[] = 'To: ' . $to;
    }
    if ($cc !== '') {
        $lines[] = 'Cc: ' . $cc;
    }
    $lines[] = 'Subject: ' . ((string) ($message['subject'] ?? '') ?: '(no subject)');
    if (!empty($message['sent_at'])) {
        $lines[] = 'Sent: ' . formatUserTimestamp($message['sent_at'], 'Y-m-d H:i:s T');
    }
    $lines[] = 'Received by ' . $brandName . ': ' . formatUserTimestamp($message['received_at'] ?? '', 'Y-m-d H:i:s T');
    if ($attachments) {
        $lines[] = 'Attachments: ' . implode(', ', $attachments);
    }
    $lines[] = '';
    $prefix = implode("\n", $lines);
    $body = trim((string) ($message['body_text'] ?? ''));
    if ($body === '') {
        $body = '[No plain-text message body was available.]';
    }
    $suffix = "\n\n[Email source: inbound message #" . (int) ($message['id'] ?? 0) . ']';
    $available = INBOUND_EMAIL_MAX_CHRON_CHARACTERS
        - mb_strlen($prefix . $suffix, 'UTF-8') - 2;
    if (mb_strlen($body, 'UTF-8') > $available) {
        $marker = "\n\n[Body truncated in Chron; review the inbound message for the retained source text.]";
        $body = mb_substr($body, 0, max(0, $available - mb_strlen($marker, 'UTF-8')), 'UTF-8')
            . $marker;
    }
    return $prefix . $body . $suffix;
}

/** @param list<int> $ids */
function inboundEmailActiveTargetIds(mysqli $conn, string $type, array $ids): array
{
    $ids = array_values(array_unique(array_filter(
        array_map('intval', $ids),
        static fn(int $id): bool => $id > 0
    )));
    if (!$ids) {
        return [];
    }
    if ($type === 'contact') {
        $table = 'contacts';
    } elseif ($type === 'organization') {
        $table = 'organizations';
    } elseif ($type === 'engagement') {
        $table = 'engagements';
    } elseif ($type === 'inquiry') {
        $table = 'booking_inquiries';
    } else {
        throw new InvalidArgumentException('Invalid inbound email target type.');
    }
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $sql = $type === 'engagement'
        ? "SELECT target.id FROM engagements target
           INNER JOIN organizations organization ON organization.id = target.organization_id
           WHERE target.is_deleted = 0 AND organization.is_deleted = 0
             AND target.id IN ({$placeholders})"
        : ($type === 'inquiry'
            ? "SELECT id FROM booking_inquiries
               WHERE stage IN ('new', 'contacted', 'qualified', 'awaiting_details', 'proposal_sent')
                 AND id IN ({$placeholders})"
            : "SELECT id FROM {$table} WHERE is_deleted = 0 AND id IN ({$placeholders})");
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare inbound target validation.');
    }
    $types = str_repeat('i', count($ids));
    $bind = [$types];
    foreach ($ids as &$id) {
        $bind[] = &$id;
    }
    unset($id);
    $stmt->bind_param(...$bind);
    $stmt->execute();
    $valid = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
    $stmt->close();
    sort($valid);
    return $valid;
}

function requireInboundEmailProcessableStatus(string $status, bool $manual): void
{
    if (in_array($status, ['processed', 'rejected'], true)) {
        throw new InvalidArgumentException(
            'That inbound message has already been ' . $status . '.'
        );
    }
    if (!in_array($status, ['pending', 'processing', 'review', 'failed'], true)) {
        throw new InvalidArgumentException('That inbound message cannot be processed.');
    }
    if ($manual && $status === 'processing') {
        throw new InvalidArgumentException(
            'That inbound message is currently being processed. Try again shortly.'
        );
    }
}

/**
 * @param list<int>|null $contactIds
 * @param list<int>|null $organizationIds
 * @param list<int>|null $engagementIds
 * @param list<int>|null $inquiryIds
 */
function processInboundEmailMessage(
    mysqli $conn,
    int $messageId,
    ?array $contactIds = null,
    ?array $organizationIds = null,
    ?int $processedBy = null,
    ?array $engagementIds = null,
    ?array $inquiryIds = null
): string {
    if ($messageId < 1) {
        throw new InvalidArgumentException('Select a valid inbound message.');
    }
    $manual = $contactIds !== null || $organizationIds !== null
        || $engagementIds !== null || $inquiryIds !== null;
    if (!$manual) {
        require_once dirname(__DIR__, 2).'/audience_inbox_runtime.php';
        $inboxResult = processAudienceInboxInbound($conn,$messageId);
        if ($inboxResult !== null) return $inboxResult;
    }
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT * FROM inbound_email_messages WHERE id = ? FOR UPDATE');
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare inbound message processing.');
        }
        $stmt->bind_param('i', $messageId);
        $stmt->execute();
        $message = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$message) {
            throw new InvalidArgumentException('That inbound message is no longer available.');
        }
        $messageStatus = (string) ($message['status'] ?? '');
        requireInboundEmailProcessableStatus($messageStatus, $manual);

        $routing = routeInboundEmailMessage($conn, $message, !$manual);
        if (!$manual && !$routing['automatic']) {
            $routingJson = inboundEmailJson($routing);
            $reviewReason = implode(' ', $routing['reasons']);
            $reviewReason = mb_substr($reviewReason, 0, 255, 'UTF-8');
            $update = $conn->prepare(
                "UPDATE inbound_email_messages
                 SET status = 'review', routing_details = ?, review_reason = ?,
                     processing_started_at = NULL, last_error = NULL
                 WHERE id = ?"
            );
            if (!$update) {
                throw new RuntimeException('Unable to prepare inbound review routing.');
            }
            $update->bind_param('ssi', $routingJson, $reviewReason, $messageId);
            $update->execute();
            $update->close();
            $conn->commit();
            return 'review';
        }

        if ($manual) {
            $candidateContactIds = array_map('intval', array_column($routing['contacts'], 'id'));
            $candidateOrganizationIds = array_map('intval', array_column($routing['organizations'], 'id'));
            $candidateInquiryIds = array_map('intval', array_column($routing['inquiries'], 'id'));
            $contactIds = array_values(array_intersect(
                array_map('intval', $contactIds ?? []),
                $candidateContactIds
            ));
            $organizationIds = array_values(array_intersect(
                array_map('intval', $organizationIds ?? []),
                $candidateOrganizationIds
            ));
            $engagementIds = array_values(array_unique(array_map('intval', $engagementIds ?? [])));
            if (count($engagementIds) > 1) {
                throw new InvalidArgumentException('Select no more than one Engagement.');
            }
            $inquiryIds = array_values(array_intersect(
                array_map('intval', $inquiryIds ?? []),
                $candidateInquiryIds
            ));
            if (count($inquiryIds) > 1) {
                throw new InvalidArgumentException('Select no more than one Inquiry.');
            }
        } elseif (($routing['authoritative_engagement'] || $routing['authoritative_inquiry'])
            && $routing['reasons'] !== []
        ) {
            $contactIds = [];
            $organizationIds = [];
            $engagementIds = array_map('intval', array_column($routing['engagements'], 'id'));
            $inquiryIds = array_map('intval', array_column($routing['inquiries'], 'id'));
        } else {
            $contactIds = array_map('intval', array_column($routing['contacts'], 'id'));
            $organizationIds = array_map('intval', array_column($routing['organizations'], 'id'));
            $engagementIds = array_map('intval', array_column($routing['engagements'], 'id'));
            $inquiryIds = array_map('intval', array_column($routing['inquiries'], 'id'));
        }
        $contactIds = inboundEmailActiveTargetIds($conn, 'contact', $contactIds);
        $organizationIds = inboundEmailActiveTargetIds($conn, 'organization', $organizationIds);
        $engagementIds = inboundEmailActiveTargetIds($conn, 'engagement', $engagementIds);
        $inquiryIds = inboundEmailActiveTargetIds($conn, 'inquiry', $inquiryIds);
        if (!$contactIds && !$organizationIds && !$engagementIds && !$inquiryIds) {
            throw new InvalidArgumentException(
                'Select at least one active Contact, Organization, Inquiry, or Engagement.'
            );
        }

        $entryText = formatInboundEmailChronEntry($message);
        // Visible From headers are routing hints, not authenticated DNR identities.
        $creatorId = null;
        $creatorName = 'Email Gateway';
        $createdAt = (string) $message['received_at'];

        // The source row remains locked for the entire transaction, serializing
        // filing/retries. Existing target entries are preserved using SELECT and
        // INSERT only; the ingest worker deliberately has no Chron UPDATE grant.
        $contactInsert = $conn->prepare(
            'INSERT INTO contact_chron_entries
                (contact_id, inbound_email_message_id, entry_text, created_by,
                 created_by_username_snapshot, updated_by, created_at, updated_at)
             SELECT ?, ?, ?, ?, ?, ?, ?, ?
             WHERE NOT EXISTS (
                 SELECT 1 FROM contact_chron_entries
                 WHERE inbound_email_message_id = ? AND contact_id = ?
             )'
        );
        if (!$contactInsert) {
            throw new RuntimeException('Unable to prepare Contact Chron email routing.');
        }
        foreach ($contactIds as $contactId) {
            $contactInsert->bind_param(
                'iisisissii',
                $contactId,
                $messageId,
                $entryText,
                $creatorId,
                $creatorName,
                $creatorId,
                $createdAt,
                $createdAt,
                $messageId,
                $contactId
            );
            $contactInsert->execute();
        }
        $contactInsert->close();

        $organizationInsert = $conn->prepare(
            'INSERT INTO organization_chron_entries
                (organization_id, inbound_email_message_id, entry_text, created_by,
                 created_by_username_snapshot, updated_by, created_at, updated_at)
             SELECT ?, ?, ?, ?, ?, ?, ?, ?
             WHERE NOT EXISTS (
                 SELECT 1 FROM organization_chron_entries
                 WHERE inbound_email_message_id = ? AND organization_id = ?
             )'
        );
        if (!$organizationInsert) {
            throw new RuntimeException('Unable to prepare Organization Chron email routing.');
        }
        foreach ($organizationIds as $organizationId) {
            $organizationInsert->bind_param(
                'iisisissii',
                $organizationId,
                $messageId,
                $entryText,
                $creatorId,
                $creatorName,
                $creatorId,
                $createdAt,
                $createdAt,
                $messageId,
                $organizationId
            );
            $organizationInsert->execute();
        }
        $organizationInsert->close();

        $engagementInsert = $conn->prepare(
            'INSERT INTO engagement_chron_entries
                (engagement_id, inbound_email_message_id, entry_text, created_by,
                 created_by_username_snapshot, updated_by, created_at, updated_at)
             SELECT ?, ?, ?, ?, ?, ?, ?, ?
             WHERE NOT EXISTS (
                 SELECT 1 FROM engagement_chron_entries
                 WHERE inbound_email_message_id = ? AND engagement_id = ?
             )'
        );
        if (!$engagementInsert) {
            throw new RuntimeException('Unable to prepare Engagement Chron email routing.');
        }
        foreach ($engagementIds as $engagementId) {
            $engagementInsert->bind_param(
                'iisisissii',
                $engagementId,
                $messageId,
                $entryText,
                $creatorId,
                $creatorName,
                $creatorId,
                $createdAt,
                $createdAt,
                $messageId,
                $engagementId
            );
            $engagementInsert->execute();
        }
        $engagementInsert->close();

        $inquiryInsert = $conn->prepare(
            'INSERT INTO booking_inquiry_chron_entries
                (booking_inquiry_id, inbound_email_message_id, entry_text, created_by,
                 created_by_username_snapshot, updated_by, created_at, updated_at)
             SELECT ?, ?, ?, ?, ?, ?, ?, ?
             WHERE NOT EXISTS (
                 SELECT 1 FROM booking_inquiry_chron_entries
                 WHERE inbound_email_message_id = ? AND booking_inquiry_id = ?
             )'
        );
        if (!$inquiryInsert) {
            throw new RuntimeException('Unable to prepare Inquiry Chron email routing.');
        }
        foreach ($inquiryIds as $inquiryId) {
            $inquiryInsert->bind_param(
                'iisisissii',
                $inquiryId,
                $messageId,
                $entryText,
                $creatorId,
                $creatorName,
                $creatorId,
                $createdAt,
                $createdAt,
                $messageId,
                $inquiryId
            );
            $inquiryInsert->execute();
        }
        $inquiryInsert->close();

        $routing['applied_contacts'] = $contactIds;
        $routing['applied_organizations'] = $organizationIds;
        $routing['applied_engagements'] = $engagementIds;
        $routing['applied_inquiries'] = $inquiryIds;
        $routingJson = inboundEmailJson($routing);
        $update = $conn->prepare(
            "UPDATE inbound_email_messages
             SET status = 'processed', routing_details = ?, review_reason = NULL,
                 processing_started_at = NULL, last_error = NULL,
                 processed_by = ?, processed_at = UTC_TIMESTAMP()
             WHERE id = ?"
        );
        if (!$update) {
            throw new RuntimeException('Unable to prepare inbound completion.');
        }
        $update->bind_param('sii', $routingJson, $processedBy, $messageId);
        $update->execute();
        $update->close();
        queueMattermostReplyNotifications(
            $conn,
            $messageId,
            $engagementIds,
            (string) $message['sender_address']
        );
        $conn->commit();
        return 'processed';
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
