<?php
/** Development-agent handoff. Read-only unless --complete is explicitly supplied. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = getenv('DNR_COACH_REPO_DIR') ?: dirname(__DIR__,2);
$source = getenv('DNR_COACH_SOURCE_DIR') ?: $root . '/src';
require_once $source . '/bootstrap.php';
require_once $source . '/ai_coach_helpers.php';
require_once $source . '/ai_coach_feedback_helpers.php';
$options = getopt('', ['complete:','limit:','replay','recent']);
try {
    if (isset($options['complete'])) {
        $raw = file_get_contents($options['complete']);
        if ($raw === false || strlen($raw)>128000) throw new InvalidArgumentException('Use a review report below 128 KB.');
        $report = json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        echo json_encode(['recorded'=>aiCoachCompleteFeedbackReview($conn,$report,$root)],JSON_THROW_ON_ERROR),"\n";
    } elseif (isset($options['recent'])) {
        $rows=$conn->query('SELECT request_id,disposition,summary,evidence_json,guidance_revision,reviewed_at FROM ai_coach_feedback_reviews ORDER BY id DESC LIMIT 20')->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['reviews'=>$rows],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
    } elseif (isset($options['replay'])) {
        // Read-only replay of the original role/page/history, never business-record actions.
        // This evaluates guidance/model output; HTTP and browser recovery have separate tests.
        foreach (aiCoachFeedbackPending($conn,(int)($options['limit'] ?? 10)) as $row) {
            $request=aiCoachValidateRequest(['question'=>$row['question'],'page'=>$row['page_path'],'step'=>$row['guided_step'],
                'history'=>json_decode($row['conversation_json'] ?? '[]',true) ?: [],'ui'=>json_decode($row['ui_state_json'] ?? '{}',true) ?: []],$row['user_role']);
            $started=microtime(true);
            $reply=aiCoachImmediateReply($request,false) ?? aiCoachApprovedReply($conn,$request) ?? aiCoachGenerate($request);
            echo json_encode(['request_id'=>(int)$row['id'],'question'=>$row['question'],'seconds'=>round(microtime(true)-$started,3),
                'answer'=>$reply],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
        }
    } else {
        echo json_encode(aiCoachFeedbackPacket(aiCoachFeedbackPending($conn,(int)($options['limit'] ?? 100))),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Feedback review failed: ' . $exception->getMessage() . "\n"); exit(1);
}
