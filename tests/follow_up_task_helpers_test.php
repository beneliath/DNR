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

expectFollowUpTaskHelper(
    safeFollowUpTaskReturnUrl('view_engagement.php?id=12#follow-up-work')
        === 'view_engagement.php?id=12#follow-up-work',
    'safe in-application task return URLs should be preserved.'
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

$templates = engagementFollowUpChecklistTemplates('2026-09-10', '2026-09-12');
$templates_by_key = [];
foreach ($templates as $template) {
    $templates_by_key[$template['key']] = $template;
}
expectFollowUpTaskHelper(
    count($templates) === 9
        && count($templates_by_key) === 9
        && $templates_by_key['standard.confirm_location']['due_date'] === '2026-08-11'
        && $templates_by_key['standard.host_reconfirmation']['due_date'] === '2026-09-03'
        && $templates_by_key['standard.send_thanks']['due_date'] === '2026-09-13'
        && $templates_by_key['standard.financial_closeout']['due_date'] === '2026-09-19',
    'the standard checklist should provide unique, date-relative preparation and closeout work.'
);

expectFollowUpTaskHelper(
    array_keys(followUpTaskStatuses()) === ['open', 'in_progress', 'waiting', 'completed', 'canceled']
        && canManageFollowUpTasks('admin')
        && canManageFollowUpTasks('editor')
        && !canManageFollowUpTasks('reviewer'),
    'task states and write permissions should follow the established role model.'
);

echo "Follow-up task helper tests passed.\n";
