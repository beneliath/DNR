<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/engagement_contact_helpers.php';

function expectEngagementContactHelper(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Engagement contact helper test failed: {$message}\n");
        exit(1);
    }
}

$assignments = normalizeEngagementContactAssignments([
    '9' => ['travel', 'primary_host', 'travel'],
    '4' => ['materials'],
]);
expectEngagementContactHelper(
    $assignments === [
        ['contact_id' => 4, 'contact_role' => 'materials'],
        ['contact_id' => 9, 'contact_role' => 'primary_host'],
        ['contact_id' => 9, 'contact_role' => 'travel'],
    ],
    'submitted assignments should be validated, deduplicated, and canonicalized.'
);
expectEngagementContactHelper(
    engagementContactAssignmentMap($assignments) === [
        4 => ['materials'],
        9 => ['primary_host', 'travel'],
    ],
    'canonical assignments should map cleanly back to form controls.'
);
expectEngagementContactHelper(
    engagementContactRoleLabel('on_site_contact') === 'On-site contact'
        && organizationContactRoleLabel([
            'contact_role' => 'other',
            'contact_role_other' => 'Events director',
        ]) === 'Events director',
    'event and organization roles should have distinct display labels.'
);

$invalid_role_rejected = false;
try {
    normalizeEngagementContactAssignments(['7' => ['owner']]);
} catch (InvalidArgumentException) {
    $invalid_role_rejected = true;
}
expectEngagementContactHelper(
    $invalid_role_rejected,
    'unsupported event contact roles should be rejected.'
);

echo "Engagement contact helper tests passed.\n";
