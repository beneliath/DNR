<?php
$task_archive_action = !empty($archive_task['is_archived']) ? 'restore' : 'archive';
$task_archive_label = $task_archive_action === 'restore' ? 'Restore task' : 'Archive task';
?>
<form method="post" action="tasks.php">
    <?php echo csrfInput(); ?>
    <input type="hidden" name="action" value="<?php echo $task_archive_action; ?>">
    <input type="hidden" name="task_id" value="<?php echo (int) $archive_task['id']; ?>">
    <input type="hidden" name="task_version" value="<?php echo htmlspecialchars($archive_task['updated_at'], ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($archive_task_return_to, ENT_QUOTES, 'UTF-8'); ?>">
    <button type="submit" class="action-button action-icon-button <?php echo $task_archive_action; ?>-button" aria-label="<?php echo $task_archive_label; ?>" title="<?php echo $task_archive_label; ?>" data-tooltip="<?php echo $task_archive_label; ?>"><?php echo actionIconSvg($task_archive_action); ?></button>
</form>
