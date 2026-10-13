<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/account_release_helpers.php';

$commit = str_repeat('a', 40);
$profile = ['application_commit' => $commit, 'application_version' => '2.5.10'];
assertAccountReleaseParity($profile, $commit, '2.5.10');
foreach ([[], ['application_commit' => str_repeat('b', 40), 'application_version' => '2.5.10'],
    ['application_commit' => $commit, 'application_version' => '2.5.9'],
    ['application_commit' => [$commit], 'application_version' => '2.5.10']] as $invalid) {
    try {
        assertAccountReleaseParity($invalid, $commit, '2.5.10');
    } catch (RuntimeException $expected) {
        continue;
    }
    throw new RuntimeException('Missing or mismatched member provenance was accepted.');
}
try {
    assertAccountReleaseParity($profile, '', '2.5.10');
    throw new LogicException('Missing primary provenance was accepted.');
} catch (RuntimeException $expected) {}
echo "Account release parity tests passed.\n";
