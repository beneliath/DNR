<?php

declare(strict_types=1);

putenv('DNR_INBOUND_ROUTING_KEY=' . base64_encode(str_repeat('R', 32)));

$sourceDirectory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
$vendorAutoload = getenv('DNR_TEST_VENDOR_AUTOLOAD') ?: __DIR__ . '/../vendor/autoload.php';
require_once $vendorAutoload;
require_once $sourceDirectory . '/functions.php';
require_once $sourceDirectory . '/mattermost_email_helpers.php';

function expectEngagementEmailHelper(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Engagement email helper test failed: {$message}\n");
        exit(1);
    }
}

$engagement = [
    'id' => 42,
    'event_title' => 'Autumn Gathering',
    'event_description' => 'Public event description.',
    'organization_name' => 'Example Organization',
    'event_start_date' => '2026-10-10',
    'event_end_date' => '2026-10-12',
    'event_address_line_1' => '100 Main Street',
    'event_city' => 'Madison',
    'event_state' => 'WI',
    'event_zipcode' => '53703',
    'event_country' => 'US',
    'engagement_notes' => 'PRIVATE CHRON CONTENT',
    'other_compensation' => 'PRIVATE COMPENSATION',
];
$presentations = [[
    'topic_title' => 'Opening Session',
    'presentation_date' => '2026-10-11',
    'presentation_time' => '09:30:00',
    'duration_minutes' => 75,
    'speaker_name' => 'Example Speaker',
]];
$brief = engagementEmailSafeEventBrief($engagement, $presentations);
expectEngagementEmailHelper(
    str_contains($brief, 'Autumn Gathering')
        && str_contains($brief, 'Opening Session')
        && str_contains($brief, '75 minutes')
        && !str_contains($brief, 'PRIVATE CHRON CONTENT')
        && !str_contains($brief, 'PRIVATE COMPENSATION'),
    'the share-safe brief should include public logistics but exclude internal and financial fields.'
);

$mattermostBody = mattermostEmailBodyWithContext(
    'Approved message.',
    "MATTERMOST POST\nAuthor: @alex\nMessage: Please confirm."
);
expectEngagementEmailHelper(
    str_starts_with($mattermostBody, 'Approved message.')
        && str_contains($mattermostBody, 'MATTERMOST POST')
        && str_contains($mattermostBody, 'Please confirm.'),
    'reviewed Mattermost context should be visibly separated and preserved in the outbound message.'
);
try {
    normalizeEngagementEmailSubject('Wrong event ' . applicationInboundMarker(99), 42);
    expectEngagementEmailHelper(false, 'a subject marker for another engagement should be rejected.');
} catch (InvalidArgumentException) {
    // Expected.
}

$templates = engagementEmailTemplates($engagement, $presentations);
expectEngagementEmailHelper(
    isset($templates['booking_confirmation'], $templates['travel_lodging'], $templates['post_event_thanks'])
        && $templates['booking_confirmation']['suggested_roles'] === ['primary_host']
        && str_contains($templates['presentation_schedule']['body'], 'Opening Session'),
    'built-in templates should carry useful content and role suggestions.'
);

$subject = normalizeEngagementEmailSubject('Final details', 42);
expectEngagementEmailHelper(
    str_contains($subject, applicationInboundMarker(42))
        && normalizeEngagementEmailSubject($subject, 42) === $subject,
    'subject normalization should add exactly one authoritative routing marker.'
);

$contacts = [
    [
        'id' => 1,
        'contact_first_name' => 'Avery',
        'contact_last_name' => 'Host',
        'contact_email' => 'shared@example.test',
        'engagement_contact_roles' => ['primary_host'],
    ],
    [
        'id' => 2,
        'contact_first_name' => 'Blair',
        'contact_last_name' => 'Coordinator',
        'contact_email' => 'SHARED@example.test',
        'engagement_contact_roles' => ['travel'],
    ],
];
$resolved = engagementEmailResolveRecipients($contacts, [1, 2]);
expectEngagementEmailHelper(
    count($resolved['contacts']) === 2
        && count($resolved['deliveries']) === 1
        && $resolved['deliveries'][0]['recipient_roles'] === ['primary_host', 'travel'],
    'contacts sharing one normalized address should create one private delivery and retain both Chron targets.'
);

echo "Engagement email helper tests passed.\n";
