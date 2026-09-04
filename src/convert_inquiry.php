<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$conn = applicationDatabaseConnection();
require_once __DIR__ . '/booking_inquiry_helpers.php';
require_once __DIR__ . '/follow_up_task_helpers.php';
require_once __DIR__ . '/engagement_contact_helpers.php';
require_once __DIR__ . '/map_helpers.php';
startSecureSession();
requireLogin();

if (!canManageBookingInquiries((string) ($_SESSION['role'] ?? ''))) {
    http_response_code(403);
    exit('Forbidden.');
}
$inquiryId = \Dnr\Http\RequestInput::positiveInt(
    $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET,
    'id'
);
$inquiry = $inquiryId ? fetchBookingInquiry($conn, $inquiryId) : null;
if (!$inquiry) {
    header('Location: inquiries.php');
    exit();
}
if (!in_array((string) $inquiry['stage'], bookingInquiryActiveStages(), true)) {
    header('Location: view_inquiry.php?id=' . $inquiryId);
    exit();
}
$readiness = bookingInquiryReadiness($inquiry);
$blocking = array_filter([
    'organization' => !$readiness['organization'] ? 'Select an active organization.' : null,
    'title' => !$readiness['title'] ? 'Add an event title.' : null,
    'dates' => !$readiness['dates'] ? 'Add a preferred start and end date.' : null,
]);
$conflicts = $readiness['dates'] ? bookingInquiryDateConflicts(
    $conn,
    (string) $inquiry['preferred_start_date'],
    (string) $inquiry['preferred_end_date'],
    $inquiryId
) : [];
$tasks = fetchFollowUpTasksForSubject($conn, 'inquiry', $inquiryId);
$selectedTaskIds = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_array($_POST['task_ids'] ?? null)) {
    foreach ($_POST['task_ids'] as $taskId) {
        if (is_scalar($taskId) && ctype_digit((string) $taskId)) {
            $selectedTaskIds[(int) $taskId] = true;
        }
    }
} else {
    foreach ($tasks as $task) {
        $selectedTaskIds[(int) $task['id']] = true;
    }
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['convert_inquiry'])) {
    requireValidCsrfToken();
    try {
        if ($blocking !== []) {
            throw new InvalidArgumentException('Resolve the missing required details before booking.');
        }
        $result = convertBookingInquiry(
            $conn,
            $inquiryId,
            (string) ($_POST['inquiry_version'] ?? ''),
            isset($_POST['acknowledge_conflicts']),
            array_keys($selectedTaskIds),
            (int) $_SESSION['user_id'],
            (string) $_SESSION['username']
        );
        $_SESSION['engagement_action_message'] = 'Inquiry #' . $inquiryId
            . ' was booked and preserved as the source record. '
            . $result['moved_task_count'] . ' open task'
            . ($result['moved_task_count'] === 1 ? ' was' : 's were') . ' moved; '
            . $result['checklist_count'] . ' standard task'
            . ($result['checklist_count'] === 1 ? ' was' : 's were') . ' added.';
        header('Location: view_engagement.php?id=' . $result['engagement_id']);
        exit();
    } catch (Throwable $exception) {
        $error = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'The inquiry could not be booked. No engagement was created.';
        if (!$exception instanceof InvalidArgumentException) {
            applicationLog('error', 'Inquiry conversion failed', [
                'inquiry_id' => $inquiryId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
$eventLocation = trim(implode(', ', array_filter([
    trim((string) ($inquiry['event_city'] ?? '')),
    trim((string) ($inquiry['event_state'] ?? '')),
]))) ?: 'Location not set';
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Review Booking'), ['styles' => [
    'assets/css/style.min.css', 'assets/css/modern.min.css',
    'assets/css/pages/booking_inquiries.min.css',
]]); ?>
<body class="inquiry-workflow-body inquiry-conversion-body">
<?php include 'templates/header.php'; ?>
<main class="container inquiry-conversion-page">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="inquiries.php">Booking Pipeline</a><span aria-hidden="true">/</span><a href="view_inquiry.php?id=<?php echo $inquiryId; ?>"><?php echo htmlspecialchars(bookingInquiryDisplayLabel($inquiry['title']), ENT_QUOTES, 'UTF-8'); ?></a><span aria-hidden="true">/</span><span>Convert</span></nav>
    <header class="page-heading inquiry-conversion-heading"><div><h1>Convert Inquiry to Engagement</h1><p class="page-intro">Review the imported details and resolve scheduling warnings before creating the engagement.</p></div></header>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>

    <ol class="conversion-stepper" aria-label="Conversion progress"><li class="is-complete"><span>1</span><strong>Details</strong></li><li class="is-current" aria-current="step"><span>2</span><strong>Availability</strong></li><li><span>3</span><strong>Create</strong></li></ol>

    <form method="post" action="convert_inquiry.php" class="inquiry-conversion-form">
        <?php echo csrfInput(); ?><input type="hidden" name="id" value="<?php echo $inquiryId; ?>"><input type="hidden" name="inquiry_version" value="<?php echo htmlspecialchars($inquiry['updated_at'], ENT_QUOTES, 'UTF-8'); ?>">
        <div class="conversion-review-layout">
            <section class="conversion-review-card conversion-details-card"><h2>Engagement Details</h2><dl><div><dt>Organization</dt><dd><?php echo htmlspecialchars((string) (bookingInquiryDisplayLabel($inquiry['organization_name']) ?: 'Missing'), ENT_QUOTES, 'UTF-8'); ?></dd></div><div><dt>Event Title</dt><dd><?php echo htmlspecialchars(bookingInquiryDisplayLabel($inquiry['title']), ENT_QUOTES, 'UTF-8'); ?></dd></div><div><dt>Start</dt><dd><?php echo htmlspecialchars(bookingInquirySingleDateLabel($inquiry['preferred_start_date']), ENT_QUOTES, 'UTF-8'); ?></dd></div><div><dt>End</dt><dd><?php echo htmlspecialchars(bookingInquirySingleDateLabel($inquiry['preferred_end_date']), ENT_QUOTES, 'UTF-8'); ?></dd></div><div><dt>Event Type</dt><dd><?php echo htmlspecialchars($inquiry['event_type'] === 'other' ? (string) $inquiry['event_type_other'] : ucwords($inquiry['event_type']), ENT_QUOTES, 'UTF-8'); ?></dd></div><div><dt>Caller / Owner</dt><dd><?php echo htmlspecialchars((string) ($inquiry['owner_username'] ?: $_SESSION['username']), ENT_QUOTES, 'UTF-8'); ?></dd></div></dl>
                <div class="conversion-host-card"><span class="inquiry-owner-avatar" aria-hidden="true"><?php echo htmlspecialchars(bookingInquiryInitials($inquiry['contact_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span><div><strong><?php echo htmlspecialchars((string) ($inquiry['contact_name'] ?: 'No primary host selected'), ENT_QUOTES, 'UTF-8'); ?></strong><span><?php echo $readiness['contact'] ? 'Primary host' : 'Follow-up required'; ?></span></div></div>
                <details class="conversion-task-section"<?php echo $tasks !== [] ? ' open' : ''; ?>><summary>Open Work Moving With the Engagement <span><?php echo count($tasks); ?></span></summary><p>Selected tasks will move to the Engagement. Unselected tasks remain linked to the source Inquiry for follow-up.</p><div class="conversion-task-list"><?php foreach ($tasks as $task): ?><label><input type="checkbox" name="task_ids[]" value="<?php echo (int) $task['id']; ?>"<?php echo isset($selectedTaskIds[(int) $task['id']]) ? ' checked' : ''; ?>><span><strong><?php echo htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8'); ?></strong><small><?php echo htmlspecialchars(bookingInquirySingleDateLabel($task['due_date'] ?? null, 'No due date') . ' · ' . ($task['assignee_username'] ?: 'Unassigned'), ENT_QUOTES, 'UTF-8'); ?></small></span></label><?php endforeach; ?><?php if ($tasks === []): ?><p class="empty-state">There are no active Inquiry tasks to move. The standard event checklist will still be created.</p><?php endif; ?></div></details>
                <p class="conversion-preservation-note"><span aria-hidden="true">✓</span>Inquiry activity, correspondence, stage history, and remaining tasks stay linked as read-only history.</p>
            </section>

            <section class="conversion-review-card conversion-availability-card"><h2>Availability Check</h2>
                <?php if ($blocking): ?><div class="conversion-warning-panel"><div class="conversion-warning-heading"><span aria-hidden="true">!</span><div><h3>Required Details Are Missing</h3><p>Complete these fields before creating the engagement.</p></div></div><ul class="conversion-blockers"><?php foreach ($blocking as $message): ?><li><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></li><?php endforeach; ?></ul><a href="edit_inquiry.php?id=<?php echo $inquiryId; ?>" class="button-secondary">Resolve Details</a></div><?php endif; ?>
                <?php if ($conflicts): ?><div class="conversion-warning-panel"><div class="conversion-warning-heading"><span aria-hidden="true">!</span><div><h3>Schedule Conflict Warning</h3><p>Review the overlapping dates before proceeding.</p></div></div><ul class="conversion-conflicts"><?php foreach ($conflicts as $conflict): ?><li><span class="conversion-conflict-node" aria-hidden="true"></span><div><a href="<?php echo htmlspecialchars($conflict['url'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) ($conflict['title'] ?: $conflict['organization_name']), ENT_QUOTES, 'UTF-8'); ?></a><span><?php echo htmlspecialchars(bookingInquiryDateLabel(['preferred_start_date' => $conflict['start_date'], 'preferred_end_date' => $conflict['end_date']]) . ' · ' . ($conflict['organization_name'] ?: ucfirst($conflict['record_type'])), ENT_QUOTES, 'UTF-8'); ?></span></div><b><?php echo htmlspecialchars(ucfirst($conflict['record_type']), ENT_QUOTES, 'UTF-8'); ?></b></li><?php endforeach; ?><li class="is-proposed"><span class="conversion-conflict-node" aria-hidden="true"></span><div><strong><?php echo htmlspecialchars($inquiry['title'], ENT_QUOTES, 'UTF-8'); ?></strong><span><?php echo htmlspecialchars(bookingInquiryDateLabel($inquiry) . ' · ' . $eventLocation, ENT_QUOTES, 'UTF-8'); ?></span></div><b>Proposed</b></li></ul><label class="conversion-acknowledgement"><input type="checkbox" name="acknowledge_conflicts" value="1"<?php echo isset($_POST['acknowledge_conflicts']) ? ' checked' : ''; ?> required><span><strong>I reviewed these schedule warnings.</strong><small>Booking will proceed with the dates shown.</small></span></label></div><?php endif; ?>
                <?php if ($conflicts === [] && $blocking === []): ?><div class="conversion-success-panel"><span aria-hidden="true">✓</span><div><strong>No direct date overlap detected</strong><p>No active engagement or qualified inquiry overlaps this date range.</p></div></div><?php endif; ?>
                <?php if ($conflicts !== []): ?><div class="conversion-success-panel"><span aria-hidden="true">✓</span><div><strong>Required booking details are present</strong><p>The conversion can proceed after the warning is acknowledged.</p></div></div><?php endif; ?>
            </section>
        </div>
        <div class="conversion-actions"><a href="view_inquiry.php?id=<?php echo $inquiryId; ?>" class="button-secondary conversion-back-action">‹ Back to Inquiry</a><p><span aria-hidden="true">ⓘ</span>Conversion preserves the original inquiry as read-only history.</p><button type="submit" name="convert_inquiry" value="1" class="save-button inquiry-primary-action"<?php echo $blocking ? ' disabled' : ''; ?> data-confirm="Create the engagement and mark this inquiry Booked?">Create Engagement</button></div>
    </form>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
