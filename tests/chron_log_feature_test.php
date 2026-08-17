<?php

function expectChronFeature($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "Chron log feature test failed: {$message}\n");
        exit(1);
    }
}

$new_engagement = file_get_contents(__DIR__ . '/../src/index.php');
$edit_engagement = file_get_contents(__DIR__ . '/../src/edit_engagement.php');
$view_engagement = file_get_contents(__DIR__ . '/../src/view_engagement.php');
$restore_chron_entries = file_get_contents(__DIR__ . '/../src/restore_chron_entries.php');
$engagement_list = file_get_contents(__DIR__ . '/../src/engagements.php');
$chron_helpers = file_get_contents(__DIR__ . '/../src/chron_log_helpers.php');
$migration = file_get_contents(
    __DIR__ . '/../migrations/20260817_add_engagement_chron_entries.sql'
);

expectChronFeature(
    str_contains($migration, 'CREATE TABLE IF NOT EXISTS engagement_chron_entries')
        && str_contains($migration, 'created_by INT NULL')
        && str_contains($migration, 'created_by_username_snapshot VARCHAR(50) NULL')
        && str_contains($migration, 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'),
    'the database should store individual entries with creator and automatic timestamps.'
);
expectChronFeature(
    str_contains($migration, 'e.engagement_notes')
        && str_contains($migration, 'legacy_engagement_note = 1'),
    'the migration should preserve existing free-text Chron logs.'
);
expectChronFeature(
    !str_contains($new_engagement, 'name="engagement_notes"')
        && str_contains($new_engagement, 'name="chron_entry"'),
    'New Engagement should replace the legacy textarea with an initial Chron entry.'
);
expectChronFeature(
    strrpos($new_engagement, 'chron-log-section')
        > strrpos($new_engagement, '<h2>Logistics &amp; compensation</h2>'),
    'Chron should be the final section on New Engagement.'
);
expectChronFeature(
    strrpos($edit_engagement, 'chron-log-section')
        > strrpos($edit_engagement, '<h2>Logistics &amp; compensation</h2>')
        && strrpos($edit_engagement, 'engagement-page-actions')
            > strrpos($edit_engagement, 'chron-log-section')
        && str_contains($edit_engagement, 'name="save_engagement" value="1"')
        && str_contains($edit_engagement, 'form="engagement-edit-form">Save Changes</button>'),
    'Chron should be the final Edit section, followed by page-level Cancel and Save Changes actions.'
);
expectChronFeature(
    str_contains($edit_engagement, 'name="save_and_add_chron"')
        && str_contains($edit_engagement, 'form="engagement-edit-form"')
        && str_contains($edit_engagement, "isset(\$_POST['save_and_add_chron'])")
        && str_contains($edit_engagement, "if (\$new_chron_entry !== '')")
        && str_contains($edit_engagement, "header(\"Location: engagements.php\")"),
    'Add entry and Save Changes should preserve new Chron content and return to the Engagements list.'
);
expectChronFeature(
    !str_contains($edit_engagement, '>Save entry</button>')
        && !str_contains($edit_engagement, "\$chron_action === 'edit'")
        && str_contains($edit_engagement, 'name="chron_entries[')
        && str_contains($edit_engagement, 'required form="engagement-edit-form"')
        && str_contains($edit_engagement, 'FOR UPDATE')
        && str_contains($edit_engagement, 'SET entry_text = ?, updated_by = ?, updated_at = UTC_TIMESTAMP()'),
    'existing Chron text changes should be saved by the bottom Save Changes button.'
);
expectChronFeature(
    str_contains($edit_engagement, "\$user_role !== 'admin'")
        && str_contains($edit_engagement, "\$chron_action === 'delete'")
        && str_contains($edit_engagement, "\$chron_action === 'archive'"),
    'editors should archive entries while permanent deletion remains admin-only.'
);
expectChronFeature(
    str_contains($edit_engagement, 'fetchChronLogEntries($conn, $engagement_id)')
        && !str_contains($edit_engagement, 'fetchChronLogEntries($conn, $engagement_id, true)')
        && !str_contains($edit_engagement, "\$chron_action === 'restore'")
        && str_contains($edit_engagement, 'restore_chron_entries.php?engagement_id=')
        && str_contains($view_engagement, 'restore_chron_entries.php?engagement_id='),
    'archived entries should disappear from View and Edit and use the dedicated restore page.'
);
expectChronFeature(
    str_contains($restore_chron_entries, 'canArchiveEntries($user_role)')
        && str_contains($restore_chron_entries, 'name="chron_entry_ids[]"')
        && str_contains($restore_chron_entries, 'name="restore_selected"')
        && str_contains($restore_chron_entries, 'WHERE engagement_id = ?')
        && str_contains($restore_chron_entries, 'AND is_archived = 1')
        && str_contains($restore_chron_entries, 'id IN ('),
    'the restore page should let editor+ users restore one or more entries for one engagement.'
);
expectChronFeature(
    strrpos($view_engagement, 'id="chron-log"')
        > strrpos($view_engagement, '<div class="detail-label">Location</div>'),
    'Chron should be the final details section on the engagement view.'
);
expectChronFeature(
    !str_contains($view_engagement, 'name="chron_q"')
        && !str_contains($edit_engagement, 'name="chron_q"')
        && str_contains($engagement_list, 'FROM engagement_chron_entries ce')
        && str_contains($engagement_list, 'ce.entry_text LIKE ?')
        && str_contains($engagement_list, 'ce.created_by_username_snapshot) LIKE ?')
        && str_contains($engagement_list, 'ce.is_archived = 0')
        && !str_contains($engagement_list, 'o.organization_name LIKE ?')
        && !str_contains($engagement_list, 'e.event_type LIKE ?')
        && !str_contains($engagement_list, 'e.confirmation_status LIKE ?'),
    'Engagement search should be limited to titles, active Chron text, and Chron creators.'
);
expectChronFeature(
    str_contains($chron_helpers, 'ORDER BY ce.created_at DESC, ce.id DESC'),
    'Chron entries should load in reverse chronological order.'
);

echo "Chron log feature tests passed.\n";
