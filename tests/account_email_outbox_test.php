<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/email_helpers.php';

function expectAccountOutbox(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Account email outbox test failed: {$message}\n");
        exit(1);
    }
}

putenv('DNR_2FA_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
putenv('DNR_PUBLIC_BASE_URL=https://moed.example.test/app');
putenv('DNR_REQUIRE_HTTPS=1');

$message = accountTokenEmailMessage(
    'recovery',
    'User@Example.test',
    'example-user',
    str_repeat('a', 43)
);
$encoded = json_encode($message, JSON_THROW_ON_ERROR);
$sealed = \Dnr\Security\ApplicationKey::seal($encoded);
$opened = \Dnr\Security\ApplicationKey::open($sealed);

expectAccountOutbox(
    $message['recipient'] === 'user@example.test'
        && str_contains($message['body'], 'https://moed.example.test/app/recover_password.php')
        && $opened === $encoded
        && !str_contains($sealed, 'recover_password.php'),
    'account links should use the canonical origin and remain encrypted at rest.'
);

$outbox_source = file_get_contents(__DIR__ . '/../src/email_helpers.php');
expectAccountOutbox(
    is_string($outbox_source)
        && str_contains($outbox_source, 'AND id < ? AND consumed_at IS NULL')
        && str_contains($outbox_source, 'token.consumed_at IS NULL')
        && str_contains($outbox_source, 'token.auth_version = user.auth_version')
        && str_contains($outbox_source, "user.account_status = 'active'")
        && str_contains($outbox_source, 'FOR UPDATE OF outbox SKIP LOCKED'),
    'queued delivery should preserve newer tokens, reject stale links, and lock only rows the restricted worker may update.'
);

putenv('DNR_2FA_ENCRYPTION_KEY');
putenv('DNR_PUBLIC_BASE_URL');
putenv('DNR_REQUIRE_HTTPS');

echo "Account email outbox tests passed.\n";
