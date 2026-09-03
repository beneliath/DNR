<?php
require_once __DIR__ . '/bootstrap.php';
include 'chron_log_helpers.php';
startSecureSession();
requireLogin();

$user_role = (string) ($_SESSION['role'] ?? '');
if (!canArchiveEntries($user_role)) {
    http_response_code(403);
    exit('Forbidden.');
}

$entity_type = is_scalar($_GET['entity_type'] ?? null)
    ? (string) $_GET['entity_type']
    : '';
$entity_id = filter_input(INPUT_GET, 'entity_id', FILTER_VALIDATE_INT);
if (!in_array($entity_type, ['contact', 'organization'], true) || !$entity_id) {
    header('Location: organizations.php');
    exit();
}

if ($entity_type === 'contact') {
    $entity_stmt = $conn->prepare(
        'SELECT c.id, c.contact_first_name, c.contact_last_name
         FROM contacts c
         LEFT JOIN organizations o ON o.id = c.organization_id
         WHERE c.id = ? AND c.is_deleted = 0
           AND (o.id IS NULL OR o.is_deleted = 0)'
    );
    $entity_label = 'Contact';
    $list_url = 'contacts.php';
    $edit_url = 'edit_contact.php?id=' . $entity_id;
    $view_url = 'view_contact.php?id=' . $entity_id;
} else {
    $entity_stmt = $conn->prepare(
        'SELECT id, organization_name
         FROM organizations
         WHERE id = ? AND is_deleted = 0'
    );
    $entity_label = 'Organization';
    $list_url = 'organizations.php';
    $edit_url = 'edit_organization.php?id=' . $entity_id;
    $view_url = 'view_organization.php?id=' . $entity_id;
}
if (!$entity_stmt) {
    abortApplication(503, 'The Chron log owner is temporarily unavailable.', [
        'entity_type' => $entity_type,
        'entity_id' => $entity_id,
        'error' => $conn->error,
    ]);
}
$entity_stmt->bind_param('i', $entity_id);
$entity_stmt->execute();
$entity = $entity_stmt->get_result()->fetch_assoc();
$entity_stmt->close();
if (!$entity) {
    header('Location: ' . $list_url);
    exit();
}

$entity_name = $entity_type === 'contact'
    ? trim((string) $entity['contact_first_name'] . ' ' . (string) $entity['contact_last_name'])
    : (string) $entity['organization_name'];
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

    try {
        $restored_count = restoreEntityChronLogEntries(
            $conn,
            $entity_type,
            $entity_id,
            $selected_ids,
            (int) $_SESSION['user_id']
        );
        $_SESSION['chron_restore_message'] = $restored_count === 1
            ? '1 Chron entry restored.'
            : $restored_count . ' Chron entries restored.';
        header('Location: restore_entity_chron_entries.php?' . http_build_query([
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
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
    $archived_chron_entries = fetchArchivedEntityChronLogEntries(
        $conn,
        $entity_type,
        $entity_id
    );
} catch (Throwable $exception) {
    abortApplication(503, 'The archived Chron log is temporarily unavailable.', [
        'entity_type' => $entity_type,
        'entity_id' => $entity_id,
        'error' => $exception->getMessage(),
    ]);
}

$restore_query = http_build_query([
    'entity_type' => $entity_type,
    'entity_id' => $entity_id,
]);
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Restore Chron Entries')); ?>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="<?php echo htmlspecialchars($list_url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($entity_label . 's', ENT_QUOTES, 'UTF-8'); ?></a><span aria-hidden="true">/</span>
        <a href="<?php echo htmlspecialchars($edit_url, ENT_QUOTES, 'UTF-8'); ?>">Edit <?php echo htmlspecialchars($entity_label, ENT_QUOTES, 'UTF-8'); ?></a><span aria-hidden="true">/</span>
        <span>Restore Chron Entries</span>
    </nav>

    <div class="page-heading form-page-heading">
        <div>
            <h1>Restore Archived Chron Entries</h1>
            <p class="page-intro"><?php echo htmlspecialchars($entity_name, ENT_QUOTES, 'UTF-8'); ?> · Select one or more entries to return to this <?php echo htmlspecialchars(strtolower($entity_label), ENT_QUOTES, 'UTF-8'); ?>'s Chron log.</p>
        </div>
    </div>

    <?php if ($restore_message !== ''): ?>
        <div class="success"><?php echo htmlspecialchars($restore_message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($restore_error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($restore_error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($archived_chron_entries): ?>
        <form method="post" action="restore_entity_chron_entries.php?<?php echo htmlspecialchars($restore_query, ENT_QUOTES, 'UTF-8'); ?>" class="chron-restore-form">
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
                    $entry_author = $chron_entry['created_by_username'] ?: 'System';
                    ?>
                    <article class="chron-entry-card chron-restore-card">
                        <label class="chron-restore-selection">
                            <input type="checkbox" name="chron_entry_ids[]" value="<?php echo (int) $chron_entry['id']; ?>">
                            <span>Select this entry</span>
                        </label>
                        <div class="chron-entry-meta">
                            <div>
                                <time datetime="<?php echo htmlspecialchars($created_timestamp['iso'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($created_timestamp['display'], ENT_QUOTES, 'UTF-8'); ?></time>
                                <span>by <?php echo htmlspecialchars($entry_author, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <?php if (!empty($chron_entry['archived_at'])): ?>
                                <small>Archived <time datetime="<?php echo htmlspecialchars($archived_timestamp['iso'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($archived_timestamp['display'], ENT_QUOTES, 'UTF-8'); ?></time><?php if (!empty($chron_entry['archived_by_username'])): ?> by <?php echo htmlspecialchars($chron_entry['archived_by_username'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="chron-entry-text"><?php echo renderChronLogEntryHtml($chron_entry['entry_text']); ?></div>
                    </article>
                <?php endforeach; ?>
            </div>
        </form>
    <?php else: ?>
        <section class="form-section">
            <p class="chron-empty-state">No archived Chron entries remain for this <?php echo htmlspecialchars(strtolower($entity_label), ENT_QUOTES, 'UTF-8'); ?>.</p>
        </section>
    <?php endif; ?>

    <div class="form-row chron-restore-page-actions">
        <a href="<?php echo htmlspecialchars($edit_url . '#chron-log', ENT_QUOTES, 'UTF-8'); ?>" class="cancel-button inline-action-link">Edit <?php echo htmlspecialchars($entity_label, ENT_QUOTES, 'UTF-8'); ?></a>
        <a href="<?php echo htmlspecialchars($view_url . '#chron-log', ENT_QUOTES, 'UTF-8'); ?>" class="button-secondary inline-action-link">View <?php echo htmlspecialchars($entity_label, ENT_QUOTES, 'UTF-8'); ?></a>
    </div>
</div>

<?php include 'templates/footer.php'; ?>
</body>
</html>
