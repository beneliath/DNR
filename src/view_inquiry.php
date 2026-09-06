<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$conn = applicationDatabaseConnection();
require_once __DIR__ . '/booking_inquiry_helpers.php';
require_once __DIR__ . '/chron_log_helpers.php';
require_once __DIR__ . '/follow_up_task_helpers.php';
require_once __DIR__ . '/two_factor_helpers.php';
startSecureSession();
requireLogin();

$inquiryId = \Dnr\Http\RequestInput::positiveInt(
    $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET,
    'id'
);
if ($inquiryId === null) {
    header('Location: inquiries.php');
    exit();
}
$inquiry = fetchBookingInquiry($conn, $inquiryId);
if (!$inquiry) {
    header('Location: inquiries.php');
    exit();
}
$userRole = (string) ($_SESSION['role'] ?? '');
$canManage = canManageBookingInquiries($userRole);
$currentUserId = (int) $_SESSION['user_id'];
$isBooked = $inquiry['stage'] === 'booked';
$editChronId = \Dnr\Http\RequestInput::positiveInt($_GET, 'edit_chron');
$chronDraft = null;
$chronDraftVersion = null;
$chronEditError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    if (!$canManage) {
        http_response_code(403);
        exit('Forbidden.');
    }
    $action = is_scalar($_POST['action'] ?? null) ? (string) $_POST['action'] : '';
    $chronTransaction = false;
    $entryId = \Dnr\Http\RequestInput::positiveInt($_POST, 'chron_entry_id');
    if ($action === 'edit_chron') {
        $editChronId = $entryId;
        $chronDraft = is_scalar($_POST['chron_entry'] ?? null) ? (string) $_POST['chron_entry'] : '';
        $chronDraftVersion = is_scalar($_POST['chron_entry_version'] ?? null)
            ? (string) $_POST['chron_entry_version'] : '';
    }
    if ($action === 'delete_chron' && $userRole !== 'admin') {
        http_response_code(403);
        exit('Forbidden.');
    }
    try {
        if ($action === 'change_stage') {
            changeBookingInquiryStage(
                $conn,
                $inquiryId,
                (string) ($_POST['stage'] ?? ''),
                is_scalar($_POST['stage_reason'] ?? null) ? (string) $_POST['stage_reason'] : null,
                (string) ($_POST['inquiry_version'] ?? ''),
                $currentUserId,
                (string) $_SESSION['username']
            );
            $_SESSION['inquiry_action_message'] = 'Inquiry stage updated.';
        } elseif (in_array($action, ['add_chron', 'edit_chron', 'archive_chron', 'delete_chron'], true)) {
            if ($isBooked) {
                throw new InvalidArgumentException('Booked inquiries are preserved as read-only source records.');
            }
            if ($action !== 'add_chron' && $entryId === null) {
                throw new InvalidArgumentException('Select a valid Chron Log Entry.');
            }
            if ($action === 'delete_chron') {
                requireRecentAdminElevation('view_inquiry.php?id=' . $inquiryId . '#inquiry-activity');
            }
            $conn->begin_transaction();
            $chronTransaction = true;
            $lockedInquiry = fetchBookingInquiry($conn, $inquiryId, true);
            if (!$lockedInquiry || $lockedInquiry['stage'] === 'booked') {
                throw new InvalidArgumentException('Booked inquiries are preserved as read-only source records.');
            }
            if ($action === 'add_chron') {
                insertEntityChronLogEntry(
                    $conn,
                    'inquiry',
                    $inquiryId,
                    $_POST['chron_entry'] ?? '',
                    $currentUserId,
                    (string) $_SESSION['username']
                );
                $chronMessage = 'Chron Log Entry added.';
            } elseif ($action === 'edit_chron') {
                $entryText = normalizeChronLogEntryText($_POST['chron_entry'] ?? '');
                $entryVersions = normalizeSubmittedChronLogVersions([
                    $entryId => $_POST['chron_entry_version'] ?? '',
                ]);
                updateEntityChronLogEntries(
                    $conn,
                    'inquiry',
                    $inquiryId,
                    [$entryId => $entryText],
                    $entryVersions,
                    $currentUserId
                );
                $chronMessage = 'Chron Log Entry updated.';
            } elseif ($action === 'archive_chron') {
                archiveEntityChronLogEntry($conn, 'inquiry', $inquiryId, $entryId, $currentUserId);
                $chronMessage = 'Chron Log Entry archived.';
            } else {
                deleteEntityChronLogEntry($conn, 'inquiry', $inquiryId, $entryId);
                $chronMessage = 'Chron Log Entry permanently deleted.';
            }
            $conn->commit();
            $chronTransaction = false;
            $_SESSION['inquiry_action_message'] = $chronMessage;
        } else {
            throw new InvalidArgumentException('Select a valid inquiry action.');
        }
    } catch (Throwable $exception) {
        if ($chronTransaction) {
            $conn->rollback();
        }
        $actionError = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'The inquiry could not be updated. Please try again.';
        if ($action === 'edit_chron') {
            $chronEditError = $actionError;
        } else {
            $_SESSION['inquiry_action_error'] = $actionError;
        }
    }
    if ($chronEditError === '') {
        $returnAnchor = $action === 'edit_chron' ? '#chron-log-entry-' . $entryId
            : ($action === 'change_stage' ? '' : '#inquiry-activity');
        header('Location: view_inquiry.php?id=' . $inquiryId . $returnAnchor);
        exit();
    }
}

$notice = (string) ($_SESSION['inquiry_action_message'] ?? '');
$error = $chronEditError !== '' ? $chronEditError : (string) ($_SESSION['inquiry_action_error'] ?? '');
unset($_SESSION['inquiry_action_message'], $_SESSION['inquiry_action_error']);
$inquiry = fetchBookingInquiry($conn, $inquiryId);
$isBooked = $inquiry['stage'] === 'booked';
$isActive = in_array((string) $inquiry['stage'], bookingInquiryActiveStages(), true);
$tasks = fetchFollowUpTasksForSubject($conn, 'inquiry', $inquiryId);
$history = fetchBookingInquiryStageHistory($conn, $inquiryId);
$chronEntries = fetchEntityChronLogEntries($conn, 'inquiry', $inquiryId, false, 100, 0);
$editChronEntry = null;
if ($canManage && !$isBooked && $editChronId !== null) {
    foreach ($chronEntries as $chronEntry) {
        if ((int) $chronEntry['id'] === $editChronId) {
            $editChronEntry = $chronEntry;
            break;
        }
    }
    if ($editChronEntry === null && $error === '') {
        $error = 'This Chron Log Entry is no longer available on this inquiry.';
    }
}
$archivedChronCount = countEntityChronLogEntries($conn, 'inquiry', $inquiryId, 1);
$correspondence = fetchBookingInquiryEmailMessages($conn, $inquiryId, 20);
$readiness = bookingInquiryReadiness($inquiry);
$readyToBook = $readiness['organization'] && $readiness['title'] && $readiness['dates'];
$marker = applicationInquiryInboundMarker($inquiryId);
$stages = bookingInquiryStages();
$activeStages = bookingInquiryActiveStages();
$currentStageIndex = array_search((string) $inquiry['stage'], $activeStages, true);
$progressStageIndex = $currentStageIndex !== false ? (int) $currentStageIndex : -1;
foreach ($history as $historyEntry) {
    $historyStageIndex = array_search((string) $historyEntry['to_stage'], $activeStages, true);
    if ($historyStageIndex !== false) {
        $progressStageIndex = max($progressStageIndex, (int) $historyStageIndex);
    }
}
$alternateDateLabel = bookingInquiryDateLabel([
    'preferred_start_date' => $inquiry['alternate_start_date'],
    'preferred_end_date' => $inquiry['alternate_end_date'],
]);
$address = array_filter([
    $inquiry['event_address_line_1'], $inquiry['event_address_line_2'],
    trim(implode(', ', array_filter([$inquiry['event_city'], $inquiry['event_state']]))
        . (!empty($inquiry['event_zipcode']) ? ' ' . $inquiry['event_zipcode'] : '')),
    !empty($inquiry['event_country']) ? addressCountryName($inquiry['event_country']) : '',
]);
$taskReturn = 'view_inquiry.php?id=' . $inquiryId . '#follow-up-work';
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Inquiry Details'), ['styles' => [
    'assets/css/style.min.css', 'assets/css/modern.min.css',
    'assets/css/pages/booking_inquiries.min.css',
    'assets/css/pages/engagement_email.min.css',
]]); ?>
<body class="inquiry-workflow-body inquiry-detail-body">
<?php include 'templates/header.php'; ?>
<main class="container inquiry-detail-page">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="inquiries.php">Booking Pipeline</a><span aria-hidden="true">/</span><span><?php echo htmlspecialchars(bookingInquiryDisplayLabel($inquiry['title']), ENT_QUOTES, 'UTF-8'); ?></span></nav>
    <header class="page-heading inquiry-detail-heading">
        <div><h1><?php echo htmlspecialchars(bookingInquiryDisplayLabel($inquiry['title']), ENT_QUOTES, 'UTF-8'); ?></h1><p class="inquiry-title-meta"><span><?php echo htmlspecialchars((string) (bookingInquiryDisplayLabel($inquiry['organization_name']) ?: 'Organization not identified'), ENT_QUOTES, 'UTF-8'); ?></span><span class="inquiry-stage-badge stage-<?php echo htmlspecialchars($inquiry['stage'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($stages[$inquiry['stage']], ENT_QUOTES, 'UTF-8'); ?></span><span class="inquiry-priority priority-<?php echo htmlspecialchars($inquiry['priority'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(bookingInquiryPriorities()[$inquiry['priority']], ENT_QUOTES, 'UTF-8'); ?></span></p></div>
        <?php if ($canManage): ?><div class="page-heading-actions"><?php if (!$isBooked): ?><a href="edit_inquiry.php?id=<?php echo $inquiryId; ?>" class="button-secondary">Edit Inquiry</a><?php endif; ?><?php if ($isActive): ?><a href="#workflow-controls" class="button-secondary inquiry-decline-action" data-inquiry-stage-target="declined">Mark Declined</a><a href="convert_inquiry.php?id=<?php echo $inquiryId; ?>" class="button-add inquiry-primary-action<?php echo !$readyToBook ? ' is-disabled' : ''; ?>"<?php echo !$readyToBook ? ' aria-disabled="true" title="Add organization and preferred dates before booking"' : ''; ?>><span class="inquiry-button-icon" aria-hidden="true">+</span>Convert to Engagement</a><?php endif; ?></div><?php endif; ?>
    </header>
    <?php if ($notice !== ''): ?><p class="success" role="status"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($isBooked): ?><div class="inquiry-terminal-banner inquiry-booked-banner"><div><strong>Booked</strong><span>This inquiry is now a read-only source record.</span></div><?php if (!empty($inquiry['converted_engagement_id'])): ?><a href="view_engagement.php?id=<?php echo (int) $inquiry['converted_engagement_id']; ?>" class="button-secondary">Open Engagement</a><?php endif; ?></div><?php elseif ($inquiry['stage'] === 'declined'): ?><div class="inquiry-terminal-banner"><div><strong>Declined</strong><span><?php echo htmlspecialchars((string) $inquiry['decline_reason'], ENT_QUOTES, 'UTF-8'); ?></span></div></div><?php endif; ?>

    <section class="inquiry-stage-path" aria-label="Inquiry progression">
        <?php foreach ($activeStages as $index => $stage): ?><?php $stageComplete = $index < $progressStageIndex || (!$isActive && $index <= $progressStageIndex); ?><div class="<?php echo $inquiry['stage'] === $stage ? 'is-current ' : ''; ?><?php echo $stageComplete ? 'is-complete' : ''; ?>"><span><?php echo $stageComplete ? '✓' : $index + 1; ?></span><strong><?php echo htmlspecialchars($stages[$stage], ENT_QUOTES, 'UTF-8'); ?></strong></div><?php endforeach; ?><div class="inquiry-terminal-stage <?php echo $isBooked ? 'is-current is-complete' : ''; ?>"><span><?php echo $isBooked ? '✓' : '6'; ?></span><strong>Booked</strong></div><div class="inquiry-terminal-stage is-declined <?php echo $inquiry['stage'] === 'declined' ? 'is-current' : ''; ?>"><span>!</span><strong>Declined</strong></div>
    </section>

    <div class="inquiry-detail-layout">
        <div class="inquiry-detail-main">
            <section class="record-section inquiry-overview-card" id="request-details"><div class="record-section-heading"><h2>Inquiry Overview</h2></div><dl class="inquiry-detail-list">
                <div><dt>Organization</dt><dd><?php if (!empty($inquiry['organization_id'])): ?><a href="view_organization.php?id=<?php echo (int) $inquiry['organization_id']; ?>"><?php echo htmlspecialchars(bookingInquiryDisplayLabel($inquiry['organization_name']), ENT_QUOTES, 'UTF-8'); ?></a><?php else: ?>Not identified<?php endif; ?></dd></div>
                <div><dt>Primary contact</dt><dd><?php if (!empty($inquiry['primary_contact_id'])): ?><a href="view_contact.php?id=<?php echo (int) $inquiry['primary_contact_id']; ?>"><?php echo htmlspecialchars((string) ($inquiry['contact_name'] ?: 'Contact'), ENT_QUOTES, 'UTF-8'); ?></a><?php if (!empty($inquiry['contact_email'])): ?><small><a href="mailto:<?php echo htmlspecialchars($inquiry['contact_email'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($inquiry['contact_email'], ENT_QUOTES, 'UTF-8'); ?></a></small><?php endif; ?><?php else: ?>Not identified<?php endif; ?></dd></div>
                <div><dt>Request</dt><dd><?php echo !empty($inquiry['request_summary']) ? nl2br(htmlspecialchars($inquiry['request_summary'], ENT_QUOTES, 'UTF-8')) : 'No summary recorded.'; ?></dd></div>
                <div><dt>Preferred dates</dt><dd><?php echo htmlspecialchars(bookingInquiryDateLabel($inquiry), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                <div><dt>Alternate dates</dt><dd><?php echo !empty($inquiry['alternate_start_date']) ? htmlspecialchars($alternateDateLabel, ENT_QUOTES, 'UTF-8') : 'None provided'; ?></dd></div>
                <div><dt>Event type</dt><dd><?php echo htmlspecialchars($inquiry['event_type'] === 'other' ? (string) $inquiry['event_type_other'] : ucwords((string) $inquiry['event_type']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                <div><dt>Location</dt><dd><?php echo $address ? htmlspecialchars(implode(' · ', $address), ENT_QUOTES, 'UTF-8') : 'Not identified'; ?></dd></div>
                <div><dt>Source</dt><dd><?php echo htmlspecialchars(bookingInquirySources()[$inquiry['source']], ENT_QUOTES, 'UTF-8'); ?><?php if (!empty($inquiry['source_detail'])): ?><small><?php echo htmlspecialchars($inquiry['source_detail'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?><?php if (!empty($inquiry['inbound_email_message_id'])): ?><small><a href="inbound_mail.php?status=all&amp;id=<?php echo (int) $inquiry['inbound_email_message_id']; ?>">Open Source Email</a></small><?php endif; ?></dd></div>
                <div><dt>Owner</dt><dd><?php echo htmlspecialchars((string) ($inquiry['owner_username'] ?: 'Unassigned'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
                <div><dt>Created</dt><dd><?php echo htmlspecialchars(applicationTimestampLabel($inquiry['created_at'], 'M j, Y g:i A T'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
            </dl></section>

            <section class="record-section inquiry-workspace" data-inquiry-tabs>
                <div class="inquiry-tab-list" role="tablist" aria-label="Inquiry work"><button type="button" role="tab" id="inquiry-activity-tab" aria-controls="inquiry-activity" aria-selected="true">Activity</button><button type="button" role="tab" id="inquiry-correspondence-tab" aria-controls="correspondence" aria-selected="false">Correspondence <span><?php echo count($correspondence); ?></span></button><button type="button" role="tab" id="inquiry-tasks-tab" aria-controls="follow-up-work" aria-selected="false">Tasks <span><?php echo count($tasks); ?></span></button></div>
                <div class="inquiry-tab-panel" id="inquiry-activity" role="tabpanel" aria-labelledby="inquiry-activity-tab">
                    <?php if ($canManage && !$isBooked): ?><details class="inquiry-add-note"><summary>Add Chron Log Entry</summary><form method="post" action="view_inquiry.php" class="inquiry-chron-form"><?php echo csrfInput(); ?><input type="hidden" name="id" value="<?php echo $inquiryId; ?>"><input type="hidden" name="action" value="add_chron"><label for="inquiry-chron-entry">Chron Log Entry</label><textarea id="inquiry-chron-entry" name="chron_entry" rows="4" maxlength="100000" required placeholder="Decision, conversation outcome, commitment, or context"></textarea><button type="submit" class="save-button inquiry-primary-action">Save Chron Log Entry</button></form></details><?php endif; ?>
                    <?php if ($chronDraft !== null && $editChronEntry === null): ?>
                        <div class="chron-view-editor">
                            <label for="inquiry-unsaved-chron">Your unsaved Chron edit</label>
                            <p>This entry cannot be edited here. Copy your draft before leaving this page.</p>
                            <textarea id="inquiry-unsaved-chron" rows="6" readonly><?php echo htmlspecialchars($chronDraft, ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                    <?php endif; ?>
                    <div class="inquiry-activity-timeline">
                        <?php foreach ($chronEntries as $entry): ?>
                            <?php
                            $entryId = (int) $entry['id'];
                            $createdTimestamp = chronLogTimestampDetails($entry['created_at']);
                            $updatedTimestamp = chronLogTimestampDetails($entry['updated_at']);
                            $entryAuthor = $entry['created_by_username'] ?: 'System';
                            ?>
                            <article id="chron-log-entry-<?php echo $entryId; ?>">
                                <span class="inquiry-activity-icon" aria-hidden="true">✎</span>
                                <div>
                                    <div class="chron-entry-meta">
                                        <div>
                                            <time datetime="<?php echo htmlspecialchars($createdTimestamp['iso'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($createdTimestamp['display'], ENT_QUOTES, 'UTF-8'); ?></time>
                                            <span>by <?php echo htmlspecialchars($entryAuthor, ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                        <?php if ((string) $entry['updated_at'] !== (string) $entry['created_at']): ?>
                                            <small>Last updated <time datetime="<?php echo htmlspecialchars($updatedTimestamp['iso'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($updatedTimestamp['display'], ENT_QUOTES, 'UTF-8'); ?></time><?php if (!empty($entry['updated_by_username'])): ?> by <?php echo htmlspecialchars($entry['updated_by_username'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($editChronEntry !== null && $entryId === $editChronId): ?>
                                        <form method="post" action="view_inquiry.php?id=<?php echo $inquiryId; ?>&amp;edit_chron=<?php echo $entryId; ?>#chron-log-entry-<?php echo $entryId; ?>" class="chron-view-editor">
                                            <?php echo csrfInput(); ?>
                                            <input type="hidden" name="id" value="<?php echo $inquiryId; ?>">
                                            <input type="hidden" name="action" value="edit_chron">
                                            <input type="hidden" name="chron_entry_id" value="<?php echo $entryId; ?>">
                                            <input type="hidden" name="chron_entry_version" value="<?php echo htmlspecialchars($chronDraftVersion ?? (string) $entry['updated_at'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <label for="chron-entry-<?php echo $entryId; ?>">Edit Chron Log Entry</label>
                                            <textarea id="chron-entry-<?php echo $entryId; ?>" name="chron_entry" rows="6" maxlength="100000" required><?php echo htmlspecialchars($chronDraft ?? (string) $entry['entry_text'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                                            <div class="chron-view-editor-actions">
                                                <button type="submit" class="save-button">Save Changes</button>
                                                <a href="view_inquiry.php?id=<?php echo $inquiryId; ?>#chron-log-entry-<?php echo $entryId; ?>" class="button-secondary">Cancel</a>
                                            </div>
                                        </form>
                                    <?php else: ?>
                                        <div class="chron-entry-text"><?php echo renderChronLogEntryHtml($entry['entry_text']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($canManage && !$isBooked): ?>
                                        <div class="chron-view-actions" role="group" aria-label="Chron Log Entry actions">
                                            <a href="view_inquiry.php?id=<?php echo $inquiryId; ?>&amp;edit_chron=<?php echo $entryId; ?>#chron-log-entry-<?php echo $entryId; ?>" class="action-button action-icon-button edit-button" aria-label="Edit Chron Log Entry" title="Edit" data-tooltip="Edit"><?php echo actionIconSvg('edit'); ?></a>
                                            <form method="post" action="view_inquiry.php" data-confirm="Archive this Chron Log Entry?">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="id" value="<?php echo $inquiryId; ?>">
                                                <input type="hidden" name="action" value="archive_chron">
                                                <input type="hidden" name="chron_entry_id" value="<?php echo $entryId; ?>">
                                                <button type="submit" class="action-button action-icon-button archive-button" aria-label="Archive Chron Log Entry" title="Archive" data-tooltip="Archive"><?php echo actionIconSvg('archive'); ?></button>
                                            </form>
                                            <?php if ($userRole === 'admin'): ?>
                                                <form method="post" action="view_inquiry.php" data-confirm="Permanently delete this Chron Log Entry? This cannot be undone.">
                                                    <?php echo csrfInput(); ?>
                                                    <input type="hidden" name="id" value="<?php echo $inquiryId; ?>">
                                                    <input type="hidden" name="action" value="delete_chron">
                                                    <input type="hidden" name="chron_entry_id" value="<?php echo $entryId; ?>">
                                                    <button type="submit" class="action-button action-icon-button delete-button" aria-label="Delete Chron Log Entry" title="Delete" data-tooltip="Delete"><?php echo actionIconSvg('delete'); ?></button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                        <?php foreach ($history as $entry): ?><article><span class="inquiry-activity-icon inquiry-stage-activity-icon" aria-hidden="true">✓</span><div><strong>Moved to <?php echo htmlspecialchars($stages[$entry['to_stage']], ENT_QUOTES, 'UTF-8'); ?></strong><small><?php echo htmlspecialchars(applicationTimestampLabel($entry['changed_at'], 'M j, Y g:i A T') . ' · ' . ($entry['changed_by_username'] ?: 'Former user'), ENT_QUOTES, 'UTF-8'); ?></small><?php if (!empty($entry['reason'])): ?><p><?php echo htmlspecialchars($entry['reason'], ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?></div></article><?php endforeach; ?>
                        <?php if ($chronEntries === [] && $history === []): ?><p class="empty-state">No inquiry activity has been recorded.</p><?php endif; ?>
                    </div>
                    <?php if ($canManage && !$isBooked && $archivedChronCount > 0): ?><a href="restore_entity_chron_entries.php?entity_type=inquiry&amp;entity_id=<?php echo $inquiryId; ?>" class="button-secondary">Restore Archived Chron Log Entries (<?php echo $archivedChronCount; ?>)</a><?php endif; ?>
                </div>
                <div class="inquiry-tab-panel" id="correspondence" role="tabpanel" aria-labelledby="inquiry-correspondence-tab">
                    <div class="inquiry-correspondence-list"><?php foreach ($correspondence as $message): ?><article><div><a href="outbound_mail.php?id=<?php echo (int) $message['id']; ?>"><strong><?php echo htmlspecialchars($message['subject'], ENT_QUOTES, 'UTF-8'); ?></strong></a><span class="email-status email-status-<?php echo htmlspecialchars($message['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucfirst($message['status']), ENT_QUOTES, 'UTF-8'); ?></span></div><p>To <?php echo htmlspecialchars($message['recipient_name'] . ' <' . $message['recipient_email'] . '>', ENT_QUOTES, 'UTF-8'); ?></p><small><?php echo htmlspecialchars(applicationTimestampLabel($message['created_at'], 'M j, Y g:i A T') . ' · ' . ($message['created_by_username'] ?: 'Former user'), ENT_QUOTES, 'UTF-8'); ?></small><?php if (!empty($message['last_error'])): ?><p class="error"><?php echo htmlspecialchars($message['last_error'], ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?></article><?php endforeach; ?><?php if ($correspondence === []): ?><p class="empty-state">No outbound correspondence has been sent from this inquiry.</p><?php endif; ?></div>
                </div>
                <div class="inquiry-tab-panel" id="follow-up-work" role="tabpanel" aria-labelledby="inquiry-tasks-tab">
                    <div class="inquiry-task-list"><?php foreach ($tasks as $task): ?><article><span class="task-priority-<?php echo htmlspecialchars($task['priority'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(bookingInquiryPriorities()[$task['priority']], ENT_QUOTES, 'UTF-8'); ?></span><div><a href="edit_task.php?id=<?php echo (int) $task['id']; ?>&amp;return_to=<?php echo urlencode($taskReturn); ?>"><strong><?php echo htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8'); ?></strong></a><small><?php echo htmlspecialchars((string) ($task['assignee_username'] ?: 'Unassigned'), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars(bookingInquirySingleDateLabel($task['due_date'] ?? null, 'No due date'), ENT_QUOTES, 'UTF-8'); ?></small></div><span class="task-status task-status-<?php echo htmlspecialchars($task['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(followUpTaskStatuses()[$task['status']], ENT_QUOTES, 'UTF-8'); ?></span></article><?php endforeach; ?><?php if ($tasks === []): ?><p class="empty-state">No active follow-up work is linked to this inquiry.</p><?php endif; ?></div>
                </div>
                <footer class="inquiry-workspace-actions"><?php if ($canManage && !$isBooked): ?><button type="button" class="button-secondary" data-inquiry-open-note>Add Chron Log Entry</button><?php endif; ?><?php if ($canManage && $isActive && empty($inquiry['contact_deleted']) && !empty($inquiry['contact_email'])): ?><a href="compose_inquiry_email.php?id=<?php echo $inquiryId; ?>" class="button-secondary">Send Email</a><?php endif; ?></footer>
            </section>
        </div>

        <aside class="inquiry-detail-sidebar">
            <section class="inquiry-next-action-card"><h2>Next Action</h2><div class="inquiry-next-action-content"><span class="inquiry-next-action-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/></svg></span><div><strong><?php echo htmlspecialchars((string) ($inquiry['next_action'] ?: 'Not set'), ENT_QUOTES, 'UTF-8'); ?></strong><span>Due <?php echo htmlspecialchars(bookingInquirySingleDateLabel($inquiry['next_action_due_date'] ?? null, 'date not set'), ENT_QUOTES, 'UTF-8'); ?></span><span>Owner <b><?php echo htmlspecialchars((string) ($inquiry['owner_username'] ?: 'Unassigned'), ENT_QUOTES, 'UTF-8'); ?></b></span></div></div><?php if ($canManage && !$isBooked): ?><a href="edit_inquiry.php?id=<?php echo $inquiryId; ?>#inquiry-next-action" class="button-add inquiry-primary-action inquiry-card-wide-action">Update Next Action</a><?php endif; ?></section>
            <section class="inquiry-readiness-card"><h2>Readiness</h2><ul><?php foreach (['organization' => 'Organization Linked', 'contact' => 'Primary Contact Linked', 'dates' => 'Event Dates Identified', 'request' => 'Request Captured', 'title' => 'Event Title Captured'] as $key => $label): ?><li class="<?php echo $readiness[$key] ? 'is-ready' : ''; ?>"><span><?php echo $readiness[$key] ? '✓' : '!'; ?></span><?php echo $label; ?></li><?php endforeach; ?></ul><?php if ($canManage && $isActive): ?><a href="add_task.php?subject_type=inquiry&amp;subject_id=<?php echo $inquiryId; ?>&amp;return_to=<?php echo urlencode($taskReturn); ?>" class="button-secondary inquiry-card-wide-action">Add Task</a><?php endif; ?></section>
            <?php if ($canManage && !$isBooked): ?><section class="inquiry-stage-control" id="workflow-controls"><h2>Workflow Controls</h2><form method="post" action="view_inquiry.php"><?php echo csrfInput(); ?><input type="hidden" name="id" value="<?php echo $inquiryId; ?>"><input type="hidden" name="action" value="change_stage"><input type="hidden" name="inquiry_version" value="<?php echo htmlspecialchars($inquiry['updated_at'], ENT_QUOTES, 'UTF-8'); ?>"><label for="inquiry-stage">Move to Stage</label><select id="inquiry-stage" name="stage" required><option value="" selected disabled>Select Stage</option><?php foreach ($stages as $key => $label): ?><?php if ($key === 'booked' || $key === $inquiry['stage']) continue; ?><option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select><label for="inquiry-stage-reason">Reason / Note</label><textarea id="inquiry-stage-reason" name="stage_reason" rows="3" maxlength="1000" placeholder="Required when declining"></textarea><button type="submit" class="button-secondary">Update Stage</button></form></section><?php endif; ?>
            <section class="inquiry-marker-card">
                <h2>Email Routing Marker</h2>
                <div class="inquiry-email-marker">
                    <span class="inquiry-email-marker-control">
                        <code><?php echo htmlspecialchars($marker, ENT_QUOTES, 'UTF-8'); ?></code>
                        <button
                            type="button"
                            class="action-icon-button inquiry-marker-copy"
                            data-copy-text="<?php echo htmlspecialchars($marker, ENT_QUOTES, 'UTF-8'); ?>"
                            data-copy-status="inquiry-marker-copy-status"
                            data-tooltip="Copy marker"
                            aria-label="Copy email routing marker"
                            title="Copy email routing marker"
                        >
                            <svg class="action-icon inquiry-marker-copy-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><rect x="8" y="8" width="11" height="11" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg>
                            <svg class="action-icon inquiry-marker-copied-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg>
                        </button>
                    </span>
                    <span class="inquiry-email-marker-help">Keep this marker in the email subject or plain-text body to route the message to this Inquiry’s Chron log.</span>
                    <span id="inquiry-marker-copy-status" class="visually-hidden" role="status" aria-live="polite"></span>
                </div>
            </section>
        </aside>
    </div>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
