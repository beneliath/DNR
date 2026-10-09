<?php

declare(strict_types=1);

/** A download response can confirm its start, never completion on the user's disk. */
function downloadFeedbackState(int $status, array $headers): string
{
    if ($status >= 200 && $status < 300) {
        foreach ($headers as $header) {
            if (preg_match('/^Content-Disposition:\s*(?:attachment|inline)\s*;/i', $header) === 1) {
                return 'started';
            }
        }
    }
    return 'failed';
}

function sendDownloadFeedback(array $query): void
{
    $token = $query['_download_feedback'] ?? null;
    if (!is_string($token) || preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) return;
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $directory = rtrim(dirname(is_string($path) ? $path : '/'), '/.') . '/';
    $state = downloadFeedbackState(http_response_code() ?: 200, headers_list());
    setcookie('dnr_download_' . $token, $state, [
        'expires' => time() + 180,
        'path' => $directory,
        'secure' => requestUsesHttps(),
        'httponly' => false,
        'samesite' => 'Strict',
    ]);
}
