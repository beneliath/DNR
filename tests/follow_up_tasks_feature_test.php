<?php

function expectFollowUpTaskFeature($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "Follow-up task feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$read = static function ($path) use ($root) {
    $contents = file_get_contents($root . '/' . $path);
    if ($contents === false) {
        throw new RuntimeException("Unable to read {$path}");
    }
    return $contents;
};

$migration = $read('migrations/20260818_add_follow_up_tasks.sql');
$hardeningMigration = $read('migrations/20260901_bugfix_security_patch.sql');
expectFollowUpTaskFeature(
    str_contains($migration, 'CREATE TABLE IF NOT EXISTS follow_up_tasks')
        && str_contains($migration, "ENUM('open', 'in_progress', 'waiting', 'completed', 'canceled')")
        && str_contains($migration, 'chk_follow_up_task_subject')
        && str_contains($migration, 'chk_follow_up_task_completion')
        && str_contains($migration, 'uq_follow_up_task_engagement_template')
        && str_contains($migration, 'audit_follow_up_tasks_after_insert')
        && str_contains($hardeningMigration, 'MODIFY updated_at TIMESTAMP(6)')
        && str_contains($hardeningMigration, 'ALTER TABLE standard_event_tasks'),
    'the forward migration should enforce task subjects, completion state, template idempotence, and auditing.'
);

$helpers = $read('src/follow_up_task_helpers.php');
$queue = $read('src/tasks.php');
$add_task = $read('src/add_task.php');
$edit_task = $read('src/edit_task.php');
$form = $read('src/templates/follow_up_task_form.php');
$subject_search = $read('src/task_subject_search.php');
$section = $read('src/templates/follow_up_task_section.php');
$header = $read('src/templates/header.php');
$styles = $read('src/assets/css/modern.css');

expectFollowUpTaskFeature(
    str_contains($queue, "'my' => 'My work'") === false
        && str_contains($helpers, "'my' => 'My work'")
        && str_contains($helpers, "'overdue' => 'Overdue'")
        && str_contains($helpers, "'today' => 'Due today'")
        && str_contains($helpers, "'upcoming' => 'Next ' . \$upcomingDays . ' days'")
        && str_contains($helpers, "applicationWorkflowSetting('task_upcoming_days')")
        && str_contains($queue, 'assigned_to IS NULL')
        && str_contains($queue, "t.status = 'waiting'"),
    'the queue should expose personal, due-date, waiting, and unassigned work views from one allowlist.'
);
expectFollowUpTaskFeature(
    str_contains($queue, "['my', 'overdue', 'today', 'upcoming', 'waiting', 'unassigned']"),
    'queue controls should omit Waiting and Unassigned buttons because their summary cards provide the same views.'
);
expectFollowUpTaskFeature(
    str_contains($queue, '<time class="task-due task-priority-')
        && str_contains($queue, 'datetime="<?php echo htmlspecialchars($task[\'due_date\']')
        && str_contains($queue, 'match ($row_due_key)')
        && str_contains($queue, "'overdue' => 'Overdue, due ' . \$task['due_date']")
        && str_contains($queue, "'today' => 'Due today, ' . \$task['due_date']")
        && str_contains($queue, "? 'Due ' . \$task['due_date']")
        && str_contains($queue, 'aria-label="<?php echo htmlspecialchars($task_due_aria_label')
        && str_contains($queue, '><?php echo htmlspecialchars($task[\'due_date\']')
        && !str_contains($queue, '<small class="task-priority'),
    'dated queue badges should show only the date while exposing due state and color-coded priority to assistive technology.'
);
expectFollowUpTaskFeature(
    str_contains($queue, "followUpTaskDueState(\$task['due_date'], \$business_date)")
        && str_contains($queue, "in_array(\$task['status'], \$active_task_statuses, true)")
        && str_contains($queue, "? \$due['key']")
        && str_contains($queue, ": 'none'")
        && str_contains($queue, 'task-row-<?php echo htmlspecialchars($row_due_key'),
    'queue rows should use the request business date and only mark active work as overdue or due today.'
);
expectFollowUpTaskFeature(
    str_contains($styles, '--task-overdue-row-bg: #ffe8ee;')
        && str_contains($styles, '--task-overdue-row-hover-bg: #ffdae4;')
        && str_contains($styles, '--task-today-row-bg: #e4f2ff;')
        && str_contains($styles, '--task-today-row-hover-bg: #d4e9ff;')
        && str_contains($styles, '--task-overdue-row-accent: #d92d20;')
        && str_contains($styles, '--task-today-row-accent: #2563eb;')
        && str_contains($styles, '--task-highlight-row-muted: #475467;')
        && str_contains($styles, '--task-overdue-row-bg: #42202b;')
        && str_contains($styles, '--task-overdue-row-hover-bg: #361a23;')
        && str_contains($styles, '--task-today-row-bg: #1d3150;')
        && str_contains($styles, '--task-today-row-hover-bg: #172840;')
        && str_contains($styles, '--task-overdue-row-accent: #ff8c82;')
        && str_contains($styles, '--task-today-row-accent: #78a7ff;')
        && preg_match('/\.task-table tbody tr\.task-row-overdue\s*\{[^}]*var\(--task-overdue-row-bg\)[^}]*\}/s', $styles) === 1
        && preg_match('/\.task-table tbody tr\.task-row-overdue:hover,\s*\.task-table tbody tr\.task-row-overdue:focus-within\s*\{[^}]*var\(--task-overdue-row-hover-bg\)[^}]*\}/s', $styles) === 1
        && preg_match('/\.task-table tbody tr\.task-row-today\s*\{[^}]*var\(--task-today-row-bg\)[^}]*\}/s', $styles) === 1
        && preg_match('/\.task-table tbody tr\.task-row-today:hover,\s*\.task-table tbody tr\.task-row-today:focus-within\s*\{[^}]*var\(--task-today-row-hover-bg\)[^}]*\}/s', $styles) === 1
        && preg_match('/html:not\(\.dark-mode\) \.task-table tbody tr\.task-row-overdue,[^{]*\{[^}]*--warning:\s*#843600;/s', $styles) === 1
        && preg_match('/\.task-table tbody tr\.task-row-overdue > td:first-child\s*\{[^}]*var\(--task-overdue-row-accent\)[^}]*\}/s', $styles) === 1
        && preg_match('/\.task-table tbody tr\.task-row-today > td:first-child\s*\{[^}]*var\(--task-today-row-accent\)[^}]*\}/s', $styles) === 1,
    'active overdue and due-today rows should have theme-aware highlights with stronger hover and keyboard-focus feedback.'
);
expectFollowUpTaskFeature(
    str_contains($queue, 'class="task-priority-legend"')
        && str_contains($queue, "['urgent', 'high', 'normal', 'low']")
        && str_contains($queue, "htmlspecialchars(\$priority_labels[\$priority_value], ENT_QUOTES, 'UTF-8')")
        && !str_contains($queue, "\$priority_labels[\$priority_value] . ' Priority'")
        && str_contains($queue, 'task-priority-legend-item task-priority-')
        && preg_match('/\.task-priority-legend-item\s*\{[^}]*padding:\s*3px 7px;[^}]*border-radius:\s*999px;/s', $styles) === 1,
    'the queue should explain every priority color with short, title-cased legend badges.'
);
expectFollowUpTaskFeature(
    str_contains($queue, 'requireValidCsrfToken()')
        && str_contains($queue, 'canManageFollowUpTasks')
        && str_contains($queue, 'canDeleteEntries')
        && str_contains($queue, "header('Location: ' . \$return_to)")
        && str_contains($edit_task, 'task_version')
        && str_contains($edit_task, 'FOR UPDATE') === false
        && str_contains($helpers, "if (\$lock)")
        && str_contains($helpers, "\$sql .= ' FOR UPDATE'")
        && str_contains($helpers, 'hash_equals'),
    'task writes should use CSRF protection, role checks, PRG, and locked optimistic concurrency.'
);
expectFollowUpTaskFeature(
    str_contains($add_task, 'normalizeFollowUpTaskInput')
        && str_contains($edit_task, 'normalizeFollowUpTaskInput')
        && str_contains($form, 'name="assigned_to"')
        && str_contains($form, 'name="due_date"')
        && str_contains($form, 'name="waiting_on"')
        && str_contains($form, 'name="subject"'),
    'create and edit workflows should share validation and expose ownership, timing, waiting, and context fields.'
);
expectFollowUpTaskFeature(
    str_contains($edit_task, "'duplicate_from' => \$task_id")
        && str_contains($form, 'Duplicate to another event')
        && str_contains($add_task, 'followUpTaskDuplicateFormValues($duplicate_source)')
        && str_contains($add_task, 'requireDifferentEngagementForTaskDuplicate($duplicate_source, $task)')
        && str_contains($add_task, "'Task duplicated.'")
        && str_contains($form, "'?type=engagement'")
        && str_contains($subject_search, "RequestInput::string(\$_GET, 'type', '')"),
    'Edit Task should offer a duplicate flow that requires a different destination event.'
);
expectFollowUpTaskFeature(
    preg_match(
        '/\.follow-up-task-form #task-subject-search\s*\{[^}]*width:\s*100%;[^}]*margin-bottom:\s*10px;/s',
        $styles
    ) === 1,
    'the related-record search should span the task form and remain separated from its select.'
);
expectFollowUpTaskFeature(
    str_contains($helpers, 'LEFT JOIN organizations o ON o.id = c.organization_id')
        && str_contains($helpers, '(o.id IS NULL OR o.is_deleted = 0)')
        && str_contains($helpers, "CONCAT_WS(' · '"),
    'standalone Contacts should remain available as task subjects without an Organization.'
);
expectFollowUpTaskFeature(
    str_contains($helpers, 'generateEngagementFollowUpChecklist')
        && str_contains($helpers, 'INSERT IGNORE INTO follow_up_tasks')
        && str_contains($queue, 'generate_engagement_checklist')
        && str_contains($section, 'Add missing checklist tasks'),
    'standard engagement checklist generation should be optional and idempotent.'
);
expectFollowUpTaskFeature(
    str_contains($header, "'standard_tasks.php'")
        && str_contains($header, "'add_standard_task.php'")
        && str_contains($header, "'view_standard_task.php'")
        && str_contains($header, "'edit_standard_task.php'")
        && str_contains($header, '<span>Work Queue</span>'),
    'the shared application shell should expose the work queue and mark all task pages active.'
);

foreach (['view_engagement.php', 'view_organization.php', 'view_contact.php'] as $record_page) {
    $source = $read('src/' . $record_page);
    expectFollowUpTaskFeature(
        str_contains($source, 'follow_up_task_helpers.php')
            && str_contains($source, "include 'templates/follow_up_task_section.php'"),
        "{$record_page} should show contextual follow-up work."
    );
}

$view_engagement = $read('src/view_engagement.php');
$chron_log_position = strpos($view_engagement, 'class="detail-group chron-log-section"');
$engagement_actions_position = strpos($view_engagement, 'class="action-buttons"', $chron_log_position);
$follow_up_work_position = strpos($view_engagement, "include 'templates/follow_up_task_section.php'", $engagement_actions_position);
expectFollowUpTaskFeature(
    $chron_log_position !== false
        && $engagement_actions_position !== false
        && $follow_up_work_position !== false
        && $chron_log_position < $engagement_actions_position
        && $engagement_actions_position < $follow_up_work_position,
    'View Engagement should place its navigation and export action row after Chron Log and before Follow-Up Work.'
);

echo "Follow-up task feature tests passed.\n";
