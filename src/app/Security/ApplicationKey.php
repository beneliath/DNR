<?php

declare(strict_types=1);

namespace Dnr\Security;

final class ApplicationKey
{
    /** @var array{active: string, legacy: string, keys: array<string, string>, versioned: bool}|null */
    private static ?array $ring = null;

    /** @return array{active: string, legacy: string, keys: array<string, string>, versioned: bool} */
    private static function ring(): array
    {
        if (self::$ring !== null) return self::$ring;
        $path = getenv('DNR_APPLICATION_KEYRING_FILE');
        if (!$path) {
            $existing = getenv('DNR_2FA_ENCRYPTION_KEY_FILE');
            if (is_string($existing) && $existing !== '' && is_readable($existing)
                && str_starts_with(ltrim((string) file_get_contents($existing)), '{')) $path = $existing;
        }
        if (is_string($path) && $path !== '') {
            $raw = @file_get_contents($path);
            if ($raw === false) throw new \RuntimeException('The application keyring is not readable.');
            $value = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($value) || !is_array($value['keys'] ?? null) || count($value['keys']) < 1 || count($value['keys']) > 8) {
                throw new \RuntimeException('The keyring must contain between one and eight keys.');
            }
            $keys = [];
            foreach ($value['keys'] as $id => $encoded) {
                if (!is_string($id) || preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]{0,47}\z/', $id) !== 1) {
                    throw new \RuntimeException('Invalid application key identifier.');
                }
                $keys[$id] = self::decode($encoded);
            }
            $active = $value['active'] ?? null;
            $legacy = $value['legacy'] ?? '';
            // Explicit null retires unversioned payloads after a verified migration.
            if (!is_string($active) || !is_string($legacy) || (!isset($keys[$active]) || ($legacy !== '' && !isset($keys[$legacy])))) {
                throw new \RuntimeException('The active and legacy application keys must exist.');
            }
            return self::$ring = ['active' => $active, 'legacy' => $legacy, 'keys' => $keys, 'versioned' => true];
        }
        $encoded = '';
        $path = getenv('DNR_2FA_ENCRYPTION_KEY_FILE');
        if (is_string($path) && $path !== '') {
            $contents = @file_get_contents($path);
            if ($contents === false) throw new \RuntimeException('The application encryption key is not readable.');
            $encoded = trim($contents);
        } else {
            $value = getenv('DNR_2FA_ENCRYPTION_KEY');
            $encoded = is_string($value) ? trim($value) : '';
        }
        return self::$ring = ['active' => 'legacy', 'legacy' => 'legacy',
            'keys' => ['legacy' => self::decode($encoded)], 'versioned' => false];
    }

    private static function decode(mixed $encoded): string
    {
        $key = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('An application encryption key must be a base64-encoded 32-byte key.');
        }
        return $key;
    }

    public static function activeId(): string { return self::ring()['active']; }
    public static function legacyId(): string { return self::ring()['legacy']; }
    public static function versioned(): bool { return self::ring()['versioned']; }
    public static function bytes(): string { return self::ring()['keys'][self::activeId()]; }

    /** @return array<string, string> Keyed HMACs allow existing recovery codes during rotation. */
    public static function lookupHashes(string $message): array
    {
        $hashes = [];
        foreach (self::ring()['keys'] as $id => $key) $hashes[$id] = hash_hmac('sha256', $message, $key, true);
        return $hashes;
    }

    public static function payloadKeyId(string $payload): string
    {
        if (!str_starts_with($payload, 'dnr1:')) {
            if (self::legacyId() === '') throw new \RuntimeException('Unversioned encrypted payloads are no longer enabled.');
            return self::legacyId();
        }
        $parts = explode(':', $payload, 3);
        if (count($parts) !== 3 || !isset(self::ring()['keys'][$parts[1]])) {
            throw new \RuntimeException('The encrypted payload requires an unavailable key.');
        }
        return $parts[1];
    }

    public static function seal(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $payload = base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, self::bytes()));
        return self::versioned() ? 'dnr1:' . self::activeId() . ':' . $payload : $payload;
    }

    public static function open(string $encodedPayload): string
    {
        $id = self::payloadKeyId($encodedPayload);
        if (str_starts_with($encodedPayload, 'dnr1:')) $encodedPayload = explode(':', $encodedPayload, 3)[2];
        $payload = base64_decode($encodedPayload, true);
        if (!is_string($payload) || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('The encrypted application payload is invalid.');
        }
        $plaintext = sodium_crypto_secretbox_open(substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::ring()['keys'][$id]);
        if (!is_string($plaintext)) throw new \RuntimeException('The encrypted application payload could not be decrypted.');
        return $plaintext;
    }
}
