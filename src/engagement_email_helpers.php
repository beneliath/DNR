<?php

declare(strict_types=1);

require_once __DIR__ . '/application_runtime.php';
require_once __DIR__ . '/email_helpers.php';
require_once __DIR__ . '/engagement_contact_helpers.php';
require_once __DIR__ . '/presentation_helpers.php';

/** @return array<string, array{label: string, suggested_roles: list<string>}> */
function engagementEmailTemplateDefinitions(): array
{
    return [
        'booking_confirmation' => [
            'label' => 'Booking confirmation',
            'suggested_roles' => ['primary_host'],
        ],
        'travel_lodging' => [
            'label' => 'Travel and lodging request',
            'suggested_roles' => ['primary_host', 'travel'],
        ],
        'final_reconfirmation' => [
            'label' => 'Final-detail reconfirmation',
            'suggested_roles' => ['primary_host', 'on_site_contact'],
        ],
        'presentation_schedule' => [
            'label' => 'Presentation schedule',
            'suggested_roles' => ['primary_host', 'on_site_contact', 'materials'],
        ],
        'post_event_thanks' => [
            'label' => 'Post-event thank-you',
            'suggested_roles' => ['primary_host', 'on_site_contact'],
        ],
        'custom' => [
            'label' => 'Custom message',
            'suggested_roles' => [],
        ],
    ];
}

function engagementEmailReplyToAddress(): string
{
    $configured = trim((string) (getenv('DNR_INBOUND_ADDRESS') ?: ''));
    if ($configured === '') {
        return '';
    }
    try {
        return normalizeAccountEmail($configured);
    } catch (InvalidArgumentException $exception) {
        throw new RuntimeException('The inbound reply address is invalid.', 0, $exception);
    }
}

/** @param array<string, mixed> $engagement */
function engagementEmailEventLabel(array $engagement): string
{
    $title = trim((string) ($engagement['event_title'] ?? ''));
    return $title !== '' ? $title : trim((string) ($engagement['organization_name'] ?? 'Engagement'));
}

/** @param array<string, mixed> $engagement */
function engagementEmailDateLabel(array $engagement): string
{
    $start = trim((string) ($engagement['event_start_date'] ?? ''));
    $end = trim((string) ($engagement['event_end_date'] ?? ''));
    if ($start === '') {
        return 'Date to be confirmed';
    }
    if ($end === '' || $end === $start) {
        return $start;
    }
    return $start . ' through ' . $end;
}

/** @param array<string, mixed> $engagement */
function engagementEmailLocationLabel(array $engagement): string
{
    $lines = [];
    foreach (['event_address_line_1', 'event_address_line_2'] as $key) {
        $value = trim((string) ($engagement[$key] ?? ''));
        if ($value !== '') {
            $lines[] = $value;
        }
    }
    $cityLine = trim(implode(', ', array_filter([
        trim((string) ($engagement['event_city'] ?? '')),
        trim((string) ($engagement['event_state'] ?? '')),
    ])));
    $postalCode = trim((string) ($engagement['event_zipcode'] ?? ''));
    if ($postalCode !== '') {
        $cityLine = trim($cityLine . ' ' . $postalCode);
    }
    if ($cityLine !== '') {
        $lines[] = $cityLine;
    }
    $country = trim((string) ($engagement['event_country'] ?? ''));
    if ($country !== '') {
        $lines[] = addressCountryName($country);
    }
    return implode(', ', $lines);
}

/**
 * The externally shareable brief deliberately excludes Chron, internal notes,
 * compensation, giving, and financial-closeout data.
 *
 * @param array<string, mixed> $engagement
 * @param list<array<string, mixed>> $presentations
 */
function engagementEmailSafeEventBrief(array $engagement, array $presentations): string
{
    $lines = [
        'EVENT BRIEF',
        'Event: ' . engagementEmailEventLabel($engagement),
        'Organization: ' . trim((string) ($engagement['organization_name'] ?? '')),
        'Dates: ' . engagementEmailDateLabel($engagement),
    ];
    $location = engagementEmailLocationLabel($engagement);
    if ($location !== '') {
        $lines[] = 'Location: ' . $location;
    }
    $description = trim((string) ($engagement['event_description'] ?? ''));
    if ($description !== '') {
        $lines[] = 'Description: ' . $description;
    }
    if ($presentations !== []) {
        $lines[] = '';
        $lines[] = 'PRESENTATIONS';
        foreach ($presentations as $presentation) {
            $schedule = trim(implode(' ', array_filter([
                trim((string) ($presentation['presentation_date'] ?? '')),
                formatPresentationTime($presentation['presentation_time'] ?? ''),
            ])));
            $duration = (int) ($presentation['duration_minutes'] ?? 0);
            if ($duration > 0) {
                $schedule = trim($schedule . ' · ' . $duration . ' minutes');
            }
            $line = '- ' . trim((string) ($presentation['topic_title'] ?? 'Presentation'));
            if ($schedule !== '') {
                $line .= ' — ' . $schedule;
            }
            $speaker = trim((string) ($presentation['speaker_name'] ?? ''));
            if ($speaker !== '') {
                $line .= ' — ' . $speaker;
            }
            $lines[] = $line;
        }
    }
    return implode("\n", $lines);
}

/**
 * @param array<string, mixed> $engagement
 * @param list<array<string, mixed>> $presentations
 * @return array<string, array{label: string, subject: string, body: string, suggested_roles: list<string>}>
 */
function engagementEmailTemplates(array $engagement, array $presentations): array
{
    $definitions = engagementEmailTemplateDefinitions();
    $event = engagementEmailEventLabel($engagement);
    $organization = trim((string) ($engagement['organization_name'] ?? ''));
    $dates = engagementEmailDateLabel($engagement);
    $marker = applicationInboundMarker((int) ($engagement['id'] ?? 0));
    $presentationLines = [];
    foreach ($presentations as $presentation) {
        $schedule = trim(implode(' at ', array_filter([
            trim((string) ($presentation['presentation_date'] ?? '')),
            formatPresentationTime($presentation['presentation_time'] ?? ''),
        ])));
        $presentationLines[] = '- ' . trim((string) ($presentation['topic_title'] ?? 'Presentation'))
            . ($schedule !== '' ? ' — ' . $schedule : '');
    }
    $schedule = $presentationLines !== []
        ? implode("\n", $presentationLines)
        : '- Presentation schedule to be confirmed';

    $content = [
        'booking_confirmation' => [
            'subject' => 'Confirmation: ' . $event . ' ' . $marker,
            'body' => "Hello,\n\nThis message confirms {$event} with {$organization} on {$dates}. Please reply with any corrections or outstanding details.\n\nThank you,",
        ],
        'travel_lodging' => [
            'subject' => 'Travel and lodging details: ' . $event . ' ' . $marker,
            'body' => "Hello,\n\nWe are preparing travel and lodging for {$event} on {$dates}. Please send the confirmed transportation, lodging, arrival, and local-contact details when available.\n\nThank you,",
        ],
        'final_reconfirmation' => [
            'subject' => 'Final details: ' . $event . ' ' . $marker,
            'body' => "Hello,\n\nWe are reconfirming the final details for {$event} on {$dates}. Please review the schedule, venue, on-site contact, travel, lodging, and materials arrangements and reply with any changes.\n\nThank you,",
        ],
        'presentation_schedule' => [
            'subject' => 'Presentation schedule: ' . $event . ' ' . $marker,
            'body' => "Hello,\n\nHere is the current presentation schedule for {$event}:\n\n{$schedule}\n\nPlease reply with any corrections.\n\nThank you,",
        ],
        'post_event_thanks' => [
            'subject' => 'Thank you: ' . $event . ' ' . $marker,
            'body' => "Hello,\n\nThank you for hosting and supporting {$event}. We appreciate the time, preparation, and hospitality that made the engagement possible.\n\nWith gratitude,",
        ],
        'custom' => [
            'subject' => $marker,
            'body' => '',
        ],
    ];

    $templates = [];
    foreach ($definitions as $key => $definition) {
        $templates[$key] = $definition + $content[$key];
    }
    return $templates;
}

function normalizeEngagementEmailSubject(mixed $subject, int $engagementId): string
{
    if (!is_scalar($subject)) {
        throw new InvalidArgumentException('Enter a valid email subject.');
    }
    $subject = (string) $subject;
    if (preg_match('/[\r\n]/', $subject) === 1) {
        throw new InvalidArgumentException('The email subject contains invalid characters.');
    }
    $subject = trim(preg_replace('/\s+/u', ' ', $subject) ?? '');
    if ($subject === '') {
        throw new InvalidArgumentException('Enter an email subject.');
    }
    $prefixPattern = implode('|', array_map(
        static fn(mixed $prefix): string => preg_quote((string) $prefix, '/'),
        deploymentConfig()->list('inbound_email.accepted_marker_prefixes')
    ));
    $markerPattern = '/\[(?:' . $prefixPattern . ')#([^\]\r\n]*)\]/i';
    preg_match_all($markerPattern, $subject, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        if (preg_match('/\A([1-9][0-9]{0,9})(?:\.[A-Za-z0-9_-]+)?\z/D', (string) $match[1], $parts) !== 1) {
            throw new InvalidArgumentException('Remove the invalid routing marker before sending.');
        }
        if ((int) $parts[1] !== $engagementId) {
            throw new InvalidArgumentException(
                'Remove the routing marker for the other engagement before sending.'
            );
        }
    }
    $subject = trim(preg_replace($markerPattern, '', $subject) ?? $subject);
    $marker = applicationInboundMarker($engagementId);
    $subject = trim($subject . ' ' . $marker);
    if (mb_strlen($subject, 'UTF-8') > 255) {
        throw new InvalidArgumentException('Email subjects must be 255 characters or fewer, including the routing marker.');
    }
    return $subject;
}

function normalizeEngagementEmailBody(mixed $body): string
{
    if (!is_scalar($body)) {
        throw new InvalidArgumentException('Enter a valid email message.');
    }
    $body = trim(str_replace(["\r\n", "\r"], "\n", (string) $body));
    if ($body === '') {
        throw new InvalidArgumentException('Enter an email message.');
    }
    if (mb_strlen($body, 'UTF-8') > 100000) {
        throw new InvalidArgumentException('Email messages must be 100,000 characters or fewer.');
    }
    return $body;
}

/** @return list<int> */
function normalizeEngagementEmailContactIds(mixed $submitted): array
{
    if (!is_array($submitted) || $submitted === [] || count($submitted) > 25) {
        throw new InvalidArgumentException('Select between one and 25 event contacts.');
    }
    $ids = [];
    foreach ($submitted as $value) {
        if (!is_scalar($value) || !ctype_digit(trim((string) $value))) {
            throw new InvalidArgumentException('Select valid event contacts.');
        }
        $id = (int) $value;
        if ($id < 1) {
            throw new InvalidArgumentException('Select valid event contacts.');
        }
        $ids[$id] = $id;
    }
    return array_values($ids);
}

/**
 * @param list<array<string, mixed>> $contacts
 * @param list<int> $selectedIds
 * @return array{contacts: list<array<string, mixed>>, deliveries: list<array<string, mixed>>}
 */
function engagementEmailResolveRecipients(array $contacts, array $selectedIds): array
{
    $available = [];
    foreach ($contacts as $contact) {
        $available[(int) $contact['id']] = $contact;
    }
    $selected = [];
    $deliveriesByAddress = [];
    foreach ($selectedIds as $contactId) {
        if (!isset($available[$contactId])) {
            throw new InvalidArgumentException('Select only active contacts assigned to this engagement.');
        }
        $contact = $available[$contactId];
        $email = normalizeAccountEmail($contact['contact_email'] ?? '');
        $name = trim(
            trim((string) ($contact['contact_first_name'] ?? '')) . ' '
            . trim((string) ($contact['contact_last_name'] ?? ''))
        );
        $roles = array_values(array_unique(array_map(
            static fn(mixed $role): string => trim((string) $role),
            (array) ($contact['engagement_contact_roles'] ?? [])
        )));
        $contact['normalized_email'] = $email;
        $contact['display_name'] = $name !== '' ? $name : $email;
        $selected[] = $contact;
        if (!isset($deliveriesByAddress[$email])) {
            $deliveriesByAddress[$email] = [
                'contact_id' => $contactId,
                'recipient_email' => $email,
                'recipient_names' => [],
                'recipient_roles' => [],
            ];
        }
        $deliveriesByAddress[$email]['recipient_names'][$contactId] = $contact['display_name'];
        foreach ($roles as $role) {
            if ($role !== '') {
                $deliveriesByAddress[$email]['recipient_roles'][$role] = $role;
            }
        }
    }
    $deliveries = [];
    foreach ($deliveriesByAddress as $delivery) {
        $deliveries[] = [
            'contact_id' => (int) $delivery['contact_id'],
            'recipient_email' => (string) $delivery['recipient_email'],
            'recipient_name' => mb_substr(implode(' / ', $delivery['recipient_names']), 0, 255, 'UTF-8'),
            'recipient_roles' => array_values($delivery['recipient_roles']),
        ];
    }
    return ['contacts' => $selected, 'deliveries' => $deliveries];
}

/**
 * @param list<array<string, mixed>> $contacts
 */
function engagementEmailChronText(
    int $messageId,
    array $contacts,
    string $subject,
    string $body
): string {
    $recipients = [];
    foreach ($contacts as $contact) {
        $recipients[] = (string) $contact['display_name'] . ' <' . (string) $contact['normalized_email'] . '>';
    }
    return "OUTBOUND EMAIL\n"
        . 'To: ' . implode(', ', $recipients) . "\n"
        . 'Subject: ' . $subject . "\n\n"
        . $body . "\n\n"
        . 'Delivery record: Outbound message #' . $messageId;
}

/**
 * @param array<string, mixed> $engagement
 * @param list<array<string, mixed>> $presentations
 * @param list<int> $contactIds
 */
function queueEngagementEmail(
    mysqli $conn,
    array $engagement,
    array $presentations,
    array $contactIds,
    string $templateKey,
    mixed $subject,
    mixed $body,
    bool $includeEventBrief,
    int $createdBy,
    string $createdByUsername,
    string $mattermostInstanceId = '',
    string $mattermostIdempotencyKey = ''
): int {
    $transport = accountMailTransport();
    $engagementId = (int) ($engagement['id'] ?? 0);
    $organizationId = (int) ($engagement['organization_id'] ?? $engagement['org_id'] ?? 0);
    if ($engagementId < 1 || $organizationId < 1 || $createdBy < 1) {
        throw new InvalidArgumentException('A valid engagement, organization, and sender are required.');
    }
    $mattermostInstanceId = trim($mattermostInstanceId);
    $mattermostIdempotencyKey = trim($mattermostIdempotencyKey);
    if (($mattermostInstanceId === '') !== ($mattermostIdempotencyKey === '')
        || ($mattermostInstanceId !== ''
            && preg_match('/\A[A-Za-z0-9._-]{1,100}\z/', $mattermostInstanceId) !== 1)
        || ($mattermostIdempotencyKey !== ''
            && preg_match('/\A[A-Za-z0-9._:-]{8,100}\z/', $mattermostIdempotencyKey) !== 1)
    ) {
        throw new InvalidArgumentException('The Mattermost email request identity is invalid.');
    }
    if (!isset(engagementEmailTemplateDefinitions()[$templateKey])) {
        throw new InvalidArgumentException('Select a supported email template.');
    }
    $subject = normalizeEngagementEmailSubject($subject, $engagementId);
    $body = normalizeEngagementEmailBody($body);
    $replyTo = engagementEmailReplyToAddress();
    if ($includeEventBrief) {
        $body .= "\n\n---\n\n" . engagementEmailSafeEventBrief($engagement, $presentations);
        if (mb_strlen($body, 'UTF-8') > 100000) {
            throw new InvalidArgumentException('The message and event brief together exceed 100,000 characters.');
        }
    }

    $conn->begin_transaction();
    $deliveryIds = [];
    $deliveries = [];
    try {
        $lock = $conn->prepare(
            'SELECT e.id, e.organization_id, e.is_deleted, o.is_deleted AS organization_deleted
             FROM engagements e
             INNER JOIN organizations o ON o.id = e.organization_id
             WHERE e.id = ? FOR UPDATE'
        );
        if (!$lock) {
            throw new RuntimeException('Unable to prepare the engagement email lock.');
        }
        $lock->bind_param('i', $engagementId);
        $lock->execute();
        $locked = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$locked
            || !empty($locked['is_deleted'])
            || !empty($locked['organization_deleted'])
            || (int) $locked['organization_id'] !== $organizationId
        ) {
            throw new InvalidArgumentException('Email can be sent only for an active engagement and organization.');
        }

        if ($mattermostInstanceId !== '') {
            $existing = $conn->prepare(
                'SELECT id, created_by FROM engagement_email_messages
                 WHERE mattermost_instance_id = ? AND mattermost_idempotency_key = ?
                 LIMIT 1 FOR UPDATE'
            );
            if (!$existing) {
                throw new RuntimeException('Unable to prepare the Mattermost email request lookup.');
            }
            $existing->bind_param('ss', $mattermostInstanceId, $mattermostIdempotencyKey);
            $existing->execute();
            $existingRow = $existing->get_result()->fetch_assoc();
            $existingMessageId = (int) ($existingRow['id'] ?? 0);
            $existing->close();
            if ($existingMessageId > 0) {
                if ((int) ($existingRow['created_by'] ?? 0) !== $createdBy) {
                    throw new InvalidArgumentException('That Mattermost email request belongs to another user.');
                }
                $conn->commit();
                return $existingMessageId;
            }
        }

        $availableContacts = fetchEngagementContacts($conn, $engagementId);
        $resolved = engagementEmailResolveRecipients($availableContacts, $contactIds);
        $selectedContacts = $resolved['contacts'];
        $deliveries = $resolved['deliveries'];

        $messageInsert = $conn->prepare(
            'INSERT INTO engagement_email_messages
                (engagement_id, organization_id, template_key, subject, body_text, reply_to,
                 included_event_brief, created_by, created_by_username_snapshot,
                 mattermost_instance_id, mattermost_idempotency_key)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'))'
        );
        if (!$messageInsert) {
            throw new RuntimeException('Unable to prepare the engagement email.');
        }
        $briefValue = $includeEventBrief ? 1 : 0;
        $messageInsert->bind_param(
            'iissssiisss',
            $engagementId,
            $organizationId,
            $templateKey,
            $subject,
            $body,
            $replyTo,
            $briefValue,
            $createdBy,
            $createdByUsername,
            $mattermostInstanceId,
            $mattermostIdempotencyKey
        );
        $messageInsert->execute();
        $messageId = (int) $conn->insert_id;
        $messageInsert->close();

        $deliveryInsert = $conn->prepare(
            'INSERT INTO engagement_email_deliveries
                (message_id, contact_id, recipient_name, recipient_email,
                 recipient_roles_json, payload_ciphertext)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        if (!$deliveryInsert) {
            throw new RuntimeException('Unable to prepare the engagement email recipients.');
        }
        $deliveryContactId = 0;
        $recipientName = '';
        $recipientEmail = '';
        $recipientRolesJson = '[]';
        $payloadCiphertext = '';
        $deliveryInsert->bind_param(
            'iissss',
            $messageId,
            $deliveryContactId,
            $recipientName,
            $recipientEmail,
            $recipientRolesJson,
            $payloadCiphertext
        );
        foreach ($deliveries as $delivery) {
            $deliveryContactId = (int) $delivery['contact_id'];
            $recipientName = (string) $delivery['recipient_name'];
            $recipientEmail = (string) $delivery['recipient_email'];
            $recipientRolesJson = json_encode(
                $delivery['recipient_roles'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            );
            $payloadCiphertext = \Dnr\Security\ApplicationKey::seal(json_encode([
                'recipient' => $recipientEmail,
                'subject' => $subject,
                'body' => $body,
                'reply_to' => $replyTo,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $deliveryInsert->execute();
            $deliveryIds[] = (int) $conn->insert_id;
        }
        $deliveryInsert->close();

        $chronText = engagementEmailChronText($messageId, $selectedContacts, $subject, $body);
        $engagementChron = $conn->prepare(
            'INSERT INTO engagement_chron_entries
                (engagement_id, outbound_email_message_id, entry_text,
                 created_by, created_by_username_snapshot)
             VALUES (?, ?, ?, ?, ?)'
        );
        $organizationChron = $conn->prepare(
            'INSERT INTO organization_chron_entries
                (organization_id, outbound_email_message_id, entry_text,
                 created_by, created_by_username_snapshot)
             VALUES (?, ?, ?, ?, ?)'
        );
        $contactChron = $conn->prepare(
            'INSERT INTO contact_chron_entries
                (contact_id, outbound_email_message_id, entry_text,
                 created_by, created_by_username_snapshot)
             VALUES (?, ?, ?, ?, ?)'
        );
        if (!$engagementChron || !$organizationChron || !$contactChron) {
            throw new RuntimeException('Unable to prepare the outgoing Chron entries.');
        }
        $engagementChron->bind_param(
            'iisis',
            $engagementId,
            $messageId,
            $chronText,
            $createdBy,
            $createdByUsername
        );
        $engagementChron->execute();
        $organizationChron->bind_param(
            'iisis',
            $organizationId,
            $messageId,
            $chronText,
            $createdBy,
            $createdByUsername
        );
        $organizationChron->execute();
        $contactId = 0;
        $contactChron->bind_param(
            'iisis',
            $contactId,
            $messageId,
            $chronText,
            $createdBy,
            $createdByUsername
        );
        foreach ($selectedContacts as $contact) {
            $contactId = (int) $contact['id'];
            $contactChron->execute();
        }
        $engagementChron->close();
        $organizationChron->close();
        $contactChron->close();
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    if ($transport === 'log') {
        foreach ($deliveryIds as $index => $deliveryId) {
            try {
                $delivery = $deliveries[$index] ?? null;
                if (!is_array($delivery)) {
                    continue;
                }
                deliverApplicationEmail(
                    (string) $delivery['recipient_email'],
                    $subject,
                    $body,
                    $replyTo
                );
                completeQueuedEngagementEmail($conn, $deliveryId);
            } catch (Throwable $exception) {
                failQueuedEngagementEmail($conn, $deliveryId, 1, $exception, true);
            }
        }
    }
    return $messageId;
}

function maintainQueuedEngagementEmail(mysqli $conn, int $leaseSeconds = 600): void
{
    $leaseSeconds = max(60, min(3600, $leaseSeconds));
    if ($conn->query(
        "UPDATE engagement_email_deliveries
         SET status = 'failed', processing_started_at = NULL,
             payload_ciphertext = NULL,
             last_error = 'Delivery stopped after the final attempt.'
         WHERE status = 'processing'
           AND processing_started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$leaseSeconds} SECOND)
           AND attempts >= 8"
    ) === false) {
        throw new RuntimeException('Unable to expire terminal engagement-email leases.');
    }
    if ($conn->query(
        "UPDATE engagement_email_deliveries
         SET status = 'retry', processing_started_at = NULL,
             next_attempt_at = UTC_TIMESTAMP(),
             last_error = 'Delivery lease expired before completion.'
         WHERE status = 'processing'
           AND processing_started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$leaseSeconds} SECOND)
           AND attempts < 8"
    ) === false) {
        throw new RuntimeException('Unable to recover expired engagement-email leases.');
    }
}

/** @return array{id: int, message_id: int, payload_ciphertext: string, attempts: int}|null */
function claimQueuedEngagementEmail(
    mysqli $conn,
    int $leaseSeconds = 600,
    bool $performMaintenance = true
): ?array {
    $leaseSeconds = max(60, min(3600, $leaseSeconds));
    if ($performMaintenance) {
        maintainQueuedEngagementEmail($conn, $leaseSeconds);
    }
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "SELECT id, message_id, payload_ciphertext, attempts
             FROM engagement_email_deliveries
             WHERE status IN ('pending', 'retry')
               AND next_attempt_at <= UTC_TIMESTAMP()
               AND attempts < 8
               AND payload_ciphertext IS NOT NULL
             ORDER BY next_attempt_at, id
             LIMIT 1
             FOR UPDATE SKIP LOCKED"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the engagement email claim.');
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            $conn->commit();
            return null;
        }
        $deliveryId = (int) $row['id'];
        $claim = $conn->prepare(
            "UPDATE engagement_email_deliveries
             SET status = 'processing', processing_started_at = UTC_TIMESTAMP(),
                 attempts = attempts + 1, last_error = NULL
             WHERE id = ? AND status IN ('pending', 'retry')"
        );
        if (!$claim) {
            throw new RuntimeException('Unable to prepare the engagement email claim update.');
        }
        $claim->bind_param('i', $deliveryId);
        $claim->execute();
        if ($claim->affected_rows !== 1) {
            $claim->close();
            throw new RuntimeException('The engagement email could not be claimed.');
        }
        $claim->close();
        $conn->commit();
        return [
            'id' => $deliveryId,
            'message_id' => (int) $row['message_id'],
            'payload_ciphertext' => (string) $row['payload_ciphertext'],
            'attempts' => (int) $row['attempts'] + 1,
        ];
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

/** @return array{recipient: string, subject: string, body: string, reply_to: string} */
function decryptQueuedEngagementEmail(string $ciphertext): array
{
    $json = \Dnr\Security\ApplicationKey::open($ciphertext);
    $message = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($message)) {
        throw new RuntimeException('The queued engagement email payload is invalid.');
    }
    return [
        'recipient' => normalizeAccountEmail($message['recipient'] ?? ''),
        'subject' => trim((string) ($message['subject'] ?? '')),
        'body' => (string) ($message['body'] ?? ''),
        'reply_to' => engagementEmailNormalizeOptionalAddress($message['reply_to'] ?? ''),
    ];
}

function engagementEmailNormalizeOptionalAddress(mixed $address): string
{
    if (!is_scalar($address) || trim((string) $address) === '') {
        return '';
    }
    return normalizeAccountEmail($address);
}

function completeQueuedEngagementEmail(mysqli $conn, int $deliveryId): void
{
    $stmt = $conn->prepare(
        "UPDATE engagement_email_deliveries
         SET status = 'sent', sent_at = UTC_TIMESTAMP(),
             processing_started_at = NULL, payload_ciphertext = NULL,
             last_error = NULL
         WHERE id = ? AND status IN ('pending', 'processing', 'retry')"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare engagement email completion.');
    }
    $stmt->bind_param('i', $deliveryId);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('The engagement email can no longer be completed.');
    }
    $stmt->close();
}

function failQueuedEngagementEmail(
    mysqli $conn,
    int $deliveryId,
    int $attempts,
    Throwable $exception,
    bool $permanent = false
): void {
    $terminal = $permanent || $attempts >= 8;
    $status = $terminal ? 'failed' : 'retry';
    $delay = min(3600, 15 * (2 ** max(0, min(7, $attempts - 1))));
    $error = trim(preg_replace('/\s+/', ' ', $exception->getMessage()) ?? 'Delivery failed.');
    $error = mb_substr($error !== '' ? $error : 'Delivery failed.', 0, 255, 'UTF-8');
    $stmt = $conn->prepare(
        "UPDATE engagement_email_deliveries
         SET status = ?, processing_started_at = NULL,
             next_attempt_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND),
             payload_ciphertext = IF(? = 1, NULL, payload_ciphertext),
             last_error = ?
         WHERE id = ? AND status IN ('pending', 'processing', 'retry')"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare engagement email failure handling.');
    }
    $terminalValue = $terminal ? 1 : 0;
    $stmt->bind_param('siisi', $status, $delay, $terminalValue, $error, $deliveryId);
    $stmt->execute();
    $stmt->close();
}

/** @return list<array<string, mixed>> */
function fetchEngagementEmailMessages(mysqli $conn, int $engagementId, int $limit = 20): array
{
    $limit = max(1, min(100, $limit));
    $stmt = $conn->prepare(
        "SELECT message.id, message.template_key, message.subject,
                message.included_event_brief, message.created_at,
                COALESCE(creator.username, message.created_by_username_snapshot) AS created_by_username,
                COUNT(delivery.id) AS recipient_count,
                SUM(delivery.status = 'sent') AS sent_count,
                SUM(delivery.status = 'failed') AS failed_count,
                SUM(delivery.status IN ('pending', 'processing', 'retry')) AS pending_count,
                GROUP_CONCAT(
                    CONCAT(delivery.recipient_name, ' <', delivery.recipient_email, '>')
                    ORDER BY delivery.id SEPARATOR ', '
                ) AS recipients
         FROM engagement_email_messages message
         LEFT JOIN users creator ON creator.id = message.created_by
         LEFT JOIN engagement_email_deliveries delivery ON delivery.message_id = message.id
         WHERE message.engagement_id = ?
         GROUP BY message.id, message.template_key, message.subject,
                  message.included_event_brief, message.created_at,
                  creator.username, message.created_by_username_snapshot
         ORDER BY message.created_at DESC, message.id DESC
         LIMIT {$limit}"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the engagement email history.');
    }
    $stmt->bind_param('i', $engagementId);
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $messages;
}

/** @param array<string, mixed> $message */
function engagementEmailAggregateStatus(array $message): string
{
    $total = (int) ($message['recipient_count'] ?? 0);
    $sent = (int) ($message['sent_count'] ?? 0);
    $failed = (int) ($message['failed_count'] ?? 0);
    if ($total > 0 && $sent === $total) {
        return 'sent';
    }
    if ($total > 0 && $failed === $total) {
        return 'failed';
    }
    if ($failed > 0 && $sent > 0) {
        return 'partial';
    }
    return 'pending';
}

/** @return array<string, mixed>|null */
function fetchEngagementEmailMessage(mysqli $conn, int $messageId): ?array
{
    $stmt = $conn->prepare(
        'SELECT message.*, engagement.event_title, engagement.is_deleted AS engagement_deleted,
                organization.organization_name,
                COALESCE(creator.username, message.created_by_username_snapshot) AS created_by_username
         FROM engagement_email_messages message
         INNER JOIN engagements engagement ON engagement.id = message.engagement_id
         INNER JOIN organizations organization ON organization.id = message.organization_id
         LEFT JOIN users creator ON creator.id = message.created_by
         WHERE message.id = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the outbound message lookup.');
    }
    $stmt->bind_param('i', $messageId);
    $stmt->execute();
    $message = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if ($message === null) {
        return null;
    }
    $deliveries = $conn->prepare(
        'SELECT id, contact_id, recipient_name, recipient_email,
                recipient_roles_json, status, attempts, sent_at, last_error,
                created_at, updated_at
         FROM engagement_email_deliveries
         WHERE message_id = ? ORDER BY id'
    );
    if (!$deliveries) {
        throw new RuntimeException('Unable to prepare the outbound message deliveries.');
    }
    $deliveries->bind_param('i', $messageId);
    $deliveries->execute();
    $message['deliveries'] = $deliveries->get_result()->fetch_all(MYSQLI_ASSOC);
    $deliveries->close();
    $message['recipient_count'] = count($message['deliveries']);
    $message['sent_count'] = count(array_filter(
        $message['deliveries'],
        static fn(array $delivery): bool => $delivery['status'] === 'sent'
    ));
    $message['failed_count'] = count(array_filter(
        $message['deliveries'],
        static fn(array $delivery): bool => $delivery['status'] === 'failed'
    ));
    return $message;
}

function retryFailedEngagementEmailDeliveries(mysqli $conn, int $messageId): int
{
    $conn->begin_transaction();
    try {
        $message = fetchEngagementEmailMessage($conn, $messageId);
        if ($message === null) {
            throw new InvalidArgumentException('That outbound message is no longer available.');
        }
        $stmt = $conn->prepare(
            "UPDATE engagement_email_deliveries
             SET status = 'retry', attempts = 0, next_attempt_at = UTC_TIMESTAMP(),
                 processing_started_at = NULL, sent_at = NULL, last_error = NULL,
                 payload_ciphertext = ?
             WHERE id = ? AND message_id = ? AND status = 'failed'"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the failed delivery retry.');
        }
        $payload = '';
        $deliveryId = 0;
        $stmt->bind_param('sii', $payload, $deliveryId, $messageId);
        $retried = 0;
        foreach ($message['deliveries'] as $delivery) {
            if ($delivery['status'] !== 'failed') {
                continue;
            }
            $deliveryId = (int) $delivery['id'];
            $payload = \Dnr\Security\ApplicationKey::seal(json_encode([
                'recipient' => normalizeAccountEmail($delivery['recipient_email']),
                'subject' => (string) $message['subject'],
                'body' => (string) $message['body_text'],
                'reply_to' => engagementEmailNormalizeOptionalAddress($message['reply_to'] ?? ''),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $stmt->execute();
            $retried += $stmt->affected_rows;
        }
        $stmt->close();
        if ($retried < 1) {
            throw new InvalidArgumentException('This message has no failed deliveries to retry.');
        }
        $conn->commit();
        return $retried;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
