<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/application_runtime.php';

function expectApplicationVersion(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Application version test failed: {$message}\n");
        exit(1);
    }
}

expectApplicationVersion(
    applicationVersion([dirname(__DIR__) . '/VERSION']) === trim((string) file_get_contents(dirname(__DIR__) . '/VERSION')),
    'the runtime should load the repository VERSION file.'
);
expectApplicationVersion(
    applicationVersion(['/path/that/does/not/exist']) === 'dev',
    'source archives without release metadata should use the explicit development fallback.'
);

$temporaryVersion = tempnam(sys_get_temp_dir(), 'dnr-version-');
if (!is_string($temporaryVersion)) {
    throw new RuntimeException('Unable to create the version test fixture.');
}
file_put_contents($temporaryVersion, "not a version\n");
expectApplicationVersion(
    applicationVersion([$temporaryVersion]) === 'dev',
    'invalid release metadata must not be exposed as an application version.'
);
chmod($temporaryVersion, 0000);
clearstatcache(true, $temporaryVersion);
if (!is_readable($temporaryVersion)) {
    set_error_handler(static function (int $severity, string $message): bool {
        if (error_reporting() & $severity) throw new RuntimeException($message);
        return true;
    });
    try {
        expectApplicationVersion(applicationVersion([$temporaryVersion]) === 'dev', 'unreadable metadata must not emit output before session headers.');
        expectApplicationVersion(applicationVersion([$temporaryVersion, dirname(__DIR__) . '/VERSION']) === trim((string) file_get_contents(dirname(__DIR__) . '/VERSION')), 'an unreadable candidate must allow the next version source.');
    } finally {
        restore_error_handler();
    }
}
chmod($temporaryVersion, 0600);
unlink($temporaryVersion);

echo "Application version tests passed.\n";
