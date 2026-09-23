<?php

declare(strict_types=1);
require_once __DIR__ . '/ai_coach_job_helpers.php';

/** Store only the validated request contract, never headers, cookies, or raw page data. */
function aiCoachRecordRequest(mysqli $conn, array $request, int $userId, bool &$created = false): ?int
{
    $created = false;
    try {
        $revision = aiCoachGuidanceRevision();
        $conn->execute_query('INSERT INTO ai_coach_requests
            (user_id, user_role, page_path, guided_step, question, conversation_json, manual_topic, model_name, application_version, guidance_revision, client_request_id,ui_state_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $userId, $request['role'], $request['page'], $request['step'], $request['question'],
            json_encode($request['history'], JSON_THROW_ON_ERROR), $request['topic'],
            mb_substr(getenv('DNR_AI_COACH_MODEL') ?: 'qwen3:8b', 0, 100), applicationVersion(), $revision, $request['request_id'] ?: null,
            json_encode($request['ui'] ?? [], JSON_THROW_ON_ERROR),
        ]);
        $created = true;
        return (int) $conn->insert_id;
    } catch (Throwable $exception) {
        if ($exception->getCode() === 1062 && $request['request_id'] !== '') {
            $existing = $conn->execute_query('SELECT id FROM ai_coach_requests WHERE user_id=? AND client_request_id=?', [$userId, $request['request_id']])->fetch_assoc();
            return $existing ? (int) $existing['id'] : null;
        }
        applicationLog('error', 'AI Coach request history write failed', ['error_code' => $exception->getCode()]);
        return null;
    }
}

function aiCoachCompleteRequest(mysqli $conn, ?int $id, array $response, int $durationMs, string $outcome = ''): bool
{
    if ($id === null) return false;
    if ($outcome === '') $outcome = match ($response['reason'] ?? '') {
        'busy' => 'busy', 'unavailable', 'timeout' => 'unavailable', 'response_error', 'error' => 'error', default => 'complete',
    };
    if (!in_array($outcome, ['complete', 'busy', 'unavailable', 'throttled', 'error'], true)) $outcome = 'error';
    try {
        $conn->execute_query('UPDATE ai_coach_requests SET response_json=?, outcome=?, duration_ms=?, completed_at=UTC_TIMESTAMP(6) WHERE id=? AND outcome<>\'cancelled\'',
            [json_encode($response, JSON_THROW_ON_ERROR), $outcome, max(0, $durationMs), $id]);
        return $conn->affected_rows === 1;
    } catch (Throwable $exception) {
        applicationLog('error', 'AI Coach response history write failed', ['request_id' => $id, 'error_code' => $exception->getCode()]);
        return false;
    }
}

/** An annotation never changes the original response or the running coach's instructions. */
function aiCoachReviewRequest(mysqli $conn, int $id, int $version, int $reviewer, string $status, string $notes, string $correction): void
{
    if ($id < 1 || $version < 0 || !in_array($status, ['unreviewed', 'useful', 'needs_work'], true)
        || mb_strlen($notes) > 4000 || mb_strlen($correction) > 8000) {
        throw new InvalidArgumentException('Choose a valid review status and keep review notes under 4,000 characters and corrected guidance under 8,000.');
    }
    $conn->execute_query('UPDATE ai_coach_requests SET review_status=?, review_notes=?, corrected_guidance=?, reviewed_by=?,
        reviewed_at=UTC_TIMESTAMP(6), review_version=review_version+1 WHERE id=? AND review_version=?',
        [$status, trim($notes), trim($correction), $reviewer, $id, $version]);
    if ($conn->affected_rows !== 1) throw new RuntimeException('This request was updated by another reviewer or is no longer available. Reload before saving.');
}

/** Owner and current-role checks prevent cross-account or downgraded-role disclosure. */
function aiCoachRequestStatus(mysqli $conn, int $userId, string $role, string $requestId): ?array
{
    aiCoachExpireJobs($conn);
    $row = $conn->execute_query('SELECT r.outcome,r.response_json,j.state AS job_state, TIMESTAMPDIFF(SECOND,r.created_at,UTC_TIMESTAMP(6)) AS age_seconds
        FROM ai_coach_requests r LEFT JOIN ai_coach_jobs j ON j.request_id=r.id WHERE r.user_id=? AND r.user_role=? AND r.client_request_id=?', [$userId, $role, $requestId])->fetch_assoc();
    if (!$row) return null;
    if ($row['outcome'] === 'pending') return ['state' => (int) $row['age_seconds'] > 25 ? 'interrupted' : 'pending', 'stage' => $row['job_state'] ?? 'queued'];
    $reply = json_decode((string) $row['response_json'], true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($reply)) return ['state' => 'interrupted'];
    return ['state' => 'complete', 'response' => $reply + ['history_saved' => true]];
}

/** Freeze the deletion boundary so questions arriving during confirmation survive. */
function aiCoachClearSnapshot(mysqli $conn): array
{
    $boundary = $conn->execute_query('SELECT UTC_TIMESTAMP(6) AS cutoff, COALESCE(MAX(id), 0) AS max_id FROM ai_coach_requests')->fetch_assoc();
    $row = $conn->execute_query('SELECT COUNT(*) AS total FROM ai_coach_requests WHERE id<=? AND
        (completed_at<=? OR (outcome=\'pending\' AND created_at<DATE_SUB(?, INTERVAL 25 SECOND)))',
        [$boundary['max_id'], $boundary['cutoff'], $boundary['cutoff']])->fetch_assoc();
    return ['cutoff' => $boundary['cutoff'], 'max_id' => (int) $boundary['max_id'], 'count' => (int) $row['total']];
}

/** Never reset IDs: an older inference worker may still complete after a clear. */
function aiCoachClearRequests(mysqli $conn, array $snapshot): int
{
    $conn->execute_query('DELETE FROM ai_coach_requests WHERE id<=? AND
        (completed_at<=? OR (outcome=\'pending\' AND created_at<DATE_SUB(?, INTERVAL 25 SECOND)))',
        [$snapshot['max_id'], $snapshot['cutoff'], $snapshot['cutoff']]);
    return $conn->affected_rows;
}
