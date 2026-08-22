<?php
require_once __DIR__ . '/bootstrap.php';
include 'follow_up_task_helpers.php';
startSecureSession();
requireLogin();
requireFollowUpTaskSchema($conn);

$user_role = $_SESSION['role'] ?? '';
if (!canManageFollowUpTasks($user_role)) {
    header('Location: standard_tasks.php');
    exit();
}
$template_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$standard_task = $template_id ? fetchStandardEventTask($conn, $template_id) : null;
if (!$standard_task) {
    header('Location: standard_tasks.php');
    exit();
}
if (!empty($standard_task['is_archived'])) {
    header('Location: view_standard_task.php?id=' . (int) $standard_task['id']);
    exit();
}

$standard_task_form_values = $standard_task;
$error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_standard_task'])) {
    requireValidCsrfToken();
    $submitted_version = (string) ($_POST['task_version'] ?? '');
    $transaction_started = false;
    try {
        $normalized = normalizeStandardEventTaskInput($_POST);
        $conn->begin_transaction();
        $transaction_started = true;
        $locked_task = fetchStandardEventTask($conn, $template_id, true);
        if (!$locked_task || !empty($locked_task['is_archived'])) {
            throw new InvalidArgumentException('That active standard task is no longer available.');
        }
        if ($submitted_version === ''
            || !hash_equals((string) $locked_task['updated_at'], $submitted_version)
        ) {
            throw new InvalidArgumentException('This standard task changed in another session. Reload it and review the latest version before saving.');
        }
        $stmt = $conn->prepare(
            'UPDATE standard_event_tasks
             SET title = ?, details = ?, priority = ?, due_anchor = ?,
                 due_offset_days = ?, sort_order = ?
             WHERE id = ? AND is_archived = 0'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the standard-task update.');
        }
        $stmt->bind_param(
            'ssssiii',
            $normalized['title'],
            $normalized['details'],
            $normalized['priority'],
            $normalized['due_anchor'],
            $normalized['due_offset_days'],
            $normalized['sort_order'],
            $template_id
        );
        if (!$stmt->execute() || $stmt->affected_rows > 1) {
            throw new RuntimeException('Unable to update the standard task.');
        }
        $stmt->close();
        $conn->commit();
        $transaction_started = false;
        $_SESSION['standard_task_action_message'] = 'Standard task updated. The change will apply automatically to future events.';
        header('Location: view_standard_task.php?id=' . $template_id);
        exit();
    } catch (Throwable $exception) {
        if ($transaction_started) {
            $conn->rollback();
        }
        $error_message = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'Unable to update the standard task. Please try again.';
        $standard_task_form_values = array_merge($standard_task, $_POST);
        $standard_task_form_values['updated_at'] = $standard_task['updated_at'];
    }
}
$standard_task_form_action = 'edit_standard_task.php?id=' . $template_id;
$standard_task_form_cancel_url = 'view_standard_task.php?id=' . $template_id;
$standard_task_form_submit_label = 'Save changes';
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead('Edit Standard Event Task - DNR', array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
  ),
)); ?>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="tasks.php">Work Queue</a><span aria-hidden="true">/</span><a href="standard_tasks.php">Standard Event Tasks</a><span aria-hidden="true">/</span><span>Edit Task</span></nav>
    <div class="page-heading form-page-heading"><div><h1>Edit Standard Event Task</h1><p class="page-intro">Set the reusable task content, priority, due-date rule, and checklist order.</p></div></div>
    <?php if ($error_message !== ''): ?><p class="error"><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php include 'templates/standard_event_task_form.php'; ?>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
