<?php if ($can_manage_tasks): ?>
<form id="task-bulk-form" class="control-group" method="post" action="task_bulk.php" data-task-bulk>
<?php echo csrfInput(); ?><input type="hidden" name="action" value="review"><input type="hidden" name="entity" value="task"><input type="hidden" name="return_to" value="<?php echo htmlspecialchars($task_return_to,ENT_QUOTES,'UTF-8'); ?>">
<span data-task-selection-count role="status">0 tasks selected</span>
<button type="button" class="button-secondary" data-task-select-all>Select All Available</button>
<button type="button" class="button-secondary" data-task-clear hidden>Clear Selection</button>
<label>Update: <select name="operation" data-task-operation><option value="complete">Complete</option><option value="assign">Reassign</option><option value="due">Change Due Date</option></select></label>
<label data-task-owner hidden>Owner <select name="assignee"><option value="0">Unassigned</option><?php foreach ($conn->query("SELECT id,username FROM users WHERE account_status='active' ORDER BY username") as $owner): ?><option value="<?php echo (int)$owner['id']; ?>"><?php echo htmlspecialchars($owner['username'],ENT_QUOTES,'UTF-8'); ?></option><?php endforeach; ?></select></label>
<label data-task-date hidden>Due Date <input type="date" name="due_date"><small>Leave blank to clear</small></label>
<span class="task-bulk-submit-actions"><button type="submit" data-task-bulk-submit>Preview Updates</button>
<?php if (canDeleteEntries($_SESSION['role'] ?? '')): ?><button type="submit" formaction="bulk_delete.php" class="delete-button" data-task-bulk-submit>Review Deletion</button><?php endif; ?></span>
</form>
<?php endif; ?>
