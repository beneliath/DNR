<?php
$submitted_chron_values = is_array($_POST['chron_entries'] ?? null)
    ? $_POST['chron_entries']
    : [];
?>
<section class="form-section chron-log-section" id="chron-log">
    <div class="chron-log-heading">
        <div>
            <h2>Chron Log</h2>
            <p>Communication history for this <?php echo htmlspecialchars($chron_entity_label, ENT_QUOTES, 'UTF-8'); ?> only. Entries are shown newest first.</p>
        </div>
        <?php if ($archived_chron_count > 0): ?>
            <a href="<?php echo htmlspecialchars($chron_restore_url, ENT_QUOTES, 'UTF-8'); ?>" class="restore-button">Restore archived entries (<?php echo $archived_chron_count; ?>)</a>
        <?php endif; ?>
    </div>

    <div class="chron-add-form">
        <label for="new-chron-entry">New Chron entry</label>
        <textarea name="new_chron_entry" id="new-chron-entry" rows="5" maxlength="100000" form="<?php echo htmlspecialchars($chron_edit_form_id, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Record a call, email, meeting, or other communication."><?php echo htmlspecialchars(is_scalar($_POST['new_chron_entry'] ?? null) ? (string) $_POST['new_chron_entry'] : '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        <button type="submit" name="save_and_add_chron" value="1" class="save-button" form="<?php echo htmlspecialchars($chron_edit_form_id, ENT_QUOTES, 'UTF-8'); ?>" data-add-chron-entry>Add entry</button>
    </div>

    <div class="chron-entry-list">
        <?php foreach ($chron_entries as $chron_entry): ?>
            <?php
            $created_timestamp = chronLogTimestampDetails($chron_entry['created_at']);
            $updated_timestamp = chronLogTimestampDetails($chron_entry['updated_at']);
            $entry_author = $chron_entry['created_by_username'] ?: 'System';
            $was_edited = (string) $chron_entry['updated_at'] !== (string) $chron_entry['created_at'];
            $submitted_chron_value = $submitted_chron_values[(string) $chron_entry['id']] ?? null;
            $chron_entry_value = is_scalar($submitted_chron_value)
                ? (string) $submitted_chron_value
                : (string) $chron_entry['entry_text'];
            ?>
            <article class="chron-entry-card">
                <div class="chron-entry-meta">
                    <div>
                        <time datetime="<?php echo htmlspecialchars($created_timestamp['iso'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($created_timestamp['display'], ENT_QUOTES, 'UTF-8'); ?></time>
                        <span>by <?php echo htmlspecialchars($entry_author, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <?php if ($was_edited): ?>
                        <small>Last updated <time datetime="<?php echo htmlspecialchars($updated_timestamp['iso'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($updated_timestamp['display'], ENT_QUOTES, 'UTF-8'); ?></time><?php if (!empty($chron_entry['updated_by_username'])): ?> by <?php echo htmlspecialchars($chron_entry['updated_by_username'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></small>
                    <?php endif; ?>
                    <?php if (!empty($chron_entry['inbound_email_message_id'])): ?>
                        <small><a href="inbound_mail.php?status=all&amp;id=<?php echo (int) $chron_entry['inbound_email_message_id']; ?>">View source email</a></small>
                    <?php endif; ?>
                </div>
                <div class="chron-entry-editor">
                    <label class="visually-hidden" for="chron-entry-<?php echo (int) $chron_entry['id']; ?>">Edit Chron entry from <?php echo htmlspecialchars($created_timestamp['display'], ENT_QUOTES, 'UTF-8'); ?></label>
                    <textarea name="chron_entries[<?php echo (int) $chron_entry['id']; ?>]" id="chron-entry-<?php echo (int) $chron_entry['id']; ?>" rows="5" maxlength="100000" required form="<?php echo htmlspecialchars($chron_edit_form_id, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($chron_entry_value, ENT_QUOTES, 'UTF-8'); ?></textarea>
                    <form method="post" action="<?php echo htmlspecialchars($chron_edit_url . '#chron-log', ENT_QUOTES, 'UTF-8'); ?>" class="chron-entry-management">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="chron_entry_id" value="<?php echo (int) $chron_entry['id']; ?>">
                        <div class="chron-entry-actions">
                            <button type="submit" name="chron_action" value="archive" class="archive-button">Archive</button>
                            <?php if ($user_role === 'admin'): ?>
                                <button type="submit" name="chron_action" value="delete" class="delete-button" data-confirm="Permanently delete this Chron entry? This cannot be undone.">Delete</button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$chron_entries): ?>
            <p class="chron-empty-state">No Chron entries have been added for this <?php echo htmlspecialchars($chron_entity_label, ENT_QUOTES, 'UTF-8'); ?> yet.</p>
        <?php endif; ?>
    </div>

    <?php if ($chron_total_pages > 1): ?>
        <nav class="pagination" aria-label="Chron log pages">
            <span>Page <?php echo $chron_page; ?> of <?php echo $chron_total_pages; ?> · <?php echo $chron_entry_count; ?> entries</span>
            <div class="pagination-actions">
                <?php if ($chron_page > 1): ?><a href="<?php echo htmlspecialchars($chron_edit_url . '&chron_page=' . ($chron_page - 1) . '#chron-log', ENT_QUOTES, 'UTF-8'); ?>">Newer</a><?php endif; ?>
                <?php if ($chron_page < $chron_total_pages): ?><a href="<?php echo htmlspecialchars($chron_edit_url . '&chron_page=' . ($chron_page + 1) . '#chron-log', ENT_QUOTES, 'UTF-8'); ?>">Older</a><?php endif; ?>
            </div>
        </nav>
    <?php endif; ?>
</section>
