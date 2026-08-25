<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/email_helpers.php';

function expectSmtpTlsConfiguration(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "SMTP TLS configuration test failed: {$message}\n");
        exit(1);
    }
}

$certificate = tempnam(sys_get_temp_dir(), 'dnr-smtp-ca-');
if ($certificate === false) {
    fwrite(STDERR, "SMTP TLS configuration test failed: unable to create a temporary certificate file.\n");
    exit(1);
}
file_put_contents($certificate, "test trust anchor\n");

putenv('DNR_SMTP_CA_FILE=' . $certificate);
putenv('DNR_SMTP_PEER_NAME=127.0.0.1');
$context = smtpTlsStreamContext('host.docker.internal');
$options = stream_context_get_options($context)['ssl'] ?? [];
expectSmtpTlsConfiguration(
    ($options['verify_peer'] ?? null) === true
        && ($options['verify_peer_name'] ?? null) === true
        && ($options['allow_self_signed'] ?? null) === false
        && ($options['peer_name'] ?? null) === '127.0.0.1'
        && ($options['cafile'] ?? null) === $certificate,
    'a custom trust anchor should preserve verification while overriding only the expected peer name.'
);

putenv('DNR_SMTP_CA_FILE');
putenv('DNR_SMTP_PEER_NAME');
$default_options = stream_context_get_options(smtpTlsStreamContext('smtp.example.test'))['ssl'] ?? [];
expectSmtpTlsConfiguration(
    ($default_options['peer_name'] ?? null) === 'smtp.example.test'
        && !array_key_exists('cafile', $default_options),
    'ordinary SMTP should verify the configured relay hostname with the system trust store.'
);

putenv('DNR_SMTP_PEER_NAME=bad/peer');
try {
    smtpTlsStreamContext('smtp.example.test');
    expectSmtpTlsConfiguration(false, 'an invalid TLS peer name should be rejected.');
} catch (RuntimeException $exception) {
    expectSmtpTlsConfiguration(
        $exception->getMessage() === 'The configured SMTP TLS peer name is invalid.',
        'invalid peer names should fail with a configuration-safe error.'
    );
}

putenv('DNR_SMTP_PEER_NAME');
putenv('DNR_SMTP_CA_FILE=' . $certificate . '.missing');
try {
    smtpTlsStreamContext('smtp.example.test');
    expectSmtpTlsConfiguration(false, 'a missing custom CA file should be rejected.');
} catch (RuntimeException $exception) {
    expectSmtpTlsConfiguration(
        $exception->getMessage() === 'The configured SMTP CA file is unavailable.',
        'missing trust anchors should fail closed with a configuration-safe error.'
    );
}

putenv('DNR_SMTP_CA_FILE');
unlink($certificate);

echo "SMTP TLS configuration tests passed.\n";
