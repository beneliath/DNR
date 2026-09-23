<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ai_coach_helpers.php';
require_once __DIR__ . '/ai_coach_improvement_helpers.php';
require_once __DIR__ . '/ai_coach_feedback_helpers.php';
startSecureSession();
requireAdmin();
header('Cache-Control: private, no-store');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET','POST'], true)) { header('Allow: GET, POST'); http_response_code(405); exit; }
$id = \Dnr\Http\RequestInput::positiveInt($_GET, 'id');
$source = \Dnr\Http\RequestInput::positiveInt($_GET, 'source');
$error = ''; $record = null; $rows = []; $feedback = null; $reviews = [];
$defaults = ['id'=>0,'version'=>0,'source_request_id'=>$source,'category'=>'retrieval','status'=>'draft','question'=>'','user_role'=>'editor','page_path'=>'help.php','guided_step'=>'','expected_guidance'=>'','expected_workflow'=>'','topic_ids'=>'[]','required_terms'=>'[]','forbidden_terms'=>'[]'];
try {
    if ($method === 'POST') {
        requireValidCsrfToken();
        $input = [];
        foreach (array_merge(array_keys($defaults), ['verified','redacted']) as $key) $input[$key] = \Dnr\Http\RequestInput::string($_POST, $key);
        $id = aiCoachSaveImprovement($conn, $input, (int) $_SESSION['user_id']);
        header('Location: ai_coach_improvements.php?id=' . $id . '&saved=1', true, 303); exit;
    }
    if (isset($_GET['export'])) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="coach-reviewed-cases.json"');
        echo json_encode(aiCoachExportImprovements($conn), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); exit;
    }
    if ($id !== null) {
        $record = $conn->execute_query('SELECT * FROM ai_coach_improvements WHERE id=?', [$id])->fetch_assoc();
        if (!$record) { http_response_code(404); $error = 'Improvement not found.'; }
    } elseif ($source !== null) {
        $request = $conn->execute_query('SELECT * FROM ai_coach_requests WHERE id=?', [$source])->fetch_assoc();
        if (!$request) { http_response_code(404); $error = 'Request not found.'; }
        else {
            $answer = json_decode($request['response_json'] ?? '{}', true) ?: [];
            $record = array_replace($defaults, ['question'=>$request['question'],'user_role'=>$request['user_role'],'page_path'=>$request['page_path'],
                'guided_step'=>$request['guided_step'],'category'=>aiCoachTriageImprovement($request),
                'expected_guidance'=>$request['corrected_guidance'] ?? '', 'topic_ids'=>json_encode(array_column($answer['sources'] ?? [], 'id'))]);
        }
    } elseif (isset($_GET['new'])) $record = $defaults;
    else {
        $rows = $conn->query('SELECT id,question,category,status,guidance_revision,updated_at FROM ai_coach_improvements ORDER BY id DESC LIMIT 100')->fetch_all(MYSQLI_ASSOC);
        $feedback = aiCoachFeedbackPacket(aiCoachFeedbackPending($conn));
        $reviews = $conn->query('SELECT request_id,disposition,summary,reviewed_at FROM ai_coach_feedback_reviews ORDER BY id DESC LIMIT 10')->fetch_all(MYSQLI_ASSOC);
    }
} catch (InvalidArgumentException | RuntimeException $exception) {
    http_response_code($exception instanceof InvalidArgumentException ? 400 : 409);
    $error = $exception instanceof mysqli_sql_exception ? 'The improvement could not be saved. Please try again.' : $exception->getMessage();
    if ($method === 'POST') {
        $record = array_replace($defaults, $input ?? []);
        foreach (['topic_ids','required_terms','forbidden_terms'] as $key) $record[$key] = json_encode(explode("\n", $record[$key]));
    }
}
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html><html lang="en">
<?php renderPageHead(applicationPageTitle('ai coach Improvements'), ['styles'=>['assets/css/style.min.css','assets/css/modern.min.css','assets/css/pages/ai_coach_requests.min.css']]); ?>
<body><?php include 'templates/header.php'; ?>
<main class="container coach-review-page">
<div class="page-heading"><div><h1>ai coach Improvements</h1><p class="page-intro">Turn verified corrections into lasting guidance and regression checks.</p></div><a class="button-secondary" href="ai_coach_requests.php">Request log</a></div>
<p>Verify the actual MOED workflow, correct the manual or guidance where needed, and check the expected answer. These cases remain when the request log is cleared. Approval enables matching explanations for the same role, page, and guidance version; it does not retrain the model.</p>
<?php if ($error): ?><p class="error" role="alert"><?php echo $escape($error); ?></p><?php endif; ?>
<?php if (isset($_GET['saved']) && !$error): ?><p class="success" role="status">Improvement saved.</p><?php endif; ?>
<?php if ($record): ?>
<section class="coach-review-card"><h2><?php echo $record['id'] ? 'Improvement #' . (int) $record['id'] : 'New improvement'; ?></h2>
<?php if ($record['status']==='approved' && ($record['guidance_revision'] ?? '')!==aiCoachGuidanceRevision()): ?><p class="error">The guidance changed. Recheck and approve this case before its answer can be reused.</p><?php endif; ?>
<form method="post" action="ai_coach_improvements.php<?php echo $record['id'] ? '?id=' . (int) $record['id'] : ''; ?>">
<?php echo csrfInput(); ?>
<?php foreach (['id','version','source_request_id'] as $key): ?><input type="hidden" name="<?php echo $key; ?>" value="<?php echo $escape($record[$key]); ?>"><?php endforeach; ?>
<label for="question">Question (remove names and private record details)</label><textarea id="question" name="question" rows="3" maxlength="1200" required><?php echo $escape($record['question']); ?></textarea>
<div class="coach-improvement-fields">
<?php foreach (['category'=>aiCoachImprovementCategories(),'user_role'=>['admin'=>'Administrator','editor'=>'Editor','reviewer'=>'Reviewer'],'page_path'=>aiCoachPages(),'guided_step'=>[''=>'No active walkthrough']+aiCoachSteps(),'expected_workflow'=>[''=>'No required walkthrough']+array_map(static fn($w)=>$w['title'],aiCoachWorkflows()),'status'=>['draft'=>'Draft','approved'=>'Approved']] as $key=>$options): ?>
<div><label for="<?php echo $key; ?>"><?php echo $escape(ucfirst(str_replace('_',' ',$key))); ?></label><select id="<?php echo $key; ?>" name="<?php echo $key; ?>"><?php foreach ($options as $value=>$label): ?><option value="<?php echo $escape($value); ?>"<?php echo $record[$key]===$value ? ' selected' : ''; ?>><?php echo $escape(is_array($label) ? ($label['title'] ?? $value) : $label); ?></option><?php endforeach; ?></select></div>
<?php endforeach; ?>
</div>
<label for="expected_guidance">Verified expected guidance</label><textarea id="expected_guidance" name="expected_guidance" rows="6" maxlength="2000" required><?php echo $escape($record['expected_guidance']); ?></textarea>
<p>Use exact control labels and the real entry point. An AI answer is evidence of a problem, not proof of application behavior.</p>
<?php foreach (['topic_ids'=>'Manual topic IDs (one per line)','required_terms'=>'Words or phrases the answer must include (one per line)','forbidden_terms'=>'Words or phrases the answer must not include (one per line)'] as $key=>$label): ?>
<label for="<?php echo $key; ?>"><?php echo $label; ?></label><textarea id="<?php echo $key; ?>" name="<?php echo $key; ?>" rows="3" maxlength="3000"><?php echo $escape(implode("\n", json_decode($record[$key],true) ?: [])); ?></textarea>
<?php endforeach; ?>
<details><summary>Find current Comprehensive Manual topic IDs</summary><ul class="coach-topic-list"><?php foreach (aiCoachManualTopics() as $topic): ?><li><a href="<?php echo $escape('assets/docs/moed-comprehensive-user-manual.pdf#page=' . (int)$topic['page']); ?>"><?php echo $escape($topic['title']); ?></a> <code><?php echo $escape($topic['id']); ?></code></li><?php endforeach; ?></ul></details>
<label class="coach-check"><input type="checkbox" name="verified" value="1"> I checked this guidance against the current application and manual</label>
<label class="coach-check"><input type="checkbox" name="redacted" value="1"> This case contains no private names, record details, credentials, or personal data</label>
<button class="button-primary" type="submit">Save improvement</button> <a class="button-secondary" href="ai_coach_improvements.php">All improvements</a>
</form></section>
<?php else: ?>
<section class="coach-review-card" aria-labelledby="feedback-queue-heading">
<h2 id="feedback-queue-heading">Feedback Review Queue</h2>
<p>Ratings, failed answers, and responses over 15 seconds enter this queue automatically. Related requests are grouped for investigation; ratings alone never change an answer. Completed reviews record the verified sources and successful checks.</p>
<?php if (empty($feedback['groups'])): ?><p>No feedback is waiting for investigation.</p>
<?php else: ?>
<p><?php echo (int)$feedback['pending_in_batch']; ?> requests in this batch (oldest first, up to 100). Investigating a group does not assume every question has the same answer.</p>
<div class="data-table-scroll coach-request-table-wrapper"><table class="data-table coach-request-table"><thead><tr><th scope="col">Questions</th><th scope="col">Context</th><th scope="col">Signals</th></tr></thead><tbody>
<?php foreach ($feedback['groups'] as $group): ?><tr><td><?php foreach ($group['requests'] as $request): ?><p><a class="coach-request-question" href="ai_coach_requests.php?id=<?php echo (int)$request['id']; ?>"><?php echo $escape($request['question']); ?></a></p><?php endforeach; ?></td>
<td><?php echo $escape(aiCoachImprovementCategories()[$group['category']]); ?><span class="coach-request-secondary"><?php echo $escape((aiCoachPages()[$group['page']] ?? $group['page']) . ' · ' . $group['role']); ?></span></td>
<td><?php echo (int)$group['problems']; ?> to investigate<span class="coach-request-secondary"><?php echo (int)$group['helpful']; ?> helpful</span></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
</section>
<?php if ($reviews): ?><section class="coach-review-card"><h2>Recent Development Reviews</h2><ul>
<?php foreach ($reviews as $review): ?><li><strong><?php echo $escape(ucfirst($review['disposition'])); ?></strong>: <?php echo $escape($review['summary']); ?><span class="coach-request-secondary"><?php echo $escape(applicationTimestampLabel($review['reviewed_at'],'M j, Y g:i A')); ?><?php if ($review['request_id']): ?> · <a href="ai_coach_requests.php?id=<?php echo (int)$review['request_id']; ?>">Original request</a><?php endif; ?></span></li><?php endforeach; ?>
</ul></section><?php endif; ?>
<h2>Verified Guidance Cases</h2>
<p><a class="button-primary" href="ai_coach_improvements.php?new=1">New improvement</a> <a class="button-secondary" href="ai_coach_improvements.php?export=1">Export approved test cases</a></p>
<div class="data-table-scroll coach-request-table-wrapper"><table class="data-table coach-request-table"><thead><tr><th scope="col">Question</th><th scope="col">Issue</th><th scope="col">Status</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr><td><a class="coach-request-question" href="ai_coach_improvements.php?id=<?php echo (int)$row['id']; ?>"><?php echo $escape($row['question']); ?></a></td><td><?php echo $escape(aiCoachImprovementCategories()[$row['category']] ?? $row['category']); ?></td><td><?php echo $escape($row['status']==='approved' && $row['guidance_revision']!==aiCoachGuidanceRevision() ? 'Needs revalidation' : ucfirst($row['status'])); ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="3" class="coach-request-empty">No improvements yet. Start from a problem response in the request log or create a new case.</td></tr><?php endif; ?>
</tbody></table></div>
<?php endif; ?></main><?php include 'templates/footer.php'; ?></body></html>
