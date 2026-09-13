<?php

declare(strict_types=1);
require_once __DIR__ . '/database_backup_helpers.php';

/** Receives only an encrypted archive; full-schema credentials stay in the exporter. */
function requestEncryptedDatabaseBackup(array $request, int $maximum): array {
    $url = (string) (getenv('DNR_BACKUP_SERVICE_URL') ?: 'http://backup/export.php');
    $path = tempnam(sys_get_temp_dir(), 'dnr-export-download-');
    if ($path === false) throw new RuntimeException('Unable to allocate the encrypted download.');
    chmod($path, 0600);
    $file = fopen($path, 'w+b');
    $curl = curl_init($url);
    $size = 0;
    $limit = databaseBackupMaximumEncryptedBytes($maximum);
    try {
        curl_setopt_array($curl, [CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Expect:'],
            CURLOPT_POSTFIELDS => json_encode($request, JSON_THROW_ON_ERROR),
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 310,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_PROXY => '',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use ($file, &$size, $limit): int {
                $size += strlen($data);
                return $size > $limit ? 0 : (int) fwrite($file, $data);
            },
        ]);
        if (!curl_exec($curl)) throw new RuntimeException('The database exporter is unavailable. Try again later.');
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        rewind($file);
        if ($status !== 200) {
            $error = json_decode((string) fread($file, 4096), true);
            throw new RuntimeException(is_string($error['error'] ?? null) ? $error['error'] : 'The database export failed.');
        }
        if (fread($file, strlen(DNR_DATABASE_BACKUP_ENCRYPTED_MAGIC)) !== DNR_DATABASE_BACKUP_ENCRYPTED_MAGIC) {
            throw new RuntimeException('The exporter returned an invalid encrypted archive.');
        }
        fclose($file);
        $file = null;
        $result = ['path' => $path, 'size' => $size];
        $path = null;
        return $result;
    } finally {
        if (is_resource($file)) fclose($file);
        if ($path !== null) @unlink($path);
    }
}
