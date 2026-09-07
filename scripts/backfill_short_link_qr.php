<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$source = is_file('/var/www/html/bootstrap.php') ? '/var/www/html' : dirname(__DIR__) . '/src';
require_once $source . '/bootstrap.php';
require_once $source . '/short_link_helpers.php';
try {
    // A CLI backfill must never guess an origin from a web request or localhost.
    if (trim((string) getenv('DNR_PUBLIC_BASE_URL')) === '') {
        throw new RuntimeException('Set DNR_PUBLIC_BASE_URL to the public MOED origin before backfilling.');
    }
    $created = backfillShortLinkQrImages($conn);
    fwrite(STDOUT, "Stored PNG and SVG images for $created links. Existing images were preserved.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "QR image backfill failed: {$exception->getMessage()}\n");
    exit(1);
}
