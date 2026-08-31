<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/email_helpers.php';

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

$source = file_get_contents(__DIR__ . '/../src/email_helpers.php');
expectSmtpMessageFormat(
    is_string($source)
        && str_contains($source, 'smtpNormalizeLineEndings(implode("\\n", [')
        && str_contains($source, "'Reply-To: <' . \$replyTo . '>'")
        && !str_contains($source, '$message = str_replace("\\n", "\\r\\n", $message);'),
    'SMTP delivery should support a validated Reply-To header and normalize the message exactly once.'
);

echo "SMTP message format tests passed.\n";
