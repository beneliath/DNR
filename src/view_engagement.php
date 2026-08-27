<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/chron_log_helpers.php';
require_once __DIR__ . '/engagement_export_helpers.php';
require_once __DIR__ . '/engagement_contact_helpers.php';
require_once __DIR__ . '/engagement_lifecycle_helpers.php';
require_once __DIR__ . '/financial_report_helpers.php';
require_once __DIR__ . '/presentation_helpers.php';
require_once __DIR__ . '/follow_up_task_helpers.php';
startSecureSession();
requireLogin();

// Get user role from session
$user_role = $_SESSION['role'] ?? '';

$engagement_id = \Dnr\Http\RequestInput::positiveInt($_GET, 'id');
if ($engagement_id === null) {
    header("Location: engagements.php");
    exit();
}

// Fetch engagement details with organization name and contacts
$query = "SELECT e.*, COALESCE(caller.username, e.caller_name) AS caller_name,
                 o.organization_name, o.id as org_id
          FROM engagements e
          LEFT JOIN organizations o ON e.organization_id = o.id
          LEFT JOIN users caller ON caller.id = e.caller_user_id
          WHERE e.id = ?";

$stmt = $conn->prepare($query);
if ($stmt === false) abortApplication(503, 'The engagement details are temporarily unavailable.', ['error' => $conn->error]);

$stmt->bind_param("i", $engagement_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: engagements.php");
    exit();
}

$engagement = $result->fetch_assoc();
$is_archived = !empty($engagement['is_deleted']);

try {
    $financial_report = fetchEngagementFinancialReport($conn, $engagement_id);
} catch (Throwable $exception) {
    abortApplication(503, 'The engagement financial report is temporarily unavailable.', [
        'engagement_id' => $engagement_id,
        'error' => $exception->getMessage(),
    ]);
}
$financial_report_message = (string) ($_SESSION['financial_report_message'] ?? '');
unset($_SESSION['financial_report_message']);

try {
    $contacts = fetchEngagementContacts($conn, $engagement_id);
} catch (Throwable $exception) {
    abortApplication(503, 'The engagement contacts are temporarily unavailable.', [
        'engagement_id' => $engagement_id,
        'error' => $exception->getMessage(),
    ]);
}
try {
    $rescheduled_target = fetchEngagementRescheduleTarget($conn, $engagement_id);
    $rescheduled_sources = fetchEngagementRescheduleSources($conn, $engagement_id);
} catch (Throwable $exception) {
    abortApplication(503, 'The engagement lifecycle links are temporarily unavailable.', [
        'engagement_id' => $engagement_id,
        'error' => $exception->getMessage(),
    ]);
}
if ($rescheduled_target !== null) {
    $engagement['rescheduled_event_label'] = engagementReferenceLabel($rescheduled_target);
}
$financial_closeout_applicable = !in_array(
    (string) ($engagement['lifecycle_status'] ?? 'active'),
    ['postponed', 'canceled'],
    true
);
$engagement_marker = applicationInboundMarker($engagement_id);

// Fetch presentations associated with this engagement.
$presentation_stmt = $conn->prepare(
    "SELECT id, topic_title, presentation_date, presentation_time, speaker_name, expected_attendance,
            slide_deck_pdf IS NOT NULL AS has_slide_deck, slide_deck_filename,
            speaker_notes_qr_image IS NOT NULL AS has_speaker_notes_qr,
            speaker_website_qr_image IS NOT NULL AS has_speaker_website_qr,
            speaker_donation_qr_image IS NOT NULL AS has_speaker_donation_qr
     FROM presentations
     WHERE engagement_id = ? AND is_archived = 0
     ORDER BY presentation_date, presentation_time, id"
);
if ($presentation_stmt === false) abortApplication(503, 'The engagement presentations are temporarily unavailable.', ['error' => $conn->error]);
$presentation_stmt->bind_param("i", $engagement_id);
$presentation_stmt->execute();
$presentations_result = $presentation_stmt->get_result();
$presentations = $presentations_result->fetch_all(MYSQLI_ASSOC);

try {
    $chron_page_size = 50;
    $chron_entry_count = countActiveChronLogEntries($conn, $engagement_id);
    $chron_total_pages = max(1, (int) ceil($chron_entry_count / $chron_page_size));
    $chron_page = min(
        filter_input(INPUT_GET, 'chron_page', FILTER_VALIDATE_INT) ?: 1,
        $chron_total_pages
    );
    $chron_entries = fetchChronLogEntries(
        $conn,
        $engagement_id,
        false,
        $chron_page_size,
        ($chron_page - 1) * $chron_page_size
    );
    $archived_chron_count = (!$is_archived && canArchiveEntries($user_role))
        ? countArchivedChronLogEntries($conn, $engagement_id)
        : 0;
    $archived_presentation_count = (!$is_archived && canArchiveEntries($user_role))
        ? countArchivedEngagementPresentations($conn, $engagement_id)
        : 0;
} catch (Throwable $exception) {
    http_response_code(503);
    exit('The engagement details are temporarily unavailable while ' . applicationBrandName() . ' is being upgraded.');
}

$event_address_parts = [];
foreach (['event_address_line_1', 'event_address_line_2'] as $address_field) {
    if (!empty($engagement[$address_field])) {
        $event_address_parts[] = $engagement[$address_field];
    }
}
$event_city_line = trim(implode(', ', array_filter([
    $engagement['event_city'] ?? '',
    $engagement['event_state'] ?? ''
])));
if (!empty($engagement['event_zipcode'])) {
    $event_city_line = trim($event_city_line . ' ' . $engagement['event_zipcode']);
}
if ($event_city_line !== '') {
    $event_address_parts[] = $event_city_line;
}
if (!empty($engagement['event_country'])) {
    $event_address_parts[] = $engagement['event_country'];
}

$engagement_export = buildEngagementExport($engagement, $contacts, $presentations, $chron_entries);
$engagement_plain_text = renderEngagementPlainText($engagement_export);
$engagement_markdown = renderEngagementMarkdown($engagement_export);

// Close statements
$stmt->close();
$presentation_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('View Engagement'), array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
    2 => 'assets/css/pages/view_engagement.min.css',
    3 => 'assets/css/pages/engagement_contacts.min.css',
    4 => 'assets/css/pages/engagement_lifecycle.min.css',
  ),
)); ?>
<body>
<?php include 'templates/header.php'; ?>
<div class="view-container">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="engagements.php<?php echo $is_archived ? '?status=archived' : ''; ?>">Engagements</a><span aria-hidden="true">/</span><span>Engagement Details</span></nav>
    <div class="page-heading record-page-heading">
        <?php $event_type_label = $engagement['event_type'] === 'other' && !empty($engagement['event_type_other']) ? $engagement['event_type_other'] : $engagement['event_type']; ?>
        <div><h1><?php echo htmlspecialchars($engagement['event_title'] ?: $engagement['organization_name']); ?><?php if ($is_archived): ?><span class="archive-status">Archived</span><?php endif; ?> <span class="lifecycle-badge lifecycle-<?php echo htmlspecialchars((string) ($engagement['lifecycle_status'] ?? 'active'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(engagementLifecycleLabel($engagement['lifecycle_status'] ?? 'active'), ENT_QUOTES, 'UTF-8'); ?></span></h1><p class="page-intro"><?php echo htmlspecialchars($engagement['organization_name']); ?> · <?php echo htmlspecialchars(ucwords($event_type_label)); ?></p></div>
        <?php if (!$is_archived && ($user_role === 'admin' || $user_role === 'editor')): ?><a href="edit_engagement.php?id=<?php echo $engagement_id; ?>" class="button-add">Edit engagement</a><?php endif; ?>
    </div>

    <?php if ($financial_report_message !== ''): ?>
        <p class="success" role="status"><?php echo htmlspecialchars($financial_report_message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <div class="detail-group">
        <?php if (!empty($engagement['event_title'])): ?>
        <div class="detail-label">Event Title</div>
        <div class="detail-value"><?php echo htmlspecialchars($engagement['event_title']); ?></div>
        <?php endif; ?>

        <div class="detail-label">Email Subject Marker</div>
        <div class="detail-value engagement-email-marker">
            <span class="engagement-email-marker-control">
                <code><?php echo htmlspecialchars($engagement_marker, ENT_QUOTES, 'UTF-8'); ?></code>
                <button
                    type="button"
                    class="action-icon-button engagement-marker-copy"
                    data-copy-text="<?php echo htmlspecialchars($engagement_marker, ENT_QUOTES, 'UTF-8'); ?>"
                    data-copy-status="engagement-marker-copy-status"
                    data-tooltip="Copy marker"
                    aria-label="Copy email subject marker"
                    title="Copy email subject marker"
                >
                    <svg class="action-icon engagement-marker-copy-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><rect x="8" y="8" width="11" height="11" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg>
                    <svg class="action-icon engagement-marker-copied-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg>
                </button>
            </span>
            <span class="engagement-email-marker-help">Keep this marker in an email subject to route the message to this Engagement’s Chron log.</span>
            <span id="engagement-marker-copy-status" class="visually-hidden" role="status" aria-live="polite"></span>
        </div>

        <?php if (!empty($engagement['event_description'])): ?>
        <div class="detail-label">Event Description</div>
        <div class="detail-value"><?php echo nl2br(htmlspecialchars($engagement['event_description'])); ?></div>
        <?php endif; ?>

        <div class="detail-label">Organization</div>
        <div class="detail-value"><?php echo htmlspecialchars($engagement['organization_name']); ?></div>

        <div class="detail-label">Event Contacts</div>
        <div class="detail-value contacts-list">
            <?php if ($contacts): ?>
                <?php foreach ($contacts as $contact): ?>
            <div class="contact-item">
                <div><strong><a href="view_contact.php?id=<?php echo (int) $contact['id']; ?>"><?php echo htmlspecialchars(
                    trim($contact['contact_first_name'] . ' ' . $contact['contact_last_name']),
                    ENT_QUOTES,
                    'UTF-8'
                ); ?></a></strong></div>
                <div class="event-contact-roles" aria-label="Event roles">
                    <?php foreach ((array) ($contact['engagement_contact_roles'] ?? []) as $event_contact_role): ?>
                        <span><?php echo htmlspecialchars(engagementContactRoleLabel($event_contact_role), ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($contact['contact_role'])): ?>
                <div class="contact-title">
                    Organization role: <?php echo htmlspecialchars(organizationContactRoleLabel($contact), ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($contact['contact_email'])): ?>
                <div>Email: <a href="mailto:<?php echo htmlspecialchars($contact['contact_email']); ?>"><?php echo htmlspecialchars($contact['contact_email']); ?></a></div>
                <?php endif; ?>
                <?php if (!empty($contact['contact_phone'])): ?>
                <div>Phone: <?php echo htmlspecialchars(formatPhoneNumberForDisplay($contact['contact_phone']), ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
            </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="engagement-contacts-empty">No event contacts have been assigned.</p>
            <?php endif; ?>
        </div>

        <div class="detail-label">Event Type</div>
        <div class="detail-value"><?php echo htmlspecialchars($event_type_label); ?></div>

        <div class="detail-label">Event Dates</div>
        <div class="detail-value">
            <?php echo htmlspecialchars($engagement['event_start_date'] . ' to ' . $engagement['event_end_date']); ?>
        </div>
    </div>

    <div class="detail-group">
        <div class="detail-label">Lifecycle</div>
        <div class="detail-value">
            <span class="lifecycle-badge lifecycle-<?php echo htmlspecialchars((string) ($engagement['lifecycle_status'] ?? 'active'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(engagementLifecycleLabel($engagement['lifecycle_status'] ?? 'active'), ENT_QUOTES, 'UTF-8'); ?></span>
            <?php if (!empty($engagement['cancellation_reason'])): ?>
                <p class="lifecycle-reason"><strong>Cancellation reason:</strong> <?php echo htmlspecialchars((string) $engagement['cancellation_reason'], ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <?php if ($rescheduled_target !== null || $rescheduled_sources !== []): ?>
                <div class="lifecycle-links">
                    <?php if ($rescheduled_target !== null): ?>
                        <span><strong>Rescheduled as:</strong> <a href="view_engagement.php?id=<?php echo (int) $rescheduled_target['id']; ?>"><?php echo htmlspecialchars(engagementReferenceLabel($rescheduled_target), ENT_QUOTES, 'UTF-8'); ?></a></span>
                    <?php endif; ?>
                    <?php foreach ($rescheduled_sources as $rescheduled_source): ?>
                        <span><strong>Rescheduled from:</strong> <a href="view_engagement.php?id=<?php echo (int) $rescheduled_source['id']; ?>"><?php echo htmlspecialchars(engagementReferenceLabel($rescheduled_source), ENT_QUOTES, 'UTF-8'); ?></a></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="detail-label">Confirmation</div>
        <div class="detail-value">
            <?php
            $status = $engagement['confirmation_status'];
            $status_class = 'status-' . str_replace('_', '-', $status);
            $display_status = str_replace('_', ' ', $status);
            echo "<span class='{$status_class}'>" . htmlspecialchars($display_status) . "</span>";
            ?>
        </div>
    </div>

    <div class="detail-group">
        <div class="detail-label">Event Details</div>
        <div class="detail-value">
            <div><strong>Book Table Provided:</strong> <?php echo !empty($engagement['book_table']) ? 'Yes' : 'No'; ?></div>
            <div><strong>Brochures Permitted:</strong> <?php echo !empty($engagement['brochures']) ? 'Yes' : 'No'; ?></div>
            <div><strong>All Travel Covered:</strong> <?php echo htmlspecialchars(ucfirst($engagement['travel_covered'] ?? 'unknown')); ?></div>
            <?php if (!empty($engagement['caller_name'])): ?>
            <div><strong>Caller:</strong> <?php echo htmlspecialchars($engagement['caller_name']); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($presentations || $archived_presentation_count > 0): ?>
    <div class="detail-group">
        <div class="detail-label">Presentation(s)</div>
        <div class="detail-value">
            <?php foreach ($presentations as $presentation): ?>
            <div class="presentation-item">
                <strong><?php echo htmlspecialchars($presentation['topic_title']); ?></strong>
                <?php if (!empty($presentation['speaker_name'])): ?>
                <div>Speaker: <?php echo htmlspecialchars($presentation['speaker_name']); ?></div>
                <?php endif; ?>
                <?php if (!empty($presentation['presentation_date']) || !empty($presentation['presentation_time'])): ?>
                <div>
                    <?php echo htmlspecialchars(trim(($presentation['presentation_date'] ?? '') . ' ' . formatPresentationTime($presentation['presentation_time'] ?? ''))); ?>
                </div>
                <?php endif; ?>
                <?php if ($presentation['expected_attendance'] !== null): ?>
                <div>Expected attendance: <?php echo (int) $presentation['expected_attendance']; ?></div>
                <?php endif; ?>
                <?php
                $presentation_has_qr = !empty($presentation['has_speaker_notes_qr'])
                    || !empty($presentation['has_speaker_website_qr'])
                    || !empty($presentation['has_speaker_donation_qr']);
                ?>
                <?php if (!empty($presentation['has_slide_deck']) || $presentation_has_qr): ?>
                    <div class="presentation-view-assets">
                        <?php if (!empty($presentation['has_slide_deck'])): ?>
                            <?php $slide_url = 'presentation_asset.php?id=' . (int) $presentation['id'] . '&type=slides'; ?>
                            <a href="<?php echo htmlspecialchars($slide_url, ENT_QUOTES, 'UTF-8'); ?>"
                               target="_blank"
                               rel="noopener"
                               class="presentation-view-pdf">
                                View PDF slide deck<?php if (!empty($presentation['slide_deck_filename'])): ?>:
                                    <?php echo htmlspecialchars((string) $presentation['slide_deck_filename'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php endif; ?>
                            </a>
                        <?php endif; ?>
                        <?php if ($presentation_has_qr): ?>
                            <div class="presentation-view-qr-grid">
                                <?php
                                $view_qr_codes = [
                                    ['has_speaker_notes_qr', 'notes_qr', 'Speaker Notes'],
                                    ['has_speaker_website_qr', 'website_qr', 'Speaker Website'],
                                    ['has_speaker_donation_qr', 'donation_qr', 'Speaker Donations'],
                                ];
                                ?>
                                <?php foreach ($view_qr_codes as [$has_key, $query_type, $label]): ?>
                                    <?php if (!empty($presentation[$has_key])): ?>
                                        <?php $qr_url = 'presentation_asset.php?id=' . (int) $presentation['id'] . '&type=' . $query_type; ?>
                                        <div class="presentation-qr-display">
                                            <div class="presentation-view-asset-label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></div>
                                            <button type="button"
                                                    class="presentation-view-qr-button"
                                                    data-copy-qr-url="<?php echo htmlspecialchars($qr_url, ENT_QUOTES, 'UTF-8'); ?>"
                                                    aria-label="Copy <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?> QR code">
                                                <img src="<?php echo htmlspecialchars($qr_url, ENT_QUOTES, 'UTF-8'); ?>"
                                                     alt="<?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?> QR code">
                                                <span>Click to copy</span>
                                            </button>
                                            <span class="presentation-qr-status" data-copy-status role="status" aria-live="polite"></span>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php if (!$presentations): ?>
                <p>No active presentations.</p>
            <?php endif; ?>
            <?php if ($archived_presentation_count > 0): ?>
                <div class="form-row">
                    <a href="restore_presentations.php?engagement_id=<?php echo $engagement_id; ?>" class="restore-button">Restore Archived Presentations (<?php echo $archived_presentation_count; ?>)</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($engagement['compensation_type'])): ?>
    <div class="detail-group">
        <div class="detail-label">Compensation</div>
        <div class="detail-value">
            <div><strong>Type:</strong> <?php echo htmlspecialchars($engagement['compensation_type']); ?></div>
            <?php if (!empty($engagement['other_compensation'])): ?>
            <div><strong>Details:</strong> <?php echo htmlspecialchars($engagement['other_compensation']); ?></div>
            <?php endif; ?>
            <?php if ($engagement['travel_amount'] !== null): ?>
            <div><strong>Travel Amount:</strong> $<?php echo number_format((float) $engagement['travel_amount'], 2); ?></div>
            <?php endif; ?>
            <?php if ($engagement['housing_amount'] !== null): ?>
            <div><strong>Lodging Amount:</strong> $<?php echo number_format((float) $engagement['housing_amount'], 2); ?></div>
            <?php endif; ?>
            <?php if (!empty($engagement['housing_type'])): ?>
            <div><strong>Lodging Type:</strong> <?php echo htmlspecialchars($engagement['housing_type']); ?></div>
            <?php endif; ?>
            <?php if (!empty($engagement['other_housing'])): ?>
            <div><strong>Lodging Details:</strong> <?php echo htmlspecialchars($engagement['other_housing']); ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="detail-group financial-closeout" id="financial-closeout">
        <div class="financial-closeout-heading">
            <div>
                <div class="detail-label">Financial Closeout</div>
                <p>Actual receipts recorded after the event; planning estimates above remain unchanged.</p>
            </div>
            <span class="financial-status <?php echo $financial_report ? 'is-finalized' : ($financial_closeout_applicable ? 'is-open' : 'is-not-applicable'); ?>">
                <?php echo $financial_report ? 'Finalized' : ($financial_closeout_applicable ? 'Open' : 'Not applicable'); ?>
            </span>
        </div>

        <?php if ($financial_report): ?>
            <div class="financial-amount-grid">
                <div><small>Giving / income</small><strong><?php echo formatFinancialAmount($financial_report['giving_income_received']); ?></strong></div>
                <div><small>Lodging received</small><strong><?php echo formatFinancialAmount($financial_report['lodging_received']); ?></strong></div>
                <div><small>Travel received</small><strong><?php echo formatFinancialAmount($financial_report['travel_received']); ?></strong></div>
                <div class="financial-total"><small>Total received</small><strong><?php echo formatFinancialAmount(financialReportTotal($financial_report)); ?></strong></div>
            </div>
            <?php
            $closed_timestamp = chronLogTimestampDetails($financial_report['closed_at']);
            $was_corrected = (string) $financial_report['updated_at'] !== (string) $financial_report['closed_at'];
            $updated_timestamp = $was_corrected
                ? chronLogTimestampDetails($financial_report['updated_at'])
                : null;
            ?>
            <p class="financial-meta">
                Finalized <time datetime="<?php echo htmlspecialchars($closed_timestamp['iso'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($closed_timestamp['display'], ENT_QUOTES, 'UTF-8'); ?></time>
                <?php if (!empty($financial_report['closed_by_username'])): ?>
                    by <?php echo htmlspecialchars((string) $financial_report['closed_by_username'], ENT_QUOTES, 'UTF-8'); ?>
                <?php endif; ?>.
                <?php if ($updated_timestamp !== null): ?>
                    Last corrected <time datetime="<?php echo htmlspecialchars($updated_timestamp['iso'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($updated_timestamp['display'], ENT_QUOTES, 'UTF-8'); ?></time><?php if (!empty($financial_report['updated_by_username'])): ?> by <?php echo htmlspecialchars((string) $financial_report['updated_by_username'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>.
                <?php endif; ?>
            </p>
            <?php if (!empty($financial_report['notes'])): ?>
                <div class="financial-notes"><strong>Closeout notes</strong><p><?php echo nl2br(htmlspecialchars((string) $financial_report['notes'], ENT_QUOTES, 'UTF-8')); ?></p></div>
            <?php endif; ?>
            <?php if (!$is_archived && in_array($user_role, ['admin', 'editor'], true)): ?>
                <a href="close_engagement.php?id=<?php echo $engagement_id; ?>" class="action-button edit-button">Correct final report</a>
            <?php endif; ?>
        <?php elseif ($financial_closeout_applicable): ?>
            <p class="financial-empty">No actual received amounts have been finalized for this event.</p>
            <?php if (!$is_archived && in_array($user_role, ['admin', 'editor'], true)): ?>
                <a href="close_engagement.php?id=<?php echo $engagement_id; ?>" class="action-button save-button">Close out event</a>
            <?php endif; ?>
        <?php else: ?>
            <p class="financial-empty">Financial closeout is unavailable while this engagement is <?php echo htmlspecialchars(strtolower(engagementLifecycleLabel($engagement['lifecycle_status'] ?? 'active')), ENT_QUOTES, 'UTF-8'); ?>.</p>
        <?php endif; ?>
    </div>

    <?php if (!empty($event_address_parts)): ?>
    <div class="detail-group">
        <div class="detail-label">Location</div>
        <div class="detail-value">
            <?php echo implode('<br>', array_map('htmlspecialchars', $event_address_parts)); ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="detail-group chron-log-section" id="chron-log">
        <div class="chron-log-heading">
            <div>
                <div class="detail-label">Chron Log</div>
                <p>Entries are shown newest first.</p>
            </div>
            <?php if ($archived_chron_count > 0): ?>
                <a href="restore_chron_entries.php?engagement_id=<?php echo $engagement_id; ?>" class="restore-button">Restore archived entries (<?php echo $archived_chron_count; ?>)</a>
            <?php endif; ?>
        </div>

        <div class="chron-entry-list">
            <?php foreach ($chron_entries as $chron_entry): ?>
                <?php
                $created_timestamp = chronLogTimestampDetails($chron_entry['created_at']);
                $updated_timestamp = chronLogTimestampDetails($chron_entry['updated_at']);
                $entry_author = $chron_entry['created_by_username']
                    ?: (!empty($chron_entry['legacy_engagement_note']) ? 'Migrated legacy note' : 'System');
                $was_edited = (string) $chron_entry['updated_at'] !== (string) $chron_entry['created_at'];
                ?>
                <article class="chron-entry-card">
                    <div class="chron-entry-meta">
                        <div>
                            <time datetime="<?php echo htmlspecialchars($created_timestamp['iso']); ?>"><?php echo htmlspecialchars($created_timestamp['display']); ?></time>
                            <span>by <?php echo htmlspecialchars($entry_author); ?></span>
                        </div>
                        <?php if ($was_edited): ?>
                            <small>Last updated <time datetime="<?php echo htmlspecialchars($updated_timestamp['iso']); ?>"><?php echo htmlspecialchars($updated_timestamp['display']); ?></time><?php if (!empty($chron_entry['updated_by_username'])): ?> by <?php echo htmlspecialchars($chron_entry['updated_by_username']); ?><?php endif; ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="chron-entry-text"><?php echo nl2br(htmlspecialchars($chron_entry['entry_text'])); ?></div>
                </article>
            <?php endforeach; ?>
            <?php if (!$chron_entries): ?>
                <p class="chron-empty-state">No Chron entries have been added yet.</p>
            <?php endif; ?>
        </div>
        <?php if ($chron_total_pages > 1): ?>
            <nav class="pagination" aria-label="Chron log pages">
                <span>Page <?php echo $chron_page; ?> of <?php echo $chron_total_pages; ?> · <?php echo $chron_entry_count; ?> entries</span>
                <div class="pagination-actions">
                    <?php if ($chron_page > 1): ?><a href="view_engagement.php?id=<?php echo $engagement_id; ?>&amp;chron_page=<?php echo $chron_page - 1; ?>#chron-log">Newer</a><?php endif; ?>
                    <?php if ($chron_page < $chron_total_pages): ?><a href="view_engagement.php?id=<?php echo $engagement_id; ?>&amp;chron_page=<?php echo $chron_page + 1; ?>#chron-log">Older</a><?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>
    </div>

    <div class="action-buttons">
        <div class="primary-actions">
            <a href="engagements.php<?php echo $is_archived ? '?status=archived' : ''; ?>" class="action-button back-button">Back to List</a>
        </div>
        <div class="export-actions" aria-label="Export engagement">
            <button type="button" class="action-button export-button" data-copy-format="text">Copy Text</button>
            <button type="button" class="action-button export-button" data-copy-format="markdown">Copy MD</button>
            <a href="download_engagement_pdf.php?id=<?php echo $engagement_id; ?>" class="action-button export-button">Download PDF</a>
        </div>
        <span id="copy-status" class="visually-hidden" role="status" aria-live="polite"></span>
    </div>

    <?php
    $context_task_subject_type = 'engagement';
    $context_task_subject_id = $engagement_id;
    $context_task_subject_active = !$is_archived
        && (string) ($engagement['lifecycle_status'] ?? 'active') !== 'canceled';
    $context_task_allow_checklist = !$is_archived
        && (string) ($engagement['lifecycle_status'] ?? 'active') === 'active';
    $context_task_return_to = 'view_engagement.php?id=' . $engagement_id . '#follow-up-work';
    include 'templates/follow_up_task_section.php';
    ?>
</div>
<script nonce="<?php echo htmlspecialchars(contentSecurityPolicyNonce(), ENT_QUOTES, 'UTF-8'); ?>" type="application/json" id="engagement-export-data"><?php echo json_encode([
        'text' => $engagement_plain_text,
        'markdown' => $engagement_markdown,
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<?php include 'templates/footer.php'; ?>
</body>
</html>
