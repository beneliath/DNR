<?php
/** Read-only live evaluation. Run inside the local web container with native Ollama enabled. */
declare(strict_types=1);
require_once (getenv('DNR_COACH_SOURCE_DIR') ?: __DIR__ . '/../../src') . '/ai_coach_helpers.php';
if (($argv[1] ?? '') !== '--live') { fwrite(STDERR, "Use --live to call the configured native model.\n"); exit(2); }
$cases = [
    ['question' => 'What are some of the functions of the moed app?', 'page' => 'dashboard.php', 'workflow' => 'none', 'chapter' => 'orientation'],
    ['question' => 'What is the purpose of this application?', 'page' => 'dashboard.php', 'workflow' => 'none', 'topic' => 'manual-topic-orientation-getting-oriented'],
    ['question' => 'We have agreed to host a conference. How do I put it in the system?', 'page' => 'view_organization.php', 'workflow' => 'engagement'],
    ['question' => 'The speaker sent me his notes as a PDF. Can you show me where it goes?', 'page' => 'view_engagement.php', 'workflow' => 'notes'],
    ['question' => 'How is an inquiry different from an engagement?', 'page' => 'dashboard.php', 'workflow' => 'none', 'topic' => 'manual-topic-booking-pipeline-inquiry-first-engagement-after-booking'],
    ['question' => 'How do I add a task to an event?', 'page' => 'view_engagement.php', 'workflow' => 'create-task', 'chapter' => 'work-queue'],
    ['question' => 'How do I see these dates in my own calendar?', 'page' => 'view_calendar.php', 'workflow' => 'calendar-subscription', 'chapter' => 'map-calendar'],
];
$failed = 0;
foreach ($cases as $case) {
    $r = aiCoachValidateRequest(['question' => $case['question'], 'page' => $case['page']], 'editor');
    $start = microtime(true);
    $record = ['model' => getenv('DNR_AI_COACH_MODEL') ?: 'qwen3:8b', 'question' => $case['question'], 'page' => $case['page'], 'expected_workflow' => $case['workflow']];
    try {
        // Exercise the real entry point, including direct overview and verified workflow paths.
        $record['answer'] = aiCoachGenerate($r);
        $record['route'] = $record['answer']['workflow'] ?? 'none';
        $record['topics'] = array_column($record['answer']['sources'] ?? [], 'id');
        $record['passed'] = !isset($record['answer']['reason']) && $record['route'] === $case['workflow']
            && (!isset($case['topic']) || in_array($case['topic'], $record['topics'], true))
            && (!isset($case['chapter']) || array_filter($record['topics'], static fn(string $id): bool => str_starts_with($id, 'manual-topic-' . $case['chapter'] . '-')) !== []);
    } catch (Throwable $e) { $record['error'] = $e->getMessage(); $record['passed'] = false; }
    $record['seconds'] = round(microtime(true) - $start, 2);
    $record['within_20_seconds'] = $record['seconds'] <= 20;
    if (!$record['passed']) $failed++;
    echo json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    flush();
}
exit($failed > 0 ? 1 : 0);
