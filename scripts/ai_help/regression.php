<?php
/** Repeatable checks for built-in cases or an approved, redacted export. Never writes business records. */
declare(strict_types=1);
require_once (getenv('DNR_COACH_SOURCE_DIR') ?: __DIR__.'/../../src') . '/ai_coach_helpers.php';
$options = getopt('', ['live','cases:','limit:']);
$path = $options['cases'] ?? __DIR__ . '/regression-cases.json';
$cases = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR)['cases'];
if (isset($options['limit'])) $cases = array_slice($cases, 0, max(1,(int)$options['limit']));
$live = isset($options['live']); $failures = 0;
foreach ($cases as $case) {
    $request = aiCoachValidateRequest(['question'=>$case['question'],'page'=>$case['page'] ?? 'help.php','step'=>$case['step'] ?? '', 'history'=>$case['history'] ?? [],'ui'=>$case['ui'] ?? []],$case['role'] ?? 'editor');
    $start = microtime(true); $errors = []; $expected = $case['expected'];
    try {
        $reply = $live ? aiCoachGenerate($request) : aiCoachImmediateReply($request);
        $actualWorkflow = $reply['workflow'] ?? '';
        if (isset($expected['workflow']) && $actualWorkflow !== $expected['workflow']) $errors[] = 'workflow: ' . ($actualWorkflow ?: 'none');
        if (isset($expected['engine']) && ($reply['engine'] ?? '') !== $expected['engine']) $errors[] = 'engine';
        if (isset($expected['procedure']) && ($reply['procedure'] ?? '') !== $expected['procedure']) $errors[] = 'procedure';
        $topics = $live ? ($reply['sources'] ?? []) : aiCoachRetrieve(aiCoachContextQuestion($request), '', $request['role']);
        $ids = array_column($topics,'id');
        if (!empty($expected['topics']) && !array_intersect($expected['topics'],$ids)) $errors[] = 'expected evidence not retrieved';
        if ($live || $reply !== null) {
            $text = mb_strtolower(($reply['message'] ?? '') . ' ' . ($reply['question'] ?? ''));
            foreach ($expected['required_terms'] ?? [] as $term) if (!str_contains($text,mb_strtolower($term))) $errors[] = 'missing: ' . $term;
            foreach ($expected['forbidden_terms'] ?? [] as $term) if (str_contains($text,mb_strtolower($term))) $errors[] = 'forbidden: ' . $term;
            if (isset($reply['reason'])) $errors[] = 'failure: ' . $reply['reason'];
        }
        if ($live && microtime(true)-$start > 20.5) $errors[] = 'over budget';
    } catch (Throwable $exception) { $errors[] = $exception->getMessage(); $reply = null; }
    if ($errors) $failures++;
    echo json_encode(['id'=>$case['id'],'question'=>$case['question'],'role'=>$request['role'],'mode'=>$live?'live':'routing-and-retrieval',
        'seconds'=>round(microtime(true)-$start,3),'passed'=>!$errors,'errors'=>$errors,'answer'=>$reply], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
}
fwrite(STDERR, sprintf("%d/%d cases passed (%s). Phrase/source checks do not establish factual accuracy; inspect generated guidance.\n",count($cases)-$failures,count($cases),$live?'live':'routing/retrieval'));
exit($failures ? 1 : 0);
