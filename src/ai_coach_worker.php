<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ai_coach_helpers.php';
require_once __DIR__ . '/ai_coach_history_helpers.php';
require_once __DIR__ . '/ai_coach_job_helpers.php';
if (!aiCoachEnabled()) exit;
// Limit active model workers even if an operator accidentally scales beyond two.
$slot = null;
foreach ([0, 1] as $candidate) {
    if ((int) $conn->execute_query('SELECT GET_LOCK(?,0)', ['dnr_coach_worker_' . $candidate])->fetch_row()[0]) { $slot = $candidate; break; }
}
if ($slot === null) { fwrite(STDERR, "Both coach worker slots are occupied.\n"); exit(1); }
while (true) {
    file_put_contents('/tmp/coach-worker-heartbeat', (string) time());
    $job = aiCoachClaimJob($conn);
    if ($job === null) { usleep(150000); continue; }
    $start = microtime(true);
    try {
        $user = $conn->execute_query('SELECT role,account_status FROM users WHERE id=?', [$job['user_id']])->fetch_assoc();
        if (!$user || ($user['role'] !== $job['user_role'] && $user['role'] !== 'admin') || $user['account_status'] !== 'active') {
            $reply = ['message' => 'Your access changed. Reload MOED before asking again.', 'question' => '', 'sources' => [], 'mode' => 'conversation', 'reason' => 'error'];
        } else {
            $request = aiCoachValidateRequest(json_decode($job['request_json'], true, 16, JSON_THROW_ON_ERROR), $job['user_role']);
            $lastCheck = 0.0; $cancelled = false;
            $check = static function () use ($conn, $job, &$lastCheck, &$cancelled): bool {
                if (microtime(true) - $lastCheck > .2) { $lastCheck = microtime(true); $cancelled = aiCoachJobCancelled($conn, $job); }
                return $cancelled;
            };
            $reply = aiCoachGenerate($request, $start + max(0, (float) $job['remaining']), $check);
        }
    } catch (Throwable $exception) { $reply = aiCoachFailureReply([], 'worker', $exception); }
    aiCoachFinishJob($conn, $job, $reply, (int) ((microtime(true) - $start) * 1000));
}
