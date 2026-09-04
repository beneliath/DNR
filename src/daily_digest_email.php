<?php

declare(strict_types=1);

function dailyTaskDigestHtmlEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @param array<string, scalar|null> $query */
function dailyTaskDigestHtmlUrl(
    string $path,
    array $query = [],
    string $fragment = ''
): string {
    $url = applicationPublicUrl($path, $query);
    if ($fragment !== '') {
        $url .= '#' . rawurlencode($fragment);
    }
    return dailyTaskDigestHtmlEscape($url);
}

/** @return array{background: string, color: string} */
function dailyTaskDigestPriorityStyle(mixed $priority): array
{
    return match ((string) $priority) {
        'urgent' => ['background' => '#fff0ee', 'color' => '#b42318'],
        'high' => ['background' => '#fff3d8', 'color' => '#843600'],
        'low' => ['background' => '#e7f6ef', 'color' => '#137a55'],
        default => ['background' => '#eff4ff', 'color' => '#2457d6'],
    };
}

/** @return array{background: string, color: string} */
function dailyTaskDigestStatusStyle(mixed $status): array
{
    return match ((string) $status) {
        'in_progress' => ['background' => '#eff4ff', 'color' => '#2457d6'],
        'waiting' => ['background' => '#fff3d8', 'color' => '#843600'],
        'completed' => ['background' => '#e7f6ef', 'color' => '#137a55'],
        'canceled' => ['background' => '#fff0ee', 'color' => '#b42318'],
        default => ['background' => '#f1f5f9', 'color' => '#667085'],
    };
}

/** @return array{background: string, color: string} */
function dailyTaskDigestConfirmationStyle(mixed $status): array
{
    return match ((string) $status) {
        'confirmed' => ['background' => '#e7f6ef', 'color' => '#137a55'],
        'under_review' => ['background' => '#fff3d8', 'color' => '#843600'],
        'work_in_progress' => ['background' => '#eff4ff', 'color' => '#2457d6'],
        default => ['background' => '#f1f5f9', 'color' => '#667085'],
    };
}

/** @param array<string, mixed> $task */
function dailyTaskDigestTaskSubject(array $task): array
{
    if (function_exists('followUpTaskSubjectFromRow')) {
        return followUpTaskSubjectFromRow($task);
    }
    return [
        'type' => 'general',
        'label' => applicationGeneralWorkLabel(),
        'url' => 'tasks.php',
    ];
}

/**
 * @param array<string, mixed> $user
 * @param array<string, mixed> $digest
 */
function renderDailyTaskDigestText(
    array $user,
    array $digest,
    string $businessDate
): string {
    $name = trim((string) ($user['first_name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($user['username'] ?? 'there')) ?: 'there';
    }
    $role = (string) ($user['role'] ?? '');
    $canManage = in_array($role, ['admin', 'editor'], true);
    $brandName = applicationBrandName();
    $dashboard = is_array($digest['dashboard'] ?? null) ? $digest['dashboard'] : [];
    $counts = is_array($digest['counts'] ?? null) ? $digest['counts'] : [];
    $taskSummary = is_array($dashboard['task_summary'] ?? null)
        ? $dashboard['task_summary']
        : [
            'active' => (int) ($counts['active'] ?? 0),
            'overdue' => (int) ($counts['dashboard_overdue'] ?? $counts['overdue'] ?? 0),
            'today' => (int) ($counts['dashboard_today'] ?? $counts['today'] ?? 0),
        ];
    $myTasks = is_array($dashboard['my_tasks'] ?? null)
        ? $dashboard['my_tasks']
        : array_slice(array_merge(
            is_array($digest['overdue'] ?? null) ? $digest['overdue'] : [],
            is_array($digest['today'] ?? null) ? $digest['today'] : [],
            is_array($digest['upcoming'] ?? null) ? $digest['upcoming'] : [],
            is_array($digest['waiting'] ?? null) ? $digest['waiting'] : []
        ), 0, 8);
    $upcoming = is_array($dashboard['upcoming_engagements'] ?? null)
        ? $dashboard['upcoming_engagements']
        : [];
    $readiness = is_array($dashboard['readiness_items'] ?? null)
        ? $dashboard['readiness_items']
        : [];
    $closeouts = is_array($dashboard['financial_closeouts'] ?? null)
        ? $dashboard['financial_closeouts']
        : (is_array($digest['closeouts'] ?? null) ? $digest['closeouts'] : []);
    $upcomingCount = (int) ($dashboard['upcoming_count'] ?? count($upcoming));
    $closeoutCount = (int) (
        $dashboard['financial_closeout_count']
        ?? $counts['closeouts']
        ?? count($closeouts)
    );
    $inboundCount = $canManage
        ? (int) ($dashboard['inbound_review_count'] ?? 0)
        : 0;
    $statusLabels = followUpTaskStatuses();
    $priorityLabels = followUpTaskPriorities();
    $dashboardDays = applicationWorkflowSetting('dashboard_upcoming_days');

    $lines = [
        'Good day, ' . $name . '.',
        '',
        'Here is your ' . $brandName . ' operations view for '
            . digestDisplayDate($businessDate) . '.',
        '',
        'DASHBOARD SUMMARY',
        '- Engagements, Next ' . $dashboardDays . ' Days: ' . $upcomingCount,
        '- My Active Work: ' . (int) ($taskSummary['active'] ?? 0),
        '- My Overdue Work: ' . (int) ($taskSummary['overdue'] ?? 0),
        '- Due Today: ' . (int) ($taskSummary['today'] ?? 0),
        '- Financial Closeouts: ' . $closeoutCount,
    ];
    if ($canManage) {
        $lines[] = '- Mail For Review: ' . $inboundCount;
    }

    $lines[] = '';
    $lines[] = 'UPCOMING ENGAGEMENTS (' . $upcomingCount . ')';
    if ($upcoming === []) {
        $lines[] = '- No upcoming engagements.';
    } else {
        foreach ($upcoming as $engagement) {
            $lines[] = '- ' . dashboardEngagementLabel($engagement) . ' · '
                . (string) ($engagement['organization_name'] ?? 'Organization') . ' · '
                . dashboardDateRangeLabel(
                    $engagement['event_start_date'] ?? '',
                    $engagement['event_end_date'] ?? ''
                );
            $lines[] = '  ' . applicationPublicUrl('view_engagement.php', [
                'id' => (int) ($engagement['id'] ?? 0),
            ]);
        }
        if ($upcomingCount > count($upcoming)) {
            $lines[] = '- … and ' . ($upcomingCount - count($upcoming)) . ' more';
        }
    }

    $lines[] = '';
    $lines[] = 'MY WORK (' . (int) ($taskSummary['active'] ?? 0) . ')';
    if ($myTasks === []) {
        $lines[] = '- No active assigned work. You are caught up.';
    } else {
        foreach ($myTasks as $task) {
            $dueState = followUpTaskDueState($task['due_date'] ?? null, $businessDate);
            $subject = dailyTaskDigestTaskSubject($task);
            $status = (string) ($task['status'] ?? 'open');
            $priority = (string) ($task['priority'] ?? 'normal');
            $lines[] = '- [' . ($priorityLabels[$priority] ?? ucfirst($priority))
                . ' · ' . ($statusLabels[$status] ?? ucfirst($status)) . '] '
                . trim((string) ($task['title'] ?? 'Task')) . ' — '
                . $dueState['label'];
            $waitingOn = trim((string) ($task['waiting_on'] ?? ''));
            if ($waitingOn !== '') {
                $lines[] = '  Waiting on: ' . $waitingOn;
            }
            $lines[] = '  Related: ' . (string) ($subject['label'] ?? applicationGeneralWorkLabel())
                . ' — ' . applicationPublicUrl((string) ($subject['url'] ?? 'tasks.php'));
            $lines[] = '  Open: ' . ($canManage
                ? applicationPublicUrl('edit_task.php', [
                    'id' => (int) ($task['id'] ?? 0),
                    'return_to' => 'dashboard.php',
                ])
                : applicationPublicUrl((string) ($subject['url'] ?? 'tasks.php')));
        }
        if ((int) ($taskSummary['active'] ?? 0) > count($myTasks)) {
            $lines[] = '- … and '
                . ((int) $taskSummary['active'] - count($myTasks)) . ' more';
        }
    }

    $lines[] = '';
    $lines[] = 'EVENT READINESS (' . count($readiness) . ')';
    if ($readiness === []) {
        $lines[] = '- Upcoming records are ready.';
    } else {
        foreach ($readiness as $engagement) {
            $issues = is_array($engagement['readiness_issues'] ?? null)
                ? $engagement['readiness_issues']
                : dashboardEngagementReadinessIssues($engagement);
            $lines[] = '- ' . dashboardEngagementLabel($engagement) . ' — '
                . implode('; ', array_map('strval', $issues));
            $lines[] = '  ' . applicationPublicUrl(
                $canManage ? 'edit_engagement.php' : 'view_engagement.php',
                ['id' => (int) ($engagement['id'] ?? 0)]
            );
        }
    }

    $lines[] = '';
    $lines[] = 'FINANCIAL CLOSEOUTS (' . $closeoutCount . ')';
    if ($closeouts === []) {
        $lines[] = '- Closeouts are current.';
    } else {
        foreach ($closeouts as $engagement) {
            $title = dashboardEngagementLabel($engagement);
            $endDate = (string) (
                ($engagement['event_end_date'] ?? '')
                ?: ($engagement['event_start_date'] ?? '')
            );
            $lines[] = '- ' . $title . ' · '
                . (string) ($engagement['organization_name'] ?? 'Organization')
                . ' — ended ' . $endDate;
            $lines[] = '  ' . applicationPublicUrl(
                $canManage ? 'close_engagement.php' : 'view_engagement.php',
                ['id' => (int) ($engagement['id'] ?? 0)]
            );
        }
        if ($closeoutCount > count($closeouts)) {
            $lines[] = '- … and ' . ($closeoutCount - count($closeouts)) . ' more';
        }
    }

    if ($canManage && $inboundCount > 0) {
        $lines[] = '';
        $lines[] = 'Review inbound mail: ' . applicationPublicUrl(
            'inbound_mail.php',
            ['status' => 'review']
        );
    }
    $lines[] = '';
    $lines[] = 'Open your work queue: ' . applicationPublicUrl('tasks.php', [
        'view' => 'my',
    ]);
    $lines[] = 'Open your Dashboard: ' . applicationPublicUrl('dashboard.php');
    $lines[] = 'Manage this digest: ' . applicationPublicUrl('profile.php')
        . '#notification-preferences-heading';

    return implode("\n", $lines);
}

/**
 * Render a deliberately light-only, email-client-compatible Dashboard snapshot.
 * Dynamic values are escaped here even though the same data is escaped on the
 * web Dashboard; queued mail must remain safe when records change later.
 *
 * @param array<string, mixed> $user
 * @param array<string, mixed> $digest
 */
function renderDailyTaskDigestHtml(
    array $user,
    array $digest,
    string $businessDate
): string {
    $name = trim((string) ($user['first_name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($user['username'] ?? 'there')) ?: 'there';
    }
    $role = (string) ($user['role'] ?? '');
    $canManage = in_array($role, ['admin', 'editor'], true);
    $brandName = applicationBrandName();
    $displayDate = digestDisplayDate($businessDate);
    $dashboardUpcomingDays = applicationWorkflowSetting('dashboard_upcoming_days');
    $dashboard = is_array($digest['dashboard'] ?? null) ? $digest['dashboard'] : [];
    $counts = is_array($digest['counts'] ?? null) ? $digest['counts'] : [];

    $taskSummary = is_array($dashboard['task_summary'] ?? null)
        ? $dashboard['task_summary']
        : [
            'active' => (int) ($counts['overdue'] ?? 0)
                + (int) ($counts['today'] ?? 0)
                + (int) ($counts['upcoming'] ?? 0)
                + (int) ($counts['waiting'] ?? 0),
            'overdue' => (int) ($counts['overdue'] ?? 0),
            'today' => (int) ($counts['today'] ?? 0),
        ];
    $myTasks = is_array($dashboard['my_tasks'] ?? null)
        ? $dashboard['my_tasks']
        : array_slice(array_merge(
            is_array($digest['overdue'] ?? null) ? $digest['overdue'] : [],
            is_array($digest['today'] ?? null) ? $digest['today'] : [],
            is_array($digest['upcoming'] ?? null) ? $digest['upcoming'] : [],
            is_array($digest['waiting'] ?? null) ? $digest['waiting'] : []
        ), 0, 8);
    $upcomingEngagements = is_array($dashboard['upcoming_engagements'] ?? null)
        ? $dashboard['upcoming_engagements']
        : [];
    $readinessItems = is_array($dashboard['readiness_items'] ?? null)
        ? $dashboard['readiness_items']
        : [];
    $financialCloseouts = is_array($dashboard['financial_closeouts'] ?? null)
        ? $dashboard['financial_closeouts']
        : (is_array($digest['closeouts'] ?? null) ? $digest['closeouts'] : []);
    $upcomingCount = (int) ($dashboard['upcoming_count'] ?? count($upcomingEngagements));
    $financialCloseoutCount = (int) (
        $dashboard['financial_closeout_count']
        ?? $counts['closeouts']
        ?? count($financialCloseouts)
    );
    $inboundReviewCount = $canManage
        ? (int) ($dashboard['inbound_review_count'] ?? 0)
        : 0;
    $statusLabels = function_exists('followUpTaskStatuses')
        ? followUpTaskStatuses()
        : [
            'open' => 'Open',
            'in_progress' => 'In progress',
            'waiting' => 'Waiting',
            'completed' => 'Completed',
            'canceled' => 'Canceled',
        ];

    $preheader = sprintf(
        '%d active task%s, %d overdue, and %d due today.',
        (int) ($taskSummary['active'] ?? 0),
        (int) ($taskSummary['active'] ?? 0) === 1 ? '' : 's',
        (int) ($taskSummary['overdue'] ?? 0),
        (int) ($taskSummary['today'] ?? 0)
    );
    $dashboardUrl = dailyTaskDigestHtmlUrl('dashboard.php');
    $taskQueueUrl = dailyTaskDigestHtmlUrl('tasks.php', ['view' => 'my']);
    $engagementsUrl = dailyTaskDigestHtmlUrl('engagements.php', [
        'sort_by' => 'date',
        'date_sort' => 'asc',
    ]);
    $closeoutsUrl = dailyTaskDigestHtmlUrl('dashboard.php', [], 'financial-closeouts');
    $inboundUrl = dailyTaskDigestHtmlUrl('inbound_mail.php', ['status' => 'review']);
    $profileUrl = dailyTaskDigestHtmlUrl(
        'profile.php',
        [],
        'notification-preferences-heading'
    );
    $newTaskUrl = dailyTaskDigestHtmlUrl('add_task.php', [
        'return_to' => 'dashboard.php',
    ]);
    $newEngagementUrl = dailyTaskDigestHtmlUrl('index.php');
    $mastheadLogoUrl = dailyTaskDigestHtmlEscape(applicationPublicUrl(
        applicationBrandEmailLogo(),
        ['v' => applicationVersion()]
    ));

    ob_start();
    ?>
<!doctype html>
<html lang="en" style="color-scheme: light only; supported-color-schemes: light;">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light only">
    <meta name="supported-color-schemes" content="light">
    <title><?php echo dailyTaskDigestHtmlEscape($brandName); ?> Daily Operations</title>
    <style>
        :root { color-scheme: light only; supported-color-schemes: light; }
        html, body { margin: 0 !important; padding: 0 !important; background: #f6f7fb !important; color: #172033 !important; }
        body, table, td, a { font-family: Rubik, -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif; }
        table { border-spacing: 0; border-collapse: separate; }
        img { border: 0; display: block; }
        a { text-decoration: none; }
        .email-shell { width: 100%; max-width: 720px; }
        .mobile-column { vertical-align: top; }
        .task-row-overdue, .task-row-overdue td { background-color: #ffe8ee !important; }
        .task-row-today, .task-row-today td { background-color: #e4f2ff !important; }
        .task-row-overdue .task-edge-overdue { background-color: #d92d20 !important; }
        .task-row-today .task-edge-today { background-color: #2563eb !important; }
        @media (prefers-color-scheme: dark) {
            html, body, .email-canvas { background: #f6f7fb !important; color: #172033 !important; }
            .email-surface, .email-surface td { background: #ffffff !important; color: #172033 !important; }
            .email-subtle, .email-subtle td { background: #f8fafc !important; color: #172033 !important; }
            .task-row-overdue, .task-row-overdue td { background: #ffe8ee !important; color: #172033 !important; }
            .task-row-today, .task-row-today td { background: #e4f2ff !important; color: #172033 !important; }
            .task-row-overdue .task-edge-overdue { background: #d92d20 !important; }
            .task-row-today .task-edge-today { background: #2563eb !important; }
        }
        [data-ogsc] .email-canvas { background: #f6f7fb !important; color: #172033 !important; }
        [data-ogsc] .email-surface, [data-ogsc] .email-surface td { background: #ffffff !important; color: #172033 !important; }
        [data-ogsc] .task-row-overdue, [data-ogsc] .task-row-overdue td { background: #ffe8ee !important; color: #172033 !important; }
        [data-ogsc] .task-row-today, [data-ogsc] .task-row-today td { background: #e4f2ff !important; color: #172033 !important; }
        [data-ogsc] .task-row-overdue .task-edge-overdue { background: #d92d20 !important; }
        [data-ogsc] .task-row-today .task-edge-today { background: #2563eb !important; }
        @media only screen and (max-width: 620px) {
            .email-gutter { padding-left: 14px !important; padding-right: 14px !important; }
            .content-padding { padding-left: 18px !important; padding-right: 18px !important; }
            .mobile-column { display: block !important; width: 100% !important; box-sizing: border-box !important; padding-left: 0 !important; padding-right: 0 !important; }
            .mobile-column + .mobile-column { padding-left: 0 !important; padding-top: 10px !important; }
            .summary-cell { display: block !important; width: 100% !important; padding: 0 0 10px !important; }
            .record-meta { width: 118px !important; }
            .hide-mobile { display: none !important; }
            .masthead-brand, .masthead-action { display: block !important; width: 100% !important; box-sizing: border-box !important; }
            .masthead-action { padding-top: 12px !important; text-align: left !important; }
            .masthead-logo { width: 180px !important; max-width: 100% !important; height: auto !important; }
        }
    </style>
</head>
<body class="email-canvas" bgcolor="#f6f7fb" style="margin:0;padding:0;background-color:#f6f7fb;color:#172033;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">
        <?php echo dailyTaskDigestHtmlEscape($preheader); ?>
    </div>
    <table role="presentation" width="100%" bgcolor="#f6f7fb" class="email-canvas" style="width:100%;background-color:#f6f7fb;">
        <tr>
            <td align="center" class="email-gutter" style="padding:28px 20px 36px;">
                <!--[if mso]><table role="presentation" width="720" align="center"><tr><td><![endif]-->
                <table role="presentation" width="720" class="email-shell" style="width:100%;max-width:720px;">
                    <tr>
                        <td class="email-surface" bgcolor="#ffffff" style="padding:17px 22px;border:1px solid #dfe4ec;border-radius:14px;background-color:#ffffff;">
                            <table role="presentation" width="100%" style="width:100%;">
                                <tr>
                                    <td class="masthead-brand" style="vertical-align:middle;">
                                        <a href="<?php echo $dashboardUrl; ?>" style="display:inline-block;text-decoration:none;">
                                            <img class="masthead-logo" src="<?php echo $mastheadLogoUrl; ?>" alt="<?php echo dailyTaskDigestHtmlEscape(applicationBrandLabel()); ?>" width="227" height="39" border="0" style="display:block;width:227px;max-width:100%;height:auto;border:0;outline:none;text-decoration:none;">
                                        </a>
                                        <div style="margin-top:7px;color:#8992a3;font-size:10px;font-weight:700;letter-spacing:0.13em;text-transform:uppercase;">Daily operations</div>
                                    </td>
                                    <td align="right" class="masthead-action" style="vertical-align:middle;">
                                        <a href="<?php echo $dashboardUrl; ?>" style="display:inline-block;padding:9px 13px;border:1px solid #c8d0dc;border-radius:9px;background:#ffffff;color:#2457d6;font-size:12px;font-weight:700;">Open Dashboard</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td class="content-padding" style="padding:28px 2px 18px;">
                            <h1 style="margin:0;color:#172033;font-size:28px;line-height:1.22;letter-spacing:-0.025em;">Good day, <?php echo dailyTaskDigestHtmlEscape($name); ?></h1>
                            <p style="margin:7px 0 0;color:#667085;font-size:14px;line-height:1.55;">Your operations view for <?php echo dailyTaskDigestHtmlEscape($displayDate); ?>:</p>
                            <div style="margin-top:15px;">
                                <a href="<?php echo $taskQueueUrl; ?>" style="display:inline-block;margin:0 7px 7px 0;padding:9px 12px;border:1px solid #c8d0dc;border-radius:9px;background:#ffffff;color:#2457d6;font-size:11px;font-weight:700;">Open My Work</a>
                                <?php if ($canManage): ?>
                                    <a href="<?php echo $newTaskUrl; ?>" style="display:inline-block;margin:0 7px 7px 0;padding:9px 12px;border:1px solid #c8d0dc;border-radius:9px;background:#ffffff;color:#2457d6;font-size:11px;font-weight:700;">+ New Task</a>
                                    <a href="<?php echo $newEngagementUrl; ?>" style="display:inline-block;margin:0 0 7px;padding:9px 12px;border:1px solid #2457d6;border-radius:9px;background:#2457d6;color:#ffffff;font-size:11px;font-weight:700;">+ New Engagement</a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td>
                            <table role="presentation" width="100%" style="width:100%;table-layout:fixed;">
                                <tr>
                                    <td width="33.333%" class="summary-cell" style="width:33.333%;padding-right:6px;vertical-align:top;">
                                        <a href="<?php echo $engagementsUrl; ?>" class="email-surface" style="display:block;min-height:92px;padding:15px;border:1px solid #dfe4ec;border-radius:12px;background:#ffffff;color:#172033;box-sizing:border-box;">
                                            <span style="display:inline-block;width:27px;height:27px;border-radius:8px;background:#eff4ff;color:#2457d6;font-size:17px;font-weight:800;line-height:27px;text-align:center;">◇</span>
                                            <span style="display:block;margin-top:11px;color:#667085;font-size:11px;line-height:1.3;">Engagements, Next <?php echo $dashboardUpcomingDays; ?> Days</span>
                                            <strong style="display:block;margin-top:3px;color:#172033;font-size:24px;line-height:1;"><?php echo $upcomingCount; ?></strong>
                                        </a>
                                    </td>
                                    <td width="33.333%" class="summary-cell" style="width:33.333%;padding:0 3px;vertical-align:top;">
                                        <a href="<?php echo $taskQueueUrl; ?>" class="email-surface" style="display:block;min-height:92px;padding:15px;border:1px solid #dfe4ec;border-radius:12px;background:#ffffff;color:#172033;box-sizing:border-box;">
                                            <span style="display:inline-block;width:27px;height:27px;border-radius:8px;background:#e7f6ef;color:#137a55;font-size:16px;font-weight:800;line-height:27px;text-align:center;">✓</span>
                                            <span style="display:block;margin-top:11px;color:#667085;font-size:11px;line-height:1.3;">My Active Work</span>
                                            <strong style="display:block;margin-top:3px;color:#172033;font-size:24px;line-height:1;"><?php echo (int) ($taskSummary['active'] ?? 0); ?></strong>
                                        </a>
                                    </td>
                                    <td width="33.333%" class="summary-cell" style="width:33.333%;padding-left:6px;vertical-align:top;">
                                        <a href="<?php echo $taskQueueUrl; ?>" class="email-surface" style="display:block;min-height:92px;padding:15px;border:1px solid #dfe4ec;border-radius:12px;background:#ffffff;color:#172033;box-sizing:border-box;">
                                            <span style="display:inline-block;width:27px;height:27px;border-radius:8px;background:#fff0ee;color:#b42318;font-size:17px;font-weight:800;line-height:27px;text-align:center;">!</span>
                                            <span style="display:block;margin-top:11px;color:#667085;font-size:11px;line-height:1.3;">My Overdue Work</span>
                                            <strong style="display:block;margin-top:3px;color:#b42318;font-size:24px;line-height:1;"><?php echo (int) ($taskSummary['overdue'] ?? 0); ?></strong>
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding-top:10px;">
                            <table role="presentation" width="100%" style="width:100%;table-layout:fixed;">
                                <tr>
                                    <td width="<?php echo $canManage ? '50' : '100'; ?>%" class="summary-cell" style="width:<?php echo $canManage ? '50' : '100'; ?>%;padding-right:<?php echo $canManage ? '5' : '0'; ?>px;vertical-align:top;">
                                        <table role="presentation" width="100%" class="email-surface" bgcolor="#ffffff" style="width:100%;border:1px solid #dfe4ec;border-radius:12px;background:#ffffff;color:#172033;">
                                            <tr>
                                                <td style="vertical-align:middle;"><a href="<?php echo $closeoutsUrl; ?>" style="display:block;padding:14px 0 14px 16px;color:#172033;"><span style="display:inline-block;margin-right:8px;color:#9a5b05;font-size:16px;font-weight:800;">$</span><span style="color:#667085;font-size:11px;">Financial Closeouts</span></a></td>
                                                <td align="right" width="52" style="width:52px;vertical-align:middle;"><a href="<?php echo $closeoutsUrl; ?>" style="display:block;padding:14px 16px 14px 0;"><strong style="color:#9a5b05;font-size:18px;"><?php echo $financialCloseoutCount; ?></strong></a></td>
                                            </tr>
                                        </table>
                                    </td>
                                    <?php if ($canManage): ?>
                                        <td width="50%" class="summary-cell" style="width:50%;padding-left:5px;vertical-align:top;">
                                            <table role="presentation" width="100%" class="email-surface" bgcolor="#ffffff" style="width:100%;border:1px solid #dfe4ec;border-radius:12px;background:#ffffff;color:#172033;">
                                                <tr>
                                                    <td style="vertical-align:middle;"><a href="<?php echo $inboundUrl; ?>" style="display:block;padding:14px 0 14px 16px;color:#172033;"><span style="display:inline-block;margin-right:8px;color:#2457d6;font-size:16px;font-weight:800;">@</span><span style="color:#667085;font-size:11px;">Mail For Review</span></a></td>
                                                    <td align="right" width="52" style="width:52px;vertical-align:middle;"><a href="<?php echo $inboundUrl; ?>" style="display:block;padding:14px 16px 14px 0;"><strong style="color:<?php echo $inboundReviewCount > 0 ? '#9a5b05' : '#172033'; ?>;font-size:18px;"><?php echo $inboundReviewCount; ?></strong></a></td>
                                                </tr>
                                            </table>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding-top:20px;">
                            <table role="presentation" width="100%" style="width:100%;table-layout:fixed;">
                                <tr>
                                    <td width="50%" class="mobile-column primary-column" style="width:50%;padding-right:5px;">
                            <table role="presentation" width="100%" class="email-surface" bgcolor="#ffffff" style="width:100%;overflow:hidden;border:1px solid #dfe4ec;border-radius:12px;background:#ffffff;">
                                <tr>
                                    <td class="content-padding" style="padding:18px 20px 14px;border-bottom:1px solid #dfe4ec;">
                                        <table role="presentation" width="100%" style="width:100%;">
                                            <tr>
                                                <td>
                                                    <h2 style="margin:0;color:#172033;font-size:17px;line-height:1.3;">Upcoming Engagements</h2>
                                                    <p style="margin:5px 0 0;color:#667085;font-size:12px;line-height:1.45;">Active events beginning or continuing during the next <?php echo $dashboardUpcomingDays; ?> days.</p>
                                                </td>
                                                <td align="right" style="padding-left:12px;white-space:nowrap;"><a href="<?php echo $engagementsUrl; ?>" style="color:#2457d6;font-size:12px;font-weight:700;">View All</a></td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <?php if ($upcomingEngagements === []): ?>
                                    <tr><td align="center" style="padding:34px 20px;color:#667085;font-size:13px;"><strong style="display:block;margin-bottom:5px;color:#172033;">No upcoming engagements</strong>The next <?php echo $dashboardUpcomingDays; ?> days are clear.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($upcomingEngagements as $index => $engagement): ?>
                                        <?php
                                        $engagementId = (int) ($engagement['id'] ?? 0);
                                        $engagementUrl = dailyTaskDigestHtmlUrl('view_engagement.php', ['id' => $engagementId]);
                                        $issues = is_array($engagement['readiness_issues'] ?? null)
                                            ? $engagement['readiness_issues']
                                            : (function_exists('dashboardEngagementReadinessIssues')
                                                ? dashboardEngagementReadinessIssues($engagement)
                                                : []);
                                        $confirmationStyle = dailyTaskDigestConfirmationStyle($engagement['confirmation_status'] ?? '');
                                        ?>
                                        <tr>
                                            <td class="content-padding" style="padding:14px 20px;<?php echo $index + 1 < count($upcomingEngagements) ? 'border-bottom:1px solid #dfe4ec;' : ''; ?>">
                                                <table role="presentation" width="100%" style="width:100%;">
                                                    <tr>
                                                        <td style="padding-right:12px;vertical-align:middle;">
                                                            <a href="<?php echo $engagementUrl; ?>" style="color:#172033;font-size:14px;font-weight:700;line-height:1.35;"><?php echo dailyTaskDigestHtmlEscape(dashboardEngagementLabel($engagement)); ?></a>
                                                            <div style="margin-top:4px;color:#667085;font-size:11px;line-height:1.4;"><?php echo dailyTaskDigestHtmlEscape($engagement['organization_name'] ?? 'Organization'); ?> · <?php echo dailyTaskDigestHtmlEscape(dashboardDateRangeLabel($engagement['event_start_date'] ?? '', $engagement['event_end_date'] ?? '')); ?></div>
                                                        </td>
                                                        <td class="record-meta" align="right" width="110" style="width:110px;vertical-align:middle;">
                                                            <?php if ($issues !== []): ?><span style="display:inline-block;margin-bottom:5px;padding:4px 8px;border-radius:999px;background:#fff0ee;color:#b42318;font-size:10px;font-weight:700;line-height:1.2;"><?php echo count($issues); ?> detail<?php echo count($issues) === 1 ? '' : 's'; ?> needed</span><br><?php endif; ?>
                                                            <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:<?php echo $confirmationStyle['background']; ?>;color:<?php echo $confirmationStyle['color']; ?>;font-size:10px;font-weight:700;line-height:1.2;"><?php echo dailyTaskDigestHtmlEscape(dashboardConfirmationStatusLabel($engagement['confirmation_status'] ?? '')); ?></span>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </table>
                                    </td>
                                    <td width="50%" class="mobile-column primary-column" style="width:50%;padding-left:5px;">
                            <table role="presentation" width="100%" class="email-surface" bgcolor="#ffffff" style="width:100%;overflow:hidden;border:1px solid #dfe4ec;border-radius:12px;background:#ffffff;">
                                <tr>
                                    <td colspan="2" class="content-padding" style="padding:18px 20px 14px;border-bottom:1px solid #dfe4ec;">
                                        <table role="presentation" width="100%" style="width:100%;">
                                            <tr>
                                                <td>
                                                    <h2 style="margin:0;color:#172033;font-size:17px;line-height:1.3;">My Work</h2>
                                                    <p style="margin:5px 0 0;color:#667085;font-size:12px;line-height:1.45;"><?php echo (int) ($taskSummary['today'] ?? 0); ?> due today, ordered by urgency and due date.</p>
                                                </td>
                                                <td align="right" style="padding-left:12px;white-space:nowrap;"><a href="<?php echo $taskQueueUrl; ?>" style="color:#2457d6;font-size:12px;font-weight:700;">Open Queue</a></td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <?php if ($myTasks === []): ?>
                                    <tr><td colspan="2" align="center" style="padding:34px 20px;color:#667085;font-size:13px;"><strong style="display:block;margin-bottom:5px;color:#172033;">No active assigned work</strong>You are caught up.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($myTasks as $index => $task): ?>
                                        <?php
                                        $dueState = followUpTaskDueState($task['due_date'] ?? null, $businessDate);
                                        $rowBackground = match ($dueState['key']) {
                                            'overdue' => '#ffe8ee',
                                            'today' => '#e4f2ff',
                                            default => '#ffffff',
                                        };
                                        $edgeColor = match ($dueState['key']) {
                                            'overdue' => '#d92d20',
                                            'today' => '#2563eb',
                                            default => '#ffffff',
                                        };
                                        $rowClass = match ($dueState['key']) {
                                            'overdue' => 'task-row-overdue',
                                            'today' => 'task-row-today',
                                            default => 'email-surface',
                                        };
                                        $subject = dailyTaskDigestTaskSubject($task);
                                        $subjectUrl = dailyTaskDigestHtmlUrl((string) ($subject['url'] ?? 'tasks.php'));
                                        $taskUrl = $canManage
                                            ? dailyTaskDigestHtmlUrl('edit_task.php', [
                                                'id' => (int) ($task['id'] ?? 0),
                                                'return_to' => 'dashboard.php',
                                            ])
                                            : $subjectUrl;
                                        $priorityStyle = dailyTaskDigestPriorityStyle($task['priority'] ?? 'normal');
                                        $statusStyle = dailyTaskDigestStatusStyle($task['status'] ?? 'open');
                                        ?>
                                        <tr class="<?php echo $rowClass; ?>" bgcolor="<?php echo $rowBackground; ?>">
                                            <td width="4" class="task-edge-<?php echo $dueState['key']; ?>" bgcolor="<?php echo $edgeColor; ?>" style="width:4px;padding:0;background-color:<?php echo $edgeColor; ?>;"></td>
                                            <td class="content-padding task-content-cell" bgcolor="<?php echo $rowBackground; ?>" style="padding:14px 16px;background-color:<?php echo $rowBackground; ?>;<?php echo $index + 1 < count($myTasks) ? 'border-bottom:1px solid #dfe4ec;' : ''; ?>">
                                                <table role="presentation" width="100%" style="width:100%;">
                                                    <tr>
                                                        <td style="padding-right:12px;vertical-align:middle;">
                                                            <a href="<?php echo $taskUrl; ?>" style="color:#172033;font-size:14px;font-weight:700;line-height:1.35;"><?php echo dailyTaskDigestHtmlEscape($task['title'] ?? 'Task'); ?></a>
                                                            <div style="margin-top:4px;color:#475467;font-size:11px;line-height:1.4;"><a href="<?php echo $subjectUrl; ?>" style="color:#475467;"><?php echo dailyTaskDigestHtmlEscape($subject['label'] ?? applicationGeneralWorkLabel()); ?></a></div>
                                                        </td>
                                                        <td class="record-meta" align="right" width="120" style="width:120px;vertical-align:middle;">
                                                            <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:<?php echo $priorityStyle['background']; ?>;color:<?php echo $priorityStyle['color']; ?>;font-size:10px;font-weight:700;line-height:1.2;"><?php echo dailyTaskDigestHtmlEscape($dueState['label']); ?></span><br>
                                                            <span style="display:inline-block;margin-top:5px;padding:4px 8px;border-radius:999px;background:<?php echo $statusStyle['background']; ?>;color:<?php echo $statusStyle['color']; ?>;font-size:10px;font-weight:700;line-height:1.2;"><?php echo dailyTaskDigestHtmlEscape($statusLabels[$task['status'] ?? ''] ?? ($task['status'] ?? 'Open')); ?></span>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td class="content-padding" style="padding:25px 2px 10px;">
                            <table role="presentation" width="100%" style="width:100%;">
                                <tr>
                                    <td>
                                        <h2 style="margin:0;color:#172033;font-size:20px;line-height:1.3;">Needs Attention</h2>
                                        <p style="margin:5px 0 0;color:#667085;font-size:12px;line-height:1.45;">Upcoming records and completed events that still need an operational decision.</p>
                                    </td>
                                    <?php if ($canManage && $inboundReviewCount > 0): ?>
                                        <td align="right" class="hide-mobile" style="padding-left:12px;"><a href="<?php echo $inboundUrl; ?>" style="display:inline-block;padding:7px 10px;border:1px solid #d7b36a;border-radius:999px;background:#fff3d8;color:#843600;font-size:10px;font-weight:700;white-space:nowrap;"><?php echo $inboundReviewCount; ?> message<?php echo $inboundReviewCount === 1 ? '' : 's'; ?> awaiting review</a></td>
                                    <?php endif; ?>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td>
                            <table role="presentation" width="100%" style="width:100%;table-layout:fixed;">
                                <tr>
                                    <td width="50%" class="mobile-column" style="width:50%;padding-right:5px;">
                                        <table role="presentation" width="100%" height="100%" class="email-surface" bgcolor="#ffffff" style="width:100%;height:100%;overflow:hidden;border:1px solid #dfe4ec;border-radius:12px;background:#ffffff;">
                                            <tr><td style="padding:17px 18px 13px;border-bottom:1px solid #dfe4ec;"><h3 style="margin:0;color:#172033;font-size:15px;line-height:1.3;">Event Readiness</h3><p style="margin:5px 0 0;color:#667085;font-size:11px;line-height:1.45;">Missing essentials for upcoming events.</p></td></tr>
                                            <?php if ($readinessItems === []): ?>
                                                <tr><td align="center" style="padding:28px 16px;color:#667085;font-size:12px;"><strong style="display:block;margin-bottom:4px;color:#137a55;">Upcoming records are ready</strong>No missing essentials were found.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($readinessItems as $index => $engagement): ?>
                                                    <?php
                                                    $readinessUrl = dailyTaskDigestHtmlUrl(
                                                        $canManage ? 'edit_engagement.php' : 'view_engagement.php',
                                                        ['id' => (int) ($engagement['id'] ?? 0)]
                                                    );
                                                    $issues = is_array($engagement['readiness_issues'] ?? null)
                                                        ? $engagement['readiness_issues']
                                                        : dashboardEngagementReadinessIssues($engagement);
                                                    ?>
                                                    <tr><td style="padding:13px 16px;<?php echo $index + 1 < count($readinessItems) ? 'border-bottom:1px solid #dfe4ec;' : ''; ?>"><a href="<?php echo $readinessUrl; ?>" style="color:#172033;font-size:12px;font-weight:700;line-height:1.35;"><?php echo dailyTaskDigestHtmlEscape(dashboardEngagementLabel($engagement)); ?></a><div style="margin-top:7px;"><?php foreach ($issues as $issue): ?><span style="display:inline-block;margin:0 4px 4px 0;padding:4px 7px;border-radius:999px;background:#fff0ee;color:#b42318;font-size:9px;font-weight:700;line-height:1.2;"><?php echo dailyTaskDigestHtmlEscape($issue); ?></span><?php endforeach; ?></div></td></tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </table>
                                    </td>
                                    <td width="50%" class="mobile-column" style="width:50%;padding-left:5px;">
                                        <table role="presentation" width="100%" height="100%" class="email-surface" bgcolor="#ffffff" style="width:100%;height:100%;overflow:hidden;border:1px solid #dfe4ec;border-radius:12px;background:#ffffff;">
                                            <tr><td style="padding:17px 18px 13px;border-bottom:1px solid #dfe4ec;"><h3 style="margin:0;color:#172033;font-size:15px;line-height:1.3;">Financial Closeouts</h3><p style="margin:5px 0 0;color:#667085;font-size:11px;line-height:1.45;">Ended active events without final figures.</p></td></tr>
                                            <?php if ($financialCloseouts === []): ?>
                                                <tr><td align="center" style="padding:28px 16px;color:#667085;font-size:12px;"><strong style="display:block;margin-bottom:4px;color:#137a55;">Closeouts are current</strong>No completed event is waiting for final figures.</td></tr>
                                            <?php else: ?>
                                                <?php foreach ($financialCloseouts as $index => $engagement): ?>
                                                    <?php
                                                    $closeoutItemUrl = dailyTaskDigestHtmlUrl(
                                                        $canManage ? 'close_engagement.php' : 'view_engagement.php',
                                                        ['id' => (int) ($engagement['id'] ?? 0)]
                                                    );
                                                    $endDate = (string) (($engagement['event_end_date'] ?? '') ?: ($engagement['event_start_date'] ?? ''));
                                                    $daysOverdue = isset($engagement['days_overdue'])
                                                        ? (int) $engagement['days_overdue']
                                                        : max(0, (int) (new DateTimeImmutable($businessDate))->diff(new DateTimeImmutable($endDate))->days);
                                                    ?>
                                                    <tr><td style="padding:13px 16px;<?php echo $index + 1 < count($financialCloseouts) ? 'border-bottom:1px solid #dfe4ec;' : ''; ?>"><table role="presentation" width="100%" style="width:100%;"><tr><td style="padding-right:8px;"><a href="<?php echo $closeoutItemUrl; ?>" style="color:#172033;font-size:12px;font-weight:700;line-height:1.35;"><?php echo dailyTaskDigestHtmlEscape(dashboardEngagementLabel($engagement)); ?></a><div style="margin-top:4px;color:#667085;font-size:10px;line-height:1.4;"><?php echo dailyTaskDigestHtmlEscape($engagement['organization_name'] ?? 'Organization'); ?> · ended <?php echo dailyTaskDigestHtmlEscape($endDate); ?></div></td><td align="right" style="color:#b42318;font-size:10px;font-weight:700;white-space:nowrap;"><?php echo $daysOverdue; ?> day<?php echo $daysOverdue === 1 ? '' : 's'; ?></td></tr></table></td></tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td align="center" class="content-padding" style="padding:26px 20px 10px;">
                            <a href="<?php echo $dashboardUrl; ?>" style="display:inline-block;padding:11px 17px;border-radius:9px;background:#2457d6;color:#ffffff;font-size:13px;font-weight:700;">Open Your Dashboard</a>
                            <div style="margin-top:16px;color:#8992a3;font-size:11px;line-height:1.6;">This is your scheduled <?php echo dailyTaskDigestHtmlEscape($brandName); ?> Daily Digest. <a href="<?php echo $profileUrl; ?>" style="color:#667085;text-decoration:underline;">Manage Delivery Settings</a>.</div>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:18px 8px 2px;">
                            <pre aria-label="ASCII art cat" style="display:inline-block;margin:0;color:#667085;font-family:Menlo,Consolas,'Courier New',monospace;font-size:8px;line-height:1.35;text-align:left;white-space:pre;opacity:0.5;filter:alpha(opacity=50);mso-line-height-rule:exactly;">     (&quot;`-''-/&quot;).___..--''&quot;`-.
     `6_ 6  )   `-.  (     ).`-.__.`)
     (_Y_.)'  ._   )  `._ `. ``-..-'
   _..`--'_..-_/  /--'_.' ,'
  (il),-''  (li),'  ((!.-'</pre>
                            <div style="margin-top:6px;color:#667085;font-family:Menlo,Consolas,'Courier New',monospace;font-size:8px;line-height:1.35;text-align:center;opacity:0.5;filter:alpha(opacity=50);mso-line-height-rule:exactly;">Genesis 49:9,10 ... Revelation 5:5<br>Do you see Him?</div>
                        </td>
                    </tr>
                </table>
                <!--[if mso]></td></tr></table><![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>
    <?php
    $html = ob_get_clean();
    if (!is_string($html)) {
        throw new RuntimeException('Unable to render the daily work digest.');
    }
    return trim($html);
}
