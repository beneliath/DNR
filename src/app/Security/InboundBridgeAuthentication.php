<?php

declare(strict_types=1);

namespace Dnr\Security;

/** Verifies assertions issued by the bundled Bridge adapter, never raw X-Pm headers. */
final class InboundBridgeAuthentication
{
    public static function verify(string $assertion, string $sender, string $messageId): ?string
    {
        if (strlen($assertion) > 4096
            || preg_match('/\Av1\.([A-Za-z0-9_-]+)\.([a-f0-9]{64})\z/', $assertion, $parts) !== 1
        ) {
            return null;
        }
        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if (!is_string($payload)) {
            return null;
        }
        try {
            $key = hash_hmac('sha256', 'dnr:proton-sender-auth:key:v1', InboundRoutingKey::bytes(), true);
        } catch (\RuntimeException) {
            return null;
        }
        $expected = hash_hmac('sha256', "dnr:proton-sender-auth:v1\n" . $payload, $key);
        if (!hash_equals($expected, $parts[2])) {
            return null;
        }
        $data = json_decode($payload, true, 8);
        if (!is_array($data)
            || !in_array($data['kind'] ?? null, ['internal', 'dmarc', 'unverified'], true)
            || $sender === '' || $messageId === ''
            || ($data['from'] ?? null) !== $sender
            || ($data['id'] ?? null) !== hash('sha256', strtolower(trim($messageId, "<> \t\r\n")))
        ) {
            return null;
        }
        return $data['kind'];
    }
}
