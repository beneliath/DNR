<?php
include 'config.php';
include 'functions.php';
include 'follow_up_task_helpers.php';
startSecureSession();
requireLogin();
requireFollowUpTaskSchema($conn);

$user_role = $_SESSION['role'] ?? '';
$template_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$standard_task = $template_id ? fetchStandardEventTask($conn, $template_id) : null;
if (!$standard_task) {
    header('Location: standard_tasks.php');
    exit();
}
$is_archived = !empty($standard_task['is_archived']);
$priority_labels = followUpTaskPriorities();
$action_message = $_SESSION['standard_task_action_message'] ?? '';
$action_error = $_SESSION['standard_task_action_error'] ?? '';
unset($_SESSION['standard_task_action_message'], $_SESSION['standard_task_action_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Standard Task Details - DNR</title>
    <link rel="stylesheet" href="assets/css/style.min.css?v=0.0.20">
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="tasks.php">Work Queue</a><span aria-hidden="true">/</span><a href="standard_tasks.php?status=<?php echo $is_archived ? 'archived' : 'active'; ?>">Standard Event Tasks</a><span aria-hidden="true">/</span><span>Task Details</span></nav>
    <div class="page-heading record-page-heading">
        <div>
            <h1><?php echo htmlspecialchars($standard_task['title'], ENT_QUOTES, 'UTF-8'); ?><?php if ($is_archived): ?><span class="archive-status">Archived</span><?php endif; ?></h1>
            <p class="page-intro">Reusable work definition for event checklists.</p>
        </div>
        <?php if (!$is_archived && canManageFollowUpTasks($user_role)): ?><a href="edit_standard_task.php?id=<?php echo (int) $standard_task['id']; ?>" class="button-add">Edit standard task</a><?php endif; ?>
    </div>

    <?php if ($action_message !== ''): ?><p class="success"><?php echo htmlspecialchars($action_message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($action_error !== ''): ?><p class="error"><?php echo htmlspecialchars($action_error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>

    <div class="standard-task-details">
        <div class="standard-task-detail"><strong>Due rule</strong><span><?php echo htmlspecialchars(standardEventTaskScheduleLabel($standard_task['due_anchor'], $standard_task['due_offset_days']), ENT_QUOTES, 'UTF-8'); ?></span></div>
        <div class="standard-task-detail"><strong>Priority</strong><span class="task-due task-priority-<?php echo htmlspecialchars($standard_task['priority'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($priority_labels[$standard_task['priority']], ENT_QUOTES, 'UTF-8'); ?></span></div>
        <div class="standard-task-detail"><strong>Display order</strong><span><?php echo (int) $standard_task['sort_order']; ?></span></div>
        <div class="standard-task-detail"><strong>Generated work</strong><span><?php echo (int) $standard_task['generated_count']; ?> event task<?php echo (int) $standard_task['generated_count'] === 1 ? '' : 's'; ?></span></div>
        <div class="standard-task-detail standard-task-detail-wide"><strong>Notes</strong><span><?php echo !empty($standard_task['details']) ? nl2br(htmlspecialchars($standard_task['details'], ENT_QUOTES, 'UTF-8')) : 'No notes'; ?></span></div>
        <div class="standard-task-detail standard-task-detail-wide"><strong>Internal key</strong><code><?php echo htmlspecialchars($standard_task['template_key'], ENT_QUOTES, 'UTF-8'); ?></code></div>
        <?php if ($is_archived): ?>
            <div class="standard-task-detail standard-task-detail-wide"><strong>Archived</strong><span><?php echo htmlspecialchars($standard_task['archived_at'], ENT_QUOTES, 'UTF-8'); ?> UTC<?php if (!empty($standard_task['archiver_username'])): ?> by <?php echo htmlspecialchars($standard_task['archiver_username'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></span></div>
        <?php endif; ?>
    </div>

    <p class="result-context">Active definitions are copied automatically when new events are created. Edits affect future copies only; existing event tasks are independent.</p>

    <div class="engagement-page-actions standard-task-page-actions">
        <a href="standard_tasks.php?status=<?php echo $is_archived ? 'archived' : 'active'; ?>" class="cancel-button">Back to standard tasks</a>
        <?php if (canArchiveEntries($user_role)): ?>
            <form method="post" action="standard_tasks.php"><?php echo csrfInput(); ?><input type="hidden" name="template_id" value="<?php echo (int) $standard_task['id']; ?>"><input type="hidden" name="list_status" value="<?php echo $is_archived ? 'archived' : 'active'; ?>"><input type="hidden" name="action" value="<?php echo $is_archived ? 'restore' : 'archive'; ?>"><button type="submit" class="<?php echo $is_archived ? 'restore-button' : 'archive-button'; ?>"><?php echo $is_archived ? 'Restore standard task' : 'Archive standard task'; ?></button></form>
        <?php endif; ?>
        <?php if ($is_archived && canDeleteEntries($user_role)): ?>
            <form method="post" action="standard_tasks.php" data-delete-confirmation="Permanently delete this standard task? Existing tasks already added to events will remain." data-archive-button-label="Keep archived"><?php echo csrfInput(); ?><input type="hidden" name="template_id" value="<?php echo (int) $standard_task['id']; ?>"><input type="hidden" name="list_status" value="archived"><input type="hidden" name="action" value="delete"><button type="submit" class="delete-button">Permanently delete</button></form>
        <?php endif; ?>
    </div>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
