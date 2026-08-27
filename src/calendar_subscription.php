<?php

require_once __DIR__ . '/bootstrap.php';
$conn = applicationDatabaseConnection();
require_once __DIR__ . '/calendar_helpers.php';
require_once __DIR__ . '/follow_up_task_helpers.php';
startSecureSession();
requireLogin();
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

$user_id = (int) $_SESSION['user_id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    try {
        if ($action === 'create') {
            $label = is_string($_POST['label'] ?? null) ? $_POST['label'] : 'Calendar subscription';
            $subscription = createCalendarSubscription($conn, $user_id, $label);
            $_SESSION['_new_calendar_subscription'] = $subscription;
            recordAuditEvent($conn, [
                'event_category' => 'security',
                'event_type' => 'calendar_subscription_created',
                'actor_user_id' => $user_id,
                'target_user_id' => $user_id,
                'entity_type' => 'calendar_subscription',
                'entity_id' => $subscription['id'],
                'entity_label' => $subscription['label'],
            ]);
        } elseif ($action === 'revoke') {
            $subscription_id = filter_input(INPUT_POST, 'subscription_id', FILTER_VALIDATE_INT);
            if (!$subscription_id || !revokeCalendarSubscription($conn, $user_id, $subscription_id)) {
                throw new RuntimeException('The calendar subscription was already revoked or could not be found.');
            }
            $_SESSION['_calendar_subscription_message'] = 'Calendar subscription revoked.';
            recordAuditEvent($conn, [
                'event_category' => 'security',
                'event_type' => 'calendar_subscription_revoked',
                'actor_user_id' => $user_id,
                'target_user_id' => $user_id,
                'entity_type' => 'calendar_subscription',
                'entity_id' => $subscription_id,
            ]);
        } elseif ($action === 'purge_revoked') {
            $conn->begin_transaction();
            try {
                $purged_count = purgeRevokedCalendarSubscriptions($conn, $user_id);
                if ($purged_count > 0 && !recordAuditEvent($conn, [
                    'event_category' => 'security',
                    'event_type' => 'calendar_subscriptions_purged',
                    'actor_user_id' => $user_id,
                    'target_user_id' => $user_id,
                    'entity_type' => 'calendar_subscription',
                    'entity_label' => 'Revoked calendar subscriptions',
                    'details' => 'Purged revoked subscriptions: ' . $purged_count,
                ])) {
                    throw new RuntimeException('Unable to audit revoked subscription cleanup.');
                }
                $conn->commit();
            } catch (Throwable $purge_exception) {
                $conn->rollback();
                throw $purge_exception;
            }
            $_SESSION['_calendar_subscription_message'] = $purged_count === 0
                ? 'No revoked calendar subscriptions were found.'
                : sprintf(
                    'Purged %d revoked calendar subscription%s.',
                    $purged_count,
                    $purged_count === 1 ? '' : 's'
                );
        } else {
            throw new InvalidArgumentException('Invalid calendar subscription action.');
        }
    } catch (Throwable $exception) {
        $_SESSION['_calendar_subscription_error'] = $exception->getMessage();
    }
    header('Location: calendar_subscription.php');
    exit();
}

$new_subscription = $_SESSION['_new_calendar_subscription'] ?? null;
$message = $_SESSION['_calendar_subscription_message'] ?? '';
$error = $_SESSION['_calendar_subscription_error'] ?? '';
unset(
    $_SESSION['_new_calendar_subscription'],
    $_SESSION['_calendar_subscription_message'],
    $_SESSION['_calendar_subscription_error']
);
$business_date = applicationBusinessDate();
$requested_day = is_string($_GET['day'] ?? null) ? $_GET['day'] : null;
$has_requested_day = is_string($requested_day) && validIsoDate($requested_day);
$calendar_day = calendarDayContext($has_requested_day ? $requested_day : null, $business_date);
$requested_month = is_string($_GET['month'] ?? null) ? $_GET['month'] : null;
$effective_month = $has_requested_day
    ? substr($calendar_day['date'], 0, 7)
    : $requested_month;
$calendar_view_mode = normalizeCalendarViewerMode(
    is_string($_GET['show'] ?? null) ? $_GET['show'] : null
);
$calendar_month = calendarMonthContext($effective_month, $business_date);
$calendar_show_events = in_array($calendar_view_mode, ['events', 'everything'], true);
$calendar_show_tasks = in_array($calendar_view_mode, ['my_tasks', 'all_tasks', 'everything'], true);
$calendar_viewer_error = '';
try {
    $calendar_events = $calendar_show_events
        ? fetchCalendarViewerEngagements(
            $conn,
            $calendar_month['grid_start'],
            $calendar_month['grid_end']
        )
        : [];
    $calendar_tasks = $calendar_show_tasks
        ? fetchCalendarViewerTasks(
            $conn,
            $calendar_month['grid_start'],
            $calendar_month['grid_end'],
            $calendar_view_mode === 'my_tasks' ? $user_id : null
        )
        : [];
} catch (Throwable $exception) {
    applicationLog('error', 'Unable to load the calendar month view', [
        'error' => $exception->getMessage(),
        'month' => $calendar_month['month'],
    ]);
    $calendar_events = [];
    $calendar_tasks = [];
    $calendar_viewer_error = 'The calendar is temporarily unavailable.';
}
$calendar_events_by_date = calendarEventsByDate(
    $calendar_events,
    $calendar_month['grid_start'],
    $calendar_month['grid_end']
);
$calendar_tasks_by_date = calendarTasksByDate(
    $calendar_tasks,
    $calendar_month['grid_start'],
    $calendar_month['grid_end']
);
$calendar_month_event_count = count(array_filter(
    $calendar_events,
    static function (array $engagement) use ($calendar_month): bool {
        $event_start = trim((string) ($engagement['event_start_date'] ?? ''));
        $event_end = trim((string) ($engagement['event_end_date'] ?? '')) ?: $event_start;
        return $event_start <= $calendar_month['month_end']
            && $event_end >= $calendar_month['month_start'];
    }
));
$calendar_month_task_count = count(array_filter(
    $calendar_tasks,
    static function (array $task) use ($calendar_month): bool {
        $due_date = trim((string) ($task['due_date'] ?? ''));
        return $due_date >= $calendar_month['month_start']
            && $due_date <= $calendar_month['month_end'];
    }
));
$calendar_task_status_labels = followUpTaskStatuses();
$calendar_task_priority_labels = followUpTaskPriorities();
$can_manage_calendar_tasks = canManageFollowUpTasks($_SESSION['role'] ?? '');
$subscriptions = calendarSubscriptionsForUser($conn, $user_id);
$revoked_subscription_count = count(array_filter(
    $subscriptions,
    static fn(array $subscription): bool => $subscription['revoked_at'] !== null
));
$calendar_url = is_array($new_subscription)
    ? calendarSubscriptionUrl($_SERVER, $new_subscription['token'])
    : null;
$webcal_url = $calendar_url === null
    ? null
    : preg_replace('/^https?:\/\//i', 'webcal://', $calendar_url);
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Calendar'), array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
  ),
  'scripts' =>
  array (
    0 =>
    array (
      'path' => 'assets/js/calendar-subscription.min.js',
    ),
  ),
)); ?>
<body>
<?php include 'templates/header.php'; ?>
<main class="container calendar-subscription">
    <div class="page-heading"><div><h1>Calendar</h1><p class="page-intro">View scheduled events and tasks by month, and manage private calendar links.</p></div></div>

    <?php if ($message !== ''): ?><p class="success"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>

    <?php include 'templates/calendar_month_viewer.php'; ?>

    <section class="calendar-subscriptions-section" aria-labelledby="calendar-subscriptions-title">
        <div class="calendar-section-heading">
            <h2 id="calendar-subscriptions-title">Calendar Subscriptions</h2>
            <p>Create independently revocable private calendar links.</p>
        </div>

    <?php if ($calendar_url !== null): ?>
        <section class="security-card calendar-card" aria-labelledby="new-calendar-title">
            <h3 id="new-calendar-title">Save This New Link</h3>
            <p>This token is shown only once. Add it to your calendar now or copy it to an approved password manager.</p>
            <label for="calendar-url"><strong>Private calendar subscription URL</strong></label>
            <div class="calendar-url-row">
                <input type="url" id="calendar-url" readonly value="<?php echo htmlspecialchars($calendar_url, ENT_QUOTES, 'UTF-8'); ?>">
                <button type="button" id="copy-calendar-url">Copy URL</button>
            </div>
            <p id="copy-calendar-status" class="calendar-copy-status" aria-live="polite"></p>
            <p><a class="security-button" id="open-calendar-app" href="<?php echo htmlspecialchars($webcal_url, ENT_QUOTES, 'UTF-8'); ?>">Open in Calendar App</a></p>
        </section>
    <?php endif; ?>

    <section class="security-card calendar-card" aria-labelledby="create-calendar-title">
        <h3 id="create-calendar-title">Create Subscription</h3>
        <p>Use a separate link for each device or calendar service so a single subscriber can be revoked without disrupting the others.</p>
        <form method="post" action="calendar_subscription.php" class="security-form">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label for="subscription-label">Device or Service</label>
                <input type="text" id="subscription-label" name="label" maxlength="100" placeholder="Personal phone" required>
            </div>
            <button type="submit" class="security-button">Create Private Link</button>
        </form>
    </section>

    <section class="security-card calendar-card" aria-labelledby="existing-calendar-title">
        <div class="calendar-card-heading">
            <h3 id="existing-calendar-title">Existing Subscriptions</h3>
            <?php if ($revoked_subscription_count > 0): ?>
                <form method="post" action="calendar_subscription.php" class="calendar-purge-form" data-confirm="Permanently delete all revoked calendar token records? Active subscriptions will not be affected. This cannot be undone.">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" value="purge_revoked">
                    <button type="submit" class="danger-button">
                        Purge <?php echo $revoked_subscription_count; ?> Revoked Token<?php echo $revoked_subscription_count === 1 ? '' : 's'; ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <?php if ($subscriptions === []): ?>
            <p>No subscriptions have been created.</p>
        <?php else: ?>
            <div class="responsive-table-wrapper calendar-subscription-table-wrapper">
                <table class="calendar-subscription-table">
                    <colgroup>
                        <col class="calendar-subscription-label-column">
                        <col class="calendar-subscription-created-column">
                        <col class="calendar-subscription-last-used-column">
                        <col class="calendar-subscription-status-column">
                        <col class="calendar-subscription-action-column">
                    </colgroup>
                    <thead><tr><th>Label</th><th>Created</th><th>Last Used</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($subscriptions as $subscription): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($subscription['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($subscription['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($subscription['last_used_at'] ?: 'Never', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo $subscription['revoked_at'] === null ? 'Active' : 'Revoked'; ?></td>
                            <td>
                                <?php if ($subscription['revoked_at'] === null): ?>
                                    <form method="post" action="calendar_subscription.php" data-confirm="Revoke this calendar subscription? Existing calendar clients will stop refreshing.">
                                        <?php echo csrfInput(); ?>
                                        <input type="hidden" name="action" value="revoke">
                                        <input type="hidden" name="subscription_id" value="<?php echo (int) $subscription['id']; ?>">
                                        <button type="submit" class="danger-button">Revoke</button>
                                    </form>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <p class="calendar-privacy-note"><strong>Keep every link private:</strong> it grants access to event and presentation schedule data, but not contacts, notes, travel, lodging, or compensation.</p>
    </section>
    </section>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
