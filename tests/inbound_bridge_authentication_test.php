<?php

declare(strict_types=1);

putenv('DNR_INBOUND_ROUTING_KEY=' . base64_encode(str_repeat('R', 32)));
putenv('DNR_INBOUND_ROUTING_KEY_FILE');
putenv('DNR_INBOUND_REQUIRE_AUTHENTICATED_FROM=1');
putenv('DNR_INBOUND_TRUSTED_AUTH_SERVERS=');
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/inbound_email_helpers.php';

function expectBridgeAuthentication(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Bridge authentication test failed: ' . $message);
    }
}

// The Go adapter checks the same independently generated protocol fixture.
$assertion = 'v1.eyJraW5kIjoiaW50ZXJuYWwiLCJmcm9tIjoic3RhZmZAZXhhbXBsZS5uZXQiLCJpZCI6IjAxNGI2MDg3ZTFiN2M2MWQ1YzJiZDZmNTIzYTFkM2ZkMjI5YmU5ZDI1ZGIxNmE0MWM1Yzg2MmYwMWNjMWU4MzUifQ.11322ab6f9821eb3f25fb2ddcc6a8afb6085d54f7db64cb6930e555f9405a7d9';
$header = 'X-Dnr-Sender-Authentication: ' . $assertion;
$message = ['sender_address' => 'staff@example.net', 'rfc_message_id' => 'fixture@example.net', 'raw_headers' => $header];
$verified = inboundEmailSenderAuthentication($message);
expectBridgeAuthentication(
    $verified['required'] && $verified['trusted'] && $verified['method'] === 'proton-internal'
        && $verified['authentication_server'] === 'proton-bridge' && $verified['reason'] === null,
    'provider-authenticated internal mail must work with an empty authserv-id list and no DMARC header.'
);
foreach ([
    ['sender_address' => 'someoneelse@example.net'],
    ['rfc_message_id' => 'another@example.net'],
    ['rfc_message_id' => ''],
    ['raw_headers' => $header . "\r\n" . $header],
    ['raw_headers' => $header . '0'],
    ['raw_headers' => str_replace('v1.', 'v2.', $header)],
    ['raw_headers' => 'X-Dnr-Sender-Authentication: unavailable'],
    ['raw_headers' => "X-Pm-Origin: internal\r\nX-Pm-Content-Encryption: end-to-end"],
    ['raw_headers' => "Subject: hello\r\n\r\n" . $header],
] as $change) {
    expectBridgeAuthentication(
        !inboundEmailSenderAuthentication(array_replace($message, $change))['trusted'],
        'mismatched, duplicated, forged, or body-only assertions must fail closed.'
    );
}
putenv('DNR_INBOUND_TRUSTED_AUTH_SERVERS=mx.example.net');
expectBridgeAuthentication(
    !inboundEmailSenderAuthentication(array_replace($message, [
        'raw_headers' => "X-Dnr-Sender-Authentication: unavailable\r\n"
            . 'Authentication-Results: mx.example.net; dmarc=pass header.from=example.net',
    ]))['trusted'],
    'an invalid Bridge assertion must never fall back to an imported or sender-supplied DMARC header.'
);
expectBridgeAuthentication(
    inboundEmailSenderAuthentication(array_replace($message, [
        'raw_headers' => 'Authentication-Results: mx.example.net; dmarc=pass header.from=example.net',
    ]))['method'] === 'dmarc',
    'ordinary trusted-provider DMARC must remain supported.'
);
echo "Inbound Bridge authentication tests passed.\n";
