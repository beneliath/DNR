<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/inbound_email_helpers.php';

function expectInboundEmail(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Inbound email helper test failed: {$message}\n");
        exit(1);
    }
}

putenv('DNR_INBOUND_ADDRESS=moed@beneliath.com');
$raw = implode("\r\n", [
    'From: =?UTF-8?B?' . base64_encode('David Gilmore') . '?= <david@beneliath.com>',
    'To: Jane Example <jane@example.org>, Pastor Example <pastor@example.org>',
    'Cc: MOED <moed@beneliath.com>',
    'Subject: =?UTF-8?B?' . base64_encode('Schedule – updated') . '?=',
    'Date: Sun, 23 Aug 2026 10:00:00 -0500',
    'Message-ID: <route-test-1@beneliath.com>',
    'MIME-Version: 1.0',
    'Content-Type: multipart/mixed; boundary="dnr-test-boundary"',
    '',
    '--dnr-test-boundary',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    '',
    "Hello Jane,\r\n\r\nHere is the updated schedule.",
    '--dnr-test-boundary',
    'Content-Type: application/pdf; name="schedule.pdf"',
    'Content-Disposition: attachment; filename="schedule.pdf"',
    'Content-Transfer-Encoding: base64',
    '',
    base64_encode('%PDF-test'),
    '--dnr-test-boundary--',
    '',
]);

$parsed = parseInboundEmail($raw, '2026-08-23T15:01:00Z');
expectInboundEmail(
    $parsed['sender_address'] === 'david@beneliath.com'
        && $parsed['sender_name'] === 'David Gilmore',
    'encoded sender names and addresses should be parsed.'
);
expectInboundEmail(
    $parsed['to_addresses'] === ['jane@example.org', 'pastor@example.org']
        && $parsed['cc_addresses'] === ['moed@beneliath.com'],
    'To and Cc participants should be normalized without losing recipients.'
);
expectInboundEmail(
    $parsed['subject'] === 'Schedule – updated'
        && str_contains($parsed['body_text'], 'updated schedule')
        && $parsed['attachment_names'] === ['schedule.pdf'],
    'MIME subject, text, and attachment metadata should be extracted.'
);
expectInboundEmail(
    $parsed['sent_at'] === '2026-08-23 15:00:00'
        && $parsed['received_at'] === '2026-08-23 15:01:00',
    'mail dates should be retained in UTC.'
);

$markers = parseInboundEmailEngagementMarkers(
    'Re: Schedule [MOED#123] [moed#123]'
);
$invalidMarkers = parseInboundEmailEngagementMarkers(
    'Bad [MOED#0] [MOED#abc] [MOED#2147483648]'
);
expectInboundEmail(
    $markers === ['ids' => [123], 'invalid' => []]
        && $invalidMarkers['ids'] === []
        && count($invalidMarkers['invalid']) === 3,
    'Engagement subject markers should be exact, bounded, case-insensitive, and deduplicated.'
);
$engagementRoute = inboundEmailEngagementRoute([
    'id' => 123,
    'organization_id' => 45,
    'event_title' => 'Are You Ready? – Texas',
    'organization_label' => 'Hope For Our Times',
    'event_start_date' => '2026-09-11',
    'event_end_date' => '2026-09-12',
    'lifecycle_status' => 'active',
]);
expectInboundEmail(
    $engagementRoute['marker'] === '[MOED#123]'
        && $engagementRoute['organization_id'] === 45
        && str_contains($engagementRoute['label'], 'Are You Ready? – Texas')
        && str_contains($engagementRoute['label'], '2026-09-11 – 2026-09-12'),
    'Engagement routes should expose ownership, a canonical marker, and a recognizable review label.'
);

foreach (['processed', 'rejected'] as $terminalStatus) {
    $terminalRejected = false;
    try {
        requireInboundEmailProcessableStatus($terminalStatus, false);
    } catch (InvalidArgumentException $exception) {
        $terminalRejected = str_contains($exception->getMessage(), 'already been');
    }
    expectInboundEmail(
        $terminalRejected,
        "{$terminalStatus} inbound messages should be terminal."
    );
}
$manualProcessingRejected = false;
try {
    requireInboundEmailProcessableStatus('processing', true);
} catch (InvalidArgumentException $exception) {
    $manualProcessingRejected = str_contains($exception->getMessage(), 'currently being processed');
}
expectInboundEmail(
    $manualProcessingRejected,
    'review actions should not race a message currently leased by the worker.'
);
foreach (['pending', 'processing', 'review', 'failed'] as $processableStatus) {
    requireInboundEmailProcessableStatus($processableStatus, false);
}

$sameId = parseInboundEmail(str_replace('updated schedule', 'different body', $raw));
expectInboundEmail(
    hash_equals($parsed['deduplication_hash'], $sameId['deduplication_hash']),
    'the RFC Message-ID should make delivery retries idempotent.'
);

$htmlOnly = implode("\r\n", [
    'From: Jane <jane@example.org>',
    'To: David <david@beneliath.com>',
    'Cc: MOED <moed@beneliath.com>',
    'Subject: HTML only',
    'Message-ID: <route-test-2@example.org>',
    'MIME-Version: 1.0',
    'Content-Type: text/html; charset=UTF-8',
    '',
    '<html><head><style>body{display:none}</style></head><body><p>Hello <strong>David</strong></p><script>alert(1)</script></body></html>',
]);
$parsedHtml = parseInboundEmail($htmlOnly);
expectInboundEmail(
    str_contains($parsedHtml['body_text'], 'Hello David')
        && !str_contains($parsedHtml['body_text'], 'alert')
        && !str_contains($parsedHtml['body_text'], 'display:none'),
    'HTML-only mail should become inert plain text without scripts or styles.'
);

$entry = formatInboundEmailChronEntry([
    'id' => 42,
    'sender_name' => $parsed['sender_name'],
    'sender_address' => $parsed['sender_address'],
    'to_addresses' => inboundEmailJson($parsed['to_addresses']),
    'cc_addresses' => inboundEmailJson($parsed['cc_addresses']),
    'subject' => $parsed['subject'],
    'sent_at' => $parsed['sent_at'],
    'received_at' => $parsed['received_at'],
    'body_text' => $parsed['body_text'],
    'attachment_names' => inboundEmailJson($parsed['attachment_names']),
]);
expectInboundEmail(
    str_contains($entry, 'Email captured by MOED')
        && str_contains($entry, 'Attachments: schedule.pdf')
        && str_contains($entry, '[Email source: inbound message #42]')
        && mb_strlen($entry, 'UTF-8') <= INBOUND_EMAIL_MAX_CHRON_CHARACTERS,
    'Chron text should preserve readable metadata, content, and a bounded source reference.'
);

echo "Inbound email helper tests passed.\n";
