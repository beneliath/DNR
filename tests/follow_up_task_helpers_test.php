<?php

require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/follow_up_task_helpers.php';

function expectFollowUpTaskHelper($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "Follow-up task helper test failed: {$message}\n");
        exit(1);
    }
}

$general = parseFollowUpTaskSubject('general');
expectFollowUpTaskHelper(
    $general['subject_type'] === 'general'
        && $general['engagement_id'] === null
        && $general['organization_id'] === null
        && $general['contact_id'] === null,
    'general work should not populate a related-record foreign key.'
);

$engagement = parseFollowUpTaskSubject('engagement:42');
expectFollowUpTaskHelper(
    $engagement['subject_type'] === 'engagement'
        && $engagement['subject_id'] === 42
        && $engagement['engagement_id'] === 42
        && followUpTaskSubjectValue($engagement) === 'engagement:42',
    'engagement subjects should round-trip through the form value.'
);

$invalid_subject_rejected = false;
try {
    parseFollowUpTaskSubject('engagement:0 OR 1=1');
} catch (InvalidArgumentException $exception) {
    $invalid_subject_rejected = true;
}
expectFollowUpTaskHelper($invalid_subject_rejected, 'crafted subject identifiers must be rejected.');

$duplicate_values = followUpTaskDuplicateFormValues([
    'title' => 'Confirm travel',
    'details' => 'Use the latest itinerary.',
    'status' => 'completed',
    'priority' => 'high',
    'due_date' => '2026-09-10',
    'waiting_on' => 'Host reply',
    'assigned_to' => 17,
]);
expectFollowUpTaskHelper(
    $duplicate_values['title'] === 'Confirm travel'
        && $duplicate_values['details'] === 'Use the latest itinerary.'
        && $duplicate_values['status'] === 'open'
        && $duplicate_values['priority'] === 'high'
        && $duplicate_values['due_date'] === '2026-09-10'
        && $duplicate_values['waiting_on'] === ''
        && $duplicate_values['assigned_to'] === 17,
    'duplicated tasks should preserve reusable details while starting as open work.'
);

requireDifferentEngagementForTaskDuplicate(
    ['engagement_id' => 12],
    ['subject_type' => 'engagement', 'engagement_id' => 13]
);
foreach ([
    ['subject_type' => 'general', 'engagement_id' => null],
    ['subject_type' => 'engagement', 'engagement_id' => 12],
] as $invalid_duplicate_destination) {
    $invalid_destination_rejected = false;
    try {
        requireDifferentEngagementForTaskDuplicate(
            ['engagement_id' => 12],
            $invalid_duplicate_destination
        );
    } catch (InvalidArgumentException $exception) {
        $invalid_destination_rejected = true;
    }
    expectFollowUpTaskHelper(
        $invalid_destination_rejected,
        'task duplicates must target a different event.'
    );
}

expectFollowUpTaskHelper(
    safeFollowUpTaskReturnUrl('view_engagement.php?id=12#follow-up-work')
        === 'view_engagement.php?id=12#follow-up-work',
    'safe in-application task return URLs should be preserved.'
);
expectFollowUpTaskHelper(
    safeFollowUpTaskReturnUrl('view_calendar.php?month=2026-08&show=everything#event-calendar')
        === 'view_calendar.php?month=2026-08&show=everything#event-calendar',
    'task edits opened from the calendar should return to the same month and view.'
);
foreach ([
    'https://example.com/',
    '//example.com/tasks.php',
    '../tasks.php',
    "/tasks.php\r\nLocation: https://example.com",
] as $unsafe_return_url) {
    expectFollowUpTaskHelper(
        safeFollowUpTaskReturnUrl($unsafe_return_url) === 'tasks.php',
        'external, absolute, traversing, or header-injection return URLs must be rejected.'
    );
}

expectFollowUpTaskHelper(
    followUpTaskDueState('2026-08-17', '2026-08-18')['key'] === 'overdue'
        && followUpTaskDueState('2026-08-18', '2026-08-18')['key'] === 'today'
        && followUpTaskDueState('2026-08-19', '2026-08-18')['key'] === 'upcoming'
        && followUpTaskDueState(null, '2026-08-18')['key'] === 'none',
    'due dates should map to stable queue states.'
);

$template_definitions = [
    [
        'template_key' => 'standard.confirm_location',
        'title' => 'Confirm location',
        'details' => null,
        'priority' => 'high',
        'due_anchor' => 'event_start',
        'due_offset_days' => -30,
    ],
    [
        'template_key' => 'standard.host_reconfirmation',
        'title' => 'Reconfirm with host',
        'details' => null,
        'priority' => 'high',
        'due_anchor' => 'event_start',
        'due_offset_days' => -7,
    ],
    [
        'template_key' => 'standard.send_thanks',
        'title' => 'Send thanks',
        'details' => 'Use the event notes.',
        'priority' => 'normal',
        'due_anchor' => 'event_end',
        'due_offset_days' => 1,
    ],
    [
        'template_key' => 'standard.financial_closeout',
        'title' => 'Financial closeout',
        'details' => null,
        'priority' => 'high',
        'due_anchor' => 'event_end',
        'due_offset_days' => 7,
    ],
];
$templates = engagementFollowUpChecklistTemplates(
    '2026-09-10',
    '2026-09-12',
    $template_definitions
);
$templates_by_key = [];
foreach ($templates as $template) {
    $templates_by_key[$template['key']] = $template;
}
expectFollowUpTaskHelper(
    count($templates) === 4
        && count($templates_by_key) === 4
        && $templates_by_key['standard.confirm_location']['due_date'] === '2026-08-11'
        && $templates_by_key['standard.host_reconfirmation']['due_date'] === '2026-09-03'
        && $templates_by_key['standard.send_thanks']['due_date'] === '2026-09-13'
        && $templates_by_key['standard.send_thanks']['details'] === 'Use the event notes.'
        && $templates_by_key['standard.financial_closeout']['due_date'] === '2026-09-19',
    'stored standard tasks should become date-relative preparation and closeout work.'
);

expectFollowUpTaskHelper(
    initialEngagementChecklistAssigneeId(23, 11) === 23
        && initialEngagementChecklistAssigneeId(null, 11) === 11,
    'initial engagement checklists should prefer the selected caller and otherwise use the creator.'
);

expectFollowUpTaskHelper(
    standardEventTaskScheduleLabel('event_start', -1) === '1 day before event start'
        && standardEventTaskScheduleLabel('event_end', 0) === 'On event end'
        && standardEventTaskScheduleLabel('event_end', 2) === '2 days after event end',
    'standard task schedules should be described in plain language.'
);

expectFollowUpTaskHelper(
    isRequiredStandardEventTask(['is_required' => 1])
        && !isRequiredStandardEventTask(['is_required' => 0])
        && !isRequiredStandardEventTask([]),
    'required standard-task policy should come from persisted template data.'
);

$normalized_standard_task = normalizeStandardEventTaskInput([
    'title' => '  Prepare follow-up  ',
    'details' => ' Notes ',
    'priority' => 'urgent',
    'due_anchor' => 'event_end',
    'due_offset_days' => '3',
    'sort_order' => '25',
]);
expectFollowUpTaskHelper(
    $normalized_standard_task['title'] === 'Prepare follow-up'
        && $normalized_standard_task['details'] === 'Notes'
        && $normalized_standard_task['due_offset_days'] === 3
        && $normalized_standard_task['sort_order'] === 25,
    'standard task edits should normalize persisted content and scheduling values.'
);

expectFollowUpTaskHelper(
    array_keys(followUpTaskStatuses()) === ['open', 'in_progress', 'waiting', 'completed', 'canceled']
        && canManageFollowUpTasks('admin')
        && canManageFollowUpTasks('editor')
        && !canManageFollowUpTasks('reviewer'),
    'task states and write permissions should follow the established role model.'
);

echo "Follow-up task helper tests passed.\n";
