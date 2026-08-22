<?php
require_once __DIR__ . '/bootstrap.php';
include 'follow_up_task_helpers.php';
include 'two_factor_helpers.php';
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
$fulltext_query = fulltextSearchQuery($search);
if ($fulltext_query === '') {
    $search = '';
}
$page_size = 50;
$cursor_keys = $view === 'completed'
    ? ['updated_at', 'id']
    : ['due_date', 'priority_rank', 'id'];
$cursor = decodePaginationCursor($_GET['cursor'] ?? '', $cursor_keys);

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
$task_return_parameters = $queue_parameters;
if (is_string($_GET['cursor'] ?? null) && $cursor !== null) {
    $task_return_parameters['cursor'] = $_GET['cursor'];
}
$task_return_to = 'tasks.php?' . http_build_query($task_return_parameters);

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
            if ($inserted > 0) {
                $_SESSION['task_action_message'] = $inserted . ' checklist task'
                    . ($inserted === 1 ? '' : 's') . ' added and assigned to you.';
            } else {
                $active_standard_task_count = count(
                    fetchStandardEventTaskTemplates($conn, 'active')
                );
                $_SESSION['task_action_message'] = $active_standard_task_count > 0
                    ? 'The active standard checklist is already present for this engagement.'
                    : 'There are no active standard event tasks to add.';
            }
        } elseif ($action === 'delete') {
            if (!canDeleteEntries($user_role)) {
                http_response_code(403);
                exit('Forbidden.');
            }
            requireRecentAdminElevation($return_to);
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
if ($fulltext_query !== '') {
    $where[] = "(
        MATCH(t.title, t.details, t.waiting_on) AGAINST (? IN BOOLEAN MODE)
        OR assignee.username LIKE ?
        OR MATCH(e.event_title, e.event_description, e.engagement_notes, e.caller_name)
            AGAINST (? IN BOOLEAN MODE)
        OR MATCH(
            eo.organization_name, eo.notes, eo.affiliation, eo.distinctives,
            eo.email, eo.phone, eo.physical_city, eo.physical_state,
            eo.mailing_city, eo.mailing_state
        ) AGAINST (? IN BOOLEAN MODE)
        OR MATCH(
            o.organization_name, o.notes, o.affiliation, o.distinctives,
            o.email, o.phone, o.physical_city, o.physical_state,
            o.mailing_city, o.mailing_state
        ) AGAINST (? IN BOOLEAN MODE)
        OR MATCH(
            c.contact_first_name, c.contact_last_name, c.contact_email,
            c.contact_phone, c.contact_role_other, c.contact_notes
        ) AGAINST (? IN BOOLEAN MODE)
    )";
    $bind_types .= 'ssssss';
    array_push(
        $bind_values,
        $fulltext_query,
        $search . '%',
        $fulltext_query,
        $fulltext_query,
        $fulltext_query,
        $fulltext_query
    );
}

$where_sql = implode(' AND ', array_map(
    static fn($clause) => '(' . $clause . ')',
    $where
));
$order_sql = $view === 'completed'
    ? 't.updated_at DESC, t.id DESC'
    : "COALESCE(t.due_date, '9999-12-31') ASC,
       FIELD(t.priority, 'urgent', 'high', 'normal', 'low'),
       t.id ASC";
$cursor_sql = '';
if ($cursor !== null && ctype_digit((string) $cursor['id'])) {
    if ($view === 'completed') {
        $cursor_sql = ' AND (t.updated_at, t.id) < (?, ?)';
        $bind_types .= 'si';
        $bind_values[] = (string) $cursor['updated_at'];
        $bind_values[] = (int) $cursor['id'];
    } elseif (ctype_digit((string) $cursor['priority_rank'])) {
        $cursor_sql = " AND (
            COALESCE(t.due_date, '9999-12-31'),
            FIELD(t.priority, 'urgent', 'high', 'normal', 'low'), t.id
        ) > (?, ?, ?)";
        $bind_types .= 'sii';
        $bind_values[] = (string) $cursor['due_date'];
        $bind_values[] = (int) $cursor['priority_rank'];
        $bind_values[] = (int) $cursor['id'];
    } else {
        $cursor = null;
    }
} else {
    $cursor = null;
}
$query_limit = $page_size + 1;
$task_sql = followUpTaskSelectSql()
    . " WHERE {$where_sql}{$cursor_sql}
        ORDER BY {$order_sql}
        LIMIT {$query_limit}";
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
$has_more_tasks = count($tasks) > $page_size;
if ($has_more_tasks) array_pop($tasks);
$next_cursor = null;
if ($has_more_tasks && $tasks !== []) {
    $last_task = $tasks[array_key_last($tasks)];
    $next_cursor = $view === 'completed'
        ? encodePaginationCursor([
            'updated_at' => (string) $last_task['updated_at'],
            'id' => (int) $last_task['id'],
        ])
        : encodePaginationCursor([
            'due_date' => (string) ($last_task['due_date'] ?: '9999-12-31'),
            'priority_rank' => array_search(
                (string) $last_task['priority'],
                ['urgent', 'high', 'normal', 'low'],
                true
            ) + 1,
            'id' => (int) $last_task['id'],
        ]);
}

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
<?php renderPageHead('Work Queue - DNR', array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
  ),
)); ?>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <div class="page-heading">
        <div>
            <h1>Work Queue</h1>
            <p class="page-intro">Make the next action, owner, and due date visible.</p>
        </div>
        <div class="page-heading-actions">
            <a href="standard_tasks.php" class="button-secondary">Standard event tasks</a>
            <?php if ($can_manage_tasks): ?>
                <a href="<?php echo htmlspecialchars($new_task_url, ENT_QUOTES, 'UTF-8'); ?>" class="button-add">+ New task</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($action_message !== ''): ?><p class="success"><?php echo htmlspecialchars($action_message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($action_error !== ''): ?><p class="error"><?php echo htmlspecialchars($action_error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>

    <div class="summary-grid task-summary-grid" aria-label="Work queue summary">
        <a class="summary-card<?php echo $view === 'my' ? ' is-selected' : ''; ?>" href="<?php echo htmlspecialchars($queue_url(['view' => 'my']), ENT_QUOTES, 'UTF-8'); ?>"><span><small>My work</small><strong><?php echo $summary['my']; ?></strong></span></a>
        <a class="summary-card summary-danger<?php echo $view === 'overdue' ? ' is-selected' : ''; ?>" href="<?php echo htmlspecialchars($queue_url(['view' => 'overdue']), ENT_QUOTES, 'UTF-8'); ?>"><span><small>Overdue</small><strong><?php echo $summary['overdue']; ?></strong></span></a>
        <a class="summary-card summary-review<?php echo $view === 'today' ? ' is-selected' : ''; ?>" href="<?php echo htmlspecialchars($queue_url(['view' => 'today']), ENT_QUOTES, 'UTF-8'); ?>"><span><small>Due today</small><strong><?php echo $summary['today']; ?></strong></span></a>
        <a class="summary-card summary-confirmed<?php echo $view === 'upcoming' ? ' is-selected' : ''; ?>" href="<?php echo htmlspecialchars($queue_url(['view' => 'upcoming']), ENT_QUOTES, 'UTF-8'); ?>"><span><small>Next 7 days</small><strong><?php echo $summary['upcoming']; ?></strong></span></a>
        <a class="summary-card<?php echo $view === 'waiting' ? ' is-selected' : ''; ?>" href="<?php echo htmlspecialchars($queue_url(['view' => 'waiting']), ENT_QUOTES, 'UTF-8'); ?>"><span><small>Waiting</small><strong><?php echo $summary['waiting']; ?></strong></span></a>
        <a class="summary-card<?php echo $view === 'unassigned' ? ' is-selected' : ''; ?>" href="<?php echo htmlspecialchars($queue_url(['view' => 'unassigned']), ENT_QUOTES, 'UTF-8'); ?>"><span><small>Unassigned</small><strong><?php echo $summary['unassigned']; ?></strong></span></a>
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
            <?php if ($search !== ''): ?><a href="<?php echo htmlspecialchars($queue_url(['q' => '']), ENT_QUOTES, 'UTF-8'); ?>" class="clear-search">Clear</a><?php endif; ?>
        </form>
        <div class="control-group" aria-label="Work queue view">
            <?php foreach ($view_labels as $view_value => $view_label): ?>
                <?php if (in_array($view_value, ['my', 'overdue', 'today', 'upcoming', 'waiting', 'unassigned'], true)) continue; ?>
                <a href="<?php echo htmlspecialchars($queue_url(['view' => $view_value]), ENT_QUOTES, 'UTF-8'); ?>" class="sort-button<?php echo $view === $view_value ? ' active' : ''; ?>"><?php echo htmlspecialchars($view_label, ENT_QUOTES, 'UTF-8'); ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($subject_filter_record): ?>
        <p class="result-context">Showing tasks for <a href="<?php echo htmlspecialchars($subject_filter_record['url'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($subject_filter_record['label'], ENT_QUOTES, 'UTF-8'); ?></a>. <a href="tasks.php?view=<?php echo urlencode($view); ?>">Clear record filter</a></p>
    <?php endif; ?>
    <div class="task-view-heading">
        <h2><?php echo htmlspecialchars($view_labels[$view], ENT_QUOTES, 'UTF-8'); ?></h2>
        <div class="task-view-meta">
            <div class="task-priority-legend" aria-label="Priority color legend">
                <span class="task-priority-legend-title">Priority:</span>
                <?php foreach (['urgent', 'high', 'normal', 'low'] as $priority_value): ?>
                    <span class="task-priority-legend-item task-priority-<?php echo $priority_value; ?>"><?php echo htmlspecialchars($priority_labels[$priority_value], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endforeach; ?>
            </div>
            <span class="task-count">Showing <?php echo count($tasks); ?> task<?php echo count($tasks) === 1 ? '' : 's'; ?></span>
        </div>
    </div>

    <table class="task-table">
        <thead><tr><th>Due</th><th>Task</th><th>Related record</th><th>Owner</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$tasks): ?><tr><td colspan="6" class="empty-state">No tasks match this work queue.</td></tr><?php endif; ?>
        <?php foreach ($tasks as $task): ?>
            <?php
            $due = followUpTaskDueState($task['due_date']);
            $subject = followUpTaskSubjectFromRow($task);
            $task_priority_label = $priority_labels[$task['priority']] . ' Priority';
            $task_edit_url = 'edit_task.php?' . http_build_query([
                'id' => (int) $task['id'],
                'return_to' => $task_return_to,
            ]);
            ?>
            <tr class="task-row task-row-<?php echo htmlspecialchars($due['key'], ENT_QUOTES, 'UTF-8'); ?>">
                <td><?php if (!empty($task['due_date'])): ?><time class="task-due task-priority-<?php echo htmlspecialchars($task['priority'], ENT_QUOTES, 'UTF-8'); ?>" datetime="<?php echo htmlspecialchars($task['due_date'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="Due <?php echo htmlspecialchars($task['due_date'], ENT_QUOTES, 'UTF-8'); ?>; <?php echo htmlspecialchars($task_priority_label, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($task['due_date'], ENT_QUOTES, 'UTF-8'); ?></time><?php else: ?><span class="task-due task-priority-<?php echo htmlspecialchars($task['priority'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="No due date; <?php echo htmlspecialchars($task_priority_label, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($due['label'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?></td>
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
                            <form method="post" action="tasks.php" data-confirm="Permanently delete this task?"><?php echo csrfInput(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="task_id" value="<?php echo (int) $task['id']; ?>"><input type="hidden" name="return_to" value="<?php echo htmlspecialchars($task_return_to, ENT_QUOTES, 'UTF-8'); ?>"><button type="submit" class="action-button action-icon-button delete-button" aria-label="Delete task" title="Delete" data-tooltip="Delete"><?php echo actionIconSvg('delete'); ?></button></form>
                        <?php endif; ?>
                    </div>
                    <?php else: ?><span class="task-read-only-label">Read only</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($cursor !== null || $next_cursor !== null): ?>
    <nav class="pagination" aria-label="Work queue pages">
        <?php if ($cursor !== null): ?><a href="<?php echo htmlspecialchars($queue_url(), ENT_QUOTES, 'UTF-8'); ?>">First page</a><?php endif; ?>
        <span>Showing up to <?php echo $page_size; ?> tasks</span>
        <?php if ($next_cursor !== null): ?><a href="<?php echo htmlspecialchars($queue_url(['cursor' => $next_cursor]), ENT_QUOTES, 'UTF-8'); ?>">Next</a><?php endif; ?>
    </nav>
    <?php endif; ?>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
