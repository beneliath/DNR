<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/record_workspace_helpers.php';
include 'follow_up_task_helpers.php';
include 'chron_log_helpers.php';
require_once __DIR__ . '/financial_report_helpers.php';
require_once __DIR__ . '/engagement_view_helpers.php';
require_once __DIR__ . '/engagement_lifecycle_helpers.php';
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
$record_list_return = safeRecordReturnUrl($_GET['return_to'] ?? null, 'organizations.php' . ($is_archived ? '?status=archived' : ''));
$record_view_url = 'view_organization.php?' . http_build_query(['id' => $org_id, 'return_to' => $record_list_return]);
$record_note_url = $record_view_url;
$record_note_entity = 'organization';
$record_can_add_note = !$is_archived && in_array($user_role, ['admin', 'editor'], true);
$record_note_error = handleRecordAddNote($conn, 'organization', (int) $org_id, $record_view_url);
$record_note_message = (string) ($_SESSION['record_note_message'] ?? '');
unset($_SESSION['record_note_message']);

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
$organization_contacts = $contacts_result->fetch_all(MYSQLI_ASSOC);
$event_count_stmt = $conn->prepare('SELECT COUNT(*) AS total FROM engagements WHERE organization_id = ?');
$event_count_stmt->bind_param('i', $org_id);
$event_count_stmt->execute();
$organization_event_count = (int) $event_count_stmt->get_result()->fetch_assoc()['total'];
$event_count_stmt->close();
$organization_event_pages = max(1, (int) ceil($organization_event_count / 25));
$organization_event_page = min($organization_event_pages, max(1, (int) ($_GET['events_page'] ?? 1)));
$event_offset = ($organization_event_page - 1) * 25;
$event_stmt = $conn->prepare('SELECT id, event_title, event_start_date, event_end_date, lifecycle_status, confirmation_status, is_deleted FROM engagements WHERE organization_id = ? ORDER BY event_start_date DESC, id DESC LIMIT 25 OFFSET ?');
$event_stmt->bind_param('ii', $org_id, $event_offset);
$event_stmt->execute();
$organization_events = $event_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$event_stmt->close();
$next_event_stmt = $conn->prepare("SELECT id, event_title, event_start_date, event_end_date FROM engagements WHERE organization_id = ? AND is_deleted = 0 AND lifecycle_status = 'active' AND COALESCE(event_end_date, event_start_date) >= ? ORDER BY event_start_date, id LIMIT 1");
$event_today = applicationBusinessDate();
$next_event_stmt->bind_param('is', $org_id, $event_today);
$next_event_stmt->execute();
$next_organization_event = $next_event_stmt->get_result()->fetch_assoc();
$next_event_stmt->close();


// Close statements
$stmt->close();
$contact_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('View Organization'), array (
  'styles' =>
  array (
    'assets/css/style.min.css',
    'assets/css/modern.min.css',
    'assets/css/pages/record_workspace.min.css',
    'assets/css/pages/view_organization.min.css',
  ),
)); ?>
<body class="view-organization-body">
<?php include 'templates/header.php'; ?>
<div class="container view-organization-page" role="main">
    <?php if ($success_message !== ''): ?>
        <p class="success"><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if ($record_note_message !== ''): ?><p class="success" role="status"><?php echo htmlspecialchars($record_note_message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?php echo htmlspecialchars($record_list_return, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(recordReturnLabel($record_list_return), ENT_QUOTES, 'UTF-8'); ?></a><span aria-hidden="true">/</span><span>Organization Details</span></nav>
    <div class="page-heading record-page-heading view-organization-heading"><div><h1><?php echo htmlspecialchars($organization['organization_name']); ?><?php if ($is_archived): ?><span class="archive-status">Archived</span><?php endif; ?></h1><p class="page-intro">Relationships, activity, and upcoming engagements.</p></div><?php if (!$is_archived && in_array($user_role, ['admin', 'editor'], true)): ?><a href="<?php echo htmlspecialchars(recordUrlWithQuery('edit_organization.php?id=' . $org_id, ['return_to' => $record_view_url]), ENT_QUOTES, 'UTF-8'); ?>" class="button-secondary">Edit Organization</a><a href="#add-note" class="button-add">Add Chron Log Entry</a><?php endif; ?></div>

    <div class="record-related-summary">
        <div><span>Contacts</span><strong><a href="#organization-contacts"><?php echo count($organization_contacts); ?> people</a></strong><?php if ($organization_contacts !== []): ?><a href="view_contact.php?id=<?php echo (int) $organization_contacts[0]['id']; ?>&amp;return_to=<?php echo rawurlencode($record_view_url . '#organization-contacts'); ?>"><?php echo htmlspecialchars(trim($organization_contacts[0]['contact_first_name'] . ' ' . $organization_contacts[0]['contact_last_name'])); ?></a><?php endif; ?></div>
        <div><span>Next engagement</span><?php if ($next_organization_event): ?><strong><a href="view_engagement.php?id=<?php echo (int) $next_organization_event['id']; ?>&amp;return_to=<?php echo rawurlencode($record_view_url . '#organization-events'); ?>"><?php echo htmlspecialchars($next_organization_event['event_title'] ?: 'Upcoming engagement'); ?></a></strong><?php echo htmlspecialchars(engagementViewDateRange($next_organization_event['event_start_date'], $next_organization_event['event_end_date'])); ?><?php else: ?><strong>No upcoming engagement</strong><?php endif; ?></div>
        <div><span>Follow-up</span><strong><a href="#organization-tasks">Open tasks</a></strong><?php if ($record_can_add_note): ?><a href="#add-note">Record a conversation</a><?php else: ?><a href="#chron-log">Read activity</a><?php endif; ?></div>
    </div>
    <details class="record-form-section" open><summary>Profile, addresses, and notes</summary>
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

    </details>
    <div class="relationship-workspace" data-record-tabs>
        <div class="record-section-links" role="tablist" aria-label="Organization work">
            <button type="button" role="tab" id="organization-activity-tab" aria-controls="chron-log" aria-selected="true">Activity</button>
            <button type="button" role="tab" id="organization-contacts-tab" aria-controls="organization-contacts" aria-selected="false">Contacts</button>
            <button type="button" role="tab" id="organization-events-tab" aria-controls="organization-events" aria-selected="false">Engagements</button>
            <button type="button" role="tab" id="organization-tasks-tab" aria-controls="organization-tasks" aria-selected="false">Tasks</button>
            <button type="button" role="tab" id="organization-financials-tab" aria-controls="organization-financials" aria-selected="false">Financials</button>
        </div>
    <?php
    $chron_tab_id = 'organization-activity-tab';
    $chron_entity_label = 'organization';
    $chron_view_url = $record_view_url;
    $chron_restore_url = 'restore_entity_chron_entries.php?entity_type=organization&entity_id=' . $org_id;
    $chron_can_restore = !$is_archived;
    include 'templates/entity_chron_log_view_section.php';
    ?>

    <div class="contacts-section" id="organization-contacts" role="tabpanel" aria-labelledby="organization-contacts-tab">
        <div class="section-heading-row">
            <h3>Contacts</h3>
            <?php if (!$is_archived && in_array($user_role, ['admin', 'editor'], true)): ?>
                <a href="add_contact.php?organization_id=<?php echo $org_id; ?>&amp;return_to=<?php echo rawurlencode($record_view_url . '#organization-contacts'); ?>" class="button-add">+ New Contact</a>
            <?php endif; ?>
        </div>
        <?php if ($organization_contacts !== []): ?>
            <div class="organization-contact-grid">
            <?php foreach ($organization_contacts as $contact): ?>
                <div class="contact-card">
                    <div class="contact-header">
                        <h4 class="contact-name"><a href="view_contact.php?id=<?php echo (int) $contact['id']; ?>&amp;return_to=<?php echo rawurlencode($record_view_url . '#organization-contacts'); ?>"><?php echo htmlspecialchars(
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
                            <div><strong>Phone:</strong> <a href="tel:<?php echo htmlspecialchars($contact['contact_phone'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(formatPhoneNumberForDisplay($contact['contact_phone']), ENT_QUOTES, 'UTF-8'); ?></a></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p>No contacts found for this organization.</p>
        <?php endif; ?>
    </div>

    <section id="organization-events" role="tabpanel" aria-labelledby="organization-events-tab">
        <h2>Engagements <span><?php echo $organization_event_count; ?></span></h2>
        <p>Upcoming and historical engagements, including archived records.</p>
        <div class="responsive-table-wrapper"><table class="data-table relationship-events"><thead><tr><th scope="col">Engagement</th><th scope="col">Event dates</th><th scope="col">Lifecycle</th><th scope="col">Confirmation</th></tr></thead><tbody>
        <?php foreach ($organization_events as $event): ?>
            <tr><td><div class="relationship-event-title"><a href="<?php echo htmlspecialchars(recordUrlWithQuery('view_engagement.php?id=' . $event['id'], ['return_to' => recordUrlWithQuery($record_view_url, ['events_page' => $organization_event_page > 1 ? $organization_event_page : null]) . '#organization-events']), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($event['event_title'] ?: 'Untitled engagement', ENT_QUOTES, 'UTF-8'); ?></a><?php if ($event['is_deleted']): ?><span class="archive-status">Archived</span><?php endif; ?></div></td><td><?php echo htmlspecialchars(engagementViewDateRange($event['event_start_date'], $event['event_end_date'])); ?></td><td><?php echo htmlspecialchars(engagementLifecycleLabel($event['lifecycle_status'])); ?></td><td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $event['confirmation_status']))); ?></td></tr>
        <?php endforeach; ?>
        <?php if ($organization_events === []): ?><tr><td colspan="4">No engagements recorded</td></tr><?php endif; ?>
        </tbody></table></div>
        <?php if ($organization_event_pages > 1): ?><nav class="pagination" aria-label="Organization engagement pages"><span>Page <?php echo $organization_event_page; ?> of <?php echo $organization_event_pages; ?></span><div class="pagination-actions"><?php if ($organization_event_page > 1): ?><a href="<?php echo htmlspecialchars(recordUrlWithQuery($record_view_url, ['events_page' => $organization_event_page - 1]) . '#organization-events', ENT_QUOTES, 'UTF-8'); ?>">Previous</a><?php endif; ?><?php if ($organization_event_page < $organization_event_pages): ?><a href="<?php echo htmlspecialchars(recordUrlWithQuery($record_view_url, ['events_page' => $organization_event_page + 1]) . '#organization-events', ENT_QUOTES, 'UTF-8'); ?>">Next</a><?php endif; ?></div></nav><?php endif; ?>
    </section>
<div id="organization-tasks" role="tabpanel" aria-labelledby="organization-tasks-tab">    <?php
    $context_task_subject_type = 'organization';
    $context_task_subject_id = $org_id;
    $context_task_subject_active = !$is_archived;
    $context_task_return_to = recordUrlWithQuery($record_view_url, ['events_page' => $organization_event_page > 1 ? $organization_event_page : null]) . '#follow-up-work';
    include 'templates/follow_up_task_section.php';
    ?>

</div>    <section class="organization-financials" id="organization-financials" role="tabpanel" aria-labelledby="organization-financials-tab">
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
                    <a href="view_engagement.php?id=<?php echo (int) $financial_summary['last_event_id']; ?>&amp;return_to=<?php echo rawurlencode($record_view_url . '#organization-events'); ?>#financial-closeout"><?php echo htmlspecialchars((string) ($financial_summary['last_event_title'] ?: 'Most recent event'), ENT_QUOTES, 'UTF-8'); ?></a>
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
                                    <a href="view_engagement.php?id=<?php echo (int) $history_report['engagement_id']; ?>&amp;return_to=<?php echo rawurlencode($record_view_url . '#organization-events'); ?>#financial-closeout"><?php echo htmlspecialchars((string) ($history_report['event_title'] ?: 'Untitled event'), ENT_QUOTES, 'UTF-8'); ?></a>
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

</div>
    <div class="action-buttons">
        <a href="<?php echo htmlspecialchars($record_list_return, ENT_QUOTES, 'UTF-8'); ?>" class="action-button back-button">Back to <?php echo htmlspecialchars(recordReturnLabel($record_list_return), ENT_QUOTES, 'UTF-8'); ?></a>
    </div>
</div>
<?php renderScript('assets/js/record-workspace.min.js'); ?>
<?php include 'templates/footer.php'; ?>
</body>
</html>
