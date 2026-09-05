<?php

declare(strict_types=1);

function expectDashboardFeature(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Dashboard feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    if ($contents === false) {
        throw new RuntimeException("Unable to read {$path}");
    }
    return $contents;
};

$dashboard = $read('src/dashboard.php');
$helpers = $read('src/dashboard_helpers.php');
$header = $read('src/templates/header.php');
$index = $read('src/index.php');
$functions = $read('src/functions.php');
$task_helpers = $read('src/follow_up_task_helpers.php');
$styles = $read('src/assets/css/pages/dashboard.css');
$modern_styles = $read('src/assets/css/modern.css');
$my_work_panel_start = strpos($dashboard, 'id="my-work"');
$my_work_list_start = $my_work_panel_start === false
    ? false
    : strpos($dashboard, '<ul class="dashboard-record-list dashboard-task-list">', $my_work_panel_start);
$booking_panel_position = strpos($dashboard, 'id="booking-inquiries"');
$summary_grid_position = strpos($dashboard, 'class="summary-grid dashboard-summary-grid"');
$my_work_due_state = $my_work_list_start === false
    ? false
    : strpos($dashboard, "followUpTaskDueState(\$task['due_date'], \$business_date)", $my_work_list_start);
$my_work_row_class = $my_work_list_start === false
    ? false
    : strpos($dashboard, 'class="task-row-<?php echo htmlspecialchars($due_state[\'key\']', $my_work_list_start);
$my_work_due_label = $my_work_list_start === false
    ? false
    : strpos($dashboard, "htmlspecialchars(\$due_presentation['date_label']", $my_work_list_start);
$my_work_list_end = $my_work_list_start === false
    ? false
    : strpos($dashboard, '</ul>', $my_work_list_start);

expectDashboardFeature(
    str_contains($index, "header('Location: dashboard.php')")
        && str_contains($functions, "return 'dashboard.php';")
        && str_contains($header, "'dashboard' => ['dashboard.php']")
        && str_contains($header, '<span>Dashboard</span>'),
    'the application root, authentication flow, and primary navigation should use the dashboard.'
);

expectDashboardFeature(
    $booking_panel_position !== false
        && $summary_grid_position !== false
        && $booking_panel_position < $summary_grid_position
        && str_contains($dashboard, 'class="button-secondary dashboard-panel-button">Open Booking Pipeline</a>')
        && str_contains($dashboard, '<small>Booking Inquiries</small>')
        && str_contains($dashboard, '<small>All Active Work</small>')
        && str_contains($dashboard, '<small>Mail For Review</small>')
        && str_contains($dashboard, '<small>Financial Closeouts</small>'),
    'the inquiry next-actions panel should appear immediately above the dashboard count cards.'
);

expectDashboardFeature(
    str_contains($dashboard, 'fetchDashboardUpcomingEngagements')
        && str_contains($dashboard, 'fetchTaskReminderCounts')
        && str_contains($dashboard, 'fetchDashboardMyTasks')
        && str_contains($dashboard, 'fetchDashboardOpenBookingInquiryCount')
        && str_contains($dashboard, 'fetchDashboardBookingPipelineHealth')
        && str_contains($dashboard, 'fetchDashboardFinancialCloseouts')
        && str_contains($dashboard, 'dashboardEngagementReadinessIssues'),
    'the dashboard should combine schedule, personal work, readiness, and closeout data.'
);

expectDashboardFeature(
    str_contains(
        $dashboard,
        '<a class="summary-card dashboard-summary-card" href="inquiries.php?view=active">'
    )
        && str_contains($helpers, 'function fetchDashboardOpenBookingInquiryCount(')
        && str_contains($helpers, "'new', 'contacted', 'qualified', 'awaiting_details', 'proposal_sent'")
        && str_contains($helpers, "inquiry.stage = 'booked'")
        && str_contains($helpers, 'inquiry.converted_at >= ?')
        && str_contains($helpers, 'inquiry.converted_at < ?'),
    'the Booking Inquiries card should count and open the complete active pipeline, including bookings from this month.'
);

expectDashboardFeature(
    str_contains($dashboard, "in_array(\$user_role, ['admin', 'editor'], true)")
        && str_contains($dashboard, 'fetchDashboardInboundReviewCount')
        && str_contains($dashboard, '<?php if ($can_manage): ?>')
        && str_contains($dashboard, 'inbound_mail.php?status=review'),
    'mail review and write-oriented quick actions should be limited to editors and administrators.'
);

expectDashboardFeature(
    str_contains($helpers, 'COUNT(*) OVER() AS dashboard_total')
        && str_contains($helpers, "status IN ('open', 'in_progress', 'waiting')")
        && str_contains($helpers, 'report.engagement_id IS NULL')
        && str_contains($helpers, "e.lifecycle_status = 'active'")
        && str_contains($helpers, "e.lifecycle_status IN ('active', 'completed')")
        && str_contains($helpers, "WHERE status = 'review'"),
    'dashboard totals should come from bounded operational queries with the expected active-state rules.'
);

expectDashboardFeature(
    str_contains($task_helpers, "'dashboard.php'")
        && str_contains($dashboard, "'return_to' => 'dashboard.php'"),
    'task edits opened from the dashboard should safely return to it.'
);

expectDashboardFeature(
    $my_work_list_start !== false
        && $my_work_due_state !== false
        && $my_work_row_class !== false
        && $my_work_due_label !== false
        && $my_work_list_end !== false
        && $my_work_list_start < $my_work_due_state
        && $my_work_due_state < $my_work_row_class
        && $my_work_row_class < $my_work_due_label
        && $my_work_due_label < $my_work_list_end
        && preg_match('/\.dashboard-task-list > li\.task-row-overdue\s*\{[^}]*var\(--task-overdue-row-bg\)[^}]*\}/s', $styles) === 1
        && preg_match('/\.dashboard-task-list > li:is\(:hover, :focus-within\)\s*\{[^}]*var\(--dashboard-task-hover-bg\) !important;[^}]*\}/s', $styles) === 1
        && preg_match('/\.dashboard-task-list > li\.task-row-today\s*\{[^}]*var\(--task-today-row-bg\)[^}]*\}/s', $styles) === 1
        && preg_match('/html:not\(\.dark-mode\) \.dashboard-task-list > li\.task-row-overdue,[^{]*\{[^}]*--warning:\s*#843600;/s', $styles) === 1
        && preg_match('/\.dashboard-task-list > li\.task-row-overdue\s*\{[^}]*var\(--task-overdue-row-accent\)[^}]*\}/s', $styles) === 1
        && preg_match('/\.dashboard-task-list > li\.task-row-today\s*\{[^}]*var\(--task-today-row-accent\)[^}]*\}/s', $styles) === 1,
    'dashboard My Work tasks should reuse the theme-aware overdue and due-today row highlights and interaction feedback.'
);

expectDashboardFeature(
    str_contains(
        $dashboard,
        '<a class="summary-card dashboard-summary-card" href="tasks.php?view=all">'
    )
        && str_contains($dashboard, '<small>All Active Work</small><strong><?php echo $task_summary[\'all\']; ?></strong>')
        && str_contains($helpers, 'COUNT(*) AS all_active_count')
        && str_contains($helpers, "status IN ('open', 'in_progress', 'waiting')"),
    'the All Active Work card should count and open every active Work Queue item.'
);

expectDashboardFeature(
    str_contains(
        $dashboard,
        '<a class="summary-card dashboard-summary-card" href="tasks.php?view=my">'
    )
        && str_contains(
            $dashboard,
            '<a class="summary-card dashboard-summary-card summary-danger" href="tasks.php?view=overdue&amp;owner=me">'
        ),
    'the personal task summary cards should open their matching active and overdue Work Queue filters.'
);

expectDashboardFeature(
    str_contains($dashboard, '<?php if ($financial_closeout_count > 0): ?>')
        && str_contains($dashboard, 'href="#financial-closeouts"')
        && str_contains($dashboard, 'dashboard-summary-card-disabled" aria-disabled="true"')
        && preg_match('/\.dashboard-summary-card-disabled:hover\s*\{[^}]*transform:\s*none;/s', $styles) === 1,
    'the financial-closeout card should only be interactive when closeouts are due.'
);

expectDashboardFeature(
    str_contains($dashboard, 'assets/css/pages/dashboard.min.css')
        && str_contains($styles, '.dashboard-primary-grid')
        && str_contains($styles, '.dashboard-inquiry-grid')
        && str_contains($styles, 'grid-template-columns: repeat(6, minmax(0, 1fr));')
        && str_contains($styles, 'grid-column: span 4;')
        && str_contains($styles, 'grid-column: span 2;')
        && str_contains($styles, '.dashboard-health-list')
        && str_contains($dashboard, 'Pipeline Health')
        && str_contains($dashboard, 'Missing Next Action')
        && str_contains($styles, 'width: min(100%, var(--app-content-max));')
        && str_contains($styles, 'font-size: clamp(1.8rem, 3vw, 2.3rem);')
        && str_contains($styles, 'font-size: 1.75rem;')
        && str_contains($styles, 'font-variant-numeric: tabular-nums;')
        && str_contains($styles, 'gap: .2rem;')
        && preg_match('/\.dashboard-summary-card > span:last-child\s*\{[^}]*min-height:\s*56px;[^}]*align-self:\s*center;[^}]*justify-content:\s*center;/s', $styles) === 1
        && str_contains($styles, 'border-radius: 11px;')
        && str_contains($styles, 'font-size: .88rem;')
        && preg_match('/html body main\.container,[^{]*\{[^}]*background-color:\s*transparent\s*!important;/s', $modern_styles) === 1
        && preg_match('/\.dashboard-summary-card small\s*\{[^}]*text-overflow:\s*clip;[^}]*white-space:\s*normal;/s', $styles) === 1
        && preg_match('/\.dashboard-page a,[^{]*\{[^}]*text-decoration:\s*none;/s', $styles) === 1
        && preg_match('/\.dashboard-panel-heading > a:hover,[^{]*\{[^}]*background:\s*var\(--primary-subtle\);[^}]*transform:\s*translateY\(-1px\);/s', $styles) === 1
        && str_contains($dashboard, 'class="button-secondary dashboard-panel-button">Review All</a>')
        && str_contains($dashboard, 'class="button-secondary dashboard-panel-button">View All</a>')
        && str_contains($dashboard, 'class="button-secondary dashboard-panel-button">Open Queue</a>')
        && preg_match('/\.dashboard-panel-heading > a\.dashboard-panel-button:hover,[^{]*\{[^}]*transform:\s*none;/s', $styles) === 1
        && str_contains($styles, '@media (max-width: 760px)')
        && str_contains($styles, 'grid-template-columns: 1fr;'),
    'the dashboard should use a transparent page root and responsive styles with multiline summary labels and underline-free interactive links.'
);

echo "Dashboard feature tests passed.\n";
