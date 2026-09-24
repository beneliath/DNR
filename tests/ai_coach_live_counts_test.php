<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/ai_coach_helpers.php';
function expectLiveCount(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
}
foreach (['admin', 'editor', 'reviewer'] as $role) {
    foreach (['tasks.php', 'view_calendar.php', 'engagements.php'] as $page) {
        foreach (['How many events do we have next quarter?', 'Count my overdue tasks',
                  'What is the total number of contacts?', 'How many engagements are scheduled this year?'] as $question) {
            $r = aiCoachValidateRequest(['question'=>$question, 'page'=>$page,
                'history'=>[['role'=>'assistant','content'=>'A calendar summary describes the displayed month.']],
                'ui'=>['observed'=>true,'visible_controls'=>['new-task'],'record_count'=>42]], $role);
            $a = aiCoachImmediateReply($r, false);
            expectLiveCount(($a['engine'] ?? '') === 'live-data-boundary', 'Live counts require an explicit data-access boundary');
            expectLiveCount(str_contains($a['message'], 'cannot read or count'), 'Do not imply access to records');
            expectLiveCount(empty($a['start_workflow']) && $a['sources'] === [], 'Do not replace the count with a workflow or irrelevant citation');
            expectLiveCount(!str_contains(json_encode(aiCoachPayload($r, [])), 'record_count'), 'Client totals cannot become authoritative data');
        }
    }
}
foreach (['How many PDF files can I attach to a presentation?', 'How do I count events in the calendar?',
          'What does the calendar count include?', 'How many megabytes can a PowerPoint contain?',
          'How do I add a task?', 'What date is it?'] as $question) {
    $a = aiCoachImmediateReply(aiCoachValidateRequest(['question'=>$question,'page'=>'view_calendar.php'], 'editor'), false);
    expectLiveCount(($a['engine'] ?? '') !== 'live-data-boundary', 'How-to, capacity and definition intents retain their routes');
}
echo "Live record count boundaries passed.\n";
