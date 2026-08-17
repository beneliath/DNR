<?php
include 'config.php';
include 'functions.php';
include 'chron_log_helpers.php';
startSecureSession();
requireLogin();

$user_role = (string) ($_SESSION['role'] ?? '');
if (!canArchiveEntries($user_role)) {
    http_response_code(403);
    exit('Forbidden.');
}

$engagement_id = filter_input(INPUT_GET, 'engagement_id', FILTER_VALIDATE_INT);
if (!$engagement_id) {
    header('Location: engagements.php');
    exit();
}

$engagement_stmt = $conn->prepare(
    'SELECT e.id, e.event_title, o.organization_name
     FROM engagements e
     LEFT JOIN organizations o ON o.id = e.organization_id
     WHERE e.id = ? AND e.is_deleted = 0'
);
if (!$engagement_stmt) {
    http_response_code(500);
    exit('Unable to load the engagement.');
}
$engagement_stmt->bind_param('i', $engagement_id);
$engagement_stmt->execute();
$engagement = $engagement_stmt->get_result()->fetch_assoc();
$engagement_stmt->close();

if (!$engagement) {
    header('Location: engagements.php');
    exit();
}

$restore_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_selected'])) {
    requireValidCsrfToken();
    $selected_ids = [];
    foreach ((array) ($_POST['chron_entry_ids'] ?? []) as $submitted_entry_id) {
        if (!is_scalar($submitted_entry_id)
            || !ctype_digit((string) $submitted_entry_id)
            || (int) $submitted_entry_id < 1) {
            continue;
        }
        $selected_ids[] = (int) $submitted_entry_id;
    }
    $selected_ids = array_values(array_unique($selected_ids));

    try {
        if (!$selected_ids) {
            throw new InvalidArgumentException('Select at least one archived Chron entry to restore.');
        }

        $placeholders = implode(', ', array_fill(0, count($selected_ids), '?'));
        $restore_stmt = $conn->prepare(
            'UPDATE engagement_chron_entries
             SET is_archived = 0,
                 archived_by = NULL,
                 archived_at = NULL,
                 updated_by = ?,
                 updated_at = UTC_TIMESTAMP()
             WHERE engagement_id = ?
               AND is_archived = 1
               AND id IN (' . $placeholders . ')'
        );
        if (!$restore_stmt) {
            throw new RuntimeException('Unable to prepare the Chron restore.');
        }

        $current_user_id = (int) $_SESSION['user_id'];
        $bind_values = array_merge([$current_user_id, $engagement_id], $selected_ids);
        $bind_types = str_repeat('i', count($bind_values));
        $bind_params = [$bind_types];
        foreach ($bind_values as $key => &$bind_value) {
            $bind_params[] = &$bind_value;
        }
        unset($bind_value);
        $restore_stmt->bind_param(...$bind_params);

        if (!$restore_stmt->execute()) {
            $restore_stmt->close();
            throw new RuntimeException('Unable to restore the selected Chron entries.');
        }
        $restored_count = $restore_stmt->affected_rows;
        $restore_stmt->close();

        if ($restored_count < 1) {
            throw new InvalidArgumentException('The selected entries are no longer archived.');
        }

        $_SESSION['chron_restore_message'] = $restored_count === 1
            ? '1 Chron entry restored.'
            : $restored_count . ' Chron entries restored.';
        header('Location: restore_chron_entries.php?' . http_build_query([
            'engagement_id' => $engagement_id,
        ]));
        exit();
    } catch (Throwable $exception) {
        $restore_error = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'Unable to restore the selected Chron entries. Please try again.';
    }
}

$restore_message = (string) ($_SESSION['chron_restore_message'] ?? '');
unset($_SESSION['chron_restore_message']);

try {
    $archived_chron_entries = fetchArchivedChronLogEntries($conn, $engagement_id);
} catch (Throwable $exception) {
    http_response_code(503);
    exit('The archived Chron log is temporarily unavailable.');
}

$engagement_title = trim((string) ($engagement['event_title'] ?? ''));
if ($engagement_title === '') {
    $engagement_title = (string) ($engagement['organization_name'] ?? 'Engagement');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Restore Chron Entries - DNR</title>
    <link rel="stylesheet" href="assets/css/style.min.css?v=0.0.20">
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="engagements.php">Engagements</a><span aria-hidden="true">/</span>
        <a href="edit_engagement.php?id=<?php echo $engagement_id; ?>">Edit Engagement</a><span aria-hidden="true">/</span>
        <span>Restore Chron Entries</span>
    </nav>

    <div class="page-heading form-page-heading">
        <div>
            <h1>Restore Archived Chron Entries</h1>
            <p class="page-intro"><?php echo htmlspecialchars($engagement_title); ?> · Select one or more entries to return to the active Chron log.</p>
        </div>
    </div>

    <?php if ($restore_message !== ''): ?>
        <div class="success"><?php echo htmlspecialchars($restore_message); ?></div>
    <?php endif; ?>
    <?php if ($restore_error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($restore_error); ?></div>
    <?php endif; ?>

    <?php if ($archived_chron_entries): ?>
        <form method="post" action="restore_chron_entries.php?engagement_id=<?php echo $engagement_id; ?>" class="chron-restore-form">
            <?php echo csrfInput(); ?>
            <div class="chron-restore-toolbar">
                <label class="chron-select-all">
                    <input type="checkbox" id="select-all-chron-entries">
                    Select all archived entries
                </label>
                <button type="submit" name="restore_selected" value="1" class="restore-button">Restore selected</button>
            </div>

            <div class="chron-entry-list">
                <?php foreach ($archived_chron_entries as $chron_entry): ?>
                    <?php
                    $created_timestamp = chronLogTimestampDetails($chron_entry['created_at']);
                    $archived_timestamp = chronLogTimestampDetails($chron_entry['archived_at']);
                    $entry_author = $chron_entry['created_by_username']
                        ?: (!empty($chron_entry['legacy_engagement_note']) ? 'Migrated legacy note' : 'System');
                    ?>
                    <article class="chron-entry-card chron-restore-card">
                        <label class="chron-restore-selection">
                            <input type="checkbox" name="chron_entry_ids[]" value="<?php echo (int) $chron_entry['id']; ?>">
                            <span>Select this entry</span>
                        </label>
                        <div class="chron-entry-meta">
                            <div>
                                <time datetime="<?php echo htmlspecialchars($created_timestamp['iso']); ?>"><?php echo htmlspecialchars($created_timestamp['display']); ?></time>
                                <span>by <?php echo htmlspecialchars($entry_author); ?></span>
                            </div>
                            <?php if (!empty($chron_entry['archived_at'])): ?>
                                <small>Archived <time datetime="<?php echo htmlspecialchars($archived_timestamp['iso']); ?>"><?php echo htmlspecialchars($archived_timestamp['display']); ?></time><?php if (!empty($chron_entry['archived_by_username'])): ?> by <?php echo htmlspecialchars($chron_entry['archived_by_username']); ?><?php endif; ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="chron-entry-text"><?php echo nl2br(htmlspecialchars($chron_entry['entry_text'])); ?></div>
                    </article>
                <?php endforeach; ?>
            </div>
        </form>
    <?php else: ?>
        <section class="form-section">
            <p class="chron-empty-state">No archived Chron entries remain for this engagement.</p>
        </section>
    <?php endif; ?>

    <div class="form-row chron-restore-page-actions">
        <a href="edit_engagement.php?id=<?php echo $engagement_id; ?>#chron-log" class="cancel-button" style="display: inline-flex !important; align-items: center; justify-content: center; line-height: 1.25 !important; text-decoration: none !important;">Edit Engagement</a>
        <a href="view_engagement.php?id=<?php echo $engagement_id; ?>#chron-log" class="button-secondary" style="display: inline-flex !important; align-items: center; justify-content: center; line-height: 1.25 !important; text-decoration: none !important;">View Engagement</a>
    </div>
</div>

<?php include 'templates/footer.php'; ?>
<script>
(function () {
    const selectAll = document.getElementById('select-all-chron-entries');
    const entryCheckboxes = Array.from(document.querySelectorAll('input[name="chron_entry_ids[]"]'));
    if (!selectAll || entryCheckboxes.length === 0) return;

    selectAll.addEventListener('change', function () {
        entryCheckboxes.forEach(function (checkbox) {
            checkbox.checked = selectAll.checked;
        });
    });

    entryCheckboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            selectAll.checked = entryCheckboxes.every(function (entryCheckbox) {
                return entryCheckbox.checked;
            });
            selectAll.indeterminate = !selectAll.checked && entryCheckboxes.some(function (entryCheckbox) {
                return entryCheckbox.checked;
            });
        });
    });
})();
</script>
</body>
</html>
