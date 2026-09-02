<?php

declare(strict_types=1);

function expectDarkModeBackgroundFeature(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Dark-mode background feature test failed: {$message}\n");
        exit(1);
    }
}

$modernStyles = file_get_contents(__DIR__ . '/../src/assets/css/modern.css');

expectDarkModeBackgroundFeature(
    preg_match(
        '/html\.dark-mode\s*\{[^}]*--app-bg:\s*#10141c;[^}]*--dark-bg-color:\s*var\(--app-bg\);[^}]*--bg-color:\s*var\(--app-bg\);/s',
        $modernStyles
    ) === 1,
    'legacy dark-mode layout backgrounds should resolve to the shared application canvas.'
);
expectDarkModeBackgroundFeature(
    preg_match(
        '/html\.dark-mode body div[^{]*\{[^}]*background-color:\s*transparent\s*!important;/s',
        $modernStyles
    ) === 1,
    'legacy div wrappers should continue to reveal the shared application canvas.'
);
expectDarkModeBackgroundFeature(
    str_contains($modernStyles, '--surface: #171d27;')
        && str_contains($modernStyles, '--success-subtle: #17382c;')
        && str_contains($modernStyles, '--danger-subtle: #47211f;'),
    'intentional component, status, and feedback surfaces should remain distinct.'
);

echo "Dark-mode background feature tests passed.\n";
