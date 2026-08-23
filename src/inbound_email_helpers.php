<?php

declare(strict_types=1);

use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\HeaderConsts;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\Message;

const INBOUND_EMAIL_MAX_RAW_BYTES = 10485760;
const INBOUND_EMAIL_MAX_CHRON_CHARACTERS = 100000;

function inboundEmailGatewayAddress(): string
{
    $configured = getenv('DNR_INBOUND_ADDRESS');
    $address = strtolower(trim(is_string($configured) ? $configured : ''));
    if ($address === '' || !filter_var($address, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('DNR_INBOUND_ADDRESS must be a valid email address.');
    }
    return $address;
}

function inboundEmailMaximumRawBytes(): int
{
    $configured = (int) (getenv('DNR_INBOUND_MAX_BYTES') ?: INBOUND_EMAIL_MAX_RAW_BYTES);
    return max(65536, min(16777215, $configured));
}

function normalizeInboundEmailAddress(mixed $address): string
{
    if (!is_scalar($address)) {
        return '';
    }
    $address = strtolower(trim((string) $address));
    return strlen($address) <= 254 && filter_var($address, FILTER_VALIDATE_EMAIL)
        ? $address
        : '';
}

/** @return list<array{address: string, name: string}> */
function inboundEmailHeaderAddresses(IMessage $message, string $headerName): array
{
    $header = $message->getHeaderAs($headerName, AddressHeader::class);
    if (!$header instanceof AddressHeader) {
        return [];
    }

    $addresses = [];
    foreach ($header->getAddresses() as $addressPart) {
        $address = normalizeInboundEmailAddress($addressPart->getEmail());
        if ($address === '') {
            continue;
        }
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $addressPart->getName()) ?? '');
        $addresses[$address] = [
            'address' => $address,
            'name' => mb_substr($name, 0, 255, 'UTF-8'),
        ];
    }
    return array_values($addresses);
}

function inboundEmailPlainText(IMessage $message): string
{
    $text = $message->getTextContent();
    if (!is_string($text) || trim($text) === '') {
        $html = $message->getHtmlContent();
        if (is_string($html) && $html !== '') {
            $html = preg_replace(
                '#<(script|style|head)\b[^>]*>.*?</\1>#is',
                '',
                $html
            ) ?? $html;
            $html = preg_replace('#<\s*br\s*/?\s*>#i', "\n", $html) ?? $html;
            $html = preg_replace('#</\s*(p|div|li|tr|h[1-6])\s*>#i', "\n", $html) ?? $html;
            $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    $text = is_string($text) ? $text : '';
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
    $text = preg_replace("/\n{4,}/", "\n\n\n", $text) ?? $text;
    return trim($text);
}

function inboundEmailUtcDate(mixed $value): ?string
{
    $value = is_scalar($value) ? trim((string) $value) : '';
    if ($value === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    } catch (Throwable $exception) {
        return null;
    }
}

/**
 * @return array{
 *   rfc_message_id: string, sender_name: string, sender_address: string,
 *   to_addresses: list<string>, cc_addresses: list<string>, subject: string,
 *   sent_at: ?string, received_at: string, body_text: string,
 *   attachment_names: list<string>, raw_headers: string, deduplication_hash: string
 * }
 */
function parseInboundEmail(string $rawMessage, ?string $receivedAt = null): array
{
    $rawLength = strlen($rawMessage);
    if ($rawLength < 1) {
        throw new InvalidArgumentException('The inbound message is empty.');
    }
    if ($rawLength > inboundEmailMaximumRawBytes()) {
        throw new InvalidArgumentException('The inbound message exceeds the configured size limit.');
    }

    $message = Message::from($rawMessage, false);
    $from = inboundEmailHeaderAddresses($message, HeaderConsts::FROM);
    if (count($from) !== 1) {
        throw new InvalidArgumentException('The inbound message must contain exactly one valid From address.');
    }
    $to = array_column(inboundEmailHeaderAddresses($message, HeaderConsts::TO), 'address');
    $cc = array_column(inboundEmailHeaderAddresses($message, HeaderConsts::CC), 'address');
    $subject = trim((string) ($message->getSubject() ?? ''));
    $subject = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $subject) ?? $subject;
    $messageId = trim((string) ($message->getHeaderValue(HeaderConsts::MESSAGE_ID) ?? ''));
    $messageId = trim($messageId, "<> \t\r\n");
    $messageId = mb_substr($messageId, 0, 998, 'UTF-8');
    $dateHeader = $message->getHeaderValue(HeaderConsts::DATE);

    $attachmentNames = [];
    foreach ($message->getAllAttachmentParts() as $attachment) {
        $filename = trim((string) ($attachment->getFilename() ?? 'unnamed attachment'));
        $filename = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $filename) ?? $filename;
        $filename = mb_substr($filename, 0, 255, 'UTF-8');
        if ($filename !== '') {
            $attachmentNames[$filename] = true;
        }
        if (count($attachmentNames) >= 100) {
            break;
        }
    }

    $headerSplit = preg_split("/\r?\n\r?\n/", $rawMessage, 2);
    $rawHeaders = is_array($headerSplit) ? (string) $headerSplit[0] : '';
    $rawHeaders = mb_substr($rawHeaders, 0, 1048576, 'UTF-8');
    $receivedAt = inboundEmailUtcDate($receivedAt ?? gmdate('c')) ?? gmdate('Y-m-d H:i:s');
    $deduplicationSource = $messageId !== ''
        ? 'message-id:' . strtolower($messageId)
        : 'raw-message:' . $rawMessage;

    return [
        'rfc_message_id' => $messageId,
        'sender_name' => (string) $from[0]['name'],
        'sender_address' => (string) $from[0]['address'],
        'to_addresses' => array_values(array_unique($to)),
        'cc_addresses' => array_values(array_unique($cc)),
        'subject' => mb_substr($subject, 0, 998, 'UTF-8'),
        'sent_at' => inboundEmailUtcDate($dateHeader),
        'received_at' => $receivedAt,
        'body_text' => inboundEmailPlainText($message),
        'attachment_names' => array_keys($attachmentNames),
        'raw_headers' => $rawHeaders,
        'deduplication_hash' => hash('sha256', $deduplicationSource, true),
    ];
}

/** @param mixed $value */
function inboundEmailJson($value): string
{
    $encoded = json_encode(
        $value,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if (!is_string($encoded)) {
        throw new RuntimeException('Unable to encode inbound email data.');
    }
    return $encoded;
}

/**
 * @param array{
 *   rfc_message_id: string, sender_name: string, sender_address: string,
 *   to_addresses: list<string>, cc_addresses: list<string>, subject: string,
 *   sent_at: ?string, received_at: string, body_text: string,
 *   attachment_names: list<string>, raw_headers: string, deduplication_hash: string
 * } $message
 * @return array{id: int, inserted: bool}
 */
function storeInboundEmailMessage(
    mysqli $conn,
    string $transport,
    string $transportKey,
    array $message,
    ?string $gatewayAddress = null
): array {
    if (!in_array($transport, ['imap', 'webhook', 'file'], true)) {
        throw new InvalidArgumentException('Invalid inbound email transport.');
    }
    $transportKey = trim($transportKey);
    if ($transportKey === '' || strlen($transportKey) > 255) {
        throw new InvalidArgumentException('Invalid inbound email transport key.');
    }
    $gatewayAddress = normalizeInboundEmailAddress($gatewayAddress ?? inboundEmailGatewayAddress());
    if ($gatewayAddress === '') {
        throw new InvalidArgumentException('Invalid inbound email gateway address.');
    }

    $toJson = inboundEmailJson($message['to_addresses']);
    $ccJson = inboundEmailJson($message['cc_addresses']);
    $attachmentJson = inboundEmailJson($message['attachment_names']);
    $stmt = $conn->prepare(
        'INSERT IGNORE INTO inbound_email_messages
            (transport, transport_key, deduplication_hash, rfc_message_id,
             gateway_address, sender_name, sender_address, to_addresses,
             cc_addresses, subject, sent_at, received_at, body_text,
             attachment_names, raw_headers)
         VALUES (?, ?, ?, NULLIF(?, \'\'), ?, NULLIF(?, \'\'), ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare inbound email storage.');
    }
    $stmt->bind_param(
        'sssssssssssssss',
        $transport,
        $transportKey,
        $message['deduplication_hash'],
        $message['rfc_message_id'],
        $gatewayAddress,
        $message['sender_name'],
        $message['sender_address'],
        $toJson,
        $ccJson,
        $message['subject'],
        $message['sent_at'],
        $message['received_at'],
        $message['body_text'],
        $attachmentJson,
        $message['raw_headers']
    );
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to save the inbound email: ' . $error);
    }
    $inserted = $stmt->affected_rows === 1;
    $messageId = $inserted ? (int) $conn->insert_id : 0;
    $stmt->close();

    if (!$inserted) {
        $lookup = $conn->prepare(
            'SELECT id FROM inbound_email_messages
             WHERE (transport = ? AND transport_key = ?)
                OR deduplication_hash = ?
             ORDER BY id LIMIT 1'
        );
        if (!$lookup) {
            throw new RuntimeException('Unable to prepare inbound email duplicate lookup.');
        }
        $lookup->bind_param('sss', $transport, $transportKey, $message['deduplication_hash']);
        $lookup->execute();
        $messageId = (int) ($lookup->get_result()->fetch_assoc()['id'] ?? 0);
        $lookup->close();
        if ($messageId < 1) {
            throw new RuntimeException('The inbound email could not be stored or located.');
        }
    }

    return ['id' => $messageId, 'inserted' => $inserted];
}

/** @return list<string> */
function inboundEmailDecodeAddressList(mixed $json): array
{
    $decoded = json_decode((string) $json, true);
    if (!is_array($decoded)) {
        return [];
    }
    $addresses = [];
    foreach ($decoded as $address) {
        $address = normalizeInboundEmailAddress($address);
        if ($address !== '') {
            $addresses[$address] = true;
        }
    }
    return array_keys($addresses);
}

/** @return list<string> */
function inboundEmailDecodeStringList(mixed $json): array
{
    $decoded = json_decode((string) $json, true);
    if (!is_array($decoded)) {
        return [];
    }
    $strings = [];
    foreach ($decoded as $value) {
        if (is_scalar($value) && trim((string) $value) !== '') {
            $strings[] = trim((string) $value);
        }
    }
    return array_values(array_unique($strings));
}

/** @return list<array{id: int, username: string}> */
function inboundEmailUserMatches(mysqli $conn, string $address): array
{
    $stmt = $conn->prepare(
        "SELECT id, username FROM users
         WHERE verified_email = ? AND account_status = 'active'
         ORDER BY id LIMIT 3"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare inbound user matching.');
    }
    $stmt->bind_param('s', $address);
    $stmt->execute();
    $matches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(
        static fn(array $row): array => [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
        ],
        $matches
    );
}

/**
 * @return list<array{id: int, label: string, organization_id: int, organization_label: string}>
 */
function inboundEmailContactMatches(mysqli $conn, string $address): array
{
    $stmt = $conn->prepare(
        "SELECT contact.id,
                TRIM(CONCAT_WS(' ', contact.contact_first_name, contact.contact_last_name)) AS label,
                organization.id AS organization_id,
                organization.organization_name AS organization_label
         FROM contacts contact
         INNER JOIN organizations organization ON organization.id = contact.organization_id
         WHERE LOWER(TRIM(contact.contact_email)) = ?
           AND contact.is_deleted = 0 AND organization.is_deleted = 0
         ORDER BY contact.id LIMIT 25"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare inbound Contact matching.');
    }
    $stmt->bind_param('s', $address);
    $stmt->execute();
    $matches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(
        static fn(array $row): array => [
            'id' => (int) $row['id'],
            'label' => (string) $row['label'],
            'organization_id' => (int) $row['organization_id'],
            'organization_label' => (string) $row['organization_label'],
        ],
        $matches
    );
}

/** @return list<array{id: int, label: string}> */
function inboundEmailOrganizationMatches(mysqli $conn, string $address): array
{
    $stmt = $conn->prepare(
        'SELECT id, organization_name AS label
         FROM organizations
         WHERE LOWER(TRIM(email)) = ? AND is_deleted = 0
         ORDER BY id LIMIT 25'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare inbound Organization matching.');
    }
    $stmt->bind_param('s', $address);
    $stmt->execute();
    $matches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(
        static fn(array $row): array => [
            'id' => (int) $row['id'],
            'label' => (string) $row['label'],
        ],
        $matches
    );
}

/**
 * @param array<string, mixed> $message
 * @return array{
 *   automatic: bool, reasons: list<string>, sender: array<string, mixed>,
 *   participants: list<array<string, mixed>>,
 *   contacts: list<array{id: int, label: string}>,
 *   organizations: list<array{id: int, label: string}>
 * }
 */
function routeInboundEmailMessage(mysqli $conn, array $message): array
{
    $senderAddress = normalizeInboundEmailAddress($message['sender_address'] ?? '');
    $gatewayAddress = normalizeInboundEmailAddress($message['gateway_address'] ?? '');
    $to = inboundEmailDecodeAddressList($message['to_addresses'] ?? '[]');
    $cc = inboundEmailDecodeAddressList($message['cc_addresses'] ?? '[]');
    $senderUsers = $senderAddress !== '' ? inboundEmailUserMatches($conn, $senderAddress) : [];
    $senderContacts = $senderAddress !== '' ? inboundEmailContactMatches($conn, $senderAddress) : [];
    $senderOrganizations = $senderAddress !== ''
        ? inboundEmailOrganizationMatches($conn, $senderAddress)
        : [];

    $sender = ['type' => 'unknown', 'id' => null, 'label' => $senderAddress];
    $reasons = [];
    if (count($senderUsers) === 1) {
        $sender = [
            'type' => 'user',
            'id' => $senderUsers[0]['id'],
            'label' => $senderUsers[0]['username'],
        ];
    } elseif (count($senderContacts) === 1) {
        $sender = [
            'type' => 'contact',
            'id' => $senderContacts[0]['id'],
            'label' => $senderContacts[0]['label'],
        ];
    } elseif (count($senderOrganizations) === 1) {
        $sender = [
            'type' => 'organization',
            'id' => $senderOrganizations[0]['id'],
            'label' => $senderOrganizations[0]['label'],
        ];
    } elseif (count($senderContacts) > 1 || count($senderOrganizations) > 1) {
        $reasons[] = 'The sender address matches more than one active record.';
    } else {
        $reasons[] = 'The sender is not a uniquely recognized active user, Contact, or Organization.';
    }

    $participantAddresses = array_values(array_unique(array_merge([$senderAddress], $to, $cc)));
    $participantAddresses = array_values(array_filter(
        $participantAddresses,
        static fn(string $address): bool => $address !== '' && $address !== $gatewayAddress
    ));

    $contacts = [];
    $organizations = [];
    $participants = [];
    foreach ($participantAddresses as $address) {
        $userMatches = inboundEmailUserMatches($conn, $address);
        $contactMatches = inboundEmailContactMatches($conn, $address);
        $organizationMatches = inboundEmailOrganizationMatches($conn, $address);
        $isInternalUser = count($userMatches) === 1;

        if ($isInternalUser) {
            $contactMatches = [];
            $organizationMatches = [];
        }

        foreach ($contactMatches as $contact) {
            $contacts[$contact['id']] = [
                'id' => $contact['id'],
                'label' => $contact['label'],
            ];
            $organizations[$contact['organization_id']] = [
                'id' => $contact['organization_id'],
                'label' => $contact['organization_label'],
            ];
        }
        foreach ($organizationMatches as $organization) {
            $organizations[$organization['id']] = $organization;
        }

        $distinctOrganizationIds = [];
        foreach ($contactMatches as $contact) {
            $distinctOrganizationIds[$contact['organization_id']] = true;
        }
        foreach ($organizationMatches as $organization) {
            $distinctOrganizationIds[$organization['id']] = true;
        }
        if (count($contactMatches) > 1) {
            $reasons[] = "{$address} matches more than one active Contact.";
        }
        if (count($organizationMatches) > 1 || count($distinctOrganizationIds) > 1) {
            $reasons[] = "{$address} identifies more than one active Organization.";
        }

        $participants[] = [
            'address' => $address,
            'internal_user' => $isInternalUser,
            'contacts' => $contactMatches,
            'organizations' => $organizationMatches,
        ];
    }

    if (!$contacts && !$organizations) {
        $reasons[] = 'No active Contact or Organization email address was matched.';
    }
    $reasons = array_values(array_unique($reasons));
    ksort($contacts);
    ksort($organizations);

    return [
        'automatic' => $reasons === [],
        'reasons' => $reasons,
        'sender' => $sender,
        'participants' => $participants,
        'contacts' => array_values($contacts),
        'organizations' => array_values($organizations),
    ];
}

/** @param array<string, mixed> $message */
function formatInboundEmailChronEntry(array $message): string
{
    $senderAddress = (string) ($message['sender_address'] ?? '');
    $senderName = trim((string) ($message['sender_name'] ?? ''));
    $from = $senderName !== '' ? "{$senderName} <{$senderAddress}>" : $senderAddress;
    $to = implode(', ', inboundEmailDecodeAddressList($message['to_addresses'] ?? '[]'));
    $cc = implode(', ', inboundEmailDecodeAddressList($message['cc_addresses'] ?? '[]'));
    $attachments = inboundEmailDecodeStringList($message['attachment_names'] ?? '[]');

    $lines = ['Email captured by MOED', '', 'From: ' . $from];
    if ($to !== '') {
        $lines[] = 'To: ' . $to;
    }
    if ($cc !== '') {
        $lines[] = 'Cc: ' . $cc;
    }
    $lines[] = 'Subject: ' . ((string) ($message['subject'] ?? '') ?: '(no subject)');
    if (!empty($message['sent_at'])) {
        $lines[] = 'Sent: ' . (string) $message['sent_at'] . ' UTC';
    }
    $lines[] = 'Received by MOED: ' . (string) ($message['received_at'] ?? '') . ' UTC';
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
    } else {
        throw new InvalidArgumentException('Invalid inbound email target type.');
    }
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare(
        "SELECT id FROM {$table} WHERE is_deleted = 0 AND id IN ({$placeholders}) FOR UPDATE"
    );
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

/**
 * @param list<int>|null $contactIds
 * @param list<int>|null $organizationIds
 */
function processInboundEmailMessage(
    mysqli $conn,
    int $messageId,
    ?array $contactIds = null,
    ?array $organizationIds = null,
    ?int $processedBy = null
): string {
    if ($messageId < 1) {
        throw new InvalidArgumentException('Select a valid inbound message.');
    }
    $manual = $contactIds !== null || $organizationIds !== null;
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
        if ($message['status'] === 'rejected') {
            throw new InvalidArgumentException('That inbound message was rejected.');
        }

        $routing = routeInboundEmailMessage($conn, $message);
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
            $contactIds = array_values(array_intersect(
                array_map('intval', $contactIds ?? []),
                $candidateContactIds
            ));
            $organizationIds = array_values(array_intersect(
                array_map('intval', $organizationIds ?? []),
                $candidateOrganizationIds
            ));
        } else {
            $contactIds = array_map('intval', array_column($routing['contacts'], 'id'));
            $organizationIds = array_map('intval', array_column($routing['organizations'], 'id'));
        }
        $contactIds = inboundEmailActiveTargetIds($conn, 'contact', $contactIds);
        $organizationIds = inboundEmailActiveTargetIds($conn, 'organization', $organizationIds);
        if (!$contactIds && !$organizationIds) {
            throw new InvalidArgumentException('Select at least one active matched Contact or Organization.');
        }

        $entryText = formatInboundEmailChronEntry($message);
        $creatorId = $routing['sender']['type'] === 'user'
            ? (int) ($routing['sender']['id'] ?? 0)
            : null;
        $creatorId = $creatorId && $creatorId > 0 ? $creatorId : null;
        $creatorName = $routing['sender']['type'] === 'user'
            ? mb_substr((string) $routing['sender']['label'], 0, 50, 'UTF-8')
            : 'Email Gateway';
        $createdAt = (string) $message['received_at'];

        $contactInsert = $conn->prepare(
            'INSERT INTO contact_chron_entries
                (contact_id, inbound_email_message_id, entry_text, created_by,
                 created_by_username_snapshot, updated_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id'
        );
        if (!$contactInsert) {
            throw new RuntimeException('Unable to prepare Contact Chron email routing.');
        }
        foreach ($contactIds as $contactId) {
            $contactInsert->bind_param(
                'iisisiss',
                $contactId,
                $messageId,
                $entryText,
                $creatorId,
                $creatorName,
                $creatorId,
                $createdAt,
                $createdAt
            );
            $contactInsert->execute();
        }
        $contactInsert->close();

        $organizationInsert = $conn->prepare(
            'INSERT INTO organization_chron_entries
                (organization_id, inbound_email_message_id, entry_text, created_by,
                 created_by_username_snapshot, updated_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE id = id'
        );
        if (!$organizationInsert) {
            throw new RuntimeException('Unable to prepare Organization Chron email routing.');
        }
        foreach ($organizationIds as $organizationId) {
            $organizationInsert->bind_param(
                'iisisiss',
                $organizationId,
                $messageId,
                $entryText,
                $creatorId,
                $creatorName,
                $creatorId,
                $createdAt,
                $createdAt
            );
            $organizationInsert->execute();
        }
        $organizationInsert->close();

        $routing['applied_contacts'] = $contactIds;
        $routing['applied_organizations'] = $organizationIds;
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
        $conn->commit();
        return 'processed';
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function rejectInboundEmailMessage(mysqli $conn, int $messageId, int $processedBy): void
{
    $stmt = $conn->prepare(
        "UPDATE inbound_email_messages
         SET status = 'rejected', processed_by = ?, processed_at = UTC_TIMESTAMP(),
             processing_started_at = NULL, review_reason = 'Rejected by reviewer'
         WHERE id = ? AND status IN ('pending', 'review', 'failed')"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare inbound message rejection.');
    }
    $stmt->bind_param('ii', $processedBy, $messageId);
    $succeeded = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();
    if (!$succeeded) {
        throw new InvalidArgumentException('That inbound message can no longer be rejected.');
    }
}

function claimInboundEmailMessage(mysqli $conn, int $leaseSeconds = 600): ?int
{
    $leaseSeconds = max(60, min(3600, $leaseSeconds));
    $conn->begin_transaction();
    try {
        $result = $conn->query(
            "SELECT id FROM inbound_email_messages
             WHERE attempts < 8
               AND (
                    (status IN ('pending', 'failed') AND next_attempt_at <= UTC_TIMESTAMP())
                    OR (status = 'processing' AND processing_started_at <= DATE_SUB(
                        UTC_TIMESTAMP(), INTERVAL {$leaseSeconds} SECOND
                    ))
               )
             ORDER BY next_attempt_at, id
             LIMIT 1 FOR UPDATE SKIP LOCKED"
        );
        $message = $result ? $result->fetch_assoc() : null;
        if (!$message) {
            $conn->commit();
            return null;
        }
        $messageId = (int) $message['id'];
        $stmt = $conn->prepare(
            "UPDATE inbound_email_messages
             SET status = 'processing', attempts = attempts + 1,
                 processing_started_at = UTC_TIMESTAMP(), last_error = NULL
             WHERE id = ?"
        );
        $stmt->bind_param('i', $messageId);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
        return $messageId;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function failInboundEmailMessage(mysqli $conn, int $messageId, Throwable $exception): void
{
    $error = mb_substr($exception->getMessage(), 0, 255, 'UTF-8');
    $stmt = $conn->prepare(
        "UPDATE inbound_email_messages
         SET status = 'failed', processing_started_at = NULL, last_error = ?,
             next_attempt_at = TIMESTAMPADD(
                 MINUTE,
                 LEAST(1440, CAST(POW(2, attempts) AS UNSIGNED)),
                 UTC_TIMESTAMP()
             )
         WHERE id = ? AND status = 'processing'"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare inbound processing failure.');
    }
    $stmt->bind_param('si', $error, $messageId);
    $stmt->execute();
    $stmt->close();
}
