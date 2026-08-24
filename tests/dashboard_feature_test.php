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

expectDashboardFeature(
    str_contains($index, "header('Location: dashboard.php')")
        && str_contains($functions, "return 'dashboard.php';")
        && str_contains($header, "'dashboard' => ['dashboard.php']")
        && str_contains($header, '<span>Dashboard</span>'),
    'the application root, authentication flow, and primary navigation should use the dashboard.'
);

expectDashboardFeature(
    str_contains($dashboard, 'fetchDashboardUpcomingEngagements')
        && str_contains($dashboard, 'fetchDashboardTaskSummary')
        && str_contains($dashboard, 'fetchDashboardMyTasks')
        && str_contains($dashboard, 'fetchDashboardFinancialCloseouts')
        && str_contains($dashboard, 'dashboardEngagementReadinessIssues'),
    'the dashboard should combine schedule, personal work, readiness, and closeout data.'
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
    str_contains(
        $dashboard,
        '<a class="summary-card dashboard-summary-card" href="engagements.php?sort_by=date&amp;date_sort=asc">'
    ),
    'the upcoming-events summary card should open the date-sorted engagements list.'
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
        && preg_match('/\.dashboard-summary-card small\s*\{[^}]*text-overflow:\s*clip;[^}]*white-space:\s*normal;/s', $styles) === 1
        && preg_match('/\.dashboard-page a,[^{]*\{[^}]*text-decoration:\s*none;/s', $styles) === 1
        && preg_match('/\.dashboard-panel-heading > a:hover,[^{]*\{[^}]*background:\s*var\(--primary-subtle\);[^}]*transform:\s*translateY\(-1px\);/s', $styles) === 1
        && str_contains($styles, '@media (max-width: 760px)')
        && str_contains($styles, 'grid-template-columns: 1fr;'),
    'the dashboard should load a responsive stylesheet with multiline summary labels and underline-free interactive links.'
);

echo "Dashboard feature tests passed.\n";
