<?php

declare(strict_types=1);

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
            'label' => 'Initial Response',
            'subject' => 'Re: ' . $title . ' ' . $marker,
            'body' => "Hello,\n\nThank you for reaching out about {$context}. We have received your inquiry and will review the details. The dates currently noted are {$date}.\n\nThank you,",
        ],
        'request_details' => [
            'label' => 'Request Details',
            'subject' => 'Details needed: ' . $title . ' ' . $marker,
            'body' => "Hello,\n\nTo continue evaluating {$context}, please reply with the venue, audience, schedule, presentation expectations, and primary on-site contact.\n\nThank you,",
        ],
        'date_options' => [
            'label' => 'Date Options',
            'subject' => 'Date options: ' . $title . ' ' . $marker,
            'body' => "Hello,\n\nWe are reviewing date options for {$context}. The current preferred range is {$date}. Please reply with any flexibility or alternate dates.\n\nThank you,",
        ],
        'proposal_follow_up' => [
            'label' => 'Proposal Follow-Up',
            'subject' => 'Following up: ' . $title . ' ' . $marker,
            'body' => "Hello,\n\nI am following up on the proposal for {$context}. Please let us know whether you have questions or are ready to confirm the booking details.\n\nThank you,",
        ],
        'custom' => [
            'label' => 'Custom Message',
            'subject' => $marker,
            'body' => '',
        ],
    ];
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
    string $username,
    ?string $followUpDate = null
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
            && !contactBelongsToOrganization($conn, $contactId, $organizationId)
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
        require_once dirname(__DIR__, 2) . '/workflow_task_helpers.php';
        createEmailFollowUpTask($conn, $messageId, 'inquiry', $inquiryId, $userId, $followUpDate);
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
