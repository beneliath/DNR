<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/record_workspace_helpers.php';
require_once __DIR__ . '/chron_log_helpers.php';
require_once __DIR__ . '/engagement_export_helpers.php';
require_once __DIR__ . '/engagement_contact_helpers.php';
require_once __DIR__ . '/engagement_lifecycle_helpers.php';
require_once __DIR__ . '/financial_report_helpers.php';
require_once __DIR__ . '/presentation_helpers.php';
require_once __DIR__ . '/follow_up_task_helpers.php';
require_once __DIR__ . '/engagement_email_helpers.php';
require_once __DIR__ . '/engagement_view_helpers.php';
require_once __DIR__ . '/two_factor_helpers.php';
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
$record_list_return = safeRecordReturnUrl($_GET['return_to'] ?? null, 'engagements.php' . ($is_archived ? '?status=archived' : ''));
$record_view_url = 'view_engagement.php?' . http_build_query(['id' => $engagement_id, 'return_to' => $record_list_return]);
$record_note_url = $record_view_url;
$record_note_entity = 'engagement';
$record_can_add_note = !$is_archived && in_array($user_role, ['admin', 'editor'], true);
$record_note_error = handleRecordAddNote($conn, 'engagement', (int) $engagement_id, $record_view_url);
$record_note_message = (string) ($_SESSION['record_note_message'] ?? '');
unset($_SESSION['record_note_message']);

$can_manage_engagement = !$is_archived && in_array($user_role, ['admin', 'editor'], true);
$chron_view_query = ['id' => $engagement_id, 'return_to' => $record_list_return];
$requested_chron_page = \Dnr\Http\RequestInput::positiveInt($_GET, 'chron_page');
if ($requested_chron_page !== null && $requested_chron_page > 1) {
    $chron_view_query['chron_page'] = $requested_chron_page;
}
$chron_view_url = 'view_engagement.php?' . http_build_query($chron_view_query);
$chron_edit_id = $can_manage_engagement
    ? \Dnr\Http\RequestInput::positiveInt($_GET, 'edit_chron') : null;
$chron_action_error = '';
$chron_edit_draft = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'add_note') {
    requireValidCsrfToken();
    if (!$can_manage_engagement) {
        http_response_code(403);
        exit('Forbidden.');
    }
    $chron_action = is_scalar($_POST['action'] ?? null) ? (string) $_POST['action'] : '';
    if ($chron_action === 'delete_chron') {
        if ($user_role !== 'admin') {
            http_response_code(403);
            exit('Forbidden.');
        }
        requireRecentAdminElevation($chron_view_url . '#chron-log');
    }
    $chron_entry_id = \Dnr\Http\RequestInput::positiveInt($_POST, 'chron_entry_id');
    $chron_transaction_started = false;
    try {
        if ($chron_entry_id === null
            || !in_array($chron_action, ['edit_chron', 'archive_chron', 'delete_chron'], true)
        ) {
            throw new InvalidArgumentException('Select a valid Chron Log Entry action.');
        }
        $conn->begin_transaction();
        $chron_transaction_started = true;
        $chron_parent_stmt = $conn->prepare('SELECT is_deleted FROM engagements WHERE id = ? FOR UPDATE');
        $chron_parent_stmt->bind_param('i', $engagement_id);
        $chron_parent_stmt->execute();
        $chron_parent = $chron_parent_stmt->get_result()->fetch_assoc();
        $chron_parent_stmt->close();
        if (!$chron_parent || !empty($chron_parent['is_deleted'])) {
            $is_archived = true;
            $engagement['is_deleted'] = 1;
            $can_manage_engagement = false;
            throw new InvalidArgumentException('Archived engagements cannot be changed.');
        }
        if ($chron_action === 'edit_chron') {
            $chron_text = normalizeChronLogEntryText($_POST['chron_entry'] ?? null);
            $chron_versions = normalizeSubmittedChronLogVersions([
                $chron_entry_id => $_POST['chron_entry_version'] ?? null,
            ]);
            updateEntityChronLogEntries(
                $conn, 'engagement', $engagement_id,
                [$chron_entry_id => $chron_text], $chron_versions, (int) $_SESSION['user_id']
            );
            $chron_message = 'Chron Log Entry updated.';
        } elseif ($chron_action === 'archive_chron') {
            archiveEntityChronLogEntry($conn, 'engagement', $engagement_id, $chron_entry_id, (int) $_SESSION['user_id']);
            $chron_message = 'Chron Log Entry archived.';
        } else {
            deleteEntityChronLogEntry($conn, 'engagement', $engagement_id, $chron_entry_id);
            $chron_message = 'Chron Log Entry permanently deleted.';
        }
        $conn->commit();
        $_SESSION['engagement_action_message'] = $chron_message;
        header('Location: ' . $chron_view_url . '#chron-log');
        exit();
    } catch (Throwable $exception) {
        if ($chron_transaction_started) {
            $conn->rollback();
        }
        $chron_action_error = $exception instanceof InvalidArgumentException
            ? $exception->getMessage() : 'Unable to update the Chron Log Entry. Please try again.';
        if ($chron_action === 'edit_chron' && $chron_entry_id !== null) {
            $chron_edit_id = $chron_entry_id;
            $chron_edit_draft = [
                'text' => is_scalar($_POST['chron_entry'] ?? null) ? (string) $_POST['chron_entry'] : '',
                'version' => is_scalar($_POST['chron_entry_version'] ?? null) ? (string) $_POST['chron_entry_version'] : '',
            ];
        }
    }
}
$source_inquiry_stmt = $conn->prepare(
    'SELECT id, title FROM booking_inquiries WHERE converted_engagement_id = ? LIMIT 1'
);
$source_inquiry_stmt->bind_param('i', $engagement_id);
$source_inquiry_stmt->execute();
$source_inquiry = $source_inquiry_stmt->get_result()->fetch_assoc() ?: null;
$source_inquiry_stmt->close();

try {
    $financial_report = fetchEngagementFinancialReport($conn, $engagement_id);
    $financial_draft = $financial_report === null ? fetchEngagementFinancialDraft($conn, $engagement_id) : null;
} catch (Throwable $exception) {
    abortApplication(503, 'The engagement financial report is temporarily unavailable.', [
        'engagement_id' => $engagement_id,
        'error' => $exception->getMessage(),
    ]);
}
$financial_report_message = (string) ($_SESSION['financial_report_message'] ?? '');
$engagement_action_message = (string) ($_SESSION['engagement_action_message'] ?? '');
unset($_SESSION['financial_report_message'], $_SESSION['engagement_action_message']);

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
    "SELECT id, topic_title, presentation_date, presentation_time, speaker_name, duration_minutes,
            expected_attendance, actual_attendance,
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
    $engagement_email_messages = fetchEngagementEmailMessages($conn, $engagement_id, 10);
} catch (Throwable $exception) {
    abortApplication(503, 'The engagement correspondence is temporarily unavailable.', [
        'engagement_id' => $engagement_id,
        'error' => $exception->getMessage(),
    ]);
}

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

$chron_edit_entry_found = false;
if ($can_manage_engagement && $chron_edit_id !== null) {
    foreach ($chron_entries as $chron_entry) {
        if ((int) $chron_entry['id'] === $chron_edit_id) {
            $chron_edit_entry_found = true;
            break;
        }
    }
    if (!$chron_edit_entry_found && $chron_action_error === '') {
        $chron_action_error = 'This Chron Log Entry is no longer available on this page.';
    }
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

$engagement_progress = engagementViewProgress($engagement);
$engagement_readiness = engagementViewReadiness($engagement, $contacts, $presentations);
$event_type_label = $engagement['event_type'] === 'other' && !empty($engagement['event_type_other'])
    ? $engagement['event_type_other'] : $engagement['event_type'];
$confirmation_label = \Dnr\Domain\ReferenceData::label((string) $engagement['confirmation_status']);
$engagement_title = (string) ($engagement['event_title'] ?: $engagement['organization_name']);

// Render the existing task controls once and reuse their loaded tasks in the sidebar.
$context_task_subject_type = 'engagement';
$context_task_subject_id = $engagement_id;
$context_task_subject_active = !$is_archived
    && (string) ($engagement['lifecycle_status'] ?? 'active') !== 'canceled';
$context_task_allow_checklist = !$is_archived
    && (string) ($engagement['lifecycle_status'] ?? 'active') === 'active';
$context_task_return_to = $record_view_url . '#follow-up-work';

ob_start();
try {
    include 'templates/follow_up_task_section.php';
    $engagement_task_html = (string) ob_get_contents();
} finally {
    ob_end_clean();
}
$next_task = $context_tasks[0] ?? null;
$next_task_edit_url = $next_task === null ? '' : 'edit_task.php?' . http_build_query([
    'id' => (int) $next_task['id'],
    'return_to' => $context_task_return_to,
]);
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('View Engagement'), ['styles' => [
    'assets/css/style.min.css',
    'assets/css/modern.min.css',
    'assets/css/pages/record_workspace.min.css',
    'assets/css/pages/engagement_contacts.min.css',
    'assets/css/pages/engagement_lifecycle.min.css',
    'assets/css/pages/engagement_email.min.css',
    'assets/css/pages/booking_inquiries.min.css',
    'assets/css/pages/view_engagement.min.css',
]]); ?>
<body class="view-engagement-body">
<?php include 'templates/header.php'; ?>
<main class="view-container view-engagement-page">
    <?php if ($record_note_message !== ''): ?><p class="success" role="status"><?php echo htmlspecialchars($record_note_message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?php echo htmlspecialchars($record_list_return, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(recordReturnLabel($record_list_return), ENT_QUOTES, 'UTF-8'); ?></a><span aria-hidden="true">/</span><span><?php echo htmlspecialchars($engagement_title); ?></span></nav>
    <header class="page-heading record-page-heading view-engagement-heading">
        <div>
            <h1><?php echo htmlspecialchars($engagement_title); ?></h1>
            <p class="engagement-title-meta">
                <a href="view_organization.php?id=<?php echo (int) $engagement['org_id']; ?>"><?php echo htmlspecialchars($engagement['organization_name']); ?></a>
                <span class="status-<?php echo htmlspecialchars(str_replace('_', '-', (string) $engagement['confirmation_status']), ENT_QUOTES, 'UTF-8'); ?>">Confirmation: <?php echo htmlspecialchars($confirmation_label); ?></span>
                <span class="lifecycle-badge lifecycle-<?php echo htmlspecialchars((string) ($engagement['lifecycle_status'] ?? 'active'), ENT_QUOTES, 'UTF-8'); ?>">Lifecycle: <?php echo htmlspecialchars(engagementLifecycleLabel($engagement['lifecycle_status'] ?? 'active')); ?></span>
                <?php if ($is_archived): ?><span class="archive-status">Archived</span><?php endif; ?>
            </p>
        </div>
        <?php if ($can_manage_engagement): ?>
            <div class="page-heading-actions">
                <a href="#add-note" class="button-add">Add Chron Log Entry</a>
                <a href="compose_engagement_email.php?id=<?php echo $engagement_id; ?>" class="button-secondary">Send Email</a>
                <a href="<?php echo htmlspecialchars(recordUrlWithQuery('edit_engagement.php?id=' . $engagement_id, ['return_to' => $record_view_url]), ENT_QUOTES, 'UTF-8'); ?>" class="button-secondary">Edit Engagement</a>
            </div>
        <?php endif; ?>
    </header>
    <?php if ($financial_report_message !== ''): ?><p class="success" role="status"><?php echo htmlspecialchars($financial_report_message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($engagement_action_message !== ''): ?><p class="success" role="status"><?php echo htmlspecialchars($engagement_action_message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($chron_action_error !== ''): ?><p class="error" role="alert"><?php echo htmlspecialchars($chron_action_error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($source_inquiry): ?><div class="inquiry-terminal-banner inquiry-booked-banner"><div><strong>Booked from Inquiry #<?php echo (int) $source_inquiry['id']; ?></strong><span>The pre-booking history remains on the read-only source record.</span></div><a href="view_inquiry.php?id=<?php echo (int) $source_inquiry['id']; ?>" class="button-secondary">Open Source Inquiry</a></div><?php endif; ?>

    <section class="engagement-card engagement-compact-overview" id="engagement-overview" aria-label="Engagement overview">
        <dl class="engagement-overview-facts">
            <div><dt>Event dates</dt><dd><?php echo htmlspecialchars(engagementViewDateRange($engagement['event_start_date'], $engagement['event_end_date'])); ?></dd></div>
            <div><dt>Event type</dt><dd><?php echo htmlspecialchars(ucwords($event_type_label)); ?></dd></div>
            <div><dt>Event contacts</dt><dd><a href="#engagement-contacts"><?php echo count($contacts); ?> assigned</a></dd></div>
            <div><dt>Presentations</dt><dd><a href="#engagement-presentations"><?php echo count($presentations); ?> added</a></dd></div>
        </dl>
        <?php if (!empty($engagement['event_description'])): ?>
        <details class="engagement-description"><summary>Show description</summary><p><?php echo nl2br(htmlspecialchars($engagement['event_description'])); ?></p></details>
        <?php endif; ?>
        <p class="engagement-overview-meta"><?php echo $event_address_parts ? implode(', ', array_map('htmlspecialchars', $event_address_parts)) : 'Location not recorded'; ?> · Caller: <?php echo htmlspecialchars($engagement['caller_name'] ?: 'Not assigned'); ?> · Updated <?php echo htmlspecialchars(applicationTimestampLabel($engagement['updated_at'], 'M j, Y')); ?></p>
    </section>

    <div class="engagement-detail-layout">
        <div class="engagement-detail-main">
            <section class="engagement-card engagement-workspace" id="engagement-workspace" data-record-tabs>
                <div class="engagement-tab-list" role="tablist" aria-label="Engagement work">
                    <button type="button" role="tab" id="engagement-activity-tab" aria-controls="chron-log" aria-selected="true">Activity <span><?php echo $chron_entry_count; ?></span></button>
                    <button type="button" role="tab" id="engagement-correspondence-tab" aria-controls="correspondence" aria-selected="false">Correspondence <span><?php echo count($engagement_email_messages) === 10 ? '10+' : count($engagement_email_messages); ?></span></button>
                    <button type="button" role="tab" id="engagement-tasks-tab" aria-controls="engagement-tasks" aria-selected="false">Tasks <span><?php echo count($context_tasks); ?></span></button>
                    <button type="button" role="tab" id="engagement-presentations-tab" aria-controls="engagement-presentations" aria-selected="false">Presentations</button>
                    <button type="button" role="tab" id="engagement-contacts-tab" aria-controls="engagement-contacts" aria-selected="false">Contacts</button>
                    <button type="button" role="tab" id="engagement-logistics-tab" aria-controls="engagement-logistics" aria-selected="false">Logistics</button>
                    <button type="button" role="tab" id="engagement-financials-tab" aria-controls="financial-closeout" aria-selected="false">Financials</button>
                </div>
    <section class="chron-log-section engagement-tab-panel" id="chron-log" role="tabpanel" aria-labelledby="engagement-activity-tab" tabindex="0">
        <div class="chron-log-heading">
            <div>
                <h2>Activity</h2>
                <p>Chron Log Entries and engagement updates, newest first.</p>
            </div>
            <?php if ($archived_chron_count > 0): ?>
                <a href="restore_chron_entries.php?engagement_id=<?php echo $engagement_id; ?>" class="restore-button">Restore Archived Entries (<?php echo $archived_chron_count; ?>)</a>
            <?php endif; ?>
        </div>

        <?php if ($chron_edit_draft !== null && !$chron_edit_entry_found): ?>
            <div class="chron-view-editor">
                <label for="engagement-unsaved-chron">Your unsaved Chron edit</label>
                <p>This entry cannot be edited here. Copy your draft before leaving this page.</p>
                <textarea id="engagement-unsaved-chron" rows="6" readonly><?php echo htmlspecialchars($chron_edit_draft['text'], ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
        <?php endif; ?>
        <?php include __DIR__ . '/templates/record_add_note.php'; ?>
        <div class="chron-entry-list">
            <?php foreach ($chron_entries as $chron_entry): ?>
                <?php
                $created_timestamp = chronLogTimestampDetails($chron_entry['created_at']);
                $updated_timestamp = chronLogTimestampDetails($chron_entry['updated_at']);
                $entry_author = $chron_entry['created_by_username']
                    ?: (!empty($chron_entry['legacy_engagement_note']) ? 'Migrated legacy note' : 'System');
                $was_edited = (string) $chron_entry['updated_at'] !== (string) $chron_entry['created_at'];
                ?>
                <article class="chron-entry-card" id="chron-log-entry-<?php echo (int) $chron_entry['id']; ?>">
                    <span class="engagement-chron-icon" aria-hidden="true">✎</span>
                    <div class="chron-entry-meta">
                        <div>
                            <time datetime="<?php echo htmlspecialchars($created_timestamp['iso']); ?>"><?php echo htmlspecialchars($created_timestamp['display']); ?></time>
                            <span>by <?php echo htmlspecialchars($entry_author); ?></span>
                        </div>
                        <?php if ($was_edited): ?>
                            <small>Last updated <time datetime="<?php echo htmlspecialchars($updated_timestamp['iso']); ?>"><?php echo htmlspecialchars($updated_timestamp['display']); ?></time><?php if (!empty($chron_entry['updated_by_username'])): ?> by <?php echo htmlspecialchars($chron_entry['updated_by_username']); ?><?php endif; ?></small>
                        <?php endif; ?>
                        <?php if (!empty($chron_entry['outbound_email_message_id'])): ?>
                            <small><a href="outbound_mail.php?id=<?php echo (int) $chron_entry['outbound_email_message_id']; ?>">View Outbound Message</a></small>
                        <?php endif; ?>
                    </div>
                    <?php if ($can_manage_engagement && $chron_edit_id === (int) $chron_entry['id']): ?>
                        <form method="post" action="<?php echo htmlspecialchars($chron_view_url . '#chron-log-entry-' . (int) $chron_entry['id'], ENT_QUOTES, 'UTF-8'); ?>" class="chron-view-editor">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="action" value="edit_chron">
                            <input type="hidden" name="chron_entry_id" value="<?php echo (int) $chron_entry['id']; ?>">
                            <input type="hidden" name="chron_entry_version" value="<?php echo htmlspecialchars($chron_edit_draft['version'] ?? (string) $chron_entry['updated_at'], ENT_QUOTES, 'UTF-8'); ?>">
                            <label for="chron-entry-edit-<?php echo (int) $chron_entry['id']; ?>">Edit Chron Log Entry</label>
                            <textarea id="chron-entry-edit-<?php echo (int) $chron_entry['id']; ?>" name="chron_entry" rows="5" maxlength="100000" required><?php echo htmlspecialchars($chron_edit_draft['text'] ?? (string) $chron_entry['entry_text'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <div class="chron-view-editor-actions">
                                <button type="submit" class="save-button">Save Chron Log Entry</button>
                                <a href="<?php echo htmlspecialchars($chron_view_url . '#chron-log-entry-' . (int) $chron_entry['id'], ENT_QUOTES, 'UTF-8'); ?>" class="button-secondary">Cancel</a>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="chron-entry-text"><?php echo renderChronLogEntryHtml($chron_entry['entry_text']); ?></div>
                    <?php endif; ?>
                    <?php if ($can_manage_engagement): ?>
                        <div class="chron-view-actions" role="group" aria-label="Chron Log Entry actions">
                            <a href="<?php echo htmlspecialchars($chron_view_url . '&edit_chron=' . (int) $chron_entry['id'] . '#chron-log-entry-' . (int) $chron_entry['id'], ENT_QUOTES, 'UTF-8'); ?>" class="action-button action-icon-button edit-button" aria-label="Edit Chron Log Entry" title="Edit" data-tooltip="Edit"><?php echo actionIconSvg('edit'); ?></a>
                            <form method="post" action="<?php echo htmlspecialchars($chron_view_url . '#chron-log', ENT_QUOTES, 'UTF-8'); ?>" data-confirm="Archive this Chron Log Entry?">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="action" value="archive_chron">
                                <input type="hidden" name="chron_entry_id" value="<?php echo (int) $chron_entry['id']; ?>">
                                <button type="submit" class="action-button action-icon-button archive-button" aria-label="Archive Chron Log Entry" title="Archive" data-tooltip="Archive"><?php echo actionIconSvg('archive'); ?></button>
                            </form>
                            <?php if ($user_role === 'admin'): ?>
                                <form method="post" action="<?php echo htmlspecialchars($chron_view_url . '#chron-log', ENT_QUOTES, 'UTF-8'); ?>" data-confirm="Permanently delete this Chron Log Entry? This cannot be undone.">
                                    <?php echo csrfInput(); ?>
                                    <input type="hidden" name="action" value="delete_chron">
                                    <input type="hidden" name="chron_entry_id" value="<?php echo (int) $chron_entry['id']; ?>">
                                    <button type="submit" class="action-button action-icon-button delete-button" aria-label="Delete Chron Log Entry" title="Delete" data-tooltip="Delete"><?php echo actionIconSvg('delete'); ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if (!$chron_entries): ?>
                <p class="chron-empty-state">No Chron Log Entries have been added yet.</p>
            <?php endif; ?>
        </div>
        <?php if ($chron_total_pages > 1): ?>
            <nav class="pagination" aria-label="Chron log pages">
                <span>Page <?php echo $chron_page; ?> of <?php echo $chron_total_pages; ?> · <?php echo $chron_entry_count; ?> entries</span>
                <div class="pagination-actions">
                    <?php if ($chron_page > 1): ?><a href="<?php echo htmlspecialchars(recordUrlWithQuery($record_view_url, ['chron_page' => $chron_page - 1]) . '#chron-log', ENT_QUOTES, 'UTF-8'); ?>">Newer</a><?php endif; ?>
                    <?php if ($chron_page < $chron_total_pages): ?><a href="<?php echo htmlspecialchars(recordUrlWithQuery($record_view_url, ['chron_page' => $chron_page + 1]) . '#chron-log', ENT_QUOTES, 'UTF-8'); ?>">Older</a><?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>
        <?php if ($can_manage_engagement): ?>
        <?php endif; ?>
    </section>
    <section class="engagement-correspondence engagement-tab-panel" id="correspondence" role="tabpanel" aria-labelledby="engagement-correspondence-tab" tabindex="0">
        <div class="engagement-correspondence-heading">
            <div>
                <h2>Correspondence</h2>
                <p>Recent tracked outbound email for this engagement.</p>
            </div>
            <?php if (!$is_archived && in_array($user_role, ['admin', 'editor'], true)): ?>
                <a href="compose_engagement_email.php?id=<?php echo $engagement_id; ?>" class="button-add">Send Message</a>
            <?php endif; ?>
        </div>
        <div class="engagement-email-history">
            <?php foreach ($engagement_email_messages as $email_message): ?>
                <?php
                $email_status = engagementEmailAggregateStatus($email_message);
                $email_status_labels = [
                    'sent' => 'Sent',
                    'failed' => 'Failed',
                    'partial' => 'Partially sent',
                    'pending' => 'Pending',
                ];
                ?>
                <article class="engagement-email-history-item">
                    <a href="outbound_mail.php?id=<?php echo (int) $email_message['id']; ?>"><?php echo htmlspecialchars((string) $email_message['subject'], ENT_QUOTES, 'UTF-8'); ?></a>
                    <span class="email-status email-status-<?php echo htmlspecialchars($email_status, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($email_status_labels[$email_status], ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="engagement-email-history-meta"><?php echo htmlspecialchars(applicationTimestampLabel($email_message['created_at'], 'M j, Y g:i A T'), ENT_QUOTES, 'UTF-8'); ?><?php if (!empty($email_message['created_by_username'])): ?> · <?php echo htmlspecialchars((string) $email_message['created_by_username'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></span>
                    <span class="engagement-email-history-recipients"><?php echo htmlspecialchars((string) ($email_message['recipients'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                </article>
            <?php endforeach; ?>
            <?php if ($engagement_email_messages === []): ?><p class="chron-empty-state">No outbound correspondence has been recorded yet.</p><?php endif; ?>
        </div>
    </section>


                <div class="engagement-tab-panel" id="engagement-tasks" role="tabpanel" aria-labelledby="engagement-tasks-tab" tabindex="0"><?php echo $engagement_task_html; ?></div>
    <section class="engagement-card engagement-tab-panel" id="engagement-presentations" role="tabpanel" aria-labelledby="engagement-presentations-tab" tabindex="0">
        <div class="engagement-card-heading"><h2>Presentations <span class="engagement-section-count"><?php echo count($presentations); ?></span></h2>
            <?php if ($can_manage_engagement): ?><a href="<?php echo htmlspecialchars(recordUrlWithQuery('edit_engagement.php?id=' . $engagement_id, ['return_to' => $record_view_url . '#engagement-presentations']), ENT_QUOTES, 'UTF-8'); ?>#presentations-container">Edit Presentations</a><?php endif; ?>
        </div>
        <div class="detail-value">
            <?php foreach ($presentations as $presentation): ?>
            <div class="presentation-item">
                <strong><?php echo htmlspecialchars(trim((string) $presentation['topic_title']) ?: 'Presentation'); ?></strong>
                <?php if (!empty($presentation['speaker_name'])): ?>
                <div>Speaker: <?php echo htmlspecialchars($presentation['speaker_name']); ?></div>
                <?php endif; ?>
                <?php if (!empty($presentation['presentation_date']) || !empty($presentation['presentation_time'])): ?>
                <div>
                    <?php echo htmlspecialchars(trim((!empty($presentation['presentation_date']) ? engagementViewDateRange($presentation['presentation_date'], $presentation['presentation_date']) : '') . ' ' . formatPresentationTime($presentation['presentation_time'] ?? ''))); ?>
                </div>
                <?php endif; ?>
                <?php if ($presentation['duration_minutes'] !== null): ?>
                <div>Duration: <?php echo (int) $presentation['duration_minutes']; ?> minutes</div>
                <?php endif; ?>
                <?php if ($presentation['expected_attendance'] !== null): ?>
                <div>Expected attendance: <?php echo (int) $presentation['expected_attendance']; ?></div>
                <?php endif; ?>
                <?php if ($presentation['actual_attendance'] !== null): ?>
                <div>Actual attendance: <?php echo (int) $presentation['actual_attendance']; ?></div>
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
                               class="presentation-view-pdf">
                                Download PDF slide deck<?php if (!empty($presentation['slide_deck_filename'])): ?>:
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
                <p class="engagement-card-empty">No presentations added yet. Details can be filled in later.</p>
            <?php endif; ?>
            <?php if ($archived_presentation_count > 0): ?>
                <div class="form-row">
                    <a href="restore_presentations.php?engagement_id=<?php echo $engagement_id; ?>" class="restore-button">Restore Archived Presentations (<?php echo $archived_presentation_count; ?>)</a>
                </div>
            <?php endif; ?>
        </div>
    </section>

            <section class="engagement-card engagement-tab-panel" id="engagement-contacts" role="tabpanel" aria-labelledby="engagement-contacts-tab" tabindex="0">
                <div class="engagement-card-heading"><h2>Event Contacts <span class="engagement-section-count"><?php echo count($contacts); ?></span></h2>
                    <?php if ($can_manage_engagement): ?><a href="<?php echo htmlspecialchars(recordUrlWithQuery('edit_engagement.php?id=' . $engagement_id, ['return_to' => $record_view_url . '#engagement-contacts']), ENT_QUOTES, 'UTF-8'); ?>#engagement-contact-selector">Edit Contacts</a><?php endif; ?>
                </div>
        <div class="contacts-list">
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
                <div>Phone: <a href="tel:<?php echo htmlspecialchars($contact['contact_phone'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(formatPhoneNumberForDisplay($contact['contact_phone']), ENT_QUOTES, 'UTF-8'); ?></a></div>
                <?php endif; ?>
            </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="engagement-contacts-empty">No event contacts have been assigned.</p>
            <?php endif; ?>
        </div>
            </section>
            <section class="engagement-card engagement-tab-panel" id="engagement-logistics" role="tabpanel" aria-labelledby="engagement-logistics-tab" tabindex="0">
                <div class="engagement-card-heading"><h2>Event Logistics</h2></div>
                <dl class="engagement-detail-list">
                    <div><dt>Book Table Provided</dt><dd><?php echo !empty($engagement['book_table']) ? 'Yes' : 'No'; ?></dd></div>
                    <div><dt>Brochures Permitted</dt><dd><?php echo !empty($engagement['brochures']) ? 'Yes' : 'No'; ?></dd></div>
                    <div><dt>All Travel Covered</dt><dd><?php echo htmlspecialchars(ucfirst($engagement['travel_covered'] ?? 'unknown')); ?></dd></div>
                </dl>
    <?php if (!empty($engagement['compensation_type'])): ?>
    <div class="engagement-compensation">
        <h3>Compensation &amp; Lodging</h3>
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


            </section>
    <section class="engagement-card financial-closeout engagement-tab-panel" id="financial-closeout" role="tabpanel" aria-labelledby="engagement-financials-tab" tabindex="0">
        <div class="financial-closeout-heading">
            <div>
                <h2>Financial Closeout</h2>
                <p>Actual receipts recorded after the event; planning estimates above remain unchanged.</p>
            </div>
            <span class="financial-status <?php echo $financial_report ? 'is-finalized' : ($financial_closeout_applicable ? 'is-open' : 'is-not-applicable'); ?>">
                <?php echo $financial_report ? 'Finalized' : ($financial_draft !== null ? 'Receipt draft' : ($financial_closeout_applicable ? 'Open' : 'Not applicable')); ?>
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
                <div class="financial-notes"><strong>Closeout notes</strong><p><?php echo renderTextWithLinks($financial_report['notes']); ?></p></div>
            <?php endif; ?>
            <?php if (!$is_archived && in_array($user_role, ['admin', 'editor'], true)): ?>
                <a href="close_engagement.php?id=<?php echo $engagement_id; ?>" class="action-button edit-button">Correct final report</a>
            <?php endif; ?>
        <?php elseif ($financial_closeout_applicable): ?>
            <p class="financial-empty">No actual received amounts have been finalized for this event.</p>
            <?php if ($financial_draft !== null): ?>
                <h3>Draft Received Amounts</h3><div class="financial-amount-grid">
                <?php foreach (['giving_income_received' => 'Giving / income', 'lodging_received' => 'Lodging', 'travel_received' => 'Travel'] as $draft_field => $draft_label): ?><div><small><?php echo $draft_label; ?></small><strong><?php echo $financial_draft[$draft_field] === null ? 'Not entered' : formatFinancialAmount($financial_draft[$draft_field]); ?></strong></div><?php endforeach; ?>
                </div><p>Draft receipts are excluded from finalized giving history.</p>
            <?php endif; ?>
            <?php if (!$is_archived && in_array($user_role, ['admin', 'editor'], true)): ?>
                <a href="close_engagement.php?id=<?php echo $engagement_id; ?>" class="action-button save-button"><?php echo $financial_draft !== null ? 'Continue Receipt Draft' : 'Record Receipts'; ?></a>
            <?php endif; ?>
        <?php else: ?>
            <p class="financial-empty">Financial closeout is unavailable while this engagement is <?php echo htmlspecialchars(strtolower(engagementLifecycleLabel($engagement['lifecycle_status'] ?? 'active')), ENT_QUOTES, 'UTF-8'); ?>.</p>
        <?php endif; ?>
    </section>

            </section>
        </div>
        <aside class="engagement-detail-sidebar" aria-label="Engagement planning and actions">
            <section class="engagement-card engagement-routing-card" aria-labelledby="engagement-routing-heading"><h2 id="engagement-routing-heading">Email Routing Marker</h2>
        <div class="detail-value engagement-email-marker">
            <span class="engagement-email-marker-control">
                <code><?php echo htmlspecialchars($engagement_marker, ENT_QUOTES, 'UTF-8'); ?></code>
                <button
                    type="button"
                    class="action-icon-button engagement-marker-copy"
                    data-copy-text="<?php echo htmlspecialchars($engagement_marker, ENT_QUOTES, 'UTF-8'); ?>"
                    data-copy-status="engagement-marker-copy-status"
                    data-tooltip="Copy marker"
                    aria-label="Copy email routing marker"
                    title="Copy email routing marker"
                >
                    <svg class="action-icon engagement-marker-copy-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><rect x="8" y="8" width="11" height="11" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg>
                    <svg class="action-icon engagement-marker-copied-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg>
                </button>
            </span>
            <span class="engagement-email-marker-help">Keep this marker in the email subject or plain-text body to route the message to this Engagement’s Chron log.</span>
            <span id="engagement-marker-copy-status" class="visually-hidden" role="status" aria-live="polite"></span>
        </div>
            </section>
            <section class="engagement-card engagement-next-action-card">
                <h2>Next Action</h2>
                <div class="engagement-next-action-content">
                    <span class="engagement-next-action-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/></svg></span>
                    <div>
                        <strong><?php echo htmlspecialchars($next_task['title'] ?? 'No open follow-up tasks'); ?></strong>
                        <?php if ($next_task !== null): ?>
                            <?php $next_task_due = followUpTaskDueState($next_task['due_date']); ?>
                            <p class="task-due task-due-<?php echo htmlspecialchars($next_task_due['key'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(empty($next_task['due_date']) ? 'No due date' : 'Due ' . engagementViewDateRange($next_task['due_date'], $next_task['due_date'])); ?></p>
                            <p>Owner <b><?php echo htmlspecialchars($next_task['assignee_username'] ?: 'Unassigned'); ?></b></p>
                            <?php if ($next_task['status'] === 'waiting' && !empty($next_task['waiting_on'])): ?><p>Waiting on <?php echo htmlspecialchars($next_task['waiting_on']); ?></p><?php endif; ?>
                        <?php else: ?><p>The next open task will appear here.</p><?php endif; ?>
                    </div>
                </div>
                <?php if ($next_task !== null && $context_task_can_manage): ?>
                    <a href="<?php echo htmlspecialchars($next_task_edit_url, ENT_QUOTES, 'UTF-8'); ?>" class="button-add engagement-card-action">Update Next Action</a>
                <?php elseif ($context_task_can_manage && $context_task_subject_active): ?>
                    <a href="<?php echo htmlspecialchars($context_task_add_url, ENT_QUOTES, 'UTF-8'); ?>" class="button-add engagement-card-action">Add Task</a>
                <?php else: ?><a href="#follow-up-work" class="button-secondary engagement-card-action">View Tasks</a><?php endif; ?>
            </section>
            <section class="engagement-card engagement-readiness-card">
                <h2>Readiness</h2>
                <ul>
                    <?php foreach ($engagement_readiness as $item): ?>
                        <li<?php echo $item['ready'] ? ' class="is-ready"' : ''; ?>><span><?php echo htmlspecialchars($item['label']); ?></span><strong aria-label="<?php echo $item['ready'] ? 'Recorded' : 'Not recorded'; ?>"><?php echo $item['ready'] ? '✓' : '!'; ?></strong></li>
                    <?php endforeach; ?>
                </ul>
                <p class="engagement-card-empty">Details can be added as planning progresses.</p>
                <?php if ($context_task_can_manage && $context_task_subject_active): ?><a href="<?php echo htmlspecialchars($context_task_add_url, ENT_QUOTES, 'UTF-8'); ?>" class="button-secondary engagement-card-action">Add Task</a><?php endif; ?>
            </section>
            <section class="engagement-card engagement-workflow-card" id="workflow-controls">
                <h2>Workflow</h2>
                <dl class="engagement-detail-list"><div><dt>Confirmation</dt><dd><?php echo htmlspecialchars($confirmation_label); ?></dd></div></dl>
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
                <?php if ($can_manage_engagement): ?><a href="<?php echo htmlspecialchars(recordUrlWithQuery('edit_engagement.php?id=' . $engagement_id, ['return_to' => $record_view_url . '#workflow-controls']), ENT_QUOTES, 'UTF-8'); ?>#lifecycle_status" class="button-secondary engagement-card-action">Update Status</a><?php endif; ?>
                <a href="#financial-closeout" class="button-secondary engagement-card-action">View Financial Closeout</a>
            </section>

            <details class="engagement-card engagement-export-card"><summary>Export engagement</summary>
        <div class="export-actions" aria-label="Export engagement">
            <button type="button" class="action-button export-button" data-copy-format="text">Copy Text</button>
            <button type="button" class="action-button export-button" data-copy-format="markdown">Copy MD</button>
            <a href="download_engagement_pdf.php?id=<?php echo $engagement_id; ?>" class="action-button export-button">Download PDF</a>
        </div>
        <span id="copy-status" class="visually-hidden" role="status" aria-live="polite"></span>

            </details>
        </aside>
    </div>
    <div class="action-buttons"><a href="<?php echo htmlspecialchars($record_list_return, ENT_QUOTES, 'UTF-8'); ?>" class="button-secondary">Back to <?php echo htmlspecialchars(recordReturnLabel($record_list_return), ENT_QUOTES, 'UTF-8'); ?></a></div>
</main>
<script nonce="<?php echo htmlspecialchars(contentSecurityPolicyNonce(), ENT_QUOTES, 'UTF-8'); ?>" type="application/json" id="engagement-export-data"><?php echo json_encode([
        'text' => $engagement_plain_text,
        'markdown' => $engagement_markdown,
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<?php renderScript('assets/js/record-workspace.min.js'); ?>
<?php include 'templates/footer.php'; ?>
</body>
</html>
