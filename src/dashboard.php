<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/dashboard_helpers.php';
require_once __DIR__ . '/follow_up_task_helpers.php';
$conn = applicationDatabaseConnection();
startSecureSession();
requireLogin();

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$user_role = (string) ($_SESSION['role'] ?? '');
$can_manage = in_array($user_role, ['admin', 'editor'], true);
$business_date = applicationBusinessDate();
$upcoming_window_end = applicationBusinessDateOffset(30);

try {
    $upcoming_engagements = fetchDashboardUpcomingEngagements(
        $conn,
        $business_date,
        $upcoming_window_end
    );
    $task_summary = fetchDashboardTaskSummary($conn, $user_id, $business_date);
    $my_tasks = fetchDashboardMyTasks($conn, $user_id);
    $financial_closeouts = fetchDashboardFinancialCloseouts($conn, $business_date);
    $inbound_review_count = $can_manage
        ? fetchDashboardInboundReviewCount($conn)
        : 0;
} catch (Throwable $exception) {
    applicationLog('error', 'Dashboard loading failed', [
        'user_id' => $user_id,
        'error' => $exception->getMessage(),
    ]);
    abortApplication(503, 'The dashboard is temporarily unavailable.');
}

$upcoming_count = $upcoming_engagements === []
    ? 0
    : (int) $upcoming_engagements[0]['dashboard_total'];
$financial_closeout_count = $financial_closeouts === []
    ? 0
    : (int) $financial_closeouts[0]['dashboard_total'];
$readiness_items = [];
foreach ($upcoming_engagements as $upcoming_engagement) {
    $issues = dashboardEngagementReadinessIssues($upcoming_engagement);
    if ($issues === []) {
        continue;
    }
    $upcoming_engagement['readiness_issues'] = $issues;
    $readiness_items[] = $upcoming_engagement;
    if (count($readiness_items) === 8) {
        break;
    }
}

$displayed_upcoming_engagements = array_slice($upcoming_engagements, 0, 8);
$business_date_value = DateTimeImmutable::createFromFormat('!Y-m-d', $business_date);
$business_date_label = $business_date_value instanceof DateTimeImmutable
    ? $business_date_value->format('l, F j, Y')
    : $business_date;
$display_name = dashboardGreetingName($_SESSION);
$task_status_labels = followUpTaskStatuses();
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead('Dashboard - MOED', [
    'styles' => [
        'assets/css/style.min.css',
        'assets/css/modern.min.css',
        'assets/css/pages/dashboard.min.css',
    ],
]); ?>
<body>
<?php include 'templates/header.php'; ?>
<main class="container dashboard-page">
    <div class="page-heading dashboard-heading">
        <div>
            <h1>Good day, <?php echo htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="page-intro">Your operations view for <?php echo htmlspecialchars($business_date_label, ENT_QUOTES, 'UTF-8'); ?>:</p>
        </div>
        <div class="page-heading-actions">
            <a href="tasks.php?view=my" class="button-secondary">Open my work</a>
            <?php if ($can_manage): ?>
                <a href="add_task.php?return_to=dashboard.php" class="button-secondary">+ New task</a>
                <a href="index.php" class="button-add">+ New engagement</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="summary-grid dashboard-summary-grid" aria-label="Daily operations summary">
        <a class="summary-card dashboard-summary-card" href="engagements.php?sort_by=date&amp;date_sort=asc">
            <span class="summary-icon" aria-hidden="true">◇</span>
            <span><small>Events, next 30 days</small><strong><?php echo $upcoming_count; ?></strong></span>
        </a>
        <a class="summary-card dashboard-summary-card" href="tasks.php?view=my">
            <span class="summary-icon" aria-hidden="true">✓</span>
            <span><small>My active work</small><strong><?php echo $task_summary['active']; ?></strong></span>
        </a>
        <a class="summary-card dashboard-summary-card summary-danger" href="tasks.php?view=my">
            <span class="summary-icon" aria-hidden="true">!</span>
            <span><small>My overdue work</small><strong><?php echo $task_summary['overdue']; ?></strong></span>
        </a>
        <?php if ($financial_closeout_count > 0): ?>
            <a class="summary-card dashboard-summary-card summary-review" href="#financial-closeouts">
                <span class="summary-icon" aria-hidden="true">$</span>
                <span><small>Financial closeouts due</small><strong><?php echo $financial_closeout_count; ?></strong></span>
            </a>
        <?php else: ?>
            <div class="summary-card dashboard-summary-card summary-review dashboard-summary-card-disabled" aria-disabled="true">
                <span class="summary-icon" aria-hidden="true">$</span>
                <span><small>Financial closeouts due</small><strong>0</strong></span>
            </div>
        <?php endif; ?>
        <?php if ($can_manage): ?>
            <a class="summary-card dashboard-summary-card<?php echo $inbound_review_count > 0 ? ' summary-review' : ''; ?>" href="inbound_mail.php?status=review">
                <span class="summary-icon" aria-hidden="true">@</span>
                <span><small>Mail awaiting review</small><strong><?php echo $inbound_review_count; ?></strong></span>
            </a>
        <?php endif; ?>
    </div>

    <div class="dashboard-primary-grid">
        <section class="dashboard-panel" id="upcoming-engagements" aria-labelledby="upcoming-engagements-heading">
            <div class="dashboard-panel-heading">
                <div>
                    <h2 id="upcoming-engagements-heading">Upcoming Engagements</h2>
                    <p>Active events beginning or continuing during the next 30 days.</p>
                </div>
                <a href="engagements.php?sort_by=date&amp;date_sort=asc">View all</a>
            </div>
            <?php if ($displayed_upcoming_engagements === []): ?>
                <div class="dashboard-empty-state"><strong>No upcoming engagements</strong><span>The next 30 days are clear.</span></div>
            <?php else: ?>
                <ul class="dashboard-record-list">
                    <?php foreach ($displayed_upcoming_engagements as $engagement): ?>
                        <?php
                        $issues = dashboardEngagementReadinessIssues($engagement);
                        $status_class = str_replace('_', '-', (string) $engagement['confirmation_status']);
                        ?>
                        <li>
                            <div class="dashboard-record-main">
                                <a class="record-link" href="view_engagement.php?id=<?php echo (int) $engagement['id']; ?>"><?php echo htmlspecialchars(dashboardEngagementLabel($engagement), ENT_QUOTES, 'UTF-8'); ?></a>
                                <span><?php echo htmlspecialchars((string) $engagement['organization_name'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars(dashboardDateRangeLabel($engagement['event_start_date'], $engagement['event_end_date']), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <div class="dashboard-record-meta">
                                <?php if ($issues !== []): ?><span class="dashboard-issue-count"><?php echo count($issues); ?> detail<?php echo count($issues) === 1 ? '' : 's'; ?> needed</span><?php endif; ?>
                                <span class="dashboard-status dashboard-status-<?php echo htmlspecialchars($status_class, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(dashboardConfirmationStatusLabel($engagement['confirmation_status']), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="dashboard-panel" id="my-work" aria-labelledby="my-work-heading">
            <div class="dashboard-panel-heading">
                <div>
                    <h2 id="my-work-heading">My Work</h2>
                    <p><?php echo $task_summary['today']; ?> due today, ordered by urgency and due date.</p>
                </div>
                <a href="tasks.php?view=my">Open queue</a>
            </div>
            <?php if ($my_tasks === []): ?>
                <div class="dashboard-empty-state"><strong>No active assigned work</strong><span>You are caught up.</span></div>
            <?php else: ?>
                <ul class="dashboard-record-list dashboard-task-list">
                    <?php foreach ($my_tasks as $task): ?>
                        <?php
                        $due_state = followUpTaskDueState($task['due_date'], $business_date);
                        $subject = followUpTaskSubjectFromRow($task);
                        $task_url = $can_manage
                            ? 'edit_task.php?' . http_build_query([
                                'id' => (int) $task['id'],
                                'return_to' => 'dashboard.php',
                            ])
                            : $subject['url'];
                        ?>
                        <li>
                            <div class="dashboard-record-main">
                                <a class="record-link" href="<?php echo htmlspecialchars($task_url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $task['title'], ENT_QUOTES, 'UTF-8'); ?></a>
                                <span><a href="<?php echo htmlspecialchars($subject['url'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($subject['label'], ENT_QUOTES, 'UTF-8'); ?></a></span>
                            </div>
                            <div class="dashboard-record-meta">
                                <span class="task-due task-due-<?php echo htmlspecialchars($due_state['key'], ENT_QUOTES, 'UTF-8'); ?> task-priority-<?php echo htmlspecialchars((string) $task['priority'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($due_state['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="task-status task-status-<?php echo htmlspecialchars((string) $task['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($task_status_labels[$task['status']] ?? (string) $task['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <div class="dashboard-attention-heading">
        <div><h2>Needs Attention</h2><p>Upcoming records and completed events that still need an operational decision.</p></div>
        <?php if ($can_manage && $inbound_review_count > 0): ?>
            <a href="inbound_mail.php?status=review" class="dashboard-mail-alert"><?php echo $inbound_review_count; ?> inbound message<?php echo $inbound_review_count === 1 ? '' : 's'; ?> awaiting routing review</a>
        <?php endif; ?>
    </div>

    <div class="dashboard-attention-grid">
        <section class="dashboard-panel" id="event-readiness" aria-labelledby="event-readiness-heading">
            <div class="dashboard-panel-heading">
                <div>
                    <h3 id="event-readiness-heading">Event Readiness</h3>
                    <p>Missing essentials for events in the next 30 days.</p>
                </div>
            </div>
            <?php if ($readiness_items === []): ?>
                <div class="dashboard-empty-state dashboard-empty-success"><strong>Upcoming records are ready</strong><span>No missing essentials were found.</span></div>
            <?php else: ?>
                <ul class="dashboard-attention-list">
                    <?php foreach ($readiness_items as $engagement): ?>
                        <li>
                            <a href="<?php echo $can_manage ? 'edit_engagement.php' : 'view_engagement.php'; ?>?id=<?php echo (int) $engagement['id']; ?>"><?php echo htmlspecialchars(dashboardEngagementLabel($engagement), ENT_QUOTES, 'UTF-8'); ?></a>
                            <div class="dashboard-issue-tags">
                                <?php foreach ($engagement['readiness_issues'] as $issue): ?><span><?php echo htmlspecialchars((string) $issue, ENT_QUOTES, 'UTF-8'); ?></span><?php endforeach; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="dashboard-panel" id="financial-closeouts" aria-labelledby="financial-closeouts-heading">
            <div class="dashboard-panel-heading">
                <div>
                    <h3 id="financial-closeouts-heading">Financial Closeouts</h3>
                    <p>Ended active events without a finalized financial report.</p>
                </div>
            </div>
            <?php if ($financial_closeouts === []): ?>
                <div class="dashboard-empty-state dashboard-empty-success"><strong>Closeouts are current</strong><span>No completed event is waiting for final figures.</span></div>
            <?php else: ?>
                <ul class="dashboard-attention-list dashboard-closeout-list">
                    <?php foreach ($financial_closeouts as $engagement): ?>
                        <li>
                            <div>
                                <a href="<?php echo $can_manage ? 'close_engagement.php' : 'view_engagement.php'; ?>?id=<?php echo (int) $engagement['id']; ?>"><?php echo htmlspecialchars(dashboardEngagementLabel($engagement), ENT_QUOTES, 'UTF-8'); ?></a>
                                <span><?php echo htmlspecialchars((string) $engagement['organization_name'], ENT_QUOTES, 'UTF-8'); ?> · ended <?php echo htmlspecialchars((string) ($engagement['event_end_date'] ?: $engagement['event_start_date']), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <strong><?php echo (int) $engagement['days_overdue']; ?> day<?php echo (int) $engagement['days_overdue'] === 1 ? '' : 's'; ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
