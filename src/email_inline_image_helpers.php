<?php
declare(strict_types=1);
require_once __DIR__ . '/application_runtime.php';

/** Include a snapshot of the configured logo in the encrypted email payload. */
function emailBrandInlineImage(): array
{
    $path = realpath(__DIR__ . '/' . applicationBrandEmailLogo());
    if (!$path || !str_starts_with($path, __DIR__ . '/assets/') || filesize($path) > 256 * 1024) {
        throw new RuntimeException('The email logo is unavailable.');
    }
    $data = file_get_contents($path);
    $mime = is_string($data) ? (getimagesizefromstring($data)['mime'] ?? '') : '';
    if (!in_array($mime, ['image/png', 'image/jpeg'], true)) throw new RuntimeException('The email logo must be PNG or JPEG.');
    return ['filename' => 'logo.' . ($mime === 'image/png' ? 'png' : 'jpg'),
        'content_type' => $mime,
        'content_id' => 'brand-logo-' . substr(hash('sha256', $data), 0, 16) . '@dnr.invalid',
        'data_base64' => base64_encode($data)];
}

/** Restore image bytes for SMTP. Older queued messages may have no images. */
function emailInlineImages(array $images): array
{
    if (count($images) > 1) throw new DomainException('Invalid email images.');
    return array_map(static function (array $image): array {
        $data = base64_decode((string) ($image['data_base64'] ?? ''), true);
        if ($data === false || strlen($data) > 256 * 1024) throw new DomainException('Invalid email logo.');
        return ['filename' => $image['filename'] ?? '', 'content_type' => $image['content_type'] ?? '',
            'content_id' => $image['content_id'] ?? '', 'data' => $data];
    }, $images);
}
