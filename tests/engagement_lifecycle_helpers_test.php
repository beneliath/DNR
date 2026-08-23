<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/engagement_lifecycle_helpers.php';

function expectEngagementLifecycleHelper(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Engagement lifecycle helper test failed: {$message}\n");
        exit(1);
    }
}

expectEngagementLifecycleHelper(
    array_keys(engagementLifecycleStatuses())
        === ['active', 'postponed', 'canceled', 'completed'],
    'lifecycle states should retain their stable operational order.'
);
expectEngagementLifecycleHelper(
    engagementLifecycleLabel('postponed') === 'Postponed'
        && engagementLifecycleLabel('invalid') === 'Lifecycle not set',
    'lifecycle labels should be readable and safely handle unknown values.'
);
expectEngagementLifecycleHelper(
    engagementReferenceLabel([
        'event_title' => 'Replacement event',
        'organization_name' => 'Example Organization',
        'event_start_date' => '2026-10-10',
    ]) === 'Replacement event · 2026-10-10',
    'replacement references should use the title and date.'
);
expectEngagementLifecycleHelper(
    engagementReferenceLabel([
        'event_title' => '',
        'organization_name' => 'Example Organization',
        'event_start_date' => '',
    ]) === 'Example Organization',
    'replacement references should fall back to the organization.'
);

echo "Engagement lifecycle helper tests passed.\n";
