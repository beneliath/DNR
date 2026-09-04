<?php
require_once __DIR__ . '/bootstrap.php';
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
$contact_stmt->close();

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
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
    2 => 'assets/css/pages/view_contact.min.css',
  ),
)); ?>
<body class="view-contact-body">
<?php include 'templates/header.php'; ?>
<div class="container view-contact-page" role="main">
    <?php if ($success_message !== ''): ?>
        <p class="success"><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="contacts.php<?php echo $is_archived ? '?status=archived' : ''; ?>">Contacts</a><span aria-hidden="true">/</span><span>Contact Details</span></nav>
    <div class="page-heading record-page-heading view-contact-heading"><div><h1><?php echo htmlspecialchars(
            $contact['contact_last_name'] . ', ' . $contact['contact_first_name'],
            ENT_QUOTES,
            'UTF-8'
        ); ?><?php if ($is_archived): ?><span class="archive-status">Archived</span><?php endif; ?></h1><p class="page-intro"><?php echo htmlspecialchars($display_role, ENT_QUOTES, 'UTF-8'); ?><?php if ($contact['organization_id'] !== null): ?> at <?php echo htmlspecialchars($contact['organization_name'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></p></div><?php if (!$is_archived && empty($contact['organization_is_archived']) && ($user_role === 'admin' || $user_role === 'editor')): ?><a href="edit_contact.php?id=<?php echo $contact_id; ?>&amp;from=view" class="button-add">Edit Contact</a><?php endif; ?></div>

    <div class="contact-overview-grid">
        <div class="contact-details contact-details-layout">
            <div class="contact-details-photo">
                <img src="contact_photo.php?id=<?php echo $contact_id; ?>&amp;size=full&amp;v=<?php echo $contact_photo_version; ?>" alt="Contact photo for <?php echo htmlspecialchars($contact['contact_first_name'] . ' ' . $contact['contact_last_name'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div>
                <div class="detail-row">
                    <strong>Organization</strong>
                    <?php if ($contact['organization_id'] !== null): ?>
                        <a href="view_organization.php?id=<?php echo (int) $contact['organization_id']; ?>">
                            <?php echo htmlspecialchars($contact['organization_name'], ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    <?php else: ?>
                        Not specified
                    <?php endif; ?>
                    <?php if (!empty($contact['organization_is_archived'])): ?>
                        <span class="archive-status">Archived</span>
                    <?php endif; ?>
                </div>

                <div class="detail-row">
                    <strong>Role</strong>
                    <?php echo htmlspecialchars($display_role, ENT_QUOTES, 'UTF-8'); ?>
                </div>

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
    $chron_log_description = "Communication history for this contact only. Entries are shown newest first. Select 'Edit Contact' to add/edit Chron Log entry.";
    $chron_view_url = 'view_contact.php?id=' . $contact_id;
    $chron_restore_url = 'restore_entity_chron_entries.php?entity_type=contact&entity_id=' . $contact_id;
    $chron_can_restore = !$is_archived && empty($contact['organization_is_archived']);
    include 'templates/entity_chron_log_view_section.php';
    ?>

    <?php
    $context_task_subject_type = 'contact';
    $context_task_subject_id = $contact_id;
    $context_task_subject_active = !$is_archived && empty($contact['organization_is_archived']);
    $context_task_return_to = 'view_contact.php?id=' . $contact_id . '#follow-up-work';
    include 'templates/follow_up_task_section.php';
    ?>

    <div class="action-buttons">
        <a href="contacts.php<?php echo $is_archived ? '?status=archived' : ''; ?>" class="action-button back-button">Back to List</a>
    </div>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
