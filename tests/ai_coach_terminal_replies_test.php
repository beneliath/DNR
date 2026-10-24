<?php
declare(strict_types=1);
require_once __DIR__.'/../src/audience_ai.php';
function terminalExpect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

foreach (['analytics', 'content'] as $mode) {
    foreach (['cancelled', 'timeout'] as $reason) {
        $reply = aiCoachTerminalReply('audience-ai:'.$mode, $reason);
        terminalExpect($reply['purpose'] === 'audience-ai' && $reply['mode'] === $mode && $reply['reason'] === $reason, 'Terminal replies retain the assistant contract');
        terminalExpect(!isset($reply['workflow_options']) && $reply['sources'] === [], 'Audience failures do not offer unrelated coaching workflows');
        $legacy = ['state'=>'complete', 'response'=>aiCoachTerminalReply('', $reason)+['history_saved'=>true, 'telemetry'=>['queue_ms'=>123]]];
        $normalized = audienceAiNormalizeStatus($legacy, $mode);
        terminalExpect($normalized['response']['purpose'] === 'audience-ai' && $normalized['response']['mode'] === $mode, 'Legacy history receives the owned assistant type');
        terminalExpect($normalized['response']['history_saved'] && $normalized['response']['telemetry']['queue_ms'] === 123, 'History/diagnostic metadata survives normalization');
        terminalExpect(!isset($legacy['response']['purpose']), 'Normalizing a response does not mutate the input history');
    }
}
terminalExpect(aiCoachTerminalReply('', 'timeout') === aiCoachFallback([], 'timeout')+['failure'=>['code'=>'timeout', 'stage'=>'queue-or-answer']], 'Ordinary coaching timeout is unchanged');
$cancelled = aiCoachTerminalReply('', 'cancelled');
terminalExpect($cancelled['mode'] === 'conversation' && !isset($cancelled['purpose']) && $cancelled['reason'] === 'cancelled', 'Ordinary coaching cancellation keeps its contract');
foreach ([['state'=>'pending'], ['state'=>'complete', 'response'=>['purpose'=>'other-assistant', 'reason'=>'cancelled']],
          ['state'=>'complete', 'response'=>['purpose'=>'audience-ai', 'mode'=>'content', 'reason'=>'', 'text'=>'Saved text']],
          ['state'=>'complete', 'response'=>['reason'=>'error', 'message'=>'Unrecognized failure']]] as $status) {
    terminalExpect(audienceAiNormalizeStatus($status, 'analytics') === $status, 'Do not relabel unrelated, successful, or unknown responses');
}
echo "Coach and audience terminal response contracts passed.\n";
