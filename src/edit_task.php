<?php
include 'config.php';
include 'functions.php';
include 'follow_up_task_helpers.php';
startSecureSession();
requireLogin();
requireFollowUpTaskSchema($conn);

$user_role = $_SESSION['role'] ?? '';
if (!canManageFollowUpTasks($user_role)) {
    header('Location: tasks.php');
    exit();
}

$task_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$task_id) {
    header('Location: tasks.php');
    exit();
}
$task = fetchFollowUpTask($conn, $task_id);
if (!$task) {
    header('Location: tasks.php');
    exit();
}

$task_return_to = safeFollowUpTaskReturnUrl(
    $_POST['return_to'] ?? $_GET['return_to'] ?? 'tasks.php'
);
$existing_subject_value = followUpTaskSubjectValue($task);
$task_selected_subject = $existing_subject_value;
$task_form_values = $task;
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_task'])) {
    requireValidCsrfToken();
    $submitted_version = (string) ($_POST['task_version'] ?? '');
    $task_selected_subject = (string) ($_POST['subject'] ?? 'general');
    $transaction_started = false;
    try {
        $normalized = normalizeFollowUpTaskInput($conn, $_POST, $existing_subject_value);
        $conn->begin_transaction();
        $transaction_started = true;
        $locked_task = fetchFollowUpTask($conn, $task_id, true);
        if (!$locked_task) {
            throw new InvalidArgumentException('That task is no longer available.');
        }
        if ($submitted_version === ''
            || !hash_equals((string) $locked_task['updated_at'], $submitted_version)
        ) {
            throw new InvalidArgumentException('This task changed in another session. Reload it and review the latest version before saving.');
        }

        $completed_by = null;
        $completed_at = null;
        if ($normalized['status'] === 'completed') {
            $completed_by = $locked_task['status'] === 'completed'
                ? $locked_task['completed_by']
                : (int) $_SESSION['user_id'];
            $completed_at = $locked_task['status'] === 'completed'
                ? $locked_task['completed_at']
                : gmdate('Y-m-d H:i:s');
        }

        $stmt = $conn->prepare(
            'UPDATE follow_up_tasks
             SET title = ?, details = ?, status = ?, priority = ?, due_date = ?,
                 waiting_on = ?, subject_type = ?, engagement_id = ?,
                 organization_id = ?, contact_id = ?, assigned_to = ?,
                 completed_by = ?, completed_at = ?
             WHERE id = ?'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the task update.');
        }
        $stmt->bind_param(
            'sssssssiiiiisi',
            $normalized['title'],
            $normalized['details'],
            $normalized['status'],
            $normalized['priority'],
            $normalized['due_date'],
            $normalized['waiting_on'],
            $normalized['subject_type'],
            $normalized['engagement_id'],
            $normalized['organization_id'],
            $normalized['contact_id'],
            $normalized['assigned_to'],
            $completed_by,
            $completed_at,
            $task_id
        );
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to update the task.');
        }
        $stmt->close();
        $conn->commit();
        $transaction_started = false;
        $_SESSION['task_action_message'] = 'Task updated.';
        header('Location: ' . $task_return_to);
        exit();
    } catch (Throwable $exception) {
        if ($transaction_started) {
            $conn->rollback();
        }
        $error_message = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'Unable to update the task. Please try again.';
        $task_form_values = array_merge($task, $_POST);
        $task_form_values['updated_at'] = $task['updated_at'];
    }
}

$task_users = followUpTaskUsers($conn);
$render_subject_value = $task_selected_subject;
try {
    $current_subject_parts = parseFollowUpTaskSubject($render_subject_value);
} catch (InvalidArgumentException $exception) {
    $render_subject_value = $existing_subject_value;
    $task_selected_subject = $existing_subject_value;
    $current_subject_parts = parseFollowUpTaskSubject($render_subject_value);
}
$current_subject_record = followUpTaskSubjectRecord(
    $conn,
    $current_subject_parts['subject_type'],
    $current_subject_parts['subject_id']
);
$task_selected_record = $current_subject_record;
$task_inactive_subject = $current_subject_record && !$current_subject_record['active']
    ? $current_subject_record
    : null;
$task_form_action = 'edit_task.php?id=' . $task_id;
$task_form_submit_label = 'Save changes';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Task - DNR</title>
    <link rel="stylesheet" href="assets/css/style.min.css?v=0.0.20">
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="tasks.php">Work Queue</a><span aria-hidden="true">/</span><span>Edit Task</span></nav>
    <div class="page-heading form-page-heading"><div><h1>Edit Task</h1><p class="page-intro">Update the next action, owner, timing, or status.</p></div></div>
    <?php if ($error_message !== ''): ?>
        <p class="error"><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php include 'templates/follow_up_task_form.php'; ?>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
