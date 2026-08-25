<?php

function expectConfirmationHandler(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Confirmation handler feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$app_shell = file_get_contents($root . '/src/assets/js/app-shell.js');
$page_actions = file_get_contents($root . '/src/assets/js/page-actions.js');

expectConfirmationHandler(
    str_contains($app_shell, "event.target.closest('form[data-confirm]')")
        && str_contains($app_shell, "event.submitter?.closest('[data-confirm]')")
        && substr_count($app_shell, 'window.confirm(') === 1,
    'the shared shell should confirm both form- and submit-button-level destructive actions once.'
);

expectConfirmationHandler(
    !str_contains($page_actions, 'initializeConfirmations')
        && !str_contains($page_actions, 'window.confirm('),
    'page actions should not attach a second confirmation prompt.'
);

echo "Confirmation handler feature tests passed.\n";
