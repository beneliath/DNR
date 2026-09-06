<?php if (!empty($record_can_add_note)): ?>
<details class="record-add-note" id="add-note"<?php echo !empty($record_note_error) ? ' open' : ''; ?>>
    <summary>Add Chron Log Entry</summary>
    <form method="post" action="<?php echo htmlspecialchars($record_note_url . '#add-note', ENT_QUOTES, 'UTF-8'); ?>" class="record-note-form">
        <?php echo csrfInput(); ?>
        <input type="hidden" name="action" value="add_note">
        <?php if (!empty($record_note_error)): ?><p class="error" role="alert"><?php echo htmlspecialchars($record_note_error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
        <label for="new-record-note">Chron Log Entry</label>
        <textarea id="new-record-note" name="new_chron_entry" rows="4" maxlength="100000" required aria-describedby="record-note-scope"><?php echo htmlspecialchars(is_string($_POST['new_chron_entry'] ?? null) ? $_POST['new_chron_entry'] : '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        <p id="record-note-scope">This Chron Log Entry will be added to this <?php echo htmlspecialchars($record_note_entity, ENT_QUOTES, 'UTF-8'); ?>’s activity.</p>
        <div class="record-note-actions"><button type="submit" class="save-button">Save Chron Log Entry</button></div>
    </form>
</details>
<?php endif; ?>
