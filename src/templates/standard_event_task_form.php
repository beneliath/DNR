<?php
$standard_task_form_values = is_array($standard_task_form_values ?? null)
    ? $standard_task_form_values
    : [];
$standard_task_form_action = (string) ($standard_task_form_action ?? 'standard_tasks.php');
$standard_task_form_cancel_url = (string) ($standard_task_form_cancel_url
    ?? ('view_standard_task.php?id=' . (int) ($standard_task_form_values['id'] ?? 0)));
$standard_task_form_submit_label = (string) ($standard_task_form_submit_label ?? 'Save changes');
$priority_labels = followUpTaskPriorities();
$due_anchor_labels = standardEventTaskDueAnchors();
$selected_priority = (string) ($standard_task_form_values['priority'] ?? 'normal');
$selected_anchor = (string) ($standard_task_form_values['due_anchor'] ?? 'event_start');
?>
<form method="post" action="<?php echo htmlspecialchars($standard_task_form_action, ENT_QUOTES, 'UTF-8'); ?>" class="standard-event-task-form">
    <?php echo csrfInput(); ?>
    <?php if (!empty($standard_task_form_values['updated_at'])): ?>
        <input type="hidden" name="task_version" value="<?php echo htmlspecialchars($standard_task_form_values['updated_at'], ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <p class="required-fields-note"><span aria-hidden="true">*</span> Required fields</p>

    <section class="form-section">
        <h2>Task</h2>
        <div class="form-group">
            <label for="standard-task-title" class="required">Title</label>
            <input type="text" id="standard-task-title" name="title" maxlength="255" required value="<?php echo htmlspecialchars($standard_task_form_values['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div class="form-group">
            <label for="standard-task-details">Notes</label>
            <textarea id="standard-task-details" name="details" rows="6" maxlength="20000"><?php echo htmlspecialchars($standard_task_form_values['details'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            <small class="field-help">These notes are copied into each event task generated from this definition.</small>
        </div>
    </section>

    <section class="form-section">
        <h2>Scheduling</h2>
        <div class="task-form-grid">
            <div class="form-group">
                <label for="standard-task-priority" class="required">Priority</label>
                <select id="standard-task-priority" name="priority" required>
                    <?php foreach ($priority_labels as $priority_value => $priority_label): ?>
                        <option value="<?php echo htmlspecialchars($priority_value, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $selected_priority === $priority_value ? ' selected' : ''; ?>><?php echo htmlspecialchars($priority_label, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="standard-task-anchor" class="required">Due date relative to</label>
                <select id="standard-task-anchor" name="due_anchor" required>
                    <?php foreach ($due_anchor_labels as $anchor_value => $anchor_label): ?>
                        <option value="<?php echo htmlspecialchars($anchor_value, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $selected_anchor === $anchor_value ? ' selected' : ''; ?>><?php echo htmlspecialchars($anchor_label, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="standard-task-offset" class="required">Day offset</label>
                <input type="number" id="standard-task-offset" name="due_offset_days" min="-3650" max="3650" step="1" required value="<?php echo htmlspecialchars($standard_task_form_values['due_offset_days'] ?? '0', ENT_QUOTES, 'UTF-8'); ?>">
                <small class="field-help">Use a negative number for days before the event date and a positive number for days after.</small>
            </div>
            <div class="form-group">
                <label for="standard-task-order" class="required">Display order</label>
                <input type="number" id="standard-task-order" name="sort_order" min="0" max="65535" step="1" required value="<?php echo htmlspecialchars($standard_task_form_values['sort_order'] ?? '0', ENT_QUOTES, 'UTF-8'); ?>">
                <small class="field-help">Lower numbers are shown and generated first.</small>
            </div>
        </div>
    </section>

    <div class="engagement-page-actions">
        <a href="<?php echo htmlspecialchars($standard_task_form_cancel_url, ENT_QUOTES, 'UTF-8'); ?>" class="cancel-button">Cancel</a>
        <button type="submit" name="save_standard_task" value="1" class="save-button"><?php echo htmlspecialchars($standard_task_form_submit_label, ENT_QUOTES, 'UTF-8'); ?></button>
    </div>
</form>
