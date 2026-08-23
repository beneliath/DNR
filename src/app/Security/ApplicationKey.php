<?php

declare(strict_types=1);

namespace Dnr\Security;

final class ApplicationKey
{
    private static ?string $key = null;

    public static function bytes(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $encoded = '';
        $path = getenv('DNR_2FA_ENCRYPTION_KEY_FILE');
        if (is_string($path) && $path !== '' && is_readable($path)) {
            $contents = file_get_contents($path);
            $encoded = is_string($contents) ? trim($contents) : '';
        }
        if ($encoded === '') {
            $environmentValue = getenv('DNR_2FA_ENCRYPTION_KEY');
            $encoded = is_string($environmentValue) ? trim($environmentValue) : '';
        }
        $decoded = $encoded !== '' ? base64_decode($encoded, true) : false;
        if (!is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException(
                'The application encryption key must be a base64-encoded 32-byte key.'
            );
        }
        self::$key = $decoded;
        return self::$key;
    }

    public static function seal(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, self::bytes()));
    }

    public static function open(string $encodedPayload): string
    {
        $payload = base64_decode($encodedPayload, true);
        if (!is_string($payload) || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('The encrypted application payload is invalid.');
        }
        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, self::bytes());
        if (!is_string($plaintext)) {
            throw new \RuntimeException('The encrypted application payload could not be decrypted.');
        }
        return $plaintext;
    }
}
