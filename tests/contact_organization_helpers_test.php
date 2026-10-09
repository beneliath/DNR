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
$uploads = [
    'name' => [1 => ['photo' => 'first.png'], 4 => ['photo' => 'second.jpg']],
    'type' => [1 => ['photo' => 'image/png'], 4 => ['photo' => 'image/jpeg']],
    'tmp_name' => [1 => ['photo' => '/tmp/first'], 4 => ['photo' => '/tmp/second']],
    'error' => [1 => ['photo' => UPLOAD_ERR_OK], 4 => ['photo' => UPLOAD_ERR_FORM_SIZE]],
    'size' => [1 => ['photo' => 1200], 4 => ['photo' => 6000000]],
];
expectContactOrganizations(organizationContactPhotoUpload($uploads, 4) === [
    'name' => 'second.jpg', 'type' => 'image/jpeg', 'tmp_name' => '/tmp/second',
    'error' => UPLOAD_ERR_FORM_SIZE, 'size' => 6000000,
], 'Sparse contact indices must retain the correct photo and upload error after removing another contact.');
expectContactOrganizations(organizationContactPhotoUpload($uploads, '1')['tmp_name'] === '/tmp/first',
    'String contact indices must resolve the same multipart upload.');
expectContactOrganizations(organizationContactPhotoUpload($uploads, 2) === []
    && organizationContactPhotoUpload([], 1) === []
    && organizationContactPhotoUpload(['name' => 'invalid', 'error' => [1 => 'invalid']], 1) === [],
    'Missing or malformed upload rows must not select another contact\'s photo.');
expectContactOrganizations(organizationContactPhotoUpload(['error' => [1 => ['photo' => UPLOAD_ERR_NO_FILE]]], 1)
    === ['error' => UPLOAD_ERR_NO_FILE], 'An empty photo selection remains optional.');

echo "Contact organization helper tests passed.\n";
