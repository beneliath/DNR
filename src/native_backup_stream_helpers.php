<?php
declare(strict_types=1);
require_once __DIR__ . '/database_backup_helpers.php';

/** Native deployment archives use pipes: plaintext never needs a host staging file. */
function transformNativeBackupStream($input, $output, string $password, bool $encrypt): void
{
    if (strlen($password) < DNR_DATABASE_BACKUP_MINIMUM_PASSWORD_BYTES) throw new InvalidArgumentException('Invalid backup password.');
    $key = $state = null;
    try {
        if ($encrypt) {
            $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
            $key = databaseBackupDeriveEncryptionKey($password, $salt);
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            databaseBackupWriteBytes($output, DNR_DATABASE_BACKUP_ENCRYPTED_MAGIC . $salt . $header);
            $chunk = fread($input, DNR_DATABASE_BACKUP_ENCRYPTION_CHUNK_BYTES);
            if ($chunk === false || $chunk === '') throw new RuntimeException('Empty native backup.');
            do {
                $next = fread($input, DNR_DATABASE_BACKUP_ENCRYPTION_CHUNK_BYTES);
                if ($next === false) throw new RuntimeException('Unable to read native backup.');
                $final = $next === '' && feof($input);
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '',
                    $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
                databaseBackupWriteBytes($output, pack('N', strlen($cipher)) . $cipher);
                $chunk = $next;
            } while (!$final);
        } else {
            $magic = databaseBackupReadExactBytes($input, strlen(DNR_DATABASE_BACKUP_ENCRYPTED_MAGIC));
            $legacy = hash_equals(DNR_DATABASE_BACKUP_ENCRYPTED_LEGACY_MAGIC, $magic);
            if (!$legacy && !hash_equals(DNR_DATABASE_BACKUP_ENCRYPTED_MAGIC, $magic)) throw new RuntimeException('Invalid encrypted backup.');
            $salt = databaseBackupReadExactBytes($input, SODIUM_CRYPTO_PWHASH_SALTBYTES);
            $key = databaseBackupDeriveEncryptionKey($password, $salt, $legacy);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(databaseBackupReadExactBytes($input,
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $key);
            do {
                $length = unpack('Nlength', databaseBackupReadExactBytes($input, 4))['length'];
                if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES
                    || $length > DNR_DATABASE_BACKUP_ENCRYPTION_CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                    throw new RuntimeException('Invalid encrypted frame.');
                }
                $decoded = sodium_crypto_secretstream_xchacha20poly1305_pull($state, databaseBackupReadExactBytes($input, $length));
                if ($decoded === false) throw new RuntimeException('Backup authentication failed.');
                [$plain, $tag] = $decoded;
                if (!in_array($tag, [SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL], true)) throw new RuntimeException('Invalid encrypted tag.');
                databaseBackupWriteBytes($output, $plain);
            } while ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            if (fread($input, 1) !== '' || !feof($input)) throw new RuntimeException('Trailing encrypted data.');
        }
        if (!fflush($output)) throw new RuntimeException('Unable to flush backup stream.');
    } finally {
        if (is_string($key)) sodium_memzero($key);
        if (is_string($state)) sodium_memzero($state);
    }
}
