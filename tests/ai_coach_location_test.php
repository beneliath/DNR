<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/ai_coach_helpers.php';

function expectCoachLocation(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$question = 'what is the function of this part of the interface?';
$history = [
    ['role'=>'user', 'content'=>$question, 'page'=>'dashboard.php', 'active_tab'=>''],
    ['role'=>'assistant', 'content'=>'The Dashboard shows your upcoming engagements and tasks.', 'page'=>'dashboard.php', 'active_tab'=>''],
];
foreach (['engagements.php'=>'manual-topic-engagements-engagements', 'contacts.php'=>'manual-topic-organizations-contacts-browse-contacts',
    'tasks.php'=>'manual-topic-work-queue-work-queue', 'view_calendar.php'=>'manual-topic-map-calendar-monthly-calendar'] as $page=>$topic) {
    $request = aiCoachValidateRequest(['question'=>$question, 'page'=>$page, 'history'=>$history, 'step'=>'presentation-save'], 'admin');
    $topics = aiCoachRelevantTopics($request, aiCoachRankEvidence(aiCoachContextQuestion($request), '', 'admin'));
    expectCoachLocation($topics[0]['id'] === $topic, 'Page explanations prefer their introduction over an unrelated workflow');
    $context = json_decode(aiCoachPayload($request, $topics)['messages'][1]['content'], true);
    expectCoachLocation($context['current_location']['page'] === $page && $context['current_location']['changed_since_previous_exchange'], 'The model receives the destination and an explicit location change');
    expectCoachLocation($context['current_location']['previous_exchange_location']['page'] === 'dashboard.php', 'Earlier location is identified as historical');
    expectCoachLocation($context['history'] === [] && count($request['history']) === 2, 'Self-contained page explanations exclude stale descriptions without clearing the conversation');
    expectCoachLocation($context['verified_current_step'] === null && $context['known_procedures'] === [], 'An earlier walkthrough cannot override a current-page explanation');
    foreach ($context['target_form_evidence'] as $form) expectCoachLocation(in_array($page, $form['pages'], true), 'Page explanations contain only forms from the current page');
}

foreach ([$question, 'What can I do here?', 'And here?', 'Where am I now?', 'What page am I on?', 'What does this tab do?'] as $text) {
    $request = aiCoachValidateRequest(['question'=>$text, 'page'=>'contacts.php', 'history'=>$history], 'editor');
    expectCoachLocation(aiCoachPageExplanation($request) && aiCoachContextQuestion($request) === 'Contacts', 'Location-relative questions use the destination: '.$text);
}

$request = aiCoachValidateRequest(['question'=>'Can you walk me through adding one?', 'page'=>'view_engagement.php', 'ui'=>['active_tab'=>'presentations'],
    'history'=>[['role'=>'user', 'content'=>'Where do speaker notes PDFs go?', 'page'=>'engagements.php', 'active_tab'=>'']]], 'editor');
$context = json_decode(aiCoachPayload($request, [])['messages'][1]['content'], true);
expectCoachLocation($context['history'] === $request['history'] && str_contains(aiCoachContextQuestion($request), 'speaker notes PDF'), 'Cross-page task follow-ups retain their topic and conversation');
expectCoachLocation($context['current_location']['active_tab'] === 'presentations', 'The current tab is available independently of history');

$request = aiCoachValidateRequest(['question'=>'What does this tab do?', 'page'=>'view_engagement.php', 'ui'=>['active_tab'=>'presentations'],
    'history'=>[['role'=>'assistant', 'content'=>'Tasks show follow-up work.', 'page'=>'view_engagement.php', 'active_tab'=>'tasks']]], 'editor');
expectCoachLocation(aiCoachLocationContext($request)['changed_since_previous_exchange'], 'Switching tabs changes location even on the same page');
expectCoachLocation(aiCoachContextQuestion($request) === 'Engagement Details presentations' && aiCoachAnswerHistory($request) === [], 'A tab explanation uses the selected tab instead of the old answer');

$request = aiCoachValidateRequest(['question'=>$question, 'page'=>'contacts.php', 'history'=>[
    ['role'=>'assistant', 'content'=>'An old Dashboard description without metadata.'],
    ['role'=>'user', 'content'=>'Example', 'page'=>'https://private.example/path?id=5', 'active_tab'=>'PRIVATE VALUE', 'record_id'=>5],
    ['role'=>'user', 'content'=>'Example', 'page'=>'dashboard.php', 'active_tab'=>'PRIVATE VALUE'],
]], 'editor');
expectCoachLocation(!isset($request['history'][0]['page'], $request['history'][1]['page']) && $request['history'][2]['active_tab'] === '', 'Old history remains usable; unrecognized page/tab values are discarded');
expectCoachLocation(!str_contains(json_encode($request), 'PRIVATE VALUE') && !str_contains(json_encode($request), 'private.example') && !str_contains(json_encode($request), 'record_id'), 'History location metadata contains no URLs, record IDs, or arbitrary field values');
expectCoachLocation(aiCoachAnswerHistory($request) === [], 'Legacy conversations also get current-page explanations without a reset');
echo "Coach location and cross-page conversation tests passed.\n";
