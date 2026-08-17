<?php
include 'config.php';
include 'functions.php';
include 'chron_log_helpers.php';
include 'presentation_helpers.php';
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
    'SELECT e.id, e.event_title, e.confirmation_status, o.organization_name
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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $conn->begin_transaction();

    try {
        if (($_POST['action'] ?? '') === 'delete') {
            if (!canDeleteEntries($user_role)) {
                http_response_code(403);
                exit('Forbidden.');
            }
            $presentation_id = filter_input(INPUT_POST, 'presentation_id', FILTER_VALIDATE_INT);
            if (!$presentation_id) {
                throw new InvalidArgumentException('Select a valid archived presentation.');
            }

            $delete_stmt = $conn->prepare(
                'DELETE FROM presentations
                 WHERE id = ? AND engagement_id = ? AND is_archived = 1'
            );
            if (!$delete_stmt) {
                throw new RuntimeException('Unable to prepare the presentation deletion.');
            }
            $delete_stmt->bind_param('ii', $presentation_id, $engagement_id);
            if (!$delete_stmt->execute() || $delete_stmt->affected_rows !== 1) {
                $delete_stmt->close();
                throw new InvalidArgumentException('That archived presentation is no longer available.');
            }
            $delete_stmt->close();
            $action_message = 'Presentation permanently deleted.';
        } elseif (isset($_POST['restore_selected'])) {
            $selected_ids = [];
            foreach ((array) ($_POST['presentation_ids'] ?? []) as $submitted_id) {
                if (is_scalar($submitted_id)
                    && ctype_digit((string) $submitted_id)
                    && (int) $submitted_id > 0
                ) {
                    $selected_ids[] = (int) $submitted_id;
                }
            }
            $selected_ids = array_values(array_unique($selected_ids));
            if (!$selected_ids) {
                throw new InvalidArgumentException('Select at least one archived presentation to restore.');
            }

            $placeholders = implode(', ', array_fill(0, count($selected_ids), '?'));
            $restore_stmt = $conn->prepare(
                'UPDATE presentations
                 SET is_archived = 0, archived_by = NULL, archived_at = NULL
                 WHERE engagement_id = ?
                   AND is_archived = 1
                   AND id IN (' . $placeholders . ')'
            );
            if (!$restore_stmt) {
                throw new RuntimeException('Unable to prepare the presentation restore.');
            }
            $bind_values = array_merge([$engagement_id], $selected_ids);
            $bind_types = str_repeat('i', count($bind_values));
            $bind_params = [$bind_types];
            foreach ($bind_values as &$bind_value) {
                $bind_params[] = &$bind_value;
            }
            unset($bind_value);
            $restore_stmt->bind_param(...$bind_params);
            if (!$restore_stmt->execute()) {
                $restore_stmt->close();
                throw new RuntimeException('Unable to restore the selected presentations.');
            }
            $restored_count = $restore_stmt->affected_rows;
            $restore_stmt->close();
            if ($restored_count < 1) {
                throw new InvalidArgumentException('The selected presentations are no longer archived.');
            }
            $action_message = $restored_count === 1
                ? '1 presentation restored.'
                : $restored_count . ' presentations restored.';
        } else {
            throw new InvalidArgumentException('Select a valid presentation action.');
        }

        $touch_stmt = $conn->prepare(
            'UPDATE engagements SET updated_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        if (!$touch_stmt) {
            throw new RuntimeException('Unable to update the engagement calendar timestamp.');
        }
        $touch_stmt->bind_param('i', $engagement_id);
        if (!$touch_stmt->execute()) {
            $touch_stmt->close();
            throw new RuntimeException('Unable to update the engagement calendar timestamp.');
        }
        $touch_stmt->close();

        $conn->commit();
        $_SESSION['presentation_restore_message'] = $action_message;
        header('Location: restore_presentations.php?' . http_build_query([
            'engagement_id' => $engagement_id,
        ]));
        exit();
    } catch (Throwable $exception) {
        $conn->rollback();
        $restore_error = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'Unable to update the archived presentations. Please try again.';
    }
}

$restore_message = (string) ($_SESSION['presentation_restore_message'] ?? '');
unset($_SESSION['presentation_restore_message']);

try {
    $archived_presentations = fetchArchivedEngagementPresentations($conn, $engagement_id);
} catch (Throwable $exception) {
    http_response_code(503);
    exit('The archived presentations are temporarily unavailable.');
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
    <title>Restore Presentations - DNR</title>
    <link rel="stylesheet" href="assets/css/style.min.css?v=0.0.20">
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="engagements.php">Engagements</a><span aria-hidden="true">/</span>
        <a href="edit_engagement.php?id=<?php echo $engagement_id; ?>">Edit Engagement</a><span aria-hidden="true">/</span>
        <span>Restore Presentations</span>
    </nav>

    <div class="page-heading form-page-heading">
        <div>
            <h1>Restore Archived Presentations</h1>
            <p class="page-intro"><?php echo htmlspecialchars($engagement_title); ?> · Select one or more presentations to return to the active engagement.</p>
        </div>
    </div>

    <?php if ($restore_message !== ''): ?>
        <div class="success"><?php echo htmlspecialchars($restore_message); ?></div>
    <?php endif; ?>
    <?php if ($restore_error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($restore_error); ?></div>
    <?php endif; ?>

    <?php if ($archived_presentations): ?>
        <form method="post" action="restore_presentations.php?engagement_id=<?php echo $engagement_id; ?>" class="chron-restore-form">
            <?php echo csrfInput(); ?>
            <div class="chron-restore-toolbar">
                <label class="chron-select-all">
                    <input type="checkbox" id="select-all-presentations">
                    Select All Archived Presentations
                </label>
                <button type="submit" name="restore_selected" value="1" class="restore-button">Restore Selected</button>
            </div>

            <div class="chron-entry-list">
                <?php foreach ($archived_presentations as $presentation): ?>
                    <?php $archived_timestamp = chronLogTimestampDetails($presentation['archived_at']); ?>
                    <article class="presentation-item chron-restore-card">
                        <label class="chron-restore-selection">
                            <input type="checkbox" name="presentation_ids[]" value="<?php echo (int) $presentation['id']; ?>">
                            <span>Select This Presentation</span>
                        </label>
                        <strong><?php echo htmlspecialchars($presentation['topic_title']); ?></strong>
                        <div><?php echo htmlspecialchars(trim($presentation['presentation_date'] . ' ' . $presentation['presentation_time'])); ?></div>
                        <?php if ($presentation['speaker_name'] !== ''): ?>
                            <div>Speaker: <?php echo htmlspecialchars($presentation['speaker_name']); ?></div>
                        <?php endif; ?>
                        <?php if ($presentation['expected_attendance'] !== null): ?>
                            <div>Expected Attendance: <?php echo (int) $presentation['expected_attendance']; ?></div>
                        <?php endif; ?>
                        <?php if (!empty($presentation['archived_at'])): ?>
                            <small>Archived <time datetime="<?php echo htmlspecialchars($archived_timestamp['iso']); ?>"><?php echo htmlspecialchars($archived_timestamp['display']); ?></time><?php if (!empty($presentation['archived_by_username'])): ?> by <?php echo htmlspecialchars($presentation['archived_by_username']); ?><?php endif; ?></small>
                        <?php endif; ?>
                        <?php if (canDeleteEntries($user_role)): ?>
                            <div class="remove-btn-container">
                                <button type="submit" form="delete-archived-presentation-<?php echo (int) $presentation['id']; ?>" class="delete-button">Delete</button>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </form>

        <?php if (canDeleteEntries($user_role)): ?>
            <?php foreach ($archived_presentations as $presentation): ?>
                <form id="delete-archived-presentation-<?php echo (int) $presentation['id']; ?>" method="post" action="restore_presentations.php?engagement_id=<?php echo $engagement_id; ?>"
                      data-delete-confirmation="Permanently delete this archived presentation?"
                      data-archive-button-label="Keep Archived" hidden>
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="presentation_id" value="<?php echo (int) $presentation['id']; ?>">
                    <input type="hidden" name="action" value="delete">
                </form>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php else: ?>
        <section class="form-section">
            <p class="chron-empty-state">No archived presentations remain for this engagement.</p>
        </section>
    <?php endif; ?>

    <div class="form-row chron-restore-page-actions">
        <a href="edit_engagement.php?id=<?php echo $engagement_id; ?>#presentations-container" class="cancel-button">Edit Engagement</a>
        <a href="view_engagement.php?id=<?php echo $engagement_id; ?>" class="button-secondary">View Engagement</a>
    </div>
</div>

<?php include 'templates/footer.php'; ?>
<script>
(function () {
    const selectAll = document.getElementById('select-all-presentations');
    const presentationCheckboxes = Array.from(document.querySelectorAll('input[name="presentation_ids[]"]'));
    if (!selectAll || presentationCheckboxes.length === 0) return;

    selectAll.addEventListener('change', function () {
        presentationCheckboxes.forEach(function (checkbox) {
            checkbox.checked = selectAll.checked;
        });
    });

    presentationCheckboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            selectAll.checked = presentationCheckboxes.every(function (presentationCheckbox) {
                return presentationCheckbox.checked;
            });
            selectAll.indeterminate = !selectAll.checked && presentationCheckboxes.some(function (presentationCheckbox) {
                return presentationCheckbox.checked;
            });
        });
    });
})();
</script>
</body>
</html>
