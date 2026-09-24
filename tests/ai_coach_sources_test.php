<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/ai_coach_helpers.php';

function expectCoachSource(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function coachSourceResponse(array $reply): array
{
    return ['done' => true, 'done_reason' => 'stop', 'message' => ['content' => json_encode($reply, JSON_THROW_ON_ERROR)]];
}

$question = 'what is the function of this part of the interface?';
$financial = 'manual-topic-operator-appendix-financial-closeout-and-giving-history';
putenv('DNR_AI_COACH_URL=');
foreach (['admin', 'editor', 'reviewer'] as $role) {
    foreach (['dashboard.php' => 'dashboard', 'tasks.php' => 'work-queue', 'contacts.php' => 'organizations-contacts',
        'view_organization.php' => 'organizations-contacts', 'view_calendar.php' => 'map-calendar'] as $page => $chapter) {
        $request = aiCoachValidateRequest(['question' => $question, 'page' => $page, 'topic' => $financial,
            'step' => 'presentation-save', 'history' => [['role' => 'user', 'content' => 'How do I attach a PowerPoint?']]], $role);
        expectCoachSource(aiCoachContextQuestion($request) === aiCoachPages()[$page], 'Interface questions use the current page instead of the previous task');
        $reply = aiCoachGenerate($request);
        $ids = array_column($reply['sources'], 'id');
        expectCoachSource($ids !== [] && !in_array($financial, $ids, true), 'Current-page help excludes the unrelated financial PDF citation');
        expectCoachSource((bool) array_filter($ids, static fn($id): bool => str_starts_with($id, 'manual-topic-' . $chapter . '-')), 'The current page has relevant manual evidence');
    }
}

foreach (['What is this page for?', 'Explain the purpose of this screen', $question] as $text) {
    expectCoachSource(aiCoachPageExplanation(aiCoachValidateRequest(['question' => $text, 'page' => 'dashboard.php'], 'admin')), 'Recognize generic interface explanations');
}
foreach (['What does the financial closeout section on this page do?', 'What is the purpose of this application?', 'How do I create a task on this page?'] as $text) {
    expectCoachSource(!aiCoachPageExplanation(aiCoachValidateRequest(['question' => $text, 'page' => 'dashboard.php'], 'admin')), 'Named topics and actions keep their own evidence');
}

foreach ([[$question, 'dashboard.php'], ['What does Open My Work do?', 'dashboard.php'], ['What does Assigned To do?', 'edit_task.php']] as [$text, $page]) {
    $request = aiCoachValidateRequest(['question' => $text, 'page' => $page], 'editor');
    $answer = ['message' => 'This control opens your work queue.', 'question' => '', 'sources' => [], 'kind' => 'information'];
    $reply = aiCoachDecodeReply(coachSourceResponse($answer), [], $request);
    expectCoachSource($reply['message'] === $answer['message'] && $reply['sources'] === [] && $reply['mode'] === 'conversation', 'Verified application explanations retain their text without a forced PDF reference');
    $payload = aiCoachPayload($request, []);
    expectCoachSource($payload['format']['properties']['sources']['maxItems'] === 0
        && !isset($payload['format']['properties']['sources']['items']['enum']), 'Empty manual evidence permits an empty source list with a valid schema');
    expectCoachSource(json_decode($payload['messages'][1]['content'], true)['application_evidence_available'], 'The model is told when application evidence is available');
}

$manual = aiCoachRetrieve('financial closeout payments expenses', '', 'admin');
$reply = aiCoachDecodeReply(coachSourceResponse(['message' => 'Financial closeout records actual receipts.', 'question' => '',
    'sources' => [0], 'kind' => 'information']), $manual);
expectCoachSource($reply['sources'][0]['id'] === $manual[0]['id'], 'Directly relevant manual citations remain available');
$unknownPage = aiCoachValidateRequest(['question' => $question, 'page' => 'other'], 'editor');
foreach ([[], $unknownPage] as $request) {
    $rejected = false;
    try {
        aiCoachDecodeReply(coachSourceResponse(['message' => 'Unsupported information.', 'question' => '', 'sources' => [], 'kind' => 'information']), [], $request);
    } catch (RuntimeException $exception) { $rejected = true; }
    expectCoachSource($rejected, 'An uncited factual answer still requires verified grounding');
}
// The exact reported wording must select the calendar introduction, even with stale context.
foreach (['admin', 'editor', 'reviewer'] as $role) {
    foreach (['what is the purpose of this part of the app?', 'Explain this section of the application'] as $text) {
        $request = aiCoachValidateRequest(['question'=>$text, 'page'=>'view_calendar.php',
            'topic'=>'manual-topic-orientation-use-the-sidebar', 'step'=>'presentation-save',
            'history'=>[['role'=>'assistant', 'content'=>'Create a private calendar subscription link.']]], $role);
        expectCoachSource(aiCoachPageExplanation($request), 'App-part wording describes the current page');
        $topics = aiCoachRelevantTopics($request, aiCoachRankEvidence(aiCoachContextQuestion($request), '', $role));
        expectCoachSource(array_column($topics, 'id') === ['manual-topic-map-calendar-monthly-calendar'],
            'Calendar overview excludes sidebar and subscription-only citations');
        $context = json_decode(aiCoachPayload($request, $topics)['messages'][1]['content'], true);
        expectCoachSource($context['current_page_overview'] && $context['history'] === []
            && $context['known_procedures'] === [] && $context['verified_current_step'] === null,
            'Calendar purpose cannot inherit an old subscription workflow');
        $reply = aiCoachGenerate($request);
        expectCoachSource(array_column($reply['sources'], 'id') === ['manual-topic-map-calendar-monthly-calendar'],
            'Fallback also links directly to the calendar introduction');
    }
}
foreach (['What is the purpose of this app?', 'What is the purpose of this application?',
    'What is the purpose of the subscription section of this app?'] as $text) {
    expectCoachSource(!aiCoachPageExplanation(aiCoachValidateRequest(['question'=>$text, 'page'=>'view_calendar.php'], 'admin')),
        'Whole-application and named-section questions retain their own scope');
}

echo "Coach citation relevance and application grounding tests passed.\n";
