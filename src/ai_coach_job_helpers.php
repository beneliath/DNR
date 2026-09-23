<?php
declare(strict_types=1);

/** InnoDB can choose either concurrent worker as a deadlock victim. Replay only
 * rolled-back, idempotent statements/transactions, never inference or side effects. */
function aiCoachRetryDatabase(callable $operation): mixed
{
    for ($attempt=0; ; $attempt++) {
        try { return $operation(); }
        catch (mysqli_sql_exception $exception) {
            if ($exception->getCode()!==1213 || $attempt>=2) throw $exception;
            usleep(10000 * ($attempt+1));
        }
    }
}

/** Serialize admission only; no session lock or database transaction spans inference. */
function aiCoachEnqueue(mysqli $conn, int $id, array $request): bool
{
    if (!(int) $conn->query("SELECT GET_LOCK('dnr_coach_admission', 1)")->fetch_row()[0]) return false;
    try {
        aiCoachExpireJobs($conn);
        $count = (int) $conn->query("SELECT COUNT(*) FROM ai_coach_jobs WHERE state IN ('queued','generating')")->fetch_row()[0];
        if ($count >= 8) return false;
        $conn->execute_query('INSERT INTO ai_coach_jobs(request_id,request_json,deadline_at)
            SELECT id,?,DATE_ADD(created_at, INTERVAL 20 SECOND) FROM ai_coach_requests WHERE id=? AND outcome=\'pending\'',
            [json_encode($request, JSON_THROW_ON_ERROR), $id]);
        return $conn->affected_rows === 1;
    } finally { $conn->query("SELECT RELEASE_LOCK('dnr_coach_admission')"); }
}

function aiCoachExpireJobs(mysqli $conn): void
{
    $reply = aiCoachFallback([], 'timeout') + ['failure' => ['code' => 'timeout', 'stage' => 'queue-or-answer']];
    aiCoachRetryDatabase(static fn()=>$conn->execute_query("UPDATE ai_coach_requests r JOIN ai_coach_jobs j ON j.request_id=r.id
        SET r.outcome='unavailable',r.response_json=?,r.duration_ms=20000,r.completed_at=UTC_TIMESTAMP(6),j.state='expired'
        WHERE r.outcome='pending' AND j.state IN ('queued','generating') AND j.deadline_at<=UTC_TIMESTAMP(6)", [json_encode($reply, JSON_THROW_ON_ERROR)]));
}

function aiCoachClaimJob(mysqli $conn): ?array
{
    aiCoachExpireJobs($conn);
    return aiCoachRetryDatabase(static function () use ($conn): ?array {
    $conn->begin_transaction();
    try {
        $row = $conn->query("SELECT j.*, r.user_id, r.user_role, TIMESTAMPDIFF(MICROSECOND,r.created_at,UTC_TIMESTAMP(6))/1000 AS queue_ms,
            TIMESTAMPDIFF(MICROSECOND,UTC_TIMESTAMP(6),j.deadline_at)/1000000 AS remaining
            FROM ai_coach_jobs j JOIN ai_coach_requests r ON r.id=j.request_id
            WHERE j.state='queued' AND r.outcome='pending' AND j.deadline_at>UTC_TIMESTAMP(6)
            ORDER BY j.request_id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch_assoc();
        if (!$row) { $conn->commit(); return null; }
        $row['claim_token'] = bin2hex(random_bytes(16));
        $conn->execute_query("UPDATE ai_coach_jobs SET state='generating',claimed_at=UTC_TIMESTAMP(6),claim_token=? WHERE request_id=?", [$row['claim_token'], $row['request_id']]);
        $conn->commit();
        return $row;
    } catch (Throwable $exception) { $conn->rollback(); throw $exception; }
    });
}

function aiCoachJobCancelled(mysqli $conn, array $job): bool
{
    $row = $conn->execute_query("SELECT state,deadline_at<=UTC_TIMESTAMP(6) AS expired FROM ai_coach_jobs WHERE request_id=? AND claim_token=?",
        [$job['request_id'], $job['claim_token']])->fetch_assoc();
    return !$row || $row['state'] !== 'generating' || (bool) $row['expired'];
}

function aiCoachFinishJob(mysqli $conn, array $job, array $reply, int $elapsedMs): void
{
    $reply['telemetry']['queue_ms'] = (int) $job['queue_ms'];
    $outcome = match ($reply['reason'] ?? '') { 'timeout','unavailable' => 'unavailable', 'error','response_error' => 'error', default => 'complete' };
    aiCoachRetryDatabase(static fn()=>$conn->execute_query("UPDATE ai_coach_requests r JOIN ai_coach_jobs j ON j.request_id=r.id
        SET r.response_json=?,r.outcome=?,r.duration_ms=?,r.completed_at=UTC_TIMESTAMP(6),j.state='complete'
        WHERE r.id=? AND r.outcome='pending' AND j.state='generating' AND j.claim_token=? AND j.deadline_at>UTC_TIMESTAMP(6)",
        [json_encode($reply, JSON_THROW_ON_ERROR), $outcome, $elapsedMs + (int) $job['queue_ms'], $job['request_id'], $job['claim_token']]));
    aiCoachExpireJobs($conn);
}

function aiCoachCancelRequest(mysqli $conn, int $userId, string $role, string $requestId): bool
{
    $reply = ['message' => 'This answer was cancelled. You can ask another question whenever you are ready.', 'question' => '', 'sources' => [], 'mode' => 'conversation', 'reason' => 'cancelled'];
    aiCoachRetryDatabase(static fn()=>$conn->execute_query("UPDATE ai_coach_requests r LEFT JOIN ai_coach_jobs j ON j.request_id=r.id
        SET r.outcome='cancelled',r.response_json=?,r.completed_at=UTC_TIMESTAMP(6),j.state='cancelled'
        WHERE r.user_id=? AND r.user_role=? AND r.client_request_id=? AND r.outcome='pending'",
        [json_encode($reply, JSON_THROW_ON_ERROR), $userId, $role, $requestId]));
    return $conn->affected_rows > 0;
}

function aiCoachSaveFeedback(mysqli $conn, int $userId, string $role, string $requestId, string $feedback, array $ui, array $location = []): bool
{
    if (!in_array($feedback, ['helpful','needs_work','control_missing'], true)) throw new InvalidArgumentException('Choose a valid feedback option.');
    $page = is_string($location['page'] ?? null) && isset(aiCoachPages()[$location['page']]) ? $location['page'] : '';
    $step = is_string($location['step'] ?? null) && isset(aiCoachSteps()[$location['step']]) ? $location['step'] : '';
    $workflow = is_string($location['workflow'] ?? null) && isset(aiCoachWorkflows()[$location['workflow']]) ? $location['workflow'] : '';
    $context = json_encode(aiCoachValidateUi($ui) + ['page'=>$page, 'step'=>$step, 'workflow'=>$workflow,
        'target'=>aiCoachSteps()[$step]['target'] ?? ''], JSON_THROW_ON_ERROR);
    $conn->execute_query("UPDATE ai_coach_requests SET feedback_version=feedback_version+
        IF(user_feedback<=>? AND feedback_context_json<=>CAST(? AS JSON),0,1), user_feedback=?,feedback_context_json=?
        WHERE user_id=? AND user_role=? AND client_request_id=? AND outcome<>'pending'",
        [$feedback, $context, $feedback, $context, $userId, $role, $requestId]);
    if ($conn->affected_rows > 0) return true;
    return (bool) $conn->execute_query("SELECT 1 FROM ai_coach_requests WHERE user_id=? AND user_role=? AND client_request_id=? AND outcome<>'pending'", [$userId,$role,$requestId])->fetch_row();
}
