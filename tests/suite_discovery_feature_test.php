<?php

function expectIntegrationRunner(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Integration runner test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$runner = file_get_contents($root . '/scripts/run_integration_tests.sh');
if ($runner === false) {
    throw new RuntimeException('Unable to read the integration test runner.');
}

$discovered = array_merge(
    glob($root . '/tests/*_integration_test.php') ?: [],
    glob($root . '/tests/integration_*_test.php') ?: []
);
$discovered = array_values(array_unique(array_map('basename', $discovered)));
sort($discovered);

expectIntegrationRunner(
    $discovered !== [],
    'at least one integration suite should be discoverable by the documented naming convention.'
);
expectIntegrationRunner(
    str_contains($runner, "-name '*_integration_test.php'")
        && str_contains($runner, "-name 'integration_*_test.php'"),
    'the runner should discover both supported integration-suite naming patterns.'
);
expectIntegrationRunner(
    str_contains($runner, 'Refusing to run integration tests without the explicit disposable target.')
        && str_contains($runner, 'DNR_INTEGRATION_TARGET=disposable'),
    'the runner should require and propagate an explicit disposable target.'
);
expectIntegrationRunner(
    in_array('database_backup_integration_test.php', $discovered, true)
        && str_contains($runner, "'database_backup_integration_test.php'")
        && str_contains($runner, 'DNR_DESTRUCTIVE_BACKUP_TEST=isolated-restore')
        && str_contains($runner, 'isolated_project="dnr-backup-test-')
        && str_contains($runner, 'compose_isolated up -d --wait db')
        && str_contains($runner, 'compose_isolated down --volumes --remove-orphans')
        && str_contains($runner, 'trap cleanup_isolated_backup EXIT HUP INT TERM')
        && str_contains($runner, 'maintenance'),
    'the destructive backup suite should use and always destroy its own database project and volume.'
);
expectIntegrationRunner(
    substr_count($runner, '</dev/null') >= 7,
    'containerized tests should not consume the loop input before every discovered suite runs.'
);
expectIntegrationRunner(
    in_array('email_outbox_worker_integration_test.php', $discovered, true)
        && str_contains($runner, "'email_outbox_worker_integration_test.php'")
        && str_contains($runner, 'maintenance "/opt/dnr/${test_file}"'),
    'email outbox state-machine tests should run with the isolated maintenance database identity.'
);
expectIntegrationRunner(
    in_array('engagement_email_integration_test.php', $discovered, true)
        && str_contains($runner, "'engagement_email_integration_test.php'")
        && str_contains($runner, 'maintenance "/opt/dnr/${test_file}"'),
    'engagement correspondence queue and worker tests should use the maintenance test identity.'
);
expectIntegrationRunner(
    in_array('task_notifications_integration_test.php', $discovered, true)
        && str_contains($runner, "'task_notifications_integration_test.php'")
        && str_contains($runner, 'maintenance "/opt/dnr/${test_file}"'),
    'notification scheduling and delivery state-machine tests should use the maintenance test identity.'
);
expectIntegrationRunner(
    in_array('geocoder_worker_integration_test.php', $discovered, true)
        && str_contains($runner, "'geocoder_worker_integration_test.php'")
        && str_contains($runner, 'geocoder "/opt/dnr/${test_file}"'),
    'geocoder write-path tests should run under the restricted worker identity.'
);

echo "Integration runner tests passed.\n";
