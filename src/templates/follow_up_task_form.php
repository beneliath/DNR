<?php
$task_form_values = is_array($task_form_values ?? null) ? $task_form_values : [];
$task_form_action = (string) ($task_form_action ?? 'add_task.php');
$task_form_submit_label = (string) ($task_form_submit_label ?? 'Save task');
$task_users = is_array($task_users ?? null) ? $task_users : [];
$task_selected_subject = (string) ($task_selected_subject ?? 'general');
$task_selected_record = is_array($task_selected_record ?? null) ? $task_selected_record : null;
$task_return_to = (string) ($task_return_to ?? 'tasks.php');
$task_inactive_subject = is_array($task_inactive_subject ?? null) ? $task_inactive_subject : null;
$task_statuses = followUpTaskStatuses();
$task_priorities = followUpTaskPriorities();
$task_selected_status = (string) ($task_form_values['status'] ?? 'open');
$task_selected_priority = (string) ($task_form_values['priority'] ?? 'normal');
$task_selected_assignee = (string) ($task_form_values['assigned_to'] ?? '');
?>
<form method="post" action="<?php echo htmlspecialchars($task_form_action, ENT_QUOTES, 'UTF-8'); ?>" class="follow-up-task-form">
    <?php echo csrfInput(); ?>
    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($task_return_to, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if (!empty($task_form_values['updated_at'])): ?>
        <input type="hidden" name="task_version" value="<?php echo htmlspecialchars($task_form_values['updated_at'], ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <p class="required-fields-note"><span aria-hidden="true">*</span> Required fields</p>

    <section class="form-section">
        <h2>Task</h2>
        <div class="form-group">
            <label for="task-title" class="required">Title</label>
            <input type="text" id="task-title" name="title" maxlength="255" required value="<?php echo htmlspecialchars($task_form_values['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div class="form-group">
            <label for="task-details">Notes</label>
            <textarea id="task-details" name="details" rows="6" maxlength="20000"><?php echo htmlspecialchars($task_form_values['details'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        </div>
        <div class="form-group">
            <label for="task-subject" class="required">Related record</label>
            <input type="search" id="task-subject-search" autocomplete="off" placeholder="Search engagements, organizations, or contacts" data-subject-search-url="task_subject_search.php">
            <select id="task-subject" name="subject" required>
                <option value="general"<?php echo $task_selected_subject === 'general' ? ' selected' : ''; ?>><?php echo htmlspecialchars(applicationGeneralWorkLabel(), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php if ($task_selected_record && $task_selected_subject !== 'general'): ?>
                    <option value="<?php echo htmlspecialchars($task_selected_subject, ENT_QUOTES, 'UTF-8'); ?>" selected><?php echo htmlspecialchars($task_selected_record['label'], ENT_QUOTES, 'UTF-8'); ?><?php echo empty($task_selected_record['active']) ? ' · Archived' : ''; ?></option>
                <?php endif; ?>
            </select>
            <small id="task-subject-status" class="field-help" aria-live="polite">Type at least three characters to load a bounded result set. Use <?php echo htmlspecialchars(applicationGeneralWorkLabel(), ENT_QUOTES, 'UTF-8'); ?> when no record applies.</small>
        </div>
    </section>

    <section class="form-section">
        <h2>Ownership &amp; Timing</h2>
        <div class="task-form-grid">
            <div class="form-group">
                <label for="task-assignee">Assigned to</label>
                <select id="task-assignee" name="assigned_to">
                    <option value="">Unassigned</option>
                    <?php foreach ($task_users as $task_user): ?>
                        <option value="<?php echo (int) $task_user['id']; ?>"<?php echo $task_selected_assignee !== '' && (int) $task_selected_assignee === (int) $task_user['id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars($task_user['username'] . ' · ' . ucfirst($task_user['role']), ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="task-due-date">Due date</label>
                <input type="date" id="task-due-date" name="due_date" value="<?php echo htmlspecialchars($task_form_values['due_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-group">
                <label for="task-priority" class="required">Priority</label>
                <select id="task-priority" name="priority" required>
                    <?php foreach ($task_priorities as $priority_value => $priority_label): ?>
                        <option value="<?php echo htmlspecialchars($priority_value, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $task_selected_priority === $priority_value ? ' selected' : ''; ?>><?php echo htmlspecialchars($priority_label, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="task-status" class="required">Status</label>
                <select id="task-status" name="status" required>
                    <?php foreach ($task_statuses as $status_value => $status_label): ?>
                        <option value="<?php echo htmlspecialchars($status_value, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $task_selected_status === $status_value ? ' selected' : ''; ?>><?php echo htmlspecialchars($status_label, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group" id="task-waiting-on-group">
            <label for="task-waiting-on">Waiting on</label>
            <input type="text" id="task-waiting-on" name="waiting_on" maxlength="255" value="<?php echo htmlspecialchars($task_form_values['waiting_on'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Person, organization, or missing decision">
        </div>
    </section>

    <div class="engagement-page-actions">
        <a href="<?php echo htmlspecialchars($task_return_to, ENT_QUOTES, 'UTF-8'); ?>" class="cancel-button">Cancel</a>
        <button type="submit" name="save_task" value="1" class="save-button"><?php echo htmlspecialchars($task_form_submit_label, ENT_QUOTES, 'UTF-8'); ?></button>
    </div>
</form>
<?php renderScript('assets/js/task-form.min.js'); ?>
