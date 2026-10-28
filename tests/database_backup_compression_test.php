<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/database_backup_helpers.php';

function expectBackupCompression(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

// Build envelopes independently of the production writer to exercise old formats
// and authenticated but invalid compressed content.
function compressionTestEnvelope(array $chunks, string $password, string $magic, ?int $lastTag = null): string {
    $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
    $key = databaseBackupDeriveEncryptionKey($password, $salt, $magic === "DNRBACKUP-ENC-1\n");
    [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
    $archive = $magic . $salt . $header;
    try {
        foreach ($chunks as $index => $chunk) {
            $tag = $index === count($chunks) - 1
                ? ($lastTag ?? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL)
                : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            $ciphertext = sodium_crypto_secretstream_xchacha20poly1305_push(
                $state, $chunk, $magic === "DNRBACKUP-ENC-3\n" ? $magic : '', $tag);
            $archive .= pack('N', strlen($ciphertext)) . $ciphertext;
        }
        return $archive;
    } finally {
        sodium_memzero($key);
        sodium_memzero($state);
    }
}

$paths = [];
$temporary = static function (string $contents) use (&$paths): string {
    $path = tempnam(sys_get_temp_dir(), 'dnr-compression-test-');
    if ($path === false) throw new RuntimeException('Cannot create backup test fixture.');
    $paths[] = $path;
    file_put_contents($path, $contents);
    return $path;
};
$password = 'compression fixture backup password';
$limit = 4 * 1024 * 1024;
$reject = static function (string $archive, string $message, ?int $maximum = null, ?string $pass = null)
    use ($temporary, $password, $limit, &$paths): void {
    $before = glob(sys_get_temp_dir() . '/dnr-decrypted-backup-*');
    try {
        $unexpected = decryptDatabaseBackup($temporary($archive), $pass ?? $password, $maximum ?? $limit);
        $paths[] = $unexpected['path'];
    } catch (RuntimeException $exception) {
        expectBackupCompression(glob(sys_get_temp_dir() . '/dnr-decrypted-backup-*') === $before,
            'Failed restores must remove their temporary plaintext: ' . $message);
        return;
    }
    throw new RuntimeException('Accepted invalid backup: ' . $message);
};

try {
    // Tiny, exact-boundary and multiple-frame incompressible data must fit even
    // when gzip is slightly larger than the original data.
    foreach ([1, 65536, 131079] as $size) {
        $data = random_bytes($size);
        $source = $temporary($data);
        $encrypted = encryptDatabaseBackup($source, $password, $size);
        $paths[] = $encrypted['path'];
        expectBackupCompression(str_starts_with(file_get_contents($encrypted['path']), "DNRBACKUP-ENC-3\n"),
            'New browser backups must use the compressed format.');
        expectBackupCompression($encrypted['size'] <= databaseBackupMaximumEncryptedBytes($size),
            'The download limit must allow worst-case gzip expansion.');
        $decrypted = decryptDatabaseBackup($encrypted['path'], $password, $size);
        $paths[] = $decrypted['path'];
        expectBackupCompression(file_get_contents($decrypted['path']) === $data, 'Binary round trip failed.');
    }

    // Keep an exact-boundary fixture reproducible across PHP/zlib builds, and
    // cover a gzip member whose final DEFLATE block follows a flushed full frame.
    $boundaryData = '';
    for ($i = 0; $i < 2048; ++$i) $boundaryData .= hash('sha256', pack('N', $i), true);
    $deflater = deflate_init(ZLIB_ENCODING_GZIP);
    $boundaryGzip = deflate_add($deflater, $boundaryData, ZLIB_SYNC_FLUSH)
        . deflate_add($deflater, '', ZLIB_FINISH);
    $boundaryArchive = compressionTestEnvelope([$boundaryGzip], $password, "DNRBACKUP-ENC-3\n");
    $restored = decryptDatabaseBackup($temporary($boundaryArchive), $password, strlen($boundaryData));
    $paths[] = $restored['path'];
    expectBackupCompression(file_get_contents($restored['path']) === $boundaryData,
        'An exact 64 KiB frame with a separate final block must restore.');

    // Model the real archive: base64 attachments plus repeated JSON row data.
    $data = base64_encode(random_bytes(1048576)) . str_repeat('{"table":"contacts","values":["MQ==","dGVzdA=="]}' . "\n", 10000);
    $source = $temporary($data);
    $encrypted = encryptDatabaseBackup($source, $password, strlen($data));
    $paths[] = $encrypted['path'];
    expectBackupCompression($encrypted['size'] < strlen($data) * 0.8, 'Representative backups must shrink materially.');
    $decrypted = decryptDatabaseBackup($encrypted['path'], $password, strlen($data));
    $paths[] = $decrypted['path'];
    expectBackupCompression(hash_file('sha256', $decrypted['path']) === hash_file('sha256', $source),
        'Compression must reproduce all archive bytes.');
    printf("Synthetic database/file archive: %d -> %d bytes (%.1f%% smaller).\n",
        strlen($data), $encrypted['size'], 100 * (1 - $encrypted['size'] / strlen($data)));
    $archive = file_get_contents($encrypted['path']);
    $reject($archive, 'wrong password', null, 'incorrect backup password');
    $reject($archive, 'total decompressed size limit', strlen($data) - 1);
    $reject(substr($archive, 0, -1), 'truncated ciphertext');
    $reject($archive . 'extra', 'trailing ciphertext');
    $changed = $archive;
    $changed[strlen($changed) - 1] = chr(ord($changed[strlen($changed) - 1]) ^ 1);
    $reject($changed, 'tampered ciphertext');
    $reject("DNRBACKUP-ENC-2\n" . substr($archive, strlen("DNRBACKUP-ENC-3\n")), 'format downgrade');

    foreach (["DNRBACKUP-ENC-1\n", "DNRBACKUP-ENC-2\n"] as $magic) {
        $oldData = random_bytes(131079);
        $oldArchive = compressionTestEnvelope(str_split($oldData, 65536), $password, $magic);
        $restored = decryptDatabaseBackup($temporary($oldArchive), $password, strlen($oldData));
        $paths[] = $restored['path'];
        expectBackupCompression(file_get_contents($restored['path']) === $oldData,
            'Existing uncompressed backups must still restore: ' . trim($magic));
    }

    $validGzip = gzencode('valid data', 6);
    $badChecksum = $validGzip;
    $badChecksum[strlen($badChecksum) - 8] = chr(ord($badChecksum[strlen($badChecksum) - 8]) ^ 1);
    foreach ([
        'invalid gzip' => 'not gzip',
        'truncated gzip' => substr($validGzip, 0, -5),
        'gzip checksum mismatch' => $badChecksum,
        'empty gzip' => gzencode(''),
        'oversized decompressed frame' => gzencode(str_repeat('x', 65537)),
        'high expansion frame' => gzencode(str_repeat('x', 16 * 1024 * 1024)),
    ] as $message => $payload) {
        $reject(compressionTestEnvelope([$payload], $password, "DNRBACKUP-ENC-3\n"), $message);
    }
    $reject(compressionTestEnvelope([$validGzip], $password, "DNRBACKUP-ENC-3\n",
        SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE), 'missing final frame');
    $reject(compressionTestEnvelope([$validGzip], $password, "DNRBACKUP-ENC-3\n",
        SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_PUSH), 'unsupported frame tag');
    try {
        $unexpected = encryptDatabaseBackup($source, $password, strlen($data) - 1);
        $paths[] = $unexpected['path'];
        throw new LogicException('Compression must not bypass the uncompressed export limit.');
    } catch (RuntimeException $expected) {}
    echo "Database backup compression tests passed.\n";
} finally {
    foreach ($paths as $path) @unlink($path);
}
