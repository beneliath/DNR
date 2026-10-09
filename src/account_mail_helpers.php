<?php
declare(strict_types=1);
require_once __DIR__ . '/account_helpers.php';

function accountMailMarkerTag(string $key, string $type, int $id, string $secret): string {
    if (!preg_match('/\A[a-z][a-z0-9-]{2,63}\z/D', $key) || !in_array($type, ['E', 'I'], true)
        || $id < 1 || $id > 2147483647 || strlen($secret) < 32) throw new InvalidArgumentException('Invalid mail marker.');
    return rtrim(strtr(base64_encode(substr(hash_hmac('sha256', "moed-account-mail-v1\0$key\0$type\0$id", $secret, true), 0, 16)), '+/', '-_'), '=');
}

function accountMailSigningKey(): string {
    return accountIsPrimary() ? \Dnr\Security\InboundRoutingKey::bytes() : configurationSecret('DNR_PLATFORM_API_KEY');
}

function accountMailMarker(string $type, int $id): string {
    $key = currentAccountKey();
    return '[MOED@' . $key . '#' . $type . $id . '.' . accountMailMarkerTag($key, $type, $id, accountMailSigningKey()) . ']';
}

/** No address, sender, contact match, or record number can select an Account. */
function resolveAccountMailMarkers(string $text, callable $keyLookup, bool $allowLegacy = false): array {
    $targets = []; $invalid = false; $offset = 0; $count = 0;
    // Scan starts incrementally. Neither marker count nor marker length may
    // allocate memory proportional to hostile mail content. The shared limit
    // includes both current and legacy markers, including incomplete ones.
    while (preg_match('/\[MOED@|\[[A-Z][A-Z0-9_-]{1,19}#/i', $text, $start, PREG_OFFSET_CAPTURE, $offset)) {
        if (++$count > 64) { $invalid = true; break; }
        $position = $start[0][1];
        $offset = $position + strlen($start[0][0]);
        $candidate = substr($text, $position, 128);
        if (strcasecmp($start[0][0], '[MOED@') === 0) {
            if (!preg_match('/\A\[(?i:MOED)@([a-z][a-z0-9-]{2,63})#([EI])([1-9][0-9]{0,9})\.([A-Za-z0-9_-]{22})\]/', $candidate, $parts)
                || (int) $parts[3] > 2147483647) { $invalid = true; break; }
            [, $key, $type, $id, $tag] = $parts;
            $secret = $keyLookup($key);
            if (!is_string($secret) || strlen($secret) < 32 || !hash_equals(accountMailMarkerTag($key, $type, (int) $id, $secret), $tag)) {
                $invalid = true; break;
            }
        } else {
            // Preserve pre-SaaS Shalom replies signed with its original key.
            if (!$allowLegacy || !preg_match('/\A\[([A-Z][A-Z0-9_-]{1,19})#([1-9][0-9]{0,9})\.([A-Za-z0-9_-]{22})\]/i', $candidate, $parts)
                || (int) $parts[2] > 2147483647) { $invalid = true; break; }
            $prefix = strtoupper($parts[1]); $type = 'E'; $id = $parts[2]; $tag = $parts[3];
            if (str_ends_with($prefix, '-I')) { $prefix = substr($prefix, 0, -2); $type = 'I'; }
            if (!in_array($prefix, array_map('strtoupper', inboundEmailAcceptedMarkerPrefixes()), true)
                || !($type === 'E' ? applicationInboundMarkerIsValid($prefix, (int) $id, $tag)
                    : applicationInquiryInboundMarkerIsValid($prefix, (int) $id, $tag))) {
                $invalid = true; break;
            }
            $key = currentAccountKey();
        }
        $targets[$key . ':' . $type . ':' . $id] = ['account_key' => $key, 'type' => $type, 'id' => (int) $id];
    }
    $keys = array_values(array_unique(array_column($targets, 'account_key')));
    $reason = $invalid ? 'A routing token is invalid, incomplete, or belongs to an unavailable Account.'
        : (count($keys) > 1 ? 'Routing tokens identify more than one Account.'
        : ($keys === [] ? 'No valid Account routing token was found.' : ''));
    return ['account_key' => $reason === '' ? $keys[0] : null, 'targets' => array_values($targets), 'reason' => $reason];
}

function accountMailMessageText(array $message): string {
    return (string) ($message['subject'] ?? '') . "\n" . (string) ($message['body_text'] ?? '');
}

function localAccountMailRoute(array $message): array {
    return resolveAccountMailMarkers(accountMailMessageText($message),
        static fn(string $key) => $key === currentAccountKey() ? accountMailSigningKey() : null, accountIsPrimary());
}

function platformMailRoute(mysqli $conn, array $message): array {
    if (!accountIsPrimary()) throw new RuntimeException('Shared mail routing requires the primary service.');
    $keys = [];
    return resolveAccountMailMarkers(accountMailMessageText($message), static function (string $key) use ($conn, &$keys) {
        if ($key === currentAccountKey()) return accountMailSigningKey();
        if (!array_key_exists($key, $keys)) {
            $row = $conn->execute_query("SELECT api_key_encrypted FROM platform_accounts WHERE account_key=? AND state='ready'", [$key])->fetch_assoc();
            $keys[$key] = $row ? \Dnr\Security\ApplicationKey::open($row['api_key_encrypted']) : null;
        }
        return $keys[$key];
    }, true);
}

function accountMailPack(array $message): array {
    $message['deduplication_hash'] = bin2hex($message['deduplication_hash']);
    return $message;
}

function accountMailUnpack(array $message): array {
    if (!is_string($message['deduplication_hash'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $message['deduplication_hash'])) {
        throw new InvalidArgumentException('Invalid mail fingerprint.');
    }
    $message['deduplication_hash'] = hex2bin($message['deduplication_hash']);
    return $message;
}

/** A manual decision is signed separately from email tokens and bound to one message/Account. */
function accountMailManualTag(array $assignment, string $secret): string {
    return hash_hmac('sha256', "moed-manual-mail-v1\0" . $assignment['account_key'] . "\0"
        . $assignment['fingerprint'] . "\0" . $assignment['reviewed_by'] . "\0" . $assignment['reviewed_at'], $secret);
}

function accountMailManualAssignmentIsValid(array $assignment, array $message, string $accountKey, string $secret): bool {
    return strlen($secret) >= 32
        && ($assignment['account_key'] ?? null) === $accountKey
        && is_string($message['deduplication_hash'] ?? null) && strlen($message['deduplication_hash']) === 32
        && ($assignment['fingerprint'] ?? null) === bin2hex($message['deduplication_hash'])
        && is_int($assignment['reviewed_by'] ?? null) && $assignment['reviewed_by'] > 0
        && is_string($assignment['reviewed_at'] ?? null)
        && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $assignment['reviewed_at']) === 1
        && is_string($assignment['signature'] ?? null)
        && hash_equals(accountMailManualTag($assignment, $secret), $assignment['signature']);
}

function platformMailAccountSecret(mysqli $conn, string $accountKey): ?string {
    if (!accountIsPrimary()) throw new RuntimeException('Shared mail routing requires the primary service.');
    if ($accountKey === currentAccountKey()) return accountMailSigningKey();
    $row = $conn->execute_query("SELECT api_key_encrypted FROM platform_accounts WHERE account_key=? AND state='ready'", [$accountKey])->fetch_assoc();
    return $row ? \Dnr\Security\ApplicationKey::open($row['api_key_encrypted']) : null;
}

/** Called only after the review controller authorizes the SuperAdmin's explicit decision. */
function platformMailManualAssignment(mysqli $conn, array $message, string $accountKey, int $reviewedBy): array {
    $secret = platformMailAccountSecret($conn, $accountKey);
    if ($secret === null || strlen($secret) < 32 || $reviewedBy < 1) {
        throw new InvalidArgumentException('Choose an available Account.');
    }
    $assignment = ['account_key' => $accountKey, 'fingerprint' => bin2hex($message['deduplication_hash']),
        'reviewed_by' => $reviewedBy, 'reviewed_at' => gmdate('Y-m-d H:i:s')];
    $assignment['signature'] = accountMailManualTag($assignment, $secret);
    return $assignment;
}

function platformMailPayloadRoute(mysqli $conn, array $payload): array {
    $message = accountMailUnpack($payload['message']);
    if (!array_key_exists('manual_routing', $payload)) return platformMailRoute($conn, $message);
    $assignment = $payload['manual_routing'];
    $key = is_array($assignment) && is_string($assignment['account_key'] ?? null) ? $assignment['account_key'] : '';
    $secret = platformMailAccountSecret($conn, $key);
    if ($secret === null || !is_array($assignment) || !accountMailManualAssignmentIsValid($assignment, $message, $key, $secret)) {
        return ['account_key' => null, 'reason' => 'The manual destination is unavailable or its routing decision could not be verified.'];
    }
    return ['account_key' => $key, 'reason' => ''];
}

/** A lost delivery response must never permit a second delivery to a different Account. */
function platformMailDeliveryHasStarted(array $row, array $payload): bool {
    return isset($payload['delivery_account_key']) || (int) $row['attempts'] > 0;
}

/** Stored separately: ordinary Inbox queries cannot see uncertain/shared mail. */
function storePlatformInboundMail(mysqli $conn, string $transport, string $transportKey, array $message, ?string $gateway = null): array {
    $route = platformMailRoute($conn, $message);
    $payload = inboundEmailJson(['message' => accountMailPack($message), 'gateway' => $gateway ?? inboundEmailGatewayAddress()]);
    $conn->execute_query('INSERT IGNORE INTO platform_inbound_mail
        (transport, transport_key, deduplication_hash, payload, account_key, status, review_reason, received_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$transport, $transportKey, $message['deduplication_hash'], $payload,
        $route['account_key'], $route['account_key'] === null ? 'review' : 'pending', $route['reason'], $message['received_at']]);
    $inserted = $conn->affected_rows === 1;
    $row = $conn->execute_query('SELECT id FROM platform_inbound_mail WHERE deduplication_hash=? OR (transport=? AND transport_key=?) LIMIT 1',
        [$message['deduplication_hash'], $transport, $transportKey])->fetch_assoc();
    if (!$row) throw new RuntimeException('Unable to store shared inbound mail.');
    $conn->execute_query('INSERT IGNORE INTO inbound_email_import_receipts (deduplication_hash) VALUES (?)', [$message['deduplication_hash']]);
    return ['id' => (int) $row['id'], 'inserted' => $inserted];
}

/** The mailbox importer never puts an unclassified message into an Account. */
function receiveAccountInboundMail(mysqli $conn, string $transport, string $transportKey, array $message): array {
    if (!accountMailEnabled()) return storeInboundEmailMessage($conn, $transport, $transportKey, $message);
    if (!accountIsPrimary()) throw new RuntimeException('The shared mailbox must be polled by the primary service only.');
    return storePlatformInboundMail($conn, $transport, $transportKey, $message);
}

function acceptAccountInboundMail(mysqli $conn, array $payload): array {
    $message = accountMailUnpack($payload['message'] ?? []);
    $assignment = $payload['manual_routing'] ?? null;
    if (array_key_exists('manual_routing', $payload)) {
        if (!is_array($assignment) || !accountMailManualAssignmentIsValid($assignment, $message, currentAccountKey(), accountMailSigningKey())) {
            throw new RuntimeException('The manual mail destination could not be verified.');
        }
    } elseif (localAccountMailRoute($message)['account_key'] !== currentAccountKey()) {
        throw new RuntimeException('Mail does not identify this Account.');
    }
    $stored = storeInboundEmailMessage($conn, 'file', 'platform:' . bin2hex($message['deduplication_hash']), $message, (string) $payload['gateway'], $assignment);
    if ($stored['inserted'] && $assignment === null) {
        try { processInboundEmailMessage($conn, $stored['id']); }
        catch (Throwable $error) { failInboundEmailMessage($conn, $stored['id'], $error); }
    }
    return ['ok' => true, 'id' => $stored['id']];
}

/** Match the Needs Review queue, excluding routed, rejected, and cleared mail. */
function platformMailReviewCount(mysqli $conn): int {
    if (!accountMailEnabled() || !accountIsPrimary()) return 0;
    return (int) $conn->query("SELECT COUNT(*) FROM platform_inbound_mail
        WHERE status='review' AND payload IS NOT NULL")->fetch_row()[0];
}

/** Queue previews never transfer message bodies to PHP. */
function platformMailReviewRows(mysqli $conn, string $status, int $offset): array {
    return $conn->execute_query("SELECT id, review_reason, received_at,
        LEFT(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.message.sender_name')),255) AS sender_name,
        LEFT(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.message.sender_address')),254) AS sender_address,
        LEFT(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.message.subject')),1000) AS subject
        FROM platform_inbound_mail WHERE status=? AND payload IS NOT NULL
        ORDER BY id DESC LIMIT 20 OFFSET ?", [$status, max(0, $offset)])->fetch_all(MYSQLI_ASSOC);
}

function deliverPlatformInboundMail(mysqli $conn, int $limit = 20, int $byteBudget = 33554432): int {
    if (!accountIsPrimary()) return 0;
    require_once __DIR__ . '/account_login_helpers.php';
    if ((int) $conn->query("SELECT GET_LOCK('moed-platform-mail-delivery',0)")->fetch_row()[0] !== 1) return 0;
    $processed = 0;
    try {
        $rows = $conn->execute_query("SELECT id, OCTET_LENGTH(payload) AS payload_bytes FROM platform_inbound_mail WHERE status='pending' AND payload IS NOT NULL
            AND (retry_after IS NULL OR retry_after <= UTC_TIMESTAMP()) ORDER BY id LIMIT ?", [max(1, min(100, $limit))])->fetch_all(MYSQLI_ASSOC);
        $bytes = 0;
        foreach ($rows as $candidate) {
            // Permit one accepted large message to progress; never hold a batch
            // of bodies. Remaining IDs are retried by the next worker cycle.
            if ($bytes > 0 && $bytes + (int) $candidate['payload_bytes'] > max(1, $byteBudget)) break;
            $row = $conn->execute_query("SELECT * FROM platform_inbound_mail WHERE id=? AND status='pending' AND payload IS NOT NULL", [$candidate['id']])->fetch_assoc();
            if (!$row) continue;
            $bytes += strlen($row['payload']);
            try {
                $payload = json_decode($row['payload'], true, 16, JSON_THROW_ON_ERROR);
                $route = platformMailPayloadRoute($conn, $payload);
                if ($route['account_key'] === null || $route['account_key'] !== $row['account_key']
                    || (isset($payload['delivery_account_key']) && $payload['delivery_account_key'] !== $route['account_key'])) {
                    $conn->execute_query("UPDATE platform_inbound_mail SET status='review', review_reason=? WHERE id=?",
                        [$route['reason'] ?: 'The routing destination changed.', $row['id']]);
                    continue;
                }
                // Persist before calling the destination, including if the process dies or the response is lost.
                $payload['delivery_account_key'] = $route['account_key'];
                $conn->execute_query('UPDATE platform_inbound_mail SET payload=? WHERE id=?', [inboundEmailJson($payload), $row['id']]);
                if ($route['account_key'] === currentAccountKey()) {
                    $result = acceptAccountInboundMail($conn, $payload);
                } else {
                    $account = $conn->execute_query("SELECT account_key, public_url, api_key_encrypted FROM platform_accounts WHERE account_key=? AND state='ready'", [$route['account_key']])->fetch_assoc();
                    if (!$account) throw new RuntimeException('Account unavailable.');
                    $result = platformMemberCall($account, 'deliver_mail', $payload);
                }
                if (empty($result['ok'])) throw new RuntimeException('Account did not accept delivery.');
                // Deduplication metadata remains; delivered bodies live only in their Account.
                $conn->execute_query("UPDATE platform_inbound_mail SET status='delivered', payload=NULL,
                    review_reason='', delivered_at=UTC_TIMESTAMP(), attempts=attempts+1 WHERE id=?", [$row['id']]);
            } catch (Throwable $error) {
                $conn->execute_query("UPDATE platform_inbound_mail SET attempts=attempts+1,
                    status=IF(attempts>=5,'review','pending'), retry_after=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 60 SECOND),
                    review_reason='Delivery could not be confirmed. The message is retained for retry.' WHERE id=?", [$row['id']]);
                applicationLog('error', 'Account mail delivery failed', ['message_id' => (int) $row['id']]);
            } finally {
                unset($row, $payload, $route, $result, $account);
            }
            $processed++;
        }
    } finally { $conn->query("DO RELEASE_LOCK('moed-platform-mail-delivery')"); }
    return $processed;
}
