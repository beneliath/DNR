<?php

declare(strict_types=1);

function inboundEmailRequiresAuthenticatedFrom(): bool
{
    $configured = getenv('DNR_INBOUND_REQUIRE_AUTHENTICATED_FROM');
    if ($configured === false || trim((string) $configured) === '') {
        return true;
    }
    $configured = trim((string) $configured);
    if (!in_array($configured, ['0', '1'], true)) {
        throw new RuntimeException('DNR_INBOUND_REQUIRE_AUTHENTICATED_FROM must be 0 or 1.');
    }
    return $configured === '1';
}

/** @return list<string> */
function inboundEmailTrustedAuthenticationServers(): array
{
    $servers = [];
    foreach (explode(',', (string) (getenv('DNR_INBOUND_TRUSTED_AUTH_SERVERS') ?: '')) as $server) {
        $server = strtolower(rtrim(trim($server), '.'));
        if ($server !== '' && preg_match('/\A[A-Za-z0-9.-]{1,253}\z/', $server) === 1) {
            $servers[$server] = true;
        }
    }
    return array_keys($servers);
}

function inboundEmailTopAuthenticationResults(string $rawHeaders): ?string
{
    return inboundEmailHeaderValues($rawHeaders, 'Authentication-Results')[0] ?? null;
}

/** @return list<string> */
function inboundEmailHeaderValues(string $rawHeaders, string $name): array
{
    $unfolded = preg_replace("/\r?\n[\t ]+/", ' ', $rawHeaders);
    if (!is_string($unfolded)) {
        return [];
    }
    $values = [];
    foreach (preg_split("/\r?\n/", $unfolded) ?: [] as $line) {
        if ($line === '') {
            break;
        }
        if (preg_match('/\A' . preg_quote($name, '/') . '\s*:\s*(.*)\z/i', $line, $match) === 1) {
            $values[] = trim($match[1]);
        }
    }
    return $values;
}

/** @return array{required: bool, trusted: bool, authentication_server: ?string, method: ?string, reason: ?string} */
function inboundEmailSenderAuthentication(array $message, bool $force = false): array
{
    $required = $force || inboundEmailRequiresAuthenticatedFrom();
    $authentication = [
        'required' => $required, 'trusted' => false, 'authentication_server' => null,
        'method' => null, 'reason' => null,
    ];
    if (!$required) {
        return $authentication;
    }

    $rawHeaders = (string) ($message['raw_headers'] ?? '');
    $senderAddress = normalizeInboundEmailAddress($message['sender_address'] ?? '');
    $assertions = inboundEmailHeaderValues($rawHeaders, 'X-Dnr-Sender-Authentication');
    if ($assertions !== []) {
        $kind = count($assertions) === 1
            ? \Dnr\Security\InboundBridgeAuthentication::verify(
                $assertions[0], $senderAddress, (string) ($message['rfc_message_id'] ?? '')
            ) : null;
        $authentication['trusted'] = in_array($kind, ['internal', 'dmarc'], true);
        $authentication['authentication_server'] = $kind !== null ? 'proton-bridge' : null;
        $authentication['method'] = $authentication['trusted'] ? 'proton-' . $kind : null;
        $authentication['reason'] = $authentication['trusted'] ? null : ($kind === 'unverified'
            ? 'Proton did not authenticate this message for automatic processing, or marked it as imported, spam, or phishing.'
            : 'The Proton Bridge sender-authentication assertion is missing, invalid, or does not match this message.');
        // A failed Bridge assertion must never fall back to sender-supplied
        // Authentication-Results, including in imported MIME messages.
        return $authentication;
    }

    $result = inboundEmailTopAuthenticationResults($rawHeaders);
    if ($result === null) {
        $authentication['reason'] = in_array('internal', inboundEmailHeaderValues($rawHeaders, 'X-Pm-Origin'), true)
            ? 'Sender authentication is unavailable for this Proton internal message; it requires manual review.'
            : 'The mailbox did not supply a trusted sender-authentication result.';
        return $authentication;
    }
    $authservSection = trim(explode(';', $result, 2)[0]);
    $authservId = strtolower(rtrim((string) (preg_split('/\s+/', $authservSection)[0] ?? ''), '.'));
    $trustedServers = inboundEmailTrustedAuthenticationServers();
    $authentication['authentication_server'] = $authservId !== '' ? $authservId : null;
    if ($authservId === '' || !in_array($authservId, $trustedServers, true)) {
        $authentication['reason'] = $trustedServers === []
            ? 'No trusted mailbox authentication servers are configured, and no verified Proton Bridge assertion is available.'
            : 'The authentication result is not from a configured trusted mailbox server.';
        return $authentication;
    }

    $senderDomain = strrchr($senderAddress, '@');
    $senderDomain = $senderDomain === false ? '' : strtolower(substr($senderDomain, 1));
    $dmarcPassed = false;
    foreach (preg_split('/\s*;\s*/', $result) ?: [] as $methodResult) {
        if (preg_match('/\Admarc\s*=\s*pass\b/i', $methodResult) !== 1) {
            continue;
        }
        if (preg_match('/\bheader\.from\s*=\s*(?:"([^"]+)"|([^;\s]+))/i', $methodResult, $match) !== 1) {
            continue;
        }
        $quotedDomain = (string) $match[1];
        $headerFrom = $quotedDomain !== '' ? $quotedDomain : (string) ($match[2] ?? '');
        $headerFrom = strtolower(rtrim(trim($headerFrom, '<>'), '.'));
        if ($senderDomain !== '' && hash_equals($senderDomain, $headerFrom)) {
            $dmarcPassed = true;
            break;
        }
    }

    $authentication['trusted'] = $dmarcPassed;
    $authentication['method'] = $dmarcPassed ? 'dmarc' : null;
    $authentication['reason'] = $dmarcPassed ? null
        : 'The visible sender does not have a trusted aligned DMARC pass from the mailbox provider.';
    return $authentication;
}
