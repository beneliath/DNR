<?php
require_once __DIR__ . '/bootstrap.php';
include 'follow_up_task_helpers.php';
startSecureSession();
requireLogin();
requireFollowUpTaskSchema($conn);

$user_role = $_SESSION['role'] ?? '';
if (!canManageFollowUpTasks($user_role)) {
    header('Location: tasks.php');
    exit();
}

$duplicate_from = filter_input(INPUT_GET, 'duplicate_from', FILTER_VALIDATE_INT);
$duplicate_source = null;
if (array_key_exists('duplicate_from', $_GET)) {
    $duplicate_source = $duplicate_from ? fetchFollowUpTask($conn, $duplicate_from) : null;
    if (!$duplicate_source) {
        header('Location: tasks.php');
        exit();
    }
}
$duplicate_mode = $duplicate_source !== null;

$task_return_to = safeFollowUpTaskReturnUrl(
    \Dnr\Http\RequestInput::string(
        $_POST,
        'return_to',
        \Dnr\Http\RequestInput::string($_GET, 'return_to', 'tasks.php')
    )
);
$requested_subject_type = \Dnr\Http\RequestInput::string($_GET, 'subject_type', 'general');
$requested_subject_id = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT);
$task_selected_subject = $requested_subject_type === 'general'
    ? 'general'
    : $requested_subject_type . ':' . (int) $requested_subject_id;
try {
    $requested_subject = parseFollowUpTaskSubject($task_selected_subject);
    $requested_record = followUpTaskSubjectRecord(
        $conn,
        $requested_subject['subject_type'],
        $requested_subject['subject_id']
    );
    if (!$requested_record || !$requested_record['active']) {
        $task_selected_subject = 'general';
    }
} catch (InvalidArgumentException $exception) {
    $task_selected_subject = 'general';
}
$task_selected_subject = $duplicate_mode ? '' : $task_selected_subject;

$task_form_values = [
    'title' => '',
    'details' => '',
    'status' => 'open',
    'priority' => 'normal',
    'due_date' => '',
    'waiting_on' => '',
    'assigned_to' => (int) $_SESSION['user_id'],
];
if ($duplicate_mode) {
    $task_form_values = followUpTaskDuplicateFormValues($duplicate_source);
}
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_task'])) {
    requireValidCsrfToken();
    $task_form_values = $_POST;
    $task_selected_subject = (string) ($_POST['subject'] ?? 'general');
    $transaction_started = false;
    try {
        $conn->begin_transaction();
        $transaction_started = true;
        $task = normalizeFollowUpTaskInput($conn, $_POST);
        if ($duplicate_mode) {
            requireDifferentEngagementForTaskDuplicate($duplicate_source, $task);
        }
        $created_by = (int) $_SESSION['user_id'];
        insertFollowUpTask($conn, $task, $created_by);
        $conn->commit();
        $transaction_started = false;
        $_SESSION['task_action_message'] = $duplicate_mode ? 'Task duplicated.' : 'Task added.';
        header('Location: ' . $task_return_to);
        exit();
    } catch (Throwable $exception) {
        if ($transaction_started) {
            $conn->rollback();
        }
        $error_message = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'Unable to save the task. Please try again.';
    }
}

$task_selected_record = null;
try {
    $selected_subject_parts = parseFollowUpTaskSubject($task_selected_subject);
    if ($duplicate_mode && $selected_subject_parts['subject_type'] !== 'engagement') {
        throw new InvalidArgumentException('Select the destination event for the duplicated task.');
    }
    $task_selected_record = followUpTaskSubjectRecord(
        $conn,
        $selected_subject_parts['subject_type'],
        $selected_subject_parts['subject_id']
    );
} catch (InvalidArgumentException $exception) {
    $task_selected_subject = $duplicate_mode ? '' : 'general';
}
$task_users = followUpTaskUsers($conn);
$task_form_action = $duplicate_mode
    ? 'add_task.php?' . http_build_query(['duplicate_from' => (int) $duplicate_source['id']])
    : 'add_task.php';
$task_form_submit_label = $duplicate_mode ? 'Duplicate task' : 'Add task';
$task_inactive_subject = null;
$task_require_engagement_subject = $duplicate_mode;
$page_title = $duplicate_mode ? 'Duplicate Task' : 'New Task';
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle($page_title), array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
  ),
)); ?>
<body>
<?php include 'templates/header.php'; ?>
<div class="container" role="main">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="tasks.php">Work Queue</a><span aria-hidden="true">/</span><span><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></span></nav>
    <div class="page-heading form-page-heading"><div><h1><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></h1><p class="page-intro"><?php echo $duplicate_mode ? 'Copy this task to a different event, then adjust its owner or timing.' : 'Make the next action, owner, and due date explicit.'; ?></p></div></div>
    <?php if ($error_message !== ''): ?>
        <p class="error"><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php include 'templates/follow_up_task_form.php'; ?>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
