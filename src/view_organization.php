<?php
require_once __DIR__ . '/bootstrap.php';
include 'follow_up_task_helpers.php';
include 'chron_log_helpers.php';
startSecureSession();
requireLogin();

// Get user role from session
$user_role = $_SESSION['role'] ?? '';

// Check if ID is provided and is numeric
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: organizations.php");
    exit();
}

$org_id = intval($_GET['id']);

// Fetch organization details
$query = "SELECT * FROM organizations WHERE id = ?";

$stmt = $conn->prepare($query);
if ($stmt === false) abortApplication(503, 'The organization is temporarily unavailable.', ['error' => $conn->error]);

$stmt->bind_param("i", $org_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: organizations.php");
    exit();
}

$organization = $result->fetch_assoc();
$is_archived = !empty($organization['is_deleted']);

try {
    $chron_page_size = 20;
    $chron_entry_count = countEntityChronLogEntries($conn, 'organization', $org_id);
    $chron_total_pages = max(1, (int) ceil($chron_entry_count / $chron_page_size));
    $chron_page = min(
        filter_input(INPUT_GET, 'chron_page', FILTER_VALIDATE_INT) ?: 1,
        $chron_total_pages
    );
    $chron_entries = fetchEntityChronLogEntries(
        $conn,
        'organization',
        $org_id,
        false,
        $chron_page_size,
        ($chron_page - 1) * $chron_page_size
    );
    $archived_chron_count = countEntityChronLogEntries($conn, 'organization', $org_id, 1);
} catch (Throwable $exception) {
    abortApplication(503, 'The organization Chron log is temporarily unavailable.', [
        'organization_id' => $org_id,
        'error' => $exception->getMessage(),
    ]);
}

// Fetch contacts for the organization
    $contact_query = "SELECT id, organization_id, contact_first_name, contact_last_name,
                             contact_role, contact_role_other, contact_email, contact_phone
                      FROM contacts
                  WHERE organization_id = ? AND is_deleted = 0
                  ORDER BY contact_last_name, contact_first_name";
$contact_stmt = $conn->prepare($contact_query);
if ($contact_stmt === false) abortApplication(503, 'The organization contacts are temporarily unavailable.', ['error' => $conn->error]);

$contact_stmt->bind_param("i", $org_id);
$contact_stmt->execute();
$contacts_result = $contact_stmt->get_result();

// Close statements
$stmt->close();
$contact_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead('View Organization - DNR', array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
    2 => 'assets/css/pages/view_organization.min.css',
  ),
)); ?>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="organizations.php<?php echo $is_archived ? '?status=archived' : ''; ?>">Organizations</a><span aria-hidden="true">/</span><span>Organization Details</span></nav>
    <div class="page-heading record-page-heading"><div><h1><?php echo htmlspecialchars($organization['organization_name']); ?><?php if ($is_archived): ?><span class="archive-status">Archived</span><?php endif; ?></h1><p class="page-intro">Organization profile, addresses, and contacts.</p></div><?php if (!$is_archived && in_array($user_role, ['admin', 'editor'], true)): ?><a href="edit_organization.php?id=<?php echo $org_id; ?>&from=view" class="button-add">Edit organization</a><?php endif; ?></div>
    <div class="organization-details">
        <div class="detail-row">
            <strong>Affiliation</strong>
            <?php echo !empty($organization['affiliation']) ? htmlspecialchars($organization['affiliation']) : 'Not specified'; ?>
        </div>

        <div class="detail-row">
            <strong>Distinctives</strong>
            <?php echo !empty($organization['distinctives']) ? htmlspecialchars($organization['distinctives']) : 'Not specified'; ?>
        </div>

        <div class="detail-row">
            <strong>Website</strong>
            <?php $safe_website_url = normalizedHttpUrl($organization['website_url'] ?? ''); ?>
            <?php if ($safe_website_url): ?>
                <a href="<?php echo htmlspecialchars($safe_website_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($safe_website_url, ENT_QUOTES, 'UTF-8'); ?></a>
            <?php else: ?>
                Not specified
            <?php endif; ?>
        </div>

        <div class="detail-row">
            <strong>Phone</strong>
            <?php echo !empty($organization['phone']) ? htmlspecialchars(formatPhoneNumberForDisplay($organization['phone']), ENT_QUOTES, 'UTF-8') : 'Not specified'; ?>
        </div>

        <div class="detail-row">
            <strong>Fax</strong>
            <?php echo !empty($organization['fax']) ? htmlspecialchars(formatPhoneNumberForDisplay($organization['fax']), ENT_QUOTES, 'UTF-8') : 'Not specified'; ?>
        </div>

        <div class="detail-row">
            <strong>Physical Address</strong>
            <?php
            $address_parts = [];
            if (!empty($organization['physical_address_line_1'])) $address_parts[] = htmlspecialchars($organization['physical_address_line_1']);
            if (!empty($organization['physical_address_line_2'])) $address_parts[] = htmlspecialchars($organization['physical_address_line_2']);
            if (!empty($organization['physical_city'])) $address_parts[] = htmlspecialchars($organization['physical_city']);
            if (!empty($organization['physical_state'])) $address_parts[] = htmlspecialchars($organization['physical_state']);
            if (!empty($organization['physical_zipcode'])) $address_parts[] = htmlspecialchars($organization['physical_zipcode']);
            if (!empty($organization['physical_country'])) $address_parts[] = htmlspecialchars($organization['physical_country']);

            echo !empty($address_parts) ? implode(', ', $address_parts) : 'Not specified';
            ?>
        </div>

        <div class="detail-row">
            <strong>Mailing Address</strong>
            <?php
            $mailing_parts = [];
            if (!empty($organization['mailing_address_line_1'])) $mailing_parts[] = htmlspecialchars($organization['mailing_address_line_1']);
            if (!empty($organization['mailing_address_line_2'])) $mailing_parts[] = htmlspecialchars($organization['mailing_address_line_2']);
            if (!empty($organization['mailing_city'])) $mailing_parts[] = htmlspecialchars($organization['mailing_city']);
            if (!empty($organization['mailing_state'])) $mailing_parts[] = htmlspecialchars($organization['mailing_state']);
            if (!empty($organization['mailing_zipcode'])) $mailing_parts[] = htmlspecialchars($organization['mailing_zipcode']);
            if (!empty($organization['mailing_country'])) $mailing_parts[] = htmlspecialchars($organization['mailing_country']);

            echo !empty($mailing_parts) ? implode(', ', $mailing_parts) : 'Not specified';
            ?>
        </div>

        <div class="detail-row">
            <strong>Notes</strong>
            <?php echo !empty($organization['notes']) ? nl2br(htmlspecialchars($organization['notes'])) : 'No notes'; ?>
        </div>
    </div>

    <?php
    $chron_entity_label = 'organization';
    $chron_view_url = 'view_organization.php?id=' . $org_id;
    $chron_restore_url = 'restore_entity_chron_entries.php?entity_type=organization&entity_id=' . $org_id;
    $chron_can_restore = !$is_archived;
    include 'templates/entity_chron_log_view_section.php';
    ?>

    <div class="contacts-section">
        <h3>Contacts</h3>
        <?php if ($contacts_result->num_rows > 0): ?>
            <?php while ($contact = $contacts_result->fetch_assoc()): ?>
                <div class="contact-card">
                    <div class="contact-header">
                        <h4 class="contact-name"><?php echo htmlspecialchars(
                            trim($contact['contact_first_name'] . ' ' . $contact['contact_last_name']),
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?></h4>
                        <span class="contact-role">
                            <?php
                            $role = $contact['contact_role'];
                            if ($role === 'other' && !empty($contact['contact_role_other'])) {
                                echo htmlspecialchars($contact['contact_role_other']);
                            } else {
                                echo ucfirst($role);
                            }
                            ?>
                        </span>
                    </div>
                    <div class="contact-info">
                        <div><strong>Email:</strong> <?php echo htmlspecialchars($contact['contact_email']); ?></div>
                        <?php if (!empty($contact['contact_phone'])): ?>
                            <div><strong>Phone:</strong> <?php echo htmlspecialchars(formatPhoneNumberForDisplay($contact['contact_phone']), ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <p>No contacts found for this organization.</p>
        <?php endif; ?>
    </div>

    <?php
    $context_task_subject_type = 'organization';
    $context_task_subject_id = $org_id;
    $context_task_subject_active = !$is_archived;
    $context_task_return_to = 'view_organization.php?id=' . $org_id . '#follow-up-work';
    include 'templates/follow_up_task_section.php';
    ?>

    <div class="action-buttons">
        <a href="organizations.php<?php echo $is_archived ? '?status=archived' : ''; ?>" class="action-button back-button">Back to List</a>
    </div>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
