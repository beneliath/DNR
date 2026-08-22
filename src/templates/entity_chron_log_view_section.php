<section class="detail-group chron-log-section" id="chron-log">
    <div class="chron-log-heading">
        <div>
            <h2>Chron Log</h2>
            <p>Communication history for this <?php echo htmlspecialchars($chron_entity_label, ENT_QUOTES, 'UTF-8'); ?> only. Entries are shown newest first.</p>
        </div>
        <?php if ($archived_chron_count > 0 && canArchiveEntries($user_role) && ($chron_can_restore ?? true)): ?>
            <a href="<?php echo htmlspecialchars($chron_restore_url, ENT_QUOTES, 'UTF-8'); ?>" class="restore-button">Restore archived entries (<?php echo $archived_chron_count; ?>)</a>
        <?php endif; ?>
    </div>

    <div class="chron-entry-list">
        <?php foreach ($chron_entries as $chron_entry): ?>
            <?php
            $created_timestamp = chronLogTimestampDetails($chron_entry['created_at']);
            $updated_timestamp = chronLogTimestampDetails($chron_entry['updated_at']);
            $entry_author = $chron_entry['created_by_username'] ?: 'System';
            $was_edited = (string) $chron_entry['updated_at'] !== (string) $chron_entry['created_at'];
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
                </div>
                <div class="chron-entry-text"><?php echo nl2br(htmlspecialchars($chron_entry['entry_text'], ENT_QUOTES, 'UTF-8')); ?></div>
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
                <?php if ($chron_page > 1): ?><a href="<?php echo htmlspecialchars($chron_view_url . '&chron_page=' . ($chron_page - 1) . '#chron-log', ENT_QUOTES, 'UTF-8'); ?>">Newer</a><?php endif; ?>
                <?php if ($chron_page < $chron_total_pages): ?><a href="<?php echo htmlspecialchars($chron_view_url . '&chron_page=' . ($chron_page + 1) . '#chron-log', ENT_QUOTES, 'UTF-8'); ?>">Older</a><?php endif; ?>
            </div>
        </nav>
    <?php endif; ?>
</section>
