<?php

declare(strict_types=1);

function expectEngagementLifecycleFeature(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Engagement lifecycle feature test failed: {$message}\n");
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

$migration = $read('migrations/20260823_add_engagement_lifecycle.sql');
$form = $read('src/templates/engagement_lifecycle_form.php');
$edit = $read('src/edit_engagement.php');
$list = $read('src/engagements.php');
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
    str_contains($edit, 'validateEngagementRescheduleLink')
        && str_contains($edit, 'cancelEngagementFollowUpTasks')
        && str_contains($tasks, "e.lifecycle_status = 'active'")
        && str_contains($tasks, "e.lifecycle_status <> 'canceled'"),
    'lifecycle changes should validate replacements, cancel open canceled-event tasks, and protect checklist generation.'
);
expectEngagementLifecycleFeature(
    str_contains($list, 'e.lifecycle_status = ?')
        && str_contains($list, '<th>Lifecycle</th>')
        && str_contains($list, '<th>Confirmation</th>')
        && !str_contains($list, '<th>Organization</th>')
        && !str_contains($list, '<th>Type</th>')
        && str_contains($list, 'class="engagement-title"')
        && str_contains($list, 'colspan="6"')
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
