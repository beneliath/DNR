<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$conn = applicationDatabaseConnection();
require_once __DIR__ . '/booking_inquiry_helpers.php';
require_once __DIR__ . '/chron_log_helpers.php';
require_once __DIR__ . '/follow_up_task_helpers.php';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    if (!$canManage) {
        http_response_code(403);
        exit('Forbidden.');
    }
    $action = is_scalar($_POST['action'] ?? null) ? (string) $_POST['action'] : '';
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
        } elseif ($action === 'add_chron') {
            if ($isBooked) {
                throw new InvalidArgumentException('Booked inquiries are preserved as read-only source records.');
            }
            insertEntityChronLogEntry(
                $conn,
                'inquiry',
                $inquiryId,
                $_POST['chron_entry'] ?? '',
                $currentUserId,
                (string) $_SESSION['username']
            );
            $_SESSION['inquiry_action_message'] = 'Chron entry added.';
        } elseif ($action === 'archive_chron') {
            if ($isBooked) {
                throw new InvalidArgumentException('Booked inquiries are preserved as read-only source records.');
            }
            $entryId = filter_input(INPUT_POST, 'chron_entry_id', FILTER_VALIDATE_INT);
            archiveEntityChronLogEntry($conn, 'inquiry', $inquiryId, (int) $entryId, $currentUserId);
            $_SESSION['inquiry_action_message'] = 'Chron entry archived.';
        } else {
            throw new InvalidArgumentException('Select a valid inquiry action.');
        }
    } catch (Throwable $exception) {
        $_SESSION['inquiry_action_error'] = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'The inquiry could not be updated. Please try again.';
    }
    header('Location: view_inquiry.php?id=' . $inquiryId);
    exit();
}

$notice = (string) ($_SESSION['inquiry_action_message'] ?? '');
$error = (string) ($_SESSION['inquiry_action_error'] ?? '');
unset($_SESSION['inquiry_action_message'], $_SESSION['inquiry_action_error']);
$inquiry = fetchBookingInquiry($conn, $inquiryId);
$isBooked = $inquiry['stage'] === 'booked';
$isActive = in_array((string) $inquiry['stage'], bookingInquiryActiveStages(), true);
$tasks = fetchFollowUpTasksForSubject($conn, 'inquiry', $inquiryId);
$history = fetchBookingInquiryStageHistory($conn, $inquiryId);
$chronEntries = fetchEntityChronLogEntries($conn, 'inquiry', $inquiryId, false, 100, 0);
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
                <div><dt>Created</dt><dd><?php echo htmlspecialchars(bookingInquirySingleDateLabel(substr((string) $inquiry['created_at'], 0, 10)), ENT_QUOTES, 'UTF-8'); ?></dd></div>
            </dl></section>

            <section class="record-section inquiry-workspace" data-inquiry-tabs>
                <div class="inquiry-tab-list" role="tablist" aria-label="Inquiry work"><button type="button" role="tab" id="inquiry-activity-tab" aria-controls="inquiry-activity" aria-selected="true">Activity</button><button type="button" role="tab" id="inquiry-correspondence-tab" aria-controls="correspondence" aria-selected="false">Correspondence <span><?php echo count($correspondence); ?></span></button><button type="button" role="tab" id="inquiry-tasks-tab" aria-controls="follow-up-work" aria-selected="false">Tasks <span><?php echo count($tasks); ?></span></button></div>
                <div class="inquiry-tab-panel" id="inquiry-activity" role="tabpanel" aria-labelledby="inquiry-activity-tab">
                    <?php if ($canManage && !$isBooked): ?><details class="inquiry-add-note"><summary>Add Chron Note</summary><form method="post" action="view_inquiry.php" class="inquiry-chron-form"><?php echo csrfInput(); ?><input type="hidden" name="id" value="<?php echo $inquiryId; ?>"><input type="hidden" name="action" value="add_chron"><label for="inquiry-chron-entry">Note</label><textarea id="inquiry-chron-entry" name="chron_entry" rows="4" maxlength="100000" required placeholder="Decision, conversation outcome, commitment, or context"></textarea><button type="submit" class="save-button inquiry-primary-action">Add to Chron</button></form></details><?php endif; ?>
                    <div class="inquiry-activity-timeline"><?php foreach ($chronEntries as $entry): ?><article><span class="inquiry-activity-icon" aria-hidden="true">✎</span><div><strong><?php echo htmlspecialchars((string) ($entry['created_by_username'] ?: 'Former user'), ENT_QUOTES, 'UTF-8'); ?> added a Chron note</strong><small><?php echo htmlspecialchars($entry['created_at'] . ' UTC', ENT_QUOTES, 'UTF-8'); ?></small><div class="chron-entry-text"><?php echo renderChronLogEntryHtml($entry['entry_text']); ?></div><?php if ($canManage && !$isBooked): ?><form method="post" action="view_inquiry.php" data-confirm="Archive this Chron entry?"><?php echo csrfInput(); ?><input type="hidden" name="id" value="<?php echo $inquiryId; ?>"><input type="hidden" name="action" value="archive_chron"><input type="hidden" name="chron_entry_id" value="<?php echo (int) $entry['id']; ?>"><button type="submit" class="action-button">Archive</button></form><?php endif; ?></div></article><?php endforeach; ?><?php foreach ($history as $entry): ?><article><span class="inquiry-activity-icon inquiry-stage-activity-icon" aria-hidden="true">✓</span><div><strong>Moved to <?php echo htmlspecialchars($stages[$entry['to_stage']], ENT_QUOTES, 'UTF-8'); ?></strong><small><?php echo htmlspecialchars($entry['changed_at'] . ' UTC · ' . ($entry['changed_by_username'] ?: 'Former user'), ENT_QUOTES, 'UTF-8'); ?></small><?php if (!empty($entry['reason'])): ?><p><?php echo htmlspecialchars($entry['reason'], ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?></div></article><?php endforeach; ?><?php if ($chronEntries === [] && $history === []): ?><p class="empty-state">No inquiry activity has been recorded.</p><?php endif; ?></div>
                    <?php if ($canManage && !$isBooked && $archivedChronCount > 0): ?><a href="restore_entity_chron_entries.php?entity_type=inquiry&amp;entity_id=<?php echo $inquiryId; ?>" class="button-secondary">Restore Archived Notes (<?php echo $archivedChronCount; ?>)</a><?php endif; ?>
                </div>
                <div class="inquiry-tab-panel" id="correspondence" role="tabpanel" aria-labelledby="inquiry-correspondence-tab">
                    <div class="inquiry-correspondence-list"><?php foreach ($correspondence as $message): ?><article><div><a href="outbound_mail.php?id=<?php echo (int) $message['id']; ?>"><strong><?php echo htmlspecialchars($message['subject'], ENT_QUOTES, 'UTF-8'); ?></strong></a><span class="email-status email-status-<?php echo htmlspecialchars($message['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucfirst($message['status']), ENT_QUOTES, 'UTF-8'); ?></span></div><p>To <?php echo htmlspecialchars($message['recipient_name'] . ' <' . $message['recipient_email'] . '>', ENT_QUOTES, 'UTF-8'); ?></p><small><?php echo htmlspecialchars($message['created_at'] . ' UTC · ' . ($message['created_by_username'] ?: 'Former user'), ENT_QUOTES, 'UTF-8'); ?></small><?php if (!empty($message['last_error'])): ?><p class="error"><?php echo htmlspecialchars($message['last_error'], ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?></article><?php endforeach; ?><?php if ($correspondence === []): ?><p class="empty-state">No outbound correspondence has been sent from this inquiry.</p><?php endif; ?></div>
                </div>
                <div class="inquiry-tab-panel" id="follow-up-work" role="tabpanel" aria-labelledby="inquiry-tasks-tab">
                    <div class="inquiry-task-list"><?php foreach ($tasks as $task): ?><article><span class="task-priority-<?php echo htmlspecialchars($task['priority'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(bookingInquiryPriorities()[$task['priority']], ENT_QUOTES, 'UTF-8'); ?></span><div><a href="edit_task.php?id=<?php echo (int) $task['id']; ?>&amp;return_to=<?php echo urlencode($taskReturn); ?>"><strong><?php echo htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8'); ?></strong></a><small><?php echo htmlspecialchars((string) ($task['assignee_username'] ?: 'Unassigned'), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars(bookingInquirySingleDateLabel($task['due_date'] ?? null, 'No due date'), ENT_QUOTES, 'UTF-8'); ?></small></div><span class="task-status task-status-<?php echo htmlspecialchars($task['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(followUpTaskStatuses()[$task['status']], ENT_QUOTES, 'UTF-8'); ?></span></article><?php endforeach; ?><?php if ($tasks === []): ?><p class="empty-state">No active follow-up work is linked to this inquiry.</p><?php endif; ?></div>
                </div>
                <footer class="inquiry-workspace-actions"><?php if ($canManage && !$isBooked): ?><button type="button" class="button-secondary" data-inquiry-open-note>Add Chron Note</button><?php endif; ?><?php if ($canManage && $isActive && empty($inquiry['contact_deleted']) && !empty($inquiry['contact_email'])): ?><a href="compose_inquiry_email.php?id=<?php echo $inquiryId; ?>" class="button-secondary">Send Email</a><?php endif; ?></footer>
            </section>
        </div>

        <aside class="inquiry-detail-sidebar">
            <section class="inquiry-next-action-card"><h2>Next Action</h2><div class="inquiry-next-action-content"><span class="inquiry-next-action-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/></svg></span><div><strong><?php echo htmlspecialchars((string) ($inquiry['next_action'] ?: 'Not set'), ENT_QUOTES, 'UTF-8'); ?></strong><span>Due <?php echo htmlspecialchars(bookingInquirySingleDateLabel($inquiry['next_action_due_date'] ?? null, 'date not set'), ENT_QUOTES, 'UTF-8'); ?></span><span>Owner <b><?php echo htmlspecialchars((string) ($inquiry['owner_username'] ?: 'Unassigned'), ENT_QUOTES, 'UTF-8'); ?></b></span></div></div><?php if ($canManage && !$isBooked): ?><a href="edit_inquiry.php?id=<?php echo $inquiryId; ?>#inquiry-next-action" class="button-add inquiry-primary-action inquiry-card-wide-action">Update Next Action</a><?php endif; ?></section>
            <section class="inquiry-readiness-card"><h2>Readiness</h2><ul><?php foreach (['organization' => 'Organization Linked', 'contact' => 'Primary Contact Linked', 'dates' => 'Target Date Identified', 'request' => 'Request Captured', 'title' => 'Event Title Captured'] as $key => $label): ?><li class="<?php echo $readiness[$key] ? 'is-ready' : ''; ?>"><span><?php echo $readiness[$key] ? '✓' : '!'; ?></span><?php echo $label; ?></li><?php endforeach; ?></ul><?php if ($canManage && $isActive): ?><a href="add_task.php?subject_type=inquiry&amp;subject_id=<?php echo $inquiryId; ?>&amp;return_to=<?php echo urlencode($taskReturn); ?>" class="button-secondary inquiry-card-wide-action">Add Task</a><?php endif; ?></section>
            <?php if ($canManage && !$isBooked): ?><section class="inquiry-stage-control" id="workflow-controls"><h2>Workflow Controls</h2><form method="post" action="view_inquiry.php"><?php echo csrfInput(); ?><input type="hidden" name="id" value="<?php echo $inquiryId; ?>"><input type="hidden" name="action" value="change_stage"><input type="hidden" name="inquiry_version" value="<?php echo htmlspecialchars($inquiry['updated_at'], ENT_QUOTES, 'UTF-8'); ?>"><label for="inquiry-stage">Move to Stage</label><select id="inquiry-stage" name="stage" required><option value="" selected disabled>Select Stage</option><?php foreach ($stages as $key => $label): ?><?php if ($key === 'booked' || $key === $inquiry['stage']) continue; ?><option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select><label for="inquiry-stage-reason">Reason / Note</label><textarea id="inquiry-stage-reason" name="stage_reason" rows="3" maxlength="1000" placeholder="Required when declining"></textarea><button type="submit" class="button-secondary">Update Stage</button></form></section><?php endif; ?>
            <details class="inquiry-marker-card"><summary>Reply Routing</summary><code><?php echo htmlspecialchars($marker, ENT_QUOTES, 'UTF-8'); ?></code><p>Keep this signed marker in the subject or plain-text body so replies return to this inquiry Chron.</p></details>
        </aside>
    </div>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
