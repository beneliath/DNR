<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/email_helpers.php';
require_once __DIR__ . '/../src/notification_helpers.php';

function expectSmtpMessageFormat(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "SMTP message format test failed: {$message}\n");
        exit(1);
    }
}

$mixed = "From: sender@example.test\r\n"
    . "To: recipient@example.test\r"
    . "Subject: Test\n\n"
    . "First line\r\nSecond line\rThird line\n";
$normalized = smtpNormalizeLineEndings($mixed);

expectSmtpMessageFormat(
    $normalized === "From: sender@example.test\r\n"
        . "To: recipient@example.test\r\n"
        . "Subject: Test\r\n\r\n"
        . "First line\r\nSecond line\r\nThird line\r\n",
    'mixed input line endings should become canonical SMTP CRLF sequences.'
);
expectSmtpMessageFormat(
    !str_contains($normalized, "\r\r\n")
        && substr_count($normalized, "\r\n\r\n") === 1,
    'the MIME header separator must not contain doubled carriage returns.'
);

$plainContent = smtpMessageContent("Plain line one\nPlain line two");
expectSmtpMessageFormat(
    $plainContent['headers'] === [
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ]
        && $plainContent['body'] === "Plain line one\nPlain line two",
    'messages without an HTML alternative should retain the legacy plain-text format.'
);

$plainAlternative = "A plain fallback.\nSecond line.";
$htmlAlternative = '<!doctype html><html><body><p>A rich alternative.</p></body></html>';
$multipart = smtpMessageContent($plainAlternative, $htmlAlternative);
$contentType = $multipart['headers'][0] ?? '';
$boundaryMatched = preg_match(
    '/\AContent-Type: multipart\/alternative; boundary="([^"]+)"\z/',
    $contentType,
    $boundaryMatch
) === 1;
$boundary = $boundaryMatched ? $boundaryMatch[1] : '';
$parts = $boundary === '' ? [] : explode('--' . $boundary, $multipart['body']);

$decodedPlain = null;
$decodedHtml = null;
foreach ($parts as $part) {
    $part = ltrim($part, "\r\n");
    if (preg_match(
        '/\AContent-Type: text\/(plain|html); charset=UTF-8\R'
            . 'Content-Transfer-Encoding: base64\R\R(.+?)\R?\z/s',
        $part,
        $partMatch
    ) !== 1) {
        continue;
    }
    $decoded = base64_decode(preg_replace('/\s+/', '', $partMatch[2]) ?? '', true);
    if ($partMatch[1] === 'plain') {
        $decodedPlain = $decoded;
    } else {
        $decodedHtml = $decoded;
    }
}

expectSmtpMessageFormat(
    $boundaryMatched
        && count($multipart['headers']) === 1
        && substr_count($multipart['body'], '--' . $boundary) === 3
        && str_ends_with($multipart['body'], '--' . $boundary . '--')
        && $decodedPlain === smtpNormalizeLineEndings($plainAlternative)
        && $decodedHtml === smtpNormalizeLineEndings($htmlAlternative),
    'HTML messages should use multipart/alternative with intact plain-text and HTML parts.'
);

putenv('DNR_2FA_ENCRYPTION_KEY=' . base64_encode(str_repeat('M', 32)));
$legacyCiphertext = \Dnr\Security\ApplicationKey::seal(json_encode([
    'recipient' => 'legacy@example.test',
    'subject' => 'Legacy notification',
    'body' => 'Plain text from an older queued row.',
], JSON_THROW_ON_ERROR));
$legacyMessage = decryptQueuedNotificationEmail($legacyCiphertext);
expectSmtpMessageFormat(
    $legacyMessage['recipient'] === 'legacy@example.test'
        && $legacyMessage['subject'] === 'Legacy notification'
        && $legacyMessage['body'] === 'Plain text from an older queued row.'
        && $legacyMessage['html_body'] === null,
    'notification payloads queued before HTML support should still decrypt as plain text.'
);

$invalidHtmlCiphertext = \Dnr\Security\ApplicationKey::seal(json_encode([
    'recipient' => 'invalid@example.test',
    'subject' => 'Invalid notification',
    'body' => 'Plain fallback',
    'html_body' => ['not', 'a', 'string'],
], JSON_THROW_ON_ERROR));
$invalidHtmlRejected = false;
try {
    decryptQueuedNotificationEmail($invalidHtmlCiphertext);
} catch (RuntimeException $exception) {
    $invalidHtmlRejected = true;
}
expectSmtpMessageFormat(
    $invalidHtmlRejected,
    'queued notification HTML should be rejected unless it is a string or null.'
);
putenv('DNR_2FA_ENCRYPTION_KEY');

$source = file_get_contents(__DIR__ . '/../src/email_helpers.php');
expectSmtpMessageFormat(
    is_string($source)
        && str_contains($source, 'smtpNormalizeLineEndings(implode("\\n", [')
        && str_contains($source, "'Reply-To: <' . \$replyTo . '>'")
        && str_contains($source, 'smtpMessageContent($body, $htmlBody)')
        && !str_contains($source, '$message = str_replace("\\n", "\\r\\n", $message);'),
    'SMTP delivery should support validated Reply-To and optional HTML while normalizing the envelope exactly once.'
);

echo "SMTP message format tests passed.\n";
