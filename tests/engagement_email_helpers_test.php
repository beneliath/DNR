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
$partialPresentation = [
    'topic_title' => '',
    'presentation_date' => null,
    'presentation_time' => null,
    'duration_minutes' => null,
    'speaker_name' => 'Example Speaker',
];
$partialBrief = engagementEmailSafeEventBrief($engagement, [$partialPresentation]);
$templateDefinitions = [[
    'template_key' => 'presentation_schedule', 'name' => 'Presentation schedule',
    'subject_template' => 'Schedule: {{event_name}}',
    'body_template' => "{{organization_name}}\n{{presentation_schedule}}",
    'suggested_roles_json' => '["primary_host"]',
]];
$partialTemplates = engagementEmailTemplates($engagement, [$partialPresentation], $templateDefinitions);
expectEngagementEmailHelper(
    str_contains($partialBrief, '- Presentation — Example Speaker')
        && !str_contains($partialBrief, 'minutes')
        && str_contains($partialTemplates['presentation_schedule']['body'], '- Presentation'),
    'unfinished presentation details should produce readable email copy without an invented duration.'
);
$durationOnlyBrief = engagementEmailSafeEventBrief($engagement, [
    array_replace($partialPresentation, ['duration_minutes' => 45]),
]);
expectEngagementEmailHelper(
    str_contains($durationOnlyBrief, '- Presentation — 45 minutes — Example Speaker'),
    'a known duration without a date or time should remain readable in the event brief.'
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
$validMattermostPostId = 'abc123def456ghi789jkl012mn';
expectEngagementEmailHelper(
    normalizeMattermostPostId($validMattermostPostId) === $validMattermostPostId
        && normalizeMattermostPostId(null) === ''
        && normalizeMattermostPostId('') === '',
    'Mattermost post IDs should remain optional and preserve valid 26-character IDs.'
);
foreach ([
    'too-short',
    'ABC123DEF456GHI789JKL012MN',
    'abc123def456ghi789jkl012m-',
    ['abc123def456ghi789jkl012mn'],
] as $invalidMattermostPostId) {
    try {
        normalizeMattermostPostId($invalidMattermostPostId);
        expectEngagementEmailHelper(false, 'an invalid Mattermost post ID should be rejected.');
    } catch (InvalidArgumentException) {
        // Expected.
    }
}
try {
    normalizeEngagementEmailSubject('Wrong event ' . applicationInboundMarker(99), 42);
    expectEngagementEmailHelper(false, 'a subject marker for another engagement should be rejected.');
} catch (InvalidArgumentException) {
    // Expected.
}
try {
    normalizeEngagementEmailSubject(
        'Wrong record type ' . applicationInquiryInboundMarker(42),
        42
    );
    expectEngagementEmailHelper(false, 'an Inquiry marker should be rejected in engagement email.');
} catch (InvalidArgumentException) {
    // Expected.
}

$templates = engagementEmailTemplates($engagement, $presentations, $templateDefinitions);
expectEngagementEmailHelper(
    isset($templates['presentation_schedule'], $templates['custom'])
        && $templates['presentation_schedule']['suggested_roles'] === ['primary_host']
        && str_contains($templates['presentation_schedule']['body'], 'Opening Session'),
    'stored templates should render event content and role suggestions.'
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

$speakers = [
    ['id' => 1, 'name' => 'Casey Speaker', 'email' => 'speaker@example.test'],
    ['id' => 2, 'name' => 'Drew Speaker', 'email' => 'SHARED@example.test'],
    ['id' => 3, 'name' => 'No Email', 'email' => 'invalid'],
];
$withSpeaker = engagementEmailResolveRecipients($contacts, [1], $speakers, [1]);
expectEngagementEmailHelper(
    count($withSpeaker['deliveries']) === 2
        && count($withSpeaker['contacts']) === 1
        && count($withSpeaker['speakers']) === 1
        && $withSpeaker['deliveries'][1]['contact_id'] === null
        && $withSpeaker['deliveries'][1]['recipient_email'] === 'speaker@example.test'
        && $withSpeaker['deliveries'][1]['recipient_roles'] === ['speaker'],
    'a speaker should receive a separate delivery without being treated as a contact with the same ID.'
);
$sharedSpeaker = engagementEmailResolveRecipients($contacts, [1, 2], $speakers, [2]);
expectEngagementEmailHelper(
    count($sharedSpeaker['deliveries']) === 1
        && $sharedSpeaker['deliveries'][0]['contact_id'] === 1
        && $sharedSpeaker['deliveries'][0]['recipient_roles'] === ['primary_host', 'travel', 'speaker']
        && str_contains($sharedSpeaker['deliveries'][0]['recipient_name'], 'Blair Coordinator')
        && str_contains($sharedSpeaker['deliveries'][0]['recipient_name'], 'Drew Speaker'),
    'speakers and contacts sharing an address should receive one delivery with all names and roles retained.'
);
$speakerOnly = engagementEmailResolveRecipients([], [], $speakers, [1, 1]);
expectEngagementEmailHelper(
    $speakerOnly['contacts'] === []
        && count($speakerOnly['speakers']) === 1
        && count($speakerOnly['deliveries']) === 1,
    'a speaker can be the only recipient, and repeated IDs must not duplicate the delivery.'
);
$chronText = engagementEmailChronText(
    7,
    array_merge($withSpeaker['contacts'], $withSpeaker['speakers']),
    'Test subject',
    'Test message'
);
expectEngagementEmailHelper(
    str_contains($chronText, 'Avery Host <shared@example.test>')
        && str_contains($chronText, 'Casey Speaker <speaker@example.test>'),
    'the engagement and organization history should include both contacts and speakers.'
);
expectEngagementEmailHelper(
    engagementEmailResolveRecipients($contacts, [1], $speakers)['speakers'] === [],
    'available speakers must never be included without an explicit selection.'
);
foreach ([
    [[], []],
    [[99], []],
    [[], [99]],
    [[], [3]],
    [range(1, 25), [1]],
    [[], [0]],
] as [$contactIds, $speakerIds]) {
    try {
        engagementEmailResolveRecipients($contacts, $contactIds, $speakers, $speakerIds);
        expectEngagementEmailHelper(false, 'empty, unavailable, invalid, or excessive recipients should be rejected.');
    } catch (InvalidArgumentException) {
        // Expected.
    }
}
foreach ([null, '1', [['1']], ['0'], ['1.5'], range(1, 26)] as $invalidIds) {
    try {
        normalizeEngagementEmailRecipientIds($invalidIds);
        expectEngagementEmailHelper(false, 'malformed submitted recipient lists should be rejected.');
    } catch (InvalidArgumentException) {
        // Expected.
    }
}

echo "Engagement email helper tests passed.\n";
