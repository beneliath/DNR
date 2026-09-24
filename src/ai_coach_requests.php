<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ai_coach_history_helpers.php';
require_once __DIR__ . '/ai_coach_helpers.php';
require_once __DIR__ . '/ai_coach_improvement_helpers.php';
startSecureSession();
requireAdmin();
header('Cache-Control: private, no-store');
header('Pragma: no-cache');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) { header('Allow: GET, POST'); http_response_code(405); exit; }
$id = \Dnr\Http\RequestInput::positiveInt($_GET, 'id');
$error = '';
$clearConfirmation = null;
$deleteConfirmation = null;
$deletedId = $_SESSION['ai_coach_deleted_id'] ?? null;
unset($_SESSION['ai_coach_deleted_id']);
$clearedCount = $_SESSION['ai_coach_cleared_count'] ?? null;
unset($_SESSION['ai_coach_cleared_count']);
if ($method === 'GET') unset($_SESSION['ai_coach_clear_confirmation'], $_SESSION['ai_coach_delete_confirmation']);
if ($method === 'POST') {
    requireValidCsrfToken();
    try {
        $action = \Dnr\Http\RequestInput::string($_POST, 'action', 'review');
        if ($action === 'prepare_delete') {
            $id = \Dnr\Http\RequestInput::positiveInt($_POST, 'id');
            if ($id === null) throw new InvalidArgumentException('Choose a request to delete.');
        } elseif ($action === 'delete_request') {
            $snapshot = $_SESSION['ai_coach_delete_confirmation'] ?? null;
            if (!is_array($snapshot) || ($snapshot['expires'] ?? 0) < time()
                || !hash_equals($snapshot['token'], \Dnr\Http\RequestInput::string($_POST, 'delete_token'))) {
                throw new InvalidArgumentException('The delete confirmation expired. Choose Delete again.');
            }
            aiCoachDeleteRequest($conn, $snapshot);
            unset($_SESSION['ai_coach_delete_confirmation']);
            $_SESSION['ai_coach_deleted_id'] = (int) $snapshot['id'];
            applicationLog('info', 'ai coach request deleted', ['user_id' => (int) $_SESSION['user_id'], 'request_id' => (int) $snapshot['id']]);
            header('Location: ai_coach_requests.php', true, 303);
            exit;
        } elseif ($action === 'prepare_clear') {
            $id = null;
            $clearConfirmation = aiCoachClearSnapshot($conn) + ['token' => bin2hex(random_bytes(32)), 'expires' => time() + 600];
            $_SESSION['ai_coach_clear_confirmation'] = $clearConfirmation;
        } elseif ($action === 'clear_requests') {
            $id = null;
            $snapshot = $_SESSION['ai_coach_clear_confirmation'] ?? null;
            if (!is_array($snapshot) || ($snapshot['expires'] ?? 0) < time()
                || !hash_equals($snapshot['token'], \Dnr\Http\RequestInput::string($_POST, 'clear_token'))) {
                throw new InvalidArgumentException('The clear confirmation expired. Choose Clear request log again.');
            }
            $count = aiCoachClearRequests($conn, $snapshot);
            unset($_SESSION['ai_coach_clear_confirmation']);
            $_SESSION['ai_coach_cleared_count'] = $count;
            applicationLog('info', 'ai coach request log cleared', ['user_id' => (int) $_SESSION['user_id'], 'deleted_count' => $count]);
            header('Location: ai_coach_requests.php', true, 303);
            exit;
        } elseif ($action === 'review') {
            $id = \Dnr\Http\RequestInput::positiveInt($_POST, 'id') ?? 0;
            $version = filter_var($_POST['version'] ?? null, FILTER_VALIDATE_INT);
            if ($version === false || $version === null) throw new InvalidArgumentException('Reload the request before reviewing it.');
            aiCoachReviewRequest($conn, $id, $version, (int) $_SESSION['user_id'],
                \Dnr\Http\RequestInput::string($_POST, 'review_status'),
                \Dnr\Http\RequestInput::string($_POST, 'review_notes'),
                \Dnr\Http\RequestInput::string($_POST, 'corrected_guidance'));
            header('Location: ai_coach_requests.php?id=' . $id . '&saved=1', true, 303);
            exit;
        } else throw new InvalidArgumentException('Choose a valid request-log action.');
    } catch (mysqli_sql_exception $exception) {
        http_response_code(503);
        $error = 'The request log could not be updated. Please try again.';
        applicationLog('error', 'ai coach review write failed', ['error_code' => $exception->getCode()]);
    } catch (InvalidArgumentException | RuntimeException $exception) {
        http_response_code($exception instanceof InvalidArgumentException ? 400 : 409);
        $error = $exception->getMessage();
    }
}
$review = \Dnr\Http\RequestInput::enum($_GET, 'review', ['all', 'unreviewed', 'useful', 'needs_work'], 'all');
$query = \Dnr\Http\RequestInput::string($_GET, 'q', '', 200);
$statusLabels = ['unreviewed' => 'Unreviewed', 'useful' => 'Useful', 'needs_work' => 'Needs work'];
$outcomeLabels = ['pending' => 'In progress', 'complete' => 'Completed', 'unavailable' => 'Unavailable', 'busy' => 'Busy', 'throttled' => 'Rate limited', 'error' => 'Error'];
$failureLabels = ['connection' => 'Connection issue', 'timeout' => 'Timed out', 'service_error' => 'Service error', 'invalid_response' => 'Invalid answer'];
$pageLabels = aiCoachPages();
$detail = null; $rows = []; $pagination = null;
try {
    if ($id !== null) {
        $detail = $conn->execute_query('SELECT r.*, u.username, reviewer.username AS reviewer_name FROM ai_coach_requests r
            LEFT JOIN users u ON u.id=r.user_id LEFT JOIN users reviewer ON reviewer.id=r.reviewed_by WHERE r.id=?', [$id])->fetch_assoc();
        if (!$detail) { http_response_code(404); $error = 'Request not found.'; }
        elseif ($method === 'POST' && ($action ?? '') === 'prepare_delete' && $error === '') {
            $deleteConfirmation = array_intersect_key($detail, array_flip(['id', 'review_version', 'feedback_version']))
                + ['token' => bin2hex(random_bytes(32)), 'expires' => time() + 600];
            $_SESSION['ai_coach_delete_confirmation'] = $deleteConfirmation;
        }
    } else {
        $from = "FROM ai_coach_requests r LEFT JOIN users u ON u.id=r.user_id
            WHERE (?='all' OR r.review_status=?) AND (LOCATE(?, r.question)>0 OR LOCATE(?, COALESCE(r.corrected_guidance, ''))>0)";
        $values = [$review, $review, $query, $query];
        $pagination = queryPagination($conn, $from, 'ssss', $values, 20, $_GET['page'] ?? null);
        $rows = $conn->execute_query("SELECT r.id, r.created_at, r.page_path, r.question, r.outcome, r.review_status, r.duration_ms, r.user_role, r.user_feedback, u.username,
            JSON_UNQUOTE(JSON_EXTRACT(r.response_json, '$.failure.code')) AS failure_code,
            JSON_UNQUOTE(JSON_EXTRACT(r.response_json, '$.message')) AS response_message
            {$from} ORDER BY r.id DESC LIMIT 20 OFFSET ?",
            [...$values, $pagination['offset']])->fetch_all(MYSQLI_ASSOC);
    }
} catch (Throwable $exception) {
    http_response_code(503);
    $error = 'Request history is unavailable. Check that the ai coach database migration has been applied.';
    applicationLog('error', 'ai coach history read failed', ['error_code' => $exception->getCode()]);
}
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('ai coach Requests'), ['styles' => ['assets/css/style.min.css', 'assets/css/modern.min.css', 'assets/css/pages/ai_coach_requests.min.css']]); ?>
<body>
<?php include 'templates/header.php'; ?>
<main class="container coach-review-page">
    <div class="page-heading"><div><h1>ai coach Requests</h1><p class="page-intro">Review real questions and improve step-by-step guidance.</p></div>
        <?php if ($id !== null): ?><a class="button-secondary" href="ai_coach_requests.php">All requests</a>
        <?php elseif ($clearConfirmation === null && $pagination): ?>
            <form method="post" action="ai_coach_requests.php"><?php echo csrfInput(); ?><button type="submit" name="action" value="prepare_clear" class="button-delete">Clear request log</button></form>
        <?php endif; ?>
    </div>
    <p><a class="button-secondary" href="ai_coach_improvements.php">Guidance improvements</a></p>
    <p>Administrator access only. Ratings, failed answers, and slow responses enter the feedback review queue. Verified improvements are tested before reuse; ratings do not directly train the model.</p>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?php echo $escape($error); ?></p><?php endif; ?>
    <?php if (is_int($deletedId)): ?><p class="success" role="status">Request #<?php echo $deletedId; ?> deleted.</p><?php endif; ?>
    <?php if ($deleteConfirmation !== null): ?>
        <section class="coach-review-card" aria-labelledby="delete-request-heading">
            <h2 id="delete-request-heading">Delete Request #<?php echo (int) $detail['id']; ?>?</h2>
            <p>This permanently deletes this question, its answer, feedback, review notes, and corrections. This cannot be undone. Linked improvement cases are kept.</p>
            <form method="post" action="ai_coach_requests.php">
                <?php echo csrfInput(); ?><input type="hidden" name="delete_token" value="<?php echo $escape($deleteConfirmation['token']); ?>">
                <button type="submit" name="action" value="delete_request" class="button-delete">Delete request</button>
                <a class="button-secondary" href="ai_coach_requests.php?id=<?php echo (int) $detail['id']; ?>">Cancel</a>
            </form>
        </section>
    <?php endif; ?>
    <?php if (is_int($clearedCount)): ?><p class="success" role="status">Request log cleared. <?php echo $clearedCount; ?> entries deleted. New questions will continue to be recorded.</p><?php endif; ?>
    <?php if ($clearConfirmation !== null): ?>
        <section class="coach-review-card" aria-labelledby="clear-log-heading">
            <h2 id="clear-log-heading">Clear Request Log?</h2>
            <p>This permanently deletes <?php echo (int) $clearConfirmation['count']; ?> requests and their answers, review notes, and corrections for all users, regardless of the current filter. This cannot be undone.</p>
            <p>Requests still being answered and new requests are kept. Reviewed improvement cases are kept. This does not clear anyone’s open conversation or change the model.</p>
            <form method="post" action="ai_coach_requests.php">
                <?php echo csrfInput(); ?><input type="hidden" name="clear_token" value="<?php echo $escape($clearConfirmation['token']); ?>">
                <button type="submit" name="action" value="clear_requests" class="button-delete"<?php echo $clearConfirmation['count'] === 0 ? ' disabled' : ''; ?>>Delete <?php echo (int) $clearConfirmation['count']; ?> entries</button>
                <a class="button-secondary" href="ai_coach_requests.php">Cancel</a>
            </form>
        </section>
    <?php elseif ($detail): ?>
        <?php $answer = json_decode((string) ($detail['response_json'] ?? '{}'), true) ?: []; ?>
        <?php if (isset($_GET['saved']) && $error === ''): ?><p class="success" role="status">Review saved. The original response is preserved.</p><?php endif; ?>
        <section class="coach-review-card" aria-labelledby="request-heading">
            <h2 id="request-heading">Request #<?php echo (int) $detail['id']; ?></h2>
            <?php if ($deleteConfirmation === null): ?>
                <form method="post" action="ai_coach_requests.php"><?php echo csrfInput(); ?><input type="hidden" name="id" value="<?php echo (int) $detail['id']; ?>"><button type="submit" name="action" value="prepare_delete" class="button-delete">Delete request</button></form>
            <?php endif; ?>
            <p><?php echo $escape(applicationTimestampLabel($detail['created_at'], 'M j, Y g:i:s A')); ?> · <?php echo $escape($detail['username'] ?: 'Deleted user'); ?> · <?php echo $escape($detail['user_role']); ?></p>
            <dl class="coach-review-metadata">
                <div><dt>Page</dt><dd><?php echo $escape($detail['page_path']); ?></dd></div>
                <div><dt>Guided step</dt><dd><?php echo $escape($detail['guided_step'] ?: 'No active walkthrough'); ?></dd></div>
                <div><dt>Result</dt><dd><?php echo $escape($detail['outcome']); ?><?php if ($detail['duration_ms'] !== null): ?> · <?php echo number_format((int) $detail['duration_ms'] / 1000, 2); ?> seconds<?php endif; ?></dd></div>
                <div><dt>Response type</dt><dd><?php echo $escape($answer['mode'] ?? 'No response recorded'); ?></dd></div>
            </dl>
            <?php if ($detail['outcome'] === 'complete' && aiCoachIsFailureText((string) ($answer['message'] ?? ''))): ?><p>The request finished processing, but its answer repeated a service-failure message. The original response is preserved below.</p><?php endif; ?>
            <?php if (isset($answer['failure'])): ?><p>Failure: <?php echo $escape($failureLabels[$answer['failure']['code'] ?? ''] ?? 'Unknown'); ?> · Stage: <?php echo $escape($answer['failure']['stage'] ?? 'unknown'); ?>.<?php if (!empty($answer['failure']['http_status'])): ?> Service HTTP status: <?php echo (int) $answer['failure']['http_status']; ?>.<?php endif; ?></p><?php endif; ?>
            <p>User feedback: <strong><?php echo $escape(str_replace('_',' ', $detail['user_feedback'] ?: 'None yet')); ?></strong> · Suggested review: <?php echo $escape(aiCoachImprovementCategories()[aiCoachTriageImprovement($detail)]); ?></p>
            <p><a class="button-secondary" href="ai_coach_improvements.php?source=<?php echo (int)$detail['id']; ?>">Create improvement case</a></p>
            <details><summary>Timing, retrieved sources, and visible controls</summary><pre><?php echo $escape(json_encode(['timing_and_retrieval'=>$answer['telemetry'] ?? [], 'page_state'=>json_decode($detail['ui_state_json'] ?? '{}',true), 'feedback_state'=>json_decode($detail['feedback_context_json'] ?? '{}',true)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); ?></pre></details>
            <h3>Question</h3><p class="coach-review-prose"><?php echo $escape($detail['question']); ?></p>
            <h3>Original Response</h3><p class="coach-review-prose"><?php echo $escape($answer['message'] ?? $answer['error'] ?? 'No completed response was recorded. The request may still be running or may have been interrupted.'); ?></p>
            <?php if (!empty($answer['question'])): ?><p class="coach-review-prose"><?php echo $escape($answer['question']); ?></p><?php endif; ?>
            <?php if (!empty($answer['sources'])): ?><h3>Manual Sources</h3><ul><?php foreach ($answer['sources'] as $source): ?><li><?php echo $escape($source['title'] ?? ''); ?> <small><?php echo $escape($source['id'] ?? ''); ?></small></li><?php endforeach; ?></ul><?php endif; ?>
            <details><summary>Request context and guidance version</summary>
                <p>Model configuration: <?php echo $escape($detail['model_name']); ?> · MOED <?php echo $escape($detail['application_version']); ?>. Guided responses do not require model inference.</p>
                <p>Guidance revision: <code><?php echo $escape($detail['guidance_revision']); ?></code></p>
                <p>Conversation supplied with this request (browser context, not independently verified):</p>
                <pre><?php echo $escape(json_encode(json_decode($detail['conversation_json'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
            </details>
        </section>
        <section class="coach-review-card" aria-labelledby="review-heading">
            <h2 id="review-heading">Review and Correction</h2>
            <?php if ($detail['reviewed_at']): ?><p>Last reviewed by <?php echo $escape($detail['reviewer_name'] ?: 'Deleted user'); ?> on <?php echo $escape(applicationTimestampLabel($detail['reviewed_at'], 'M j, Y g:i A')); ?>.</p><?php endif; ?>
            <form method="post" action="ai_coach_requests.php?id=<?php echo (int) $detail['id']; ?>">
                <?php echo csrfInput(); ?><input type="hidden" name="id" value="<?php echo (int) $detail['id']; ?>"><input type="hidden" name="version" value="<?php echo (int) $detail['review_version']; ?>">
                <label for="coach-review-status">Assessment</label><select id="coach-review-status" name="review_status"><?php foreach ($statusLabels as $key => $label): ?><option value="<?php echo $key; ?>"<?php echo ($error !== '' ? ($_POST['review_status'] ?? '') : $detail['review_status']) === $key ? ' selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select>
                <label for="coach-review-notes">What worked or needs improvement?</label><textarea id="coach-review-notes" name="review_notes" rows="3" maxlength="4000"><?php echo $escape($error !== '' ? ($_POST['review_notes'] ?? '') : $detail['review_notes']); ?></textarea>
                <label for="coach-correction">Correct step-by-step guidance</label><textarea id="coach-correction" name="corrected_guidance" rows="6" maxlength="8000" placeholder="Start from the user's page. Name the next control, what changes after selecting it, and how to confirm the task succeeded."><?php echo $escape($error !== '' ? ($_POST['corrected_guidance'] ?? '') : $detail['corrected_guidance']); ?></textarea>
                <button type="submit" class="button-primary">Save review</button>
            </form>
        </section>
    <?php elseif ($id === null && $pagination): ?>
        <form method="get" action="ai_coach_requests.php" class="coach-review-filters" role="search">
            <div><label for="coach-request-search">Search questions or corrections</label><input id="coach-request-search" type="search" name="q" value="<?php echo $escape($query); ?>" maxlength="200"></div>
            <div><label for="coach-review-filter">Assessment</label><select id="coach-review-filter" name="review"><option value="all">All requests</option><?php foreach ($statusLabels as $key => $label): ?><option value="<?php echo $key; ?>"<?php echo $review === $key ? ' selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></div>
            <button type="submit" class="button-secondary">Filter</button>
        </form>
        <?php renderPagination($pagination['total'], $pagination['page'], 20, 'ai_coach_requests.php?' . http_build_query(['q' => $query, 'review' => $review]), 'requests', 'ai coach request pages', 'page', 'per_page', [20]); ?>
        <div class="data-table-scroll coach-request-table-wrapper" role="region" aria-label="ai coach request history" tabindex="0"><table class="data-table coach-request-table">
            <colgroup><col class="coach-request-question-col"><col class="coach-request-user-col"><col class="coach-request-result-col"><col class="coach-request-assessment-col"><col class="coach-request-actions-col"></colgroup>
            <thead><tr><th scope="col">Question</th><th scope="col">User</th><th scope="col">Result</th><th scope="col">Assessment</th><th scope="col">Actions</th></tr></thead><tbody>
            <?php foreach ($rows as $row):
                $failureEcho = $row['outcome'] === 'complete' && aiCoachIsFailureText((string) $row['response_message']);
                $failureCode = $failureEcho ? 'invalid_response' : ($row['failure_code'] ?? '');
                $displayOutcome = $failureEcho ? 'error' : $row['outcome'];
            ?><tr>
                <td><a class="record-link coach-request-question" href="ai_coach_requests.php?id=<?php echo (int) $row['id']; ?>"><?php echo $escape($row['question']); ?></a><small class="coach-request-secondary">Page: <?php echo $escape($pageLabels[$row['page_path']] ?? $row['page_path']); ?></small></td>
                <td><span class="coach-request-user"><?php echo $escape($row['username'] ?: 'Deleted user'); ?></span><small class="coach-request-secondary"><?php echo $escape(ucfirst($row['user_role'])); ?></small><small class="coach-request-secondary coach-request-timestamp"><span><?php echo $escape(applicationTimestampLabel($row['created_at'], 'M j, Y')); ?></span><span><?php echo $escape(applicationTimestampLabel($row['created_at'], 'g:i A T')); ?></span></small></td>
                <td><span class="coach-request-badge coach-result-<?php echo $escape($displayOutcome); ?>"><?php echo $escape($failureLabels[$failureCode] ?? $outcomeLabels[$displayOutcome] ?? ucfirst($displayOutcome)); ?></span><?php if ($row['duration_ms'] !== null): ?><small class="coach-request-secondary"><?php echo number_format((int) $row['duration_ms'] / 1000, 1); ?> s</small><?php endif; ?></td>
                <td><span class="coach-request-badge coach-assessment-<?php echo $escape($row['review_status']); ?>"><?php echo $escape($statusLabels[$row['review_status']] ?? $row['review_status']); ?></span><?php if ($row['user_feedback']): ?><small class="coach-request-secondary">User: <?php echo $escape(str_replace('_',' ',$row['user_feedback'])); ?></small><?php endif; ?></td>
                <td><form method="post" action="ai_coach_requests.php" class="coach-request-delete-form"><?php echo csrfInput(); ?><input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>"><button type="submit" name="action" value="prepare_delete" class="button-delete" aria-label="Delete request #<?php echo (int) $row['id']; ?>">Delete</button></form></td>
            </tr><?php endforeach; ?>
            <?php if ($rows === []): ?><tr><td colspan="5" class="coach-request-empty">No requests recorded for this filter. New questions appear here after they are submitted to ai coach.</td></tr><?php endif; ?>
        </tbody></table></div>
    <?php endif; ?>
</main>
<?php include 'templates/footer.php'; ?>
</body></html>
