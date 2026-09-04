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

$suggested_sort_order = 10;
$order_result = $conn->query(
    'SELECT COALESCE(MAX(sort_order), 0) AS maximum_order FROM standard_event_tasks'
);
if ($order_result) {
    $maximum_order = (int) ($order_result->fetch_assoc()['maximum_order'] ?? 0);
    $suggested_sort_order = min(65535, $maximum_order + 10);
}
$standard_task_form_values = [
    'title' => '',
    'details' => '',
    'priority' => 'normal',
    'due_anchor' => 'event_start',
    'due_offset_days' => 0,
    'sort_order' => $suggested_sort_order,
];
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_standard_task'])) {
    requireValidCsrfToken();
    $standard_task_form_values = array_merge($standard_task_form_values, $_POST);
    try {
        $created_by = (int) $_SESSION['user_id'];
        $template_id = createStandardEventTask($conn, $_POST, $created_by);
        $_SESSION['standard_task_action_message'] = 'Standard task added. It will be included automatically when new events are created.';
        header('Location: view_standard_task.php?id=' . $template_id);
        exit();
    } catch (Throwable $exception) {
        $error_message = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'Unable to save the standard task. Please try again.';
    }
}

$standard_task_form_action = 'add_standard_task.php';
$standard_task_form_cancel_url = 'standard_tasks.php';
$standard_task_form_submit_label = 'Add standard task';
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('New Standard Event Task'), array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
    2 => 'assets/css/pages/add_standard_task.min.css',
  ),
)); ?>
<body class="add-standard-task-body">
<?php include 'templates/header.php'; ?>
<main class="container add-standard-task-page">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="tasks.php">Work Queue</a><span aria-hidden="true">/</span><a href="standard_tasks.php">Standard Event Tasks</a><span aria-hidden="true">/</span><span>New Task</span></nav>
    <div class="page-heading form-page-heading add-standard-task-heading"><div><h1>New Standard Event Task</h1><p class="page-intro">Add reusable work that will be assigned automatically when a new event is created.</p></div></div>
    <?php if ($error_message !== ''): ?><p class="error"><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php include 'templates/standard_event_task_form.php'; ?>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
