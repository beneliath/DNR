<?php
include 'config.php';
include 'functions.php';
include 'follow_up_task_helpers.php';
startSecureSession();
requireLogin();
requireFollowUpTaskSchema($conn);

$user_role = $_SESSION['role'] ?? '';
$current_user_id = (int) $_SESSION['user_id'];
$can_manage_tasks = canManageFollowUpTasks($user_role);

$requested_view = (string) ($_GET['view'] ?? '');
$subject_filter_type = (string) ($_GET['subject_type'] ?? '');
$subject_filter_id = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT);
$has_subject_filter = in_array(
    $subject_filter_type,
    ['engagement', 'organization', 'contact'],
    true
) && $subject_filter_id;
$view = array_key_exists($requested_view, followUpTaskQueueViews())
    ? $requested_view
    : ($has_subject_filter ? 'all' : 'my');
$search = trim((string) ($_GET['q'] ?? ''));
if (strlen($search) > 100) {
    $search = substr($search, 0, 100);
}
$current_page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$current_page = max(1, $current_page);
$page_size = 50;

$queue_parameters = [
    'view' => $view,
];
if ($search !== '') {
    $queue_parameters['q'] = $search;
}
if ($has_subject_filter) {
    $queue_parameters['subject_type'] = $subject_filter_type;
    $queue_parameters['subject_id'] = (int) $subject_filter_id;
}
$task_return_to = 'tasks.php?' . http_build_query(array_merge(
    $queue_parameters,
    ['page' => $current_page]
));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    if (!$can_manage_tasks) {
        http_response_code(403);
        exit('Forbidden.');
    }

    $return_to = safeFollowUpTaskReturnUrl($_POST['return_to'] ?? 'tasks.php');
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'set_status') {
            $task_id = filter_input(INPUT_POST, 'task_id', FILTER_VALIDATE_INT);
            setFollowUpTaskStatus(
                $conn,
                $task_id,
                $_POST['status'] ?? '',
                $_POST['task_version'] ?? '',
                $current_user_id
            );
            $_SESSION['task_action_message'] = 'Task status updated.';
        } elseif ($action === 'assign_to_me') {
            $task_id = filter_input(INPUT_POST, 'task_id', FILTER_VALIDATE_INT);
            assignFollowUpTaskToUser(
                $conn,
                $task_id,
                $current_user_id,
                $_POST['task_version'] ?? ''
            );
            $_SESSION['task_action_message'] = 'Task assigned to you.';
        } elseif ($action === 'generate_engagement_checklist') {
            $engagement_id = filter_input(INPUT_POST, 'engagement_id', FILTER_VALIDATE_INT);
            $inserted = generateEngagementFollowUpChecklist(
                $conn,
                $engagement_id,
                $current_user_id,
                $current_user_id
            );
            $_SESSION['task_action_message'] = $inserted > 0
                ? $inserted . ' checklist task' . ($inserted === 1 ? '' : 's') . ' added and assigned to you.'
                : 'The standard checklist is already present for this engagement.';
        } elseif ($action === 'delete') {
            if (!canDeleteEntries($user_role)) {
                http_response_code(403);
                exit('Forbidden.');
            }
            $task_id = filter_input(INPUT_POST, 'task_id', FILTER_VALIDATE_INT);
            if (!$task_id) {
                throw new InvalidArgumentException('Select a valid task.');
            }
            $stmt = $conn->prepare('DELETE FROM follow_up_tasks WHERE id = ?');
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare the task deletion.');
            }
            $stmt->bind_param('i', $task_id);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException('Unable to delete the task.');
            }
            $stmt->close();
            $_SESSION['task_action_message'] = 'Task permanently deleted.';
        } else {
            throw new InvalidArgumentException('Select a valid task action.');
        }
    } catch (Throwable $exception) {
        $_SESSION['task_action_error'] = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'Unable to update the task. Please try again.';
    }
    header('Location: ' . $return_to);
    exit();
}

$action_message = $_SESSION['task_action_message'] ?? '';
$action_error = $_SESSION['task_action_error'] ?? '';
unset($_SESSION['task_action_message'], $_SESSION['task_action_error']);

$summary_stmt = $conn->prepare(
    "SELECT
        SUM(status IN ('open', 'in_progress', 'waiting') AND assigned_to = ?) AS my_count,
        SUM(status IN ('open', 'in_progress', 'waiting') AND due_date < CURDATE()) AS overdue_count,
        SUM(status IN ('open', 'in_progress', 'waiting') AND due_date = CURDATE()) AS today_count,
        SUM(status IN ('open', 'in_progress', 'waiting')
            AND due_date > CURDATE()
            AND due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)) AS upcoming_count,
        SUM(status = 'waiting') AS waiting_count,
        SUM(status IN ('open', 'in_progress', 'waiting') AND assigned_to IS NULL) AS unassigned_count
     FROM follow_up_tasks"
);
$summary = [
    'my' => 0,
    'overdue' => 0,
    'today' => 0,
    'upcoming' => 0,
    'waiting' => 0,
    'unassigned' => 0,
];
if ($summary_stmt) {
    $summary_stmt->bind_param('i', $current_user_id);
    $summary_stmt->execute();
    $summary_row = $summary_stmt->get_result()->fetch_assoc() ?: [];
    $summary_stmt->close();
    foreach (array_keys($summary) as $summary_key) {
        $summary[$summary_key] = (int) ($summary_row[$summary_key . '_count'] ?? 0);
    }
}

$where = [];
$bind_types = '';
$bind_values = [];
$active_status_sql = "t.status IN ('open', 'in_progress', 'waiting')";
if ($view === 'my') {
    $where[] = $active_status_sql . ' AND t.assigned_to = ?';
    $bind_types .= 'i';
    $bind_values[] = $current_user_id;
} elseif ($view === 'overdue') {
    $where[] = $active_status_sql . ' AND t.due_date < CURDATE()';
} elseif ($view === 'today') {
    $where[] = $active_status_sql . ' AND t.due_date = CURDATE()';
} elseif ($view === 'upcoming') {
    $where[] = $active_status_sql
        . ' AND t.due_date > CURDATE() AND t.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)';
} elseif ($view === 'waiting') {
    $where[] = "t.status = 'waiting'";
} elseif ($view === 'unassigned') {
    $where[] = $active_status_sql . ' AND t.assigned_to IS NULL';
} elseif ($view === 'completed') {
    $where[] = "t.status IN ('completed', 'canceled')";
} else {
    $where[] = $active_status_sql;
}

if ($has_subject_filter) {
    $where[] = 't.' . $subject_filter_type . '_id = ?';
    $bind_types .= 'i';
    $bind_values[] = (int) $subject_filter_id;
}
if ($search !== '') {
    $where[] = "(
        t.title LIKE ?
        OR COALESCE(t.details, '') LIKE ?
        OR COALESCE(t.waiting_on, '') LIKE ?
        OR COALESCE(assignee.username, '') LIKE ?
        OR COALESCE(NULLIF(TRIM(e.event_title), ''), eo.organization_name, '') LIKE ?
        OR COALESCE(o.organization_name, '') LIKE ?
        OR CONCAT_WS(' ', c.contact_first_name, c.contact_last_name) LIKE ?
    )";
    $search_pattern = '%' . $search . '%';
    for ($index = 0; $index < 7; $index++) {
        $bind_types .= 's';
        $bind_values[] = $search_pattern;
    }
}

$where_sql = implode(' AND ', array_map(
    static fn($clause) => '(' . $clause . ')',
    $where
));
$count_sql = "SELECT COUNT(*) AS total
              FROM follow_up_tasks t
              LEFT JOIN users assignee ON assignee.id = t.assigned_to
              LEFT JOIN engagements e ON e.id = t.engagement_id
              LEFT JOIN organizations eo ON eo.id = e.organization_id
              LEFT JOIN organizations o ON o.id = t.organization_id
              LEFT JOIN contacts c ON c.id = t.contact_id
              WHERE {$where_sql}";
$count_stmt = $conn->prepare($count_sql);
if (!$count_stmt) {
    http_response_code(500);
    exit('Unable to load the work queue.');
}
if ($bind_types !== '') {
    $count_bind = [$bind_types];
    foreach ($bind_values as &$bind_value) {
        $count_bind[] = &$bind_value;
    }
    unset($bind_value);
    $count_stmt->bind_param(...$count_bind);
}
$count_stmt->execute();
$total_tasks = (int) ($count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
$count_stmt->close();
$total_pages = max(1, (int) ceil($total_tasks / $page_size));
$current_page = min($current_page, $total_pages);
$offset = ($current_page - 1) * $page_size;

$order_sql = $view === 'completed'
    ? 't.updated_at DESC, t.id DESC'
    : "t.due_date IS NULL,
       t.due_date ASC,
       FIELD(t.priority, 'urgent', 'high', 'normal', 'low'),
       t.id ASC";
$task_sql = followUpTaskSelectSql()
    . " WHERE {$where_sql}
        ORDER BY {$order_sql}
        LIMIT {$page_size} OFFSET {$offset}";
$task_stmt = $conn->prepare($task_sql);
if (!$task_stmt) {
    http_response_code(500);
    exit('Unable to load the work queue.');
}
if ($bind_types !== '') {
    $task_bind = [$bind_types];
    foreach ($bind_values as &$bind_value) {
        $task_bind[] = &$bind_value;
    }
    unset($bind_value);
    $task_stmt->bind_param(...$task_bind);
}
$task_stmt->execute();
$tasks = $task_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$task_stmt->close();

$queue_url = static function (array $overrides = []) use ($queue_parameters) {
    return 'tasks.php?' . http_build_query(array_merge($queue_parameters, $overrides));
};
$subject_filter_record = $has_subject_filter
    ? followUpTaskSubjectRecord($conn, $subject_filter_type, $subject_filter_id)
    : null;
$new_task_parameters = [
    'return_to' => $task_return_to,
];
if ($has_subject_filter) {
    $new_task_parameters['subject_type'] = $subject_filter_type;
    $new_task_parameters['subject_id'] = (int) $subject_filter_id;
}
$new_task_url = 'add_task.php?' . http_build_query($new_task_parameters);
$view_labels = followUpTaskQueueViews();
$status_labels = followUpTaskStatuses();
$priority_labels = followUpTaskPriorities();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Work Queue - DNR</title>
    <link rel="stylesheet" href="assets/css/style.min.css?v=0.0.20">
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <div class="page-heading">
        <div>
            <h1>Work Queue</h1>
            <p class="page-intro">Make the next action, owner, and due date visible.</p>
        </div>
        <?php if ($can_manage_tasks): ?>
            <a href="<?php echo htmlspecialchars($new_task_url, ENT_QUOTES, 'UTF-8'); ?>" class="button-add">+ New task</a>
        <?php endif; ?>
    </div>

    <?php if ($action_message !== ''): ?><p class="success"><?php echo htmlspecialchars($action_message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($action_error !== ''): ?><p class="error"><?php echo htmlspecialchars($action_error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>

    <div class="summary-grid task-summary-grid" aria-label="Work queue summary">
        <a class="summary-card<?php echo $view === 'my' ? ' is-selected' : ''; ?>" href="<?php echo htmlspecialchars($queue_url(['view' => 'my', 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>"><span><small>My work</small><strong><?php echo $summary['my']; ?></strong></span></a>
        <a class="summary-card summary-danger<?php echo $view === 'overdue' ? ' is-selected' : ''; ?>" href="<?php echo htmlspecialchars($queue_url(['view' => 'overdue', 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>"><span><small>Overdue</small><strong><?php echo $summary['overdue']; ?></strong></span></a>
        <a class="summary-card summary-review<?php echo $view === 'today' ? ' is-selected' : ''; ?>" href="<?php echo htmlspecialchars($queue_url(['view' => 'today', 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>"><span><small>Due today</small><strong><?php echo $summary['today']; ?></strong></span></a>
        <a class="summary-card summary-confirmed<?php echo $view === 'upcoming' ? ' is-selected' : ''; ?>" href="<?php echo htmlspecialchars($queue_url(['view' => 'upcoming', 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>"><span><small>Next 7 days</small><strong><?php echo $summary['upcoming']; ?></strong></span></a>
        <a class="summary-card<?php echo $view === 'waiting' ? ' is-selected' : ''; ?>" href="<?php echo htmlspecialchars($queue_url(['view' => 'waiting', 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>"><span><small>Waiting</small><strong><?php echo $summary['waiting']; ?></strong></span></a>
        <a class="summary-card<?php echo $view === 'unassigned' ? ' is-selected' : ''; ?>" href="<?php echo htmlspecialchars($queue_url(['view' => 'unassigned', 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>"><span><small>Unassigned</small><strong><?php echo $summary['unassigned']; ?></strong></span></a>
    </div>

    <div class="list-controls task-list-controls">
        <form method="get" action="tasks.php" class="list-search-form" role="search">
            <input type="hidden" name="view" value="<?php echo htmlspecialchars($view, ENT_QUOTES, 'UTF-8'); ?>">
            <?php if ($has_subject_filter): ?>
                <input type="hidden" name="subject_type" value="<?php echo htmlspecialchars($subject_filter_type, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="subject_id" value="<?php echo (int) $subject_filter_id; ?>">
            <?php endif; ?>
            <label class="visually-hidden" for="task-search">Search tasks</label>
            <span class="search-icon" aria-hidden="true">⌕</span>
            <input type="search" id="task-search" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search tasks, records, and assignees">
            <?php if ($search !== ''): ?><a href="<?php echo htmlspecialchars($queue_url(['q' => '', 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>" class="clear-search">Clear</a><?php endif; ?>
        </form>
        <div class="control-group" aria-label="Work queue view">
            <?php foreach ($view_labels as $view_value => $view_label): ?>
                <?php if (in_array($view_value, ['my', 'overdue', 'today', 'upcoming', 'waiting', 'unassigned'], true)) continue; ?>
                <a href="<?php echo htmlspecialchars($queue_url(['view' => $view_value, 'page' => 1]), ENT_QUOTES, 'UTF-8'); ?>" class="sort-button<?php echo $view === $view_value ? ' active' : ''; ?>"><?php echo htmlspecialchars($view_label, ENT_QUOTES, 'UTF-8'); ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($subject_filter_record): ?>
        <p class="result-context">Showing tasks for <a href="<?php echo htmlspecialchars($subject_filter_record['url'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($subject_filter_record['label'], ENT_QUOTES, 'UTF-8'); ?></a>. <a href="tasks.php?view=<?php echo urlencode($view); ?>">Clear record filter</a></p>
    <?php endif; ?>
    <div class="task-view-heading">
        <h2><?php echo htmlspecialchars($view_labels[$view], ENT_QUOTES, 'UTF-8'); ?></h2>
        <span><?php echo $total_tasks; ?> task<?php echo $total_tasks === 1 ? '' : 's'; ?></span>
    </div>

    <table class="task-table">
        <thead><tr><th>Due</th><th>Task</th><th>Related record</th><th>Owner</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$tasks): ?><tr><td colspan="6" class="empty-state">No tasks match this work queue.</td></tr><?php endif; ?>
        <?php foreach ($tasks as $task): ?>
            <?php
            $due = followUpTaskDueState($task['due_date']);
            $subject = followUpTaskSubjectFromRow($task);
            $task_edit_url = 'edit_task.php?' . http_build_query([
                'id' => (int) $task['id'],
                'return_to' => $task_return_to,
            ]);
            ?>
            <tr class="task-row task-row-<?php echo htmlspecialchars($due['key'], ENT_QUOTES, 'UTF-8'); ?>">
                <td><?php if (!empty($task['due_date'])): ?><time class="task-due task-due-<?php echo htmlspecialchars($due['key'], ENT_QUOTES, 'UTF-8'); ?>" datetime="<?php echo htmlspecialchars($task['due_date'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($task['due_date'], ENT_QUOTES, 'UTF-8'); ?></time><?php else: ?><span class="task-due task-due-none"><?php echo htmlspecialchars($due['label'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?><small class="task-priority task-priority-<?php echo htmlspecialchars($task['priority'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($priority_labels[$task['priority']], ENT_QUOTES, 'UTF-8'); ?> priority</small></td>
                <td>
                    <?php if ($can_manage_tasks): ?><a class="record-link" href="<?php echo htmlspecialchars($task_edit_url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8'); ?></a><?php else: ?><strong><?php echo htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8'); ?></strong><?php endif; ?>
                    <?php if (!empty($task['details'])): ?><small class="task-notes-preview"><?php echo htmlspecialchars(strlen($task['details']) > 160 ? substr($task['details'], 0, 157) . '…' : $task['details'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                    <?php if ($task['status'] === 'waiting' && !empty($task['waiting_on'])): ?><small class="task-waiting-on">Waiting on: <?php echo htmlspecialchars($task['waiting_on'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                </td>
                <td><a href="<?php echo htmlspecialchars($subject['url'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($subject['label'], ENT_QUOTES, 'UTF-8'); ?></a><small><?php echo htmlspecialchars(ucfirst($subject['type']), ENT_QUOTES, 'UTF-8'); ?></small></td>
                <td><?php echo htmlspecialchars($task['assignee_username'] ?: 'Unassigned', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><span class="task-status task-status-<?php echo htmlspecialchars($task['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($status_labels[$task['status']], ENT_QUOTES, 'UTF-8'); ?></span></td>
                <td>
                    <?php if ($can_manage_tasks): ?>
                    <div class="task-actions">
                        <a href="<?php echo htmlspecialchars($task_edit_url, ENT_QUOTES, 'UTF-8'); ?>" class="action-button action-icon-button edit-button" aria-label="Edit task" title="Edit" data-tooltip="Edit"><?php echo actionIconSvg('edit'); ?></a>
                        <?php if ($task['assigned_to'] === null): ?>
                            <form method="post" action="tasks.php"><?php echo csrfInput(); ?><input type="hidden" name="action" value="assign_to_me"><input type="hidden" name="task_id" value="<?php echo (int) $task['id']; ?>"><input type="hidden" name="task_version" value="<?php echo htmlspecialchars($task['updated_at'], ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="return_to" value="<?php echo htmlspecialchars($task_return_to, ENT_QUOTES, 'UTF-8'); ?>"><button type="submit" class="task-action-button">Assign to me</button></form>
                        <?php endif; ?>
                        <?php if (in_array($task['status'], ['completed', 'canceled'], true)): ?>
                            <form method="post" action="tasks.php"><?php echo csrfInput(); ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="open"><input type="hidden" name="task_id" value="<?php echo (int) $task['id']; ?>"><input type="hidden" name="task_version" value="<?php echo htmlspecialchars($task['updated_at'], ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="return_to" value="<?php echo htmlspecialchars($task_return_to, ENT_QUOTES, 'UTF-8'); ?>"><button type="submit" class="action-button action-icon-button restore-button" aria-label="Reopen task" title="Reopen" data-tooltip="Reopen"><?php echo actionIconSvg('restore'); ?></button></form>
                        <?php else: ?>
                            <?php if ($task['status'] !== 'in_progress'): ?><form method="post" action="tasks.php"><?php echo csrfInput(); ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="in_progress"><input type="hidden" name="task_id" value="<?php echo (int) $task['id']; ?>"><input type="hidden" name="task_version" value="<?php echo htmlspecialchars($task['updated_at'], ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="return_to" value="<?php echo htmlspecialchars($task_return_to, ENT_QUOTES, 'UTF-8'); ?>"><button type="submit" class="action-button action-icon-button start-button" aria-label="Start task" title="Start" data-tooltip="Start"><?php echo actionIconSvg('start'); ?></button></form><?php endif; ?>
                            <form method="post" action="tasks.php"><?php echo csrfInput(); ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="status" value="completed"><input type="hidden" name="task_id" value="<?php echo (int) $task['id']; ?>"><input type="hidden" name="task_version" value="<?php echo htmlspecialchars($task['updated_at'], ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="return_to" value="<?php echo htmlspecialchars($task_return_to, ENT_QUOTES, 'UTF-8'); ?>"><button type="submit" class="action-button action-icon-button complete-button" aria-label="Complete task" title="Complete" data-tooltip="Complete"><?php echo actionIconSvg('complete'); ?></button></form>
                        <?php endif; ?>
                        <?php if (canDeleteEntries($user_role)): ?>
                            <form method="post" action="tasks.php" onsubmit="return confirm('Permanently delete this task?');"><?php echo csrfInput(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="task_id" value="<?php echo (int) $task['id']; ?>"><input type="hidden" name="return_to" value="<?php echo htmlspecialchars($task_return_to, ENT_QUOTES, 'UTF-8'); ?>"><button type="submit" class="action-button action-icon-button delete-button" aria-label="Delete task" title="Delete" data-tooltip="Delete"><?php echo actionIconSvg('delete'); ?></button></form>
                        <?php endif; ?>
                    </div>
                    <?php else: ?><span class="task-read-only-label">Read only</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($total_pages > 1): ?>
    <nav class="pagination" aria-label="Work queue pages">
        <?php if ($current_page > 1): ?><a href="<?php echo htmlspecialchars($queue_url(['page' => $current_page - 1]), ENT_QUOTES, 'UTF-8'); ?>">Previous</a><?php endif; ?>
        <span>Page <?php echo $current_page; ?> of <?php echo $total_pages; ?></span>
        <?php if ($current_page < $total_pages): ?><a href="<?php echo htmlspecialchars($queue_url(['page' => $current_page + 1]), ENT_QUOTES, 'UTF-8'); ?>">Next</a><?php endif; ?>
    </nav>
    <?php endif; ?>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
