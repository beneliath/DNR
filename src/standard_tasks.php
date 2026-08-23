<?php
require_once __DIR__ . '/bootstrap.php';
include 'follow_up_task_helpers.php';
include 'two_factor_helpers.php';
startSecureSession();
requireLogin();
requireFollowUpTaskSchema($conn);

$user_role = $_SESSION['role'] ?? '';
$current_user_id = (int) ($_SESSION['user_id'] ?? 0);
$requested_list_status = \Dnr\Http\RequestInput::string(
    $_POST,
    'list_status',
    \Dnr\Http\RequestInput::string($_GET, 'status')
);
$list_status = $requested_list_status === 'archived'
    ? 'archived'
    : 'active';
$show_archived = $list_status === 'archived';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = (string) ($_POST['action'] ?? '');
    $template_id = filter_input(INPUT_POST, 'template_id', FILTER_VALIDATE_INT);
    if (in_array($action, ['archive', 'restore'], true)
        && !canArchiveEntries($user_role)
    ) {
        http_response_code(403);
        exit('Forbidden.');
    }
    if ($action === 'delete' && !canDeleteEntries($user_role)) {
        http_response_code(403);
        exit('Forbidden.');
    }
    if ($action === 'delete') {
        requireRecentAdminElevation('standard_tasks.php?status=archived');
    }

    try {
        if (!$template_id) {
            throw new InvalidArgumentException('Select a valid standard task.');
        }
        if ($action === 'archive') {
            $stmt = $conn->prepare(
                'UPDATE standard_event_tasks
                 SET is_archived = 1, archived_by = ?, archived_at = UTC_TIMESTAMP()
                 WHERE id = ? AND is_archived = 0'
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare the standard-task archive.');
            }
            $stmt->bind_param('ii', $current_user_id, $template_id);
            $message = 'Standard task archived. It will not be added to future event checklists.';
        } elseif ($action === 'restore') {
            $stmt = $conn->prepare(
                'UPDATE standard_event_tasks
                 SET is_archived = 0, archived_by = NULL, archived_at = NULL
                 WHERE id = ? AND is_archived = 1'
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare the standard-task restore.');
            }
            $stmt->bind_param('i', $template_id);
            $message = 'Standard task restored. It can be added to future event checklists.';
        } elseif ($action === 'delete') {
            $stmt = $conn->prepare(
                'DELETE FROM standard_event_tasks WHERE id = ? AND is_archived = 1'
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare the standard-task deletion.');
            }
            $stmt->bind_param('i', $template_id);
            $message = 'Standard task permanently deleted. Existing event tasks were not changed.';
        } else {
            throw new InvalidArgumentException('Select a valid standard-task action.');
        }
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            $stmt->close();
            throw new InvalidArgumentException(
                $action === 'delete'
                    ? 'Archive the standard task before permanently deleting it.'
                    : 'That standard task is no longer available in this view.'
            );
        }
        $stmt->close();
        $_SESSION['standard_task_action_message'] = $message;
    } catch (Throwable $exception) {
        $_SESSION['standard_task_action_error'] = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'Unable to update the standard task. Please try again.';
    }
    header('Location: standard_tasks.php?' . http_build_query(['status' => $list_status]));
    exit();
}

$action_message = $_SESSION['standard_task_action_message'] ?? '';
$action_error = $_SESSION['standard_task_action_error'] ?? '';
unset($_SESSION['standard_task_action_message'], $_SESSION['standard_task_action_error']);

try {
    $standard_tasks = fetchStandardEventTaskTemplates($conn, $list_status);
} catch (Throwable $exception) {
    http_response_code(500);
    exit('Unable to load the standard event tasks.');
}
$counts = ['active' => 0, 'archived' => 0];
$count_result = $conn->query(
    'SELECT SUM(is_archived = 0) AS active_count,
            SUM(is_archived = 1) AS archived_count
     FROM standard_event_tasks'
);
if ($count_result) {
    $count_row = $count_result->fetch_assoc() ?: [];
    $counts['active'] = (int) ($count_row['active_count'] ?? 0);
    $counts['archived'] = (int) ($count_row['archived_count'] ?? 0);
}
$priority_labels = followUpTaskPriorities();
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead('Standard Event Tasks - DNR', array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
  ),
)); ?>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="tasks.php">Work Queue</a><span aria-hidden="true">/</span><span>Standard Event Tasks</span></nav>
    <div class="page-heading">
        <div>
            <h1><?php echo $show_archived ? 'Archived Standard Event Tasks' : 'Standard Event Tasks'; ?></h1>
            <p class="page-intro">Control the reusable work assigned automatically when new events are created.</p>
        </div>
        <div class="page-heading-actions">
            <a href="tasks.php" class="button-secondary">Back to Work Queue</a>
            <?php if (canManageFollowUpTasks($user_role)): ?><a href="add_standard_task.php" class="button-add">+ New standard task</a><?php endif; ?>
        </div>
    </div>

    <?php if ($action_message !== ''): ?><p class="success"><?php echo htmlspecialchars($action_message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($action_error !== ''): ?><p class="error"><?php echo htmlspecialchars($action_error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>

    <div class="list-controls standard-task-controls">
        <div class="control-group" aria-label="Standard event task archive status">
            <a href="standard_tasks.php?status=active" class="sort-button<?php echo !$show_archived ? ' active' : ''; ?>">Active (<?php echo $counts['active']; ?>)</a>
            <a href="standard_tasks.php?status=archived" class="sort-button<?php echo $show_archived ? ' active' : ''; ?>">Archived (<?php echo $counts['archived']; ?>)</a>
        </div>
    </div>

    <p class="result-context">Every active definition is added and assigned to the creator automatically with each new event. “Add missing checklist tasks” remains available for older events. Existing event tasks remain unchanged.</p>

    <table class="task-table standard-task-table">
        <thead><tr><th>Order</th><th>Standard task</th><th>Due rule</th><th>Priority</th><th>Generated</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$standard_tasks): ?><tr><td colspan="6" class="empty-state">No <?php echo $show_archived ? 'archived' : 'active'; ?> standard event tasks.</td></tr><?php endif; ?>
        <?php foreach ($standard_tasks as $standard_task): ?>
            <tr>
                <td><?php echo (int) $standard_task['sort_order']; ?></td>
                <td>
                    <a class="record-link" href="view_standard_task.php?id=<?php echo (int) $standard_task['id']; ?>"><?php echo htmlspecialchars($standard_task['title'], ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php if (!empty($standard_task['details'])): ?><small class="task-notes-preview"><?php echo htmlspecialchars(strlen($standard_task['details']) > 160 ? substr($standard_task['details'], 0, 157) . '…' : $standard_task['details'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars(standardEventTaskScheduleLabel($standard_task['due_anchor'], $standard_task['due_offset_days']), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><span class="task-due task-priority-<?php echo htmlspecialchars($standard_task['priority'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($priority_labels[$standard_task['priority']], ENT_QUOTES, 'UTF-8'); ?></span></td>
                <td><?php echo (int) $standard_task['generated_count']; ?> event task<?php echo (int) $standard_task['generated_count'] === 1 ? '' : 's'; ?></td>
                <td>
                    <div class="task-actions">
                        <a href="view_standard_task.php?id=<?php echo (int) $standard_task['id']; ?>" class="action-button action-icon-button view-button" aria-label="View standard task" title="View" data-tooltip="View"><?php echo actionIconSvg('view'); ?></a>
                        <?php if (!$show_archived && canManageFollowUpTasks($user_role)): ?>
                            <a href="edit_standard_task.php?id=<?php echo (int) $standard_task['id']; ?>" class="action-button action-icon-button edit-button" aria-label="Edit standard task" title="Edit" data-tooltip="Edit"><?php echo actionIconSvg('edit'); ?></a>
                        <?php endif; ?>
                        <?php if (canArchiveEntries($user_role)): ?>
                            <form method="post" action="standard_tasks.php"><?php echo csrfInput(); ?><input type="hidden" name="template_id" value="<?php echo (int) $standard_task['id']; ?>"><input type="hidden" name="list_status" value="<?php echo $list_status; ?>"><input type="hidden" name="action" value="<?php echo $show_archived ? 'restore' : 'archive'; ?>"><button type="submit" class="action-button action-icon-button <?php echo $show_archived ? 'restore-button' : 'archive-button'; ?>" aria-label="<?php echo $show_archived ? 'Restore' : 'Archive'; ?> standard task" title="<?php echo $show_archived ? 'Restore' : 'Archive'; ?>" data-tooltip="<?php echo $show_archived ? 'Restore' : 'Archive'; ?>"><?php echo actionIconSvg($show_archived ? 'restore' : 'archive'); ?></button></form>
                        <?php endif; ?>
                        <?php if ($show_archived && canDeleteEntries($user_role)): ?>
                            <form method="post" action="standard_tasks.php" data-delete-confirmation="Permanently delete this standard task? Existing tasks already added to events will remain." data-archive-button-label="Keep archived"><?php echo csrfInput(); ?><input type="hidden" name="template_id" value="<?php echo (int) $standard_task['id']; ?>"><input type="hidden" name="list_status" value="archived"><input type="hidden" name="action" value="delete"><button type="submit" class="action-button action-icon-button delete-button" aria-label="Delete standard task" title="Delete" data-tooltip="Delete"><?php echo actionIconSvg('delete'); ?></button></form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
