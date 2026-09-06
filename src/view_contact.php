<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/record_workspace_helpers.php';
require_once __DIR__ . '/contact_organization_helpers.php';
include 'follow_up_task_helpers.php';
include 'chron_log_helpers.php';
startSecureSession();
requireLogin();

$user_role = $_SESSION['role'] ?? '';
$contact_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$contact_id) {
    header('Location: contacts.php');
    exit();
}

$contact_stmt = $conn->prepare(
    "SELECT
        c.id, c.organization_id, c.contact_first_name, c.contact_last_name,
        c.contact_role, c.contact_role_other, c.contact_email, c.contact_phone,
        c.contact_birthday, c.contact_notes, c.contact_photo_updated_at, c.is_deleted,
        o.organization_name,
        o.is_deleted AS organization_is_archived
     FROM contacts c
     LEFT JOIN organizations o ON o.id = c.organization_id
     WHERE c.id = ?"
);
if (!$contact_stmt) {
    abortApplication(503, 'The contact is temporarily unavailable.', ['error' => $conn->error]);
}

$contact_stmt->bind_param('i', $contact_id);
$contact_stmt->execute();
$contact_result = $contact_stmt->get_result();
if ($contact_result->num_rows === 0) {
    $contact_stmt->close();
    header('Location: contacts.php');
    exit();
}

$contact = $contact_result->fetch_assoc();
$is_archived = !empty($contact['is_deleted']);
$record_list_return = safeRecordReturnUrl($_GET['return_to'] ?? null, 'contacts.php' . ($is_archived ? '?status=archived' : ''));
$record_view_url = 'view_contact.php?' . http_build_query(['id' => $contact_id, 'return_to' => $record_list_return]);
$record_note_url = $record_view_url;
$record_note_entity = 'contact';
$record_can_add_note = !$is_archived && empty($contact['organization_is_archived']) && in_array($user_role, ['admin', 'editor'], true);
$record_note_error = handleRecordAddNote($conn, 'contact', (int) $contact_id, $record_view_url);
$record_note_message = (string) ($_SESSION['record_note_message'] ?? '');
unset($_SESSION['record_note_message']);

$contact_stmt->close();
try {
    $contact_organizations = fetchContactOrganizations($conn, (int) $contact_id);
} catch (Throwable $exception) {
    abortApplication(503, 'The contact organizations are temporarily unavailable.', [
        'contact_id' => $contact_id,
        'error' => $exception->getMessage(),
    ]);
}

$display_role = $contact['contact_role'] === 'other'
    ? ($contact['contact_role_other'] ?: 'Other')
    : ucfirst($contact['contact_role']);

$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);
$contact_notes = trim((string) ($contact['contact_notes'] ?? ''));
$contact_birthday_display = 'Not specified';
if (!empty($contact['contact_birthday'])) {
    $birthday = DateTimeImmutable::createFromFormat('!m/d/Y', (string) $contact['contact_birthday'] . '/2000');
    if ($birthday instanceof DateTimeImmutable) {
        $contact_birthday_display = $birthday->format('F j');
    }
}
$contact_photo_version = strtotime((string) ($contact['contact_photo_updated_at'] ?? '')) ?: 0;
try {
    $chron_page_size = 20;
    $chron_entry_count = countEntityChronLogEntries($conn, 'contact', $contact_id);
    $chron_total_pages = max(1, (int) ceil($chron_entry_count / $chron_page_size));
    $chron_page = min(
        filter_input(INPUT_GET, 'chron_page', FILTER_VALIDATE_INT) ?: 1,
        $chron_total_pages
    );
    $chron_entries = fetchEntityChronLogEntries(
        $conn,
        'contact',
        $contact_id,
        false,
        $chron_page_size,
        ($chron_page - 1) * $chron_page_size
    );
    $archived_chron_count = countEntityChronLogEntries($conn, 'contact', $contact_id, 1);
} catch (Throwable $exception) {
    abortApplication(503, 'The contact Chron log is temporarily unavailable.', [
        'contact_id' => $contact_id,
        'error' => $exception->getMessage(),
    ]);
}
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('View Contact'), array (
  'styles' =>
  array (
    'assets/css/style.min.css',
    'assets/css/modern.min.css',
    'assets/css/pages/record_workspace.min.css',
    'assets/css/pages/view_contact.min.css',
  ),
)); ?>
<body class="view-contact-body">
<?php include 'templates/header.php'; ?>
<div class="container view-contact-page" role="main">
    <?php if ($success_message !== ''): ?>
        <p class="success"><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <?php if ($record_note_message !== ''): ?><p class="success" role="status"><?php echo htmlspecialchars($record_note_message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?php echo htmlspecialchars($record_list_return, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(recordReturnLabel($record_list_return), ENT_QUOTES, 'UTF-8'); ?></a><span aria-hidden="true">/</span><span>Contact Details</span></nav>
    <div class="page-heading record-page-heading view-contact-heading"><div><h1><?php echo htmlspecialchars(
            $contact['contact_last_name'] . ', ' . $contact['contact_first_name'],
            ENT_QUOTES,
            'UTF-8'
        ); ?><?php if ($is_archived): ?><span class="archive-status">Archived</span><?php endif; ?></h1><p class="page-intro"><?php echo htmlspecialchars($display_role, ENT_QUOTES, 'UTF-8'); ?><?php if ($contact['organization_id'] !== null): ?> at <?php echo htmlspecialchars($contact['organization_name'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></p></div><?php if (!$is_archived && empty($contact['organization_is_archived']) && ($user_role === 'admin' || $user_role === 'editor')): ?><a href="<?php echo htmlspecialchars(recordUrlWithQuery('edit_contact.php?id=' . $contact_id, ['return_to' => $record_view_url]), ENT_QUOTES, 'UTF-8'); ?>" class="button-secondary">Edit Contact</a><a href="#add-note" class="button-add">Add Chron Log Entry</a><?php endif; ?></div>

    <div class="contact-overview-grid">
        <div class="contact-details contact-details-layout">
            <div class="contact-details-photo">
                <img src="contact_photo.php?id=<?php echo $contact_id; ?>&amp;size=full&amp;v=<?php echo $contact_photo_version; ?>" alt="Contact photo for <?php echo htmlspecialchars($contact['contact_first_name'] . ' ' . $contact['contact_last_name'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div>
                <div class="detail-row">
                    <strong>Organizations and roles</strong>
                    <?php if ($contact_organizations !== []): ?>
                        <ul class="contact-affiliation-list">
                            <?php foreach ($contact_organizations as $affiliation): ?>
                                <li>
                                    <a href="view_organization.php?id=<?php echo (int) $affiliation['organization_id']; ?>"><?php echo htmlspecialchars($affiliation['organization_name'], ENT_QUOTES, 'UTF-8'); ?></a>
                                    <span class="contact-affiliation-kind"><?php echo !empty($affiliation['is_primary']) ? 'Primary' : 'Additional'; ?></span>
                                    <?php if (!empty($affiliation['organization_is_deleted'])): ?><span class="archive-status">Archived</span><?php endif; ?>
                                    <small><?php echo htmlspecialchars($affiliation['role_title'] ?: 'Role not specified', ENT_QUOTES, 'UTF-8'); ?></small>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        No organizations linked
                    <?php endif; ?>
                </div>

                <?php if ($contact['organization_id'] === null): ?>
                    <div class="detail-row">
                        <strong>Role</strong>
                        <?php echo htmlspecialchars($display_role, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <div class="detail-row">
                    <strong>Email</strong>
                    <a href="mailto:<?php echo htmlspecialchars($contact['contact_email'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($contact['contact_email'], ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                </div>

                <div class="detail-row">
                    <strong>Phone</strong>
                    <?php echo htmlspecialchars(
                        !empty($contact['contact_phone'])
                            ? formatPhoneNumberForDisplay($contact['contact_phone'])
                            : 'Not specified',
                        ENT_QUOTES,
                        'UTF-8'
                    ); ?>
                </div>

                <div class="detail-row">
                    <strong>Birthday</strong>
                    <?php echo htmlspecialchars($contact_birthday_display, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            </div>
        </div>

        <section class="contact-details contact-notes-panel" aria-labelledby="contact-notes-heading">
            <h2 id="contact-notes-heading">Notes</h2>
            <div class="contact-notes-content">
                <?php echo $contact_notes !== ''
                    ? renderTextWithLinks($contact_notes)
                    : 'No notes'; ?>
            </div>
        </section>
    </div>

    <?php
    $chron_entity_label = 'contact';
    $chron_log_description = 'Communication history for this contact, newest first.';
    $chron_view_url = $record_view_url;
    $chron_restore_url = 'restore_entity_chron_entries.php?entity_type=contact&entity_id=' . $contact_id;
    $chron_can_restore = !$is_archived && empty($contact['organization_is_archived']);
    include 'templates/entity_chron_log_view_section.php';
    ?>

    <?php
    $context_task_subject_type = 'contact';
    $context_task_subject_id = $contact_id;
    $context_task_subject_active = !$is_archived && empty($contact['organization_is_archived']);
    $context_task_return_to = $record_view_url . '#follow-up-work';
    include 'templates/follow_up_task_section.php';
    ?>

    <div class="action-buttons">
        <a href="<?php echo htmlspecialchars($record_list_return, ENT_QUOTES, 'UTF-8'); ?>" class="action-button back-button">Back to <?php echo htmlspecialchars(recordReturnLabel($record_list_return), ENT_QUOTES, 'UTF-8'); ?></a>
    </div>
</div>
<?php renderScript('assets/js/record-workspace.min.js'); ?>
<?php include 'templates/footer.php'; ?>
</body>
</html>
