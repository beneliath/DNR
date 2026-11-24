<?php

declare(strict_types=1);
require_once __DIR__ . '/support/source_modules.php';


function expectEngagementLifecycleFeature(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Engagement lifecycle feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $contents = readModuleSource($root . '/' . $path);
    if ($contents === false) {
        throw new RuntimeException("Unable to read {$path}");
    }
    return $contents;
};

$migration = $read('migrations/20260823_add_engagement_lifecycle.sql');
$form = $read('src/templates/engagement_lifecycle_form.php');
$edit = $read('src/edit_engagement.php');
$engagement_write = file_get_contents(__DIR__ . '/../src/app/Service/EngagementWrite.php');
$list = $read('src/engagements.php');
$list_markup = preg_replace('/<\?(?:php|=).*?\?>/s', '', $list);
preg_match_all('/<th\b[^>]*>(.*?)<\/th>/s', $list_markup, $list_header_matches);
$list_headers = array_map(static fn(string $heading): string => trim(strip_tags($heading)), $list_header_matches[1]);
$calendar = $read('src/calendar_helpers.php');
$dashboard = $read('src/dashboard_helpers.php');
$map = $read('src/map.php');
$tasks = $read('src/follow_up_task_helpers.php');
$closeout = $read('src/close_engagement.php');
$view = $read('src/view_engagement.php');
$styles = $read('src/assets/css/pages/engagement_lifecycle.css');

expectEngagementLifecycleFeature(
    str_contains($migration, "ENUM('active', 'postponed', 'canceled', 'completed')")
        && str_contains($migration, 'cancellation_reason VARCHAR(1000)')
        && str_contains($migration, 'rescheduled_to_engagement_id INT')
        && str_contains($migration, 'fk_engagement_rescheduled_to')
        && str_contains($migration, 'chk_engagement_cancellation_reason')
        && str_contains($migration, 'validate_engagement_lifecycle_before_insert')
        && str_contains($migration, 'validate_engagement_lifecycle_before_update')
        && str_contains($migration, 'An engagement cannot be rescheduled to itself'),
    'the schema should separate lifecycle, require cancellation context, and link replacements safely.'
);
expectEngagementLifecycleFeature(
    str_contains($form, 'name="lifecycle_status"')
        && str_contains($form, 'name="confirmation_status"')
        && str_contains($form, 'name="cancellation_reason"')
        && str_contains($form, 'name="rescheduled_to_engagement_id"'),
    'engagement forms should edit lifecycle separately from confirmation.'
);
expectEngagementLifecycleFeature(
    str_contains($engagement_write, 'validateEngagementRescheduleLink')
        && str_contains($engagement_write, 'cancelEngagementFollowUpTasks')
        && str_contains($tasks, "e.lifecycle_status = 'active'")
        && str_contains($tasks, "e.lifecycle_status <> 'canceled'"),
    'lifecycle changes should validate replacements, cancel open canceled-event tasks, and protect checklist generation.'
);
expectEngagementLifecycleFeature(
    str_contains($list, 'e.lifecycle_status = ?')
        && in_array('Lifecycle', $list_headers, true)
        && in_array('Confirmation', $list_headers, true)
        && array_slice($list_headers, 0, 2) === ['Event Title', 'Organization']
        && !in_array('Type', $list_headers, true)
        && str_contains($list, 'class="engagement-title"')
        && str_contains($list, 'colspan="7"')
        && str_contains($map, 'e.lifecycle_status = ?')
        && str_contains($dashboard, "e.lifecycle_status = 'active'"),
    'lists should prioritize event titles, while maps and the dashboard apply lifecycle-aware operational views.'
);
expectEngagementLifecycleFeature(
    str_contains($calendar, "return 'CANCELLED';")
        && str_contains($calendar, "['postponed', 'canceled']")
        && str_contains($calendar, 'Cancellation reason: ')
        && str_contains($calendar, 'Rescheduled as: '),
    'calendar entries should publish cancellations, transparency, reasons, and replacement context.'
);
expectEngagementLifecycleFeature(
    str_contains($closeout, "lifecycle_status = 'completed'")
        && str_contains($dashboard, "e.lifecycle_status IN ('active', 'completed')")
        && str_contains($view, '$financial_closeout_applicable'),
    'financial closeout should complete events and exclude postponed or canceled work.'
);
expectEngagementLifecycleFeature(
    str_contains($styles, '.lifecycle-links a {')
        && str_contains($styles, 'text-decoration: none;')
        && str_contains($styles, '.lifecycle-links a:hover,')
        && str_contains($styles, 'background: var(--control-hover-bg'),
    'replacement links should avoid underlines while retaining a visible hover treatment.'
);

echo "Engagement lifecycle feature tests passed.\n";
