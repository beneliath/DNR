<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/ai_coach_helpers.php';
function checkReference(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
// Synthetic examples: an unnamed UI element is not identified by an old answer,
// a guided step, or the mere presence of one visible control.
foreach (['admin', 'editor', 'reviewer'] as $role) {
    foreach (['dashboard.php', 'engagements.php', 'tasks.php', 'users.php', 'ai_coach_requests.php', 'other'] as $page) {
        foreach (['What does this button do?', 'Explain that field', 'What is this control for?'] as $question) {
            $request = aiCoachValidateRequest(['question'=>$question, 'page'=>$page,
                'step'=>'presentation-edit', 'history'=>[
                    ['role'=>'user', 'content'=>'Explain event dates'],
                    ['role'=>'assistant', 'content'=>'The Dashboard shows upcoming work.']],
                'ui'=>['observed'=>true, 'visible_controls'=>['edit-event']]], $role);
            $reply = aiCoachImmediateReply($request, false);
            checkReference(($reply['engine'] ?? '') === 'interface-clarification', 'Unnamed UI reference must clarify');
            checkReference(str_contains($reply['message'], 'label'), 'Ask for the missing label');
            checkReference($reply['sources'] === [] && empty($reply['start_workflow']), 'Do not invent citations or actions');
            checkReference(!str_contains($reply['message'], 'Dashboard'), 'Do not repeat stale page explanations');
        }
    }
}
foreach (['admin', 'editor', 'reviewer'] as $role) {
    foreach (['dashboard.php', 'engagements.php', 'tasks.php', 'users.php', 'ai_coach_requests.php'] as $page) {
        foreach (['What is this section for?', 'What is the purpose of this part of the screen?'] as $question) {
            $request = aiCoachValidateRequest(['question'=>$question, 'page'=>$page,
                'history'=>[['role'=>'assistant', 'content'=>'The Dashboard shows upcoming work.', 'page'=>'dashboard.php']]], $role);
            checkReference(aiCoachImmediateReply($request, false) === null, 'Known-page explanations retain the deployed inference path');
            checkReference(aiCoachContextQuestion($request) === aiCoachPages()[$page], 'Page evidence uses the current location');
            checkReference(aiCoachAnswerHistory($request) === [], 'Old page descriptions do not contaminate a new overview');
        }
    }
    $unknown = aiCoachValidateRequest(['question'=>'What is the purpose of this part of the screen?', 'page'=>'other'], $role);
    checkReference(aiCoachImmediateReply($unknown, false)['engine'] === 'interface-clarification', 'An unknown page still needs clarification');
}
foreach (['What does Save Changes do?', 'What is this task for?', 'Explain this step',
          'What is the purpose of the Dashboard?', 'How do I change this field?',
          'What does this button do after I select a PDF?'] as $question) {
    $reply = aiCoachImmediateReply(aiCoachValidateRequest(['question'=>$question, 'page'=>'tasks.php'], 'editor'), false);
    checkReference(($reply['engine'] ?? '') !== 'interface-clarification', 'Specific subjects and actions retain their routing');
}
foreach (['admin', 'editor', 'reviewer'] as $role) {
    $reply = aiCoachImmediateReply(aiCoachValidateRequest(['question'=>'What date is it?', 'page'=>'tasks.php'], $role), false);
    checkReference(($reply['engine'] ?? '') === 'application-clock', 'Clock remains independent of page and role');
    checkReference(str_contains($reply['message'], (new DateTimeImmutable('now', new DateTimeZone('America/Chicago')))->format('F j, Y')), 'Clock uses configured local date');
}
echo "Interface reference tests passed.\n";
