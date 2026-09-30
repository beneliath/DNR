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

$reimbursementMime = smtpMessageContent('Report attached.', '<p>Report attached.</p>', [
    ['filename' => 'request.zip', 'content_type' => 'application/zip', 'data' => 'PK'],
]);
expectSmtpMessageFormat(
    str_contains($reimbursementMime['headers'][0], 'multipart/mixed')
        && substr_count($reimbursementMime['body'], 'Content-Disposition: attachment;') === 1
        && str_contains($reimbursementMime['body'], 'Content-Type: application/zip; name="request.zip"'),
    'Reimbursement email contains one ZIP attachment.'
);

// The provider limit applies after MIME encoding, including headers and body.
$buildMessage = static fn(string $body, array $files = []) => smtpMessageData(
    'sender@example.test', 'Sender', 'to@example.test', 'Reimbursement', $body,
    attachments: $files
);
$zipFile = ['filename' => 'request.zip', 'content_type' => 'application/zip', 'data' => str_repeat('A', 18_000_000)];
$encoded = $buildMessage('Report attached.', [$zipFile]);
expectSmtpMessageFormat(strlen($encoded) < SMTP_MAX_MESSAGE_BYTES && strlen($encoded) > 24_000_000,
    'An 18 MB ZIP fits after Base64 encoding and MIME headers.');
unset($encoded);
$zipFile['data'] = str_repeat('A', 19_000_000);
try {
    $buildMessage('Report attached.', [$zipFile]);
    throw new RuntimeException('A 19 MB ZIP incorrectly passed the total email limit.');
} catch (InvalidArgumentException $e) {
    expectSmtpMessageFormat(str_contains($e->getMessage(), 'complete encoded email exceeds 25 MB'),
        'Encoding overhead must reject oversized messages even when the ZIP is below 25 MB.');
}
unset($zipFile);
$overhead = strlen($buildMessage('')) + 2;
$boundaryBody = str_repeat('A', SMTP_MAX_MESSAGE_BYTES - $overhead);
expectSmtpMessageFormat(strlen($buildMessage($boundaryBody)) + 2 === SMTP_MAX_MESSAGE_BYTES,
    'The full message exactly at the limit is accepted, including headers and final CRLF.');
try {
    $buildMessage($boundaryBody . 'A');
    throw new RuntimeException('A message one byte over the limit was accepted.');
} catch (InvalidArgumentException $e) {
    expectSmtpMessageFormat(str_contains($e->getMessage(), '25 MB'), 'Full message limit is enforced at the byte boundary.');
}
unset($boundaryBody);

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
        && str_contains($source, 'smtpMessageContent($body, $htmlBody, $attachments)')
        && !str_contains($source, '$message = str_replace("\\n", "\\r\\n", $message);'),
    'SMTP delivery should support validated Reply-To and optional HTML while normalizing the envelope exactly once.'
);

expectSmtpMessageFormat(smtpRecipientHeaders('old@example.test') === ['To: <old@example.test>'],
    'legacy mail should keep its private recipient header.');
$headers = smtpRecipientHeaders('hidden@example.test', [
    'to' => ['first@example.test', 'SECOND@example.test'], 'cc' => ['copy@example.test'],
]);
expectSmtpMessageFormat($headers === ["To: <first@example.test>,\n <second@example.test>", 'Cc: <copy@example.test>']
    && !str_contains(implode("\n", $headers), 'hidden@example.test'),
    'visible headers should be folded, normalized, and omit the hidden envelope recipient.');
expectSmtpMessageFormat(smtpRecipientHeaders('hidden@example.test', ['to' => [], 'cc' => []])
    === ['To: undisclosed-recipients:;'], 'Bcc-only mail must not reveal its envelope recipient.');
foreach ([['to' => ['valid@example.test' . "\r\nBcc: injected@example.test"], 'cc' => []],
    ['to' => [], 'cc' => [], 'bcc' => ['hidden@example.test']],
    ['to' => [], 'cc' => 'invalid'], ['to' => [[]], 'cc' => []]] as $invalid) {
    try {
        smtpNormalizeVisibleRecipients($invalid);
        expectSmtpMessageFormat(false, 'malformed visible headers must be rejected.');
    } catch (InvalidArgumentException) {
        // Expected.
    }
}
echo "SMTP message format tests passed.\n";
