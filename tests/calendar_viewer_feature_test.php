<?php

function expectCalendarViewerFeature(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Calendar viewer feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$page = file_get_contents($root . '/src/calendar_subscription.php');
$viewer = file_get_contents($root . '/src/templates/calendar_month_viewer.php');
$helpers = file_get_contents($root . '/src/calendar_helpers.php');
$styles = file_get_contents($root . '/src/assets/css/modern.css');

expectCalendarViewerFeature(
    str_contains($page, '<h1>Calendar</h1>')
        && str_contains($page, '<main class="container calendar-subscription">')
        && str_contains($page, "include 'templates/calendar_month_viewer.php'")
        && str_contains($viewer, 'id="event-calendar"')
        && str_contains($viewer, 'class="calendar-month-table"')
        && str_contains($viewer, 'calendarViewerPageUrl($calendar_month[\'previous_month\'], $calendar_view_mode)')
        && str_contains($viewer, 'view_engagement.php?id='),
    'the Calendar page should render a graphical month grid with month navigation and event links.'
);

expectCalendarViewerFeature(
    str_contains($page, "normalizeCalendarViewerMode(")
        && str_contains($page, "fetchCalendarViewerTasks(")
        && str_contains($page, "['my_tasks', 'all_tasks', 'everything']")
        && str_contains($helpers, "'events' => 'Events'")
        && str_contains($helpers, "'my_tasks' => 'My Tasks'")
        && str_contains($helpers, "'all_tasks' => 'All Tasks'")
        && str_contains($helpers, "'everything' => 'Everything'")
        && str_contains($viewer, 'class="calendar-view-filters"')
        && str_contains($viewer, 'calendar-task-<?php echo $task_tone; ?>'),
    'the calendar should switch between events, personal tasks, all tasks, and their combined view.'
);

expectCalendarViewerFeature(
    str_contains($helpers, 'function calendarMonthContext(')
        && str_contains($helpers, 'function fetchCalendarViewerEngagements(')
        && str_contains($helpers, 'function fetchCalendarViewerTasks(')
        && str_contains($helpers, 'function calendarEventsByDate(')
        && str_contains($helpers, 'function calendarTasksByDate(')
        && str_contains($helpers, 'COALESCE(e.event_end_date, e.event_start_date) >= ?'),
    'the calendar viewer should load events overlapping the grid and active tasks due within it.'
);

expectCalendarViewerFeature(
    str_contains($styles, '.calendar-month-table {')
        && preg_match('/html body main\.container,[^{]*\{[^}]*background-color:\s*transparent\s*!important;/s', $styles) === 1
        && str_contains($styles, 'min-width: 900px;')
        && str_contains($styles, '.calendar-month-control')
        && preg_match('/\.calendar-month-navigation\s*\{[^}]*background:\s*transparent\s*!important;/s', $styles) === 1
        && preg_match('/\.calendar-view-filters\s*\{[^}]*background:\s*transparent\s*!important;/s', $styles) === 1
        && preg_match('/\.calendar-subscriptions-section\s*\{[^}]*background:\s*transparent\s*!important;/s', $styles) === 1
        && str_contains($styles, '.calendar-view-filter-events')
        && str_contains($styles, '.calendar-filter-swatch-my-task')
        && str_contains($styles, '.calendar-task-mine')
        && str_contains($styles, '.calendar-task-other')
        && str_contains($styles, '.calendar-event-canceled')
        && str_contains($styles, '--calendar-today: #be185d;')
        && str_contains($styles, '--calendar-today: #f9a8d4;')
        && preg_match('/\.calendar-month-table td\.is-today\s*\{[^}]*background:\s*var\(--calendar-today-bg\) !important;[^}]*box-shadow:\s*inset 0 0 0 3px var\(--calendar-today\);/s', $styles) === 1
        && preg_match('/\.calendar-today-label\s*\{[^}]*background:\s*var\(--calendar-today\);[^}]*color:\s*var\(--surface\);/s', $styles) === 1
        && str_contains($styles, '@media (max-width: 760px)'),
    'the month view should use transparent navigation surfaces, responsive color-coded items, and a distinct current-day treatment.'
);

echo "Calendar viewer feature tests passed.\n";
