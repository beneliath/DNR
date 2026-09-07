<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/contact_organization_helpers.php';

function expectContactOrganizations(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Contact organization test failed: ' . $message);
    }
}

expectContactOrganizations(normalizeContactOrganizationAffiliations(null) === [], 'empty forms should have no additional affiliations.');
expectContactOrganizations(
    normalizeContactOrganizationAffiliations([
        ['organization_id' => '8', 'role_title' => ' Chairman '],
        ['organization_id' => '', 'role_title' => ''],
        ['organization_id' => '4', 'role_title' => ''],
    ], 2) === [
        ['organization_id' => 4, 'role_title' => ''],
        ['organization_id' => 8, 'role_title' => 'Chairman'],
    ],
    'affiliations should have stable ordering, optional titles, and trimmed input.'
);
foreach ([
    'invalid',
    [['organization_id' => ['2'], 'role_title' => 'Chairman']],
    [['organization_id' => '', 'role_title' => 'Chairman']],
    [['organization_id' => '0', 'role_title' => '']],
    [['organization_id' => '2 OR 1=1', 'role_title' => '']],
    [['organization_id' => '99999999999999999999999999', 'role_title' => '']],
    [['organization_id' => '3', 'role_title' => str_repeat('é', 256)]],
    [['organization_id' => '3', 'role_title' => ['Chairman']]],
    [['organization_id' => '2', 'role_title' => 'Duplicate primary']],
    [['organization_id' => '3'], ['organization_id' => '3']],
    array_fill(0, 101, ['organization_id' => '3']),
] as $invalid) {
    $rejected = false;
    try {
        normalizeContactOrganizationAffiliations($invalid, 2);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    expectContactOrganizations($rejected, 'invalid organization/title input must be rejected.');
}

expectContactOrganizations(normalizeOrganizationExistingContacts(null) === []
    && normalizeOrganizationExistingContacts([['contact_id' => '', 'role_title' => '']]) === [],
    'Creating an organization without existing contacts remains supported.');
expectContactOrganizations(normalizeOrganizationExistingContacts([
    ['contact_id' => '8', 'role_title' => ' Chair '], ['contact_id' => '4', 'role_title' => 'Trustee'],
]) === [['contact_id' => 4, 'role_title' => 'Trustee'], ['contact_id' => 8, 'role_title' => 'Chair']],
    'Existing contacts receive their own trimmed role and a stable lock order.');
foreach (['bad', [['contact_id' => [], 'role_title' => 'Chair']],
    [['contact_id' => '', 'role_title' => 'Chair']], [['contact_id' => '0', 'role_title' => 'Chair']],
    [['contact_id' => '3', 'role_title' => '']], [['contact_id' => '3', 'role_title' => str_repeat('é', 256)]],
    [['contact_id' => '3', 'role_title' => 'Chair'], ['contact_id' => '3', 'role_title' => 'Trustee']],
    array_fill(0, 21, ['contact_id' => '3', 'role_title' => 'Chair'])] as $invalid) {
    try {
        normalizeOrganizationExistingContacts($invalid);
        throw new RuntimeException('Invalid existing-contact selection was accepted.');
    } catch (InvalidArgumentException) {
    }
}
echo "Contact organization helper tests passed.\n";
