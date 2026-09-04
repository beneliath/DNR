<?php
require_once __DIR__ . '/bootstrap.php';
include 'follow_up_task_helpers.php';
include 'chron_log_helpers.php';
require_once __DIR__ . '/financial_report_helpers.php';
startSecureSession();
requireLogin();

// Get user role from session
$user_role = $_SESSION['role'] ?? '';

$org_id = \Dnr\Http\RequestInput::positiveInt($_GET, 'id');
if ($org_id === null) {
    header("Location: organizations.php");
    exit();
}

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
$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);

try {
    $financial_summary = fetchOrganizationFinancialSummary($conn, $org_id);
    $financial_history = fetchOrganizationFinancialHistory($conn, $org_id);
} catch (Throwable $exception) {
    abortApplication(503, 'The organization financial history is temporarily unavailable.', [
        'organization_id' => $org_id,
        'error' => $exception->getMessage(),
    ]);
}

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
<?php renderPageHead(applicationPageTitle('View Organization'), array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
    2 => 'assets/css/pages/view_organization.min.css',
  ),
)); ?>
<body class="view-organization-body">
<?php include 'templates/header.php'; ?>
<div class="container view-organization-page" role="main">
    <?php if ($success_message !== ''): ?>
        <p class="success"><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="organizations.php<?php echo $is_archived ? '?status=archived' : ''; ?>">Organizations</a><span aria-hidden="true">/</span><span>Organization Details</span></nav>
    <div class="page-heading record-page-heading view-organization-heading"><div><h1><?php echo htmlspecialchars($organization['organization_name']); ?><?php if ($is_archived): ?><span class="archive-status">Archived</span><?php endif; ?></h1><p class="page-intro">Organization profile, addresses, and contacts.</p></div><?php if (!$is_archived && in_array($user_role, ['admin', 'editor'], true)): ?><a href="edit_organization.php?id=<?php echo $org_id; ?>&from=view" class="button-add">Edit Organization</a><?php endif; ?></div>

    <section class="organization-financials" aria-labelledby="organization-financial-heading">
        <div class="section-heading-row">
            <div>
                <h2 id="organization-financial-heading">Financial History</h2>
                <p>Only finalized event reports are included in these figures.</p>
            </div>
            <span><?php echo (int) $financial_summary['closed_event_count']; ?> closed event<?php echo (int) $financial_summary['closed_event_count'] === 1 ? '' : 's'; ?></span>
        </div>
        <div class="financial-summary-grid">
            <article class="financial-summary-card">
                <small>Lifetime giving</small>
                <strong><?php echo formatFinancialAmount($financial_summary['lifetime_giving']); ?></strong>
            </article>
            <article class="financial-summary-card">
                <small>Last event giving</small>
                <strong><?php echo $financial_summary['last_event_giving'] === null ? '—' : formatFinancialAmount($financial_summary['last_event_giving']); ?></strong>
                <?php if ($financial_summary['last_event_id'] !== null): ?>
                    <a href="view_engagement.php?id=<?php echo (int) $financial_summary['last_event_id']; ?>#financial-closeout"><?php echo htmlspecialchars((string) ($financial_summary['last_event_title'] ?: 'Most recent event'), ENT_QUOTES, 'UTF-8'); ?></a>
                <?php endif; ?>
            </article>
            <article class="financial-summary-card">
                <small>Average event giving</small>
                <strong><?php echo (int) $financial_summary['closed_event_count'] === 0 ? '—' : formatFinancialAmount($financial_summary['average_event_giving']); ?></strong>
            </article>
            <article class="financial-summary-card">
                <small>Lodging received</small>
                <strong><?php echo formatFinancialAmount($financial_summary['lifetime_lodging']); ?></strong>
            </article>
            <article class="financial-summary-card">
                <small>Travel received</small>
                <strong><?php echo formatFinancialAmount($financial_summary['lifetime_travel']); ?></strong>
            </article>
        </div>

        <?php if ($financial_history !== []): ?>
            <?php if ((int) $financial_summary['closed_event_count'] > count($financial_history)): ?>
                <p class="financial-history-empty">Showing the <?php echo count($financial_history); ?> most recent finalized event reports.</p>
            <?php endif; ?>
            <div class="financial-history-table-wrap">
                <table class="financial-history-table data-table">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Date</th>
                            <th>Giving / income</th>
                            <th>Lodging</th>
                            <th>Travel</th>
                            <th>Total received</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($financial_history as $history_report): ?>
                            <tr>
                                <td>
                                    <a href="view_engagement.php?id=<?php echo (int) $history_report['engagement_id']; ?>#financial-closeout"><?php echo htmlspecialchars((string) ($history_report['event_title'] ?: 'Untitled event'), ENT_QUOTES, 'UTF-8'); ?></a>
                                    <?php if (!empty($history_report['is_deleted'])): ?><span class="archive-status">Archived</span><?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars((string) $history_report['event_end_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo formatFinancialAmount($history_report['giving_income_received']); ?></td>
                                <td><?php echo formatFinancialAmount($history_report['lodging_received']); ?></td>
                                <td><?php echo formatFinancialAmount($history_report['travel_received']); ?></td>
                                <td><strong><?php echo formatFinancialAmount(financialReportTotal($history_report)); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="financial-history-empty">No events have a finalized financial report yet.</p>
        <?php endif; ?>
    </section>

    <div class="organization-overview-grid">
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
                if (!empty($organization['physical_state'])) $address_parts[] = htmlspecialchars(addressRegionName($organization['physical_country'], $organization['physical_state']));
                if (!empty($organization['physical_zipcode'])) $address_parts[] = htmlspecialchars($organization['physical_zipcode']);
                if (!empty($organization['physical_country'])) $address_parts[] = htmlspecialchars(addressCountryName($organization['physical_country']));

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
                if (!empty($organization['mailing_state'])) $mailing_parts[] = htmlspecialchars(addressRegionName($organization['mailing_country'], $organization['mailing_state']));
                if (!empty($organization['mailing_zipcode'])) $mailing_parts[] = htmlspecialchars($organization['mailing_zipcode']);
                if (!empty($organization['mailing_country'])) $mailing_parts[] = htmlspecialchars(addressCountryName($organization['mailing_country']));

                echo !empty($mailing_parts) ? implode(', ', $mailing_parts) : 'Not specified';
                ?>
            </div>
        </div>

        <section class="organization-details organization-notes-panel" aria-labelledby="organization-notes-heading">
            <h2 id="organization-notes-heading">Notes</h2>
            <div class="organization-notes-content">
                <?php echo !empty($organization['notes']) ? renderTextWithLinks($organization['notes']) : 'No notes'; ?>
            </div>
        </section>
    </div>

    <?php
    $chron_entity_label = 'organization';
    $chron_view_url = 'view_organization.php?id=' . $org_id;
    $chron_restore_url = 'restore_entity_chron_entries.php?entity_type=organization&entity_id=' . $org_id;
    $chron_can_restore = !$is_archived;
    include 'templates/entity_chron_log_view_section.php';
    ?>

    <div class="contacts-section">
        <div class="section-heading-row">
            <h3>Contacts</h3>
            <?php if (!$is_archived && in_array($user_role, ['admin', 'editor'], true)): ?>
                <a href="add_contact.php?organization_id=<?php echo $org_id; ?>" class="button-add">+ New Contact</a>
            <?php endif; ?>
        </div>
        <?php if ($contacts_result->num_rows > 0): ?>
            <div class="organization-contact-grid">
            <?php while ($contact = $contacts_result->fetch_assoc()): ?>
                <div class="contact-card">
                    <div class="contact-header">
                        <h4 class="contact-name"><a href="view_contact.php?id=<?php echo (int) $contact['id']; ?>"><?php echo htmlspecialchars(
                                trim($contact['contact_first_name'] . ' ' . $contact['contact_last_name']),
                                ENT_QUOTES,
                                'UTF-8'
                            ); ?></a></h4>
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
                        <div><strong>Email:</strong> <a href="mailto:<?php echo htmlspecialchars($contact['contact_email'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($contact['contact_email']); ?></a></div>
                        <?php if (!empty($contact['contact_phone'])): ?>
                            <div><strong>Phone:</strong> <?php echo htmlspecialchars(formatPhoneNumberForDisplay($contact['contact_phone']), ENT_QUOTES, 'UTF-8'); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endwhile; ?>
            </div>
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
