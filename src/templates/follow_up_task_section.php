<?php
$context_task_subject_type = (string) ($context_task_subject_type ?? 'general');
requireFollowUpTaskSchema($conn);
$context_task_subject_id = isset($context_task_subject_id) ? (int) $context_task_subject_id : null;
$context_task_return_to = safeFollowUpTaskReturnUrl($context_task_return_to ?? 'tasks.php');
$context_task_subject_active = !empty($context_task_subject_active);
$context_task_allow_checklist = isset($context_task_allow_checklist)
    ? !empty($context_task_allow_checklist)
    : $context_task_subject_active;
$context_task_can_manage = canManageFollowUpTasks($_SESSION['role'] ?? '');
$context_tasks = fetchFollowUpTasksForSubject(
    $conn,
    $context_task_subject_type,
    $context_task_subject_id
);
$context_task_message = $_SESSION['task_action_message'] ?? '';
$context_task_error = $_SESSION['task_action_error'] ?? '';
unset($_SESSION['task_action_message'], $_SESSION['task_action_error']);
$context_task_list_url = 'tasks.php?' . http_build_query([
    'view' => 'all',
    'subject_type' => $context_task_subject_type,
    'subject_id' => $context_task_subject_id,
]);
$context_task_add_url = 'add_task.php?' . http_build_query([
    'subject_type' => $context_task_subject_type,
    'subject_id' => $context_task_subject_id,
    'return_to' => $context_task_return_to,
]);
$context_status_labels = followUpTaskStatuses();
?>
<section class="context-task-section" id="follow-up-work">
    <div class="context-task-heading">
        <div>
            <h2>Follow-Up Work</h2>
            <p>Open commitments connected to this record.</p>
        </div>
        <div class="context-task-heading-actions">
            <a href="<?php echo htmlspecialchars($context_task_list_url, ENT_QUOTES, 'UTF-8'); ?>" class="button-secondary">View in Work Queue</a>
            <?php if ($context_task_can_manage && $context_task_subject_active): ?>
                <a href="<?php echo htmlspecialchars($context_task_add_url, ENT_QUOTES, 'UTF-8'); ?>" class="button-add">+ Add Task</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($context_task_message !== ''): ?><p class="success"><?php echo htmlspecialchars($context_task_message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($context_task_error !== ''): ?><p class="error"><?php echo htmlspecialchars($context_task_error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>

    <?php if ($context_task_can_manage
        && $context_task_subject_active
        && $context_task_allow_checklist
        && $context_task_subject_type === 'engagement'
    ): ?>
        <form method="post" action="tasks.php" class="checklist-generation-form">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="generate_engagement_checklist">
            <input type="hidden" name="engagement_id" value="<?php echo (int) $context_task_subject_id; ?>">
            <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($context_task_return_to, ENT_QUOTES, 'UTF-8'); ?>">
            <div><strong>Standard engagement checklist</strong><span>Preparation, host reconfirmation, thank-you, outcomes, and financial closeout.</span></div>
            <button type="submit" class="button-secondary">Add Missing Checklist Tasks</button>
        </form>
    <?php endif; ?>

    <div class="context-task-list">
        <?php foreach ($context_tasks as $context_task): ?>
            <?php
            $context_due = followUpTaskDueState($context_task['due_date']);
            $context_edit_url = 'edit_task.php?' . http_build_query([
                'id' => (int) $context_task['id'],
                'return_to' => $context_task_return_to,
            ]);
            ?>
            <article class="context-task-card context-task-<?php echo htmlspecialchars($context_due['key'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="context-task-main">
                    <div class="context-task-title-row">
                        <?php if ($context_task_can_manage): ?><a href="<?php echo htmlspecialchars($context_edit_url, ENT_QUOTES, 'UTF-8'); ?>" class="record-link"><?php echo htmlspecialchars($context_task['title'], ENT_QUOTES, 'UTF-8'); ?></a><?php else: ?><strong><?php echo htmlspecialchars($context_task['title'], ENT_QUOTES, 'UTF-8'); ?></strong><?php endif; ?>
                        <span class="task-status task-status-<?php echo htmlspecialchars($context_task['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($context_status_labels[$context_task['status']], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="context-task-meta">
                        <span class="task-due task-due-<?php echo htmlspecialchars($context_due['key'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($context_due['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span><?php echo htmlspecialchars($context_task['assignee_username'] ?: 'Unassigned', ENT_QUOTES, 'UTF-8'); ?></span>
                        <span><?php echo htmlspecialchars(ucfirst($context_task['priority']), ENT_QUOTES, 'UTF-8'); ?> priority</span>
                    </div>
                    <?php if ($context_task['status'] === 'waiting' && !empty($context_task['waiting_on'])): ?><p class="task-waiting-on">Waiting on: <?php echo htmlspecialchars($context_task['waiting_on'], ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
                </div>
                <?php if ($context_task_can_manage): ?>
                <div class="context-task-actions">
                    <a href="<?php echo htmlspecialchars($context_edit_url, ENT_QUOTES, 'UTF-8'); ?>" class="action-button action-icon-button edit-button" aria-label="Edit task" title="Edit" data-tooltip="Edit"><?php echo actionIconSvg('edit'); ?></a>
                    <form method="post" action="tasks.php">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="action" value="set_status">
                        <input type="hidden" name="status" value="completed">
                        <input type="hidden" name="task_id" value="<?php echo (int) $context_task['id']; ?>">
                        <input type="hidden" name="task_version" value="<?php echo htmlspecialchars($context_task['updated_at'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($context_task_return_to, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" class="action-button action-icon-button complete-button" aria-label="Complete task" title="Complete" data-tooltip="Complete"><?php echo actionIconSvg('complete'); ?></button>
                    </form>
                </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        <?php if (!$context_tasks): ?><p class="empty-state context-task-empty">No open follow-up work for this record.</p><?php endif; ?>
    </div>
</section>
