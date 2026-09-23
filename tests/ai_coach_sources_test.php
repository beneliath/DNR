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
    $answer = ['message' => 'This control opens your work queue.', 'question' => '', 'sources' => [], 'kind' => 'application'];
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
foreach (['information', 'application'] as $kind) {
    $rejected = false;
    try {
        aiCoachDecodeReply(coachSourceResponse(['message' => 'Unsupported information.', 'question' => '', 'sources' => [], 'kind' => $kind]), []);
    } catch (RuntimeException $exception) { $rejected = true; }
    expectCoachSource($rejected, 'An uncited factual answer still requires verified grounding');
}
echo "Coach citation relevance and application grounding tests passed.\n";
