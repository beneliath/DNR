<?php

function expectStandardEventTaskFeature($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "Standard event task feature test failed: {$message}\n");
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

$migration = $read('migrations/20260821_add_standard_event_tasks.sql');
$schema = $read('init.sql');
foreach ([$migration, $schema] as $standard_task_schema) {
    expectStandardEventTaskFeature(
        str_contains($standard_task_schema, 'CREATE TABLE IF NOT EXISTS standard_event_tasks')
            && str_contains($standard_task_schema, 'due_offset_days SMALLINT')
            && str_contains($standard_task_schema, 'is_archived TINYINT(1)')
            && str_contains($standard_task_schema, 'chk_standard_event_task_archive')
            && str_contains($standard_task_schema, 'uq_standard_event_task_key')
            && str_contains($standard_task_schema, 'audit_standard_event_tasks_after_insert')
            && substr_count($standard_task_schema, "('standard.") >= 9,
        'fresh and upgraded databases should seed, constrain, and audit configurable standard event tasks.'
    );
}

$helpers = $read('src/follow_up_task_helpers.php');
$queue = $read('src/tasks.php');
$list = $read('src/standard_tasks.php');
$add = $read('src/add_standard_task.php');
$view = $read('src/view_standard_task.php');
$edit = $read('src/edit_standard_task.php');
$new_engagement = $read('src/index.php');
$form = $read('src/templates/standard_event_task_form.php');
$header = $read('src/templates/header.php');
$privileges = $read('scripts/configure_database_privileges.sh');
$checklist_position = strpos($new_engagement, 'generateEngagementFollowUpChecklist(');
$map_queue_position = strpos($new_engagement, 'queueEngagementMapAddress(', $checklist_position ?: 0);
$engagement_commit_position = strpos($new_engagement, '$conn->commit()', $map_queue_position ?: 0);

expectStandardEventTaskFeature(
    str_contains($helpers, "FROM standard_event_tasks template")
        && str_contains($helpers, "fetchStandardEventTaskTemplates(\$conn, 'active')")
        && str_contains($helpers, "\$template['details']")
        && str_contains($helpers, "INSERT IGNORE INTO follow_up_tasks"),
    'checklist generation should copy only active persisted definitions, including their notes.'
);
expectStandardEventTaskFeature(
    str_contains($queue, 'href="standard_tasks.php"')
        && str_contains($list, 'href="add_standard_task.php"')
        && str_contains($list, "['archive', 'restore']")
        && str_contains($list, "requireRecentAdminElevation('standard_tasks.php?status=archived')")
        && str_contains($list, 'DELETE FROM standard_event_tasks WHERE id = ? AND is_archived = 1')
        && str_contains($list, 'Existing event tasks were not changed'),
    'the Work Queue should expose archive, restore, and guarded permanent deletion workflows.'
);
expectStandardEventTaskFeature(
    str_contains($add, 'createStandardEventTask')
        && str_contains($helpers, "'custom.' . bin2hex(random_bytes(16))")
        && str_contains($helpers, 'INSERT INTO standard_event_tasks')
        && str_contains($helpers, 'created_by')
        && str_contains($add, "'Add standard task'"),
    'authorized users should be able to add stable, audited standard-task definitions.'
);
expectStandardEventTaskFeature(
    str_contains($new_engagement, "include 'follow_up_task_helpers.php'")
        && str_contains($new_engagement, 'generateEngagementFollowUpChecklist(')
        && str_contains($new_engagement, '$standard_task_count')
        && $checklist_position !== false
        && $map_queue_position !== false
        && $engagement_commit_position !== false
        && $checklist_position < $map_queue_position
        && $map_queue_position < $engagement_commit_position,
    'new engagement creation should atomically generate standard tasks and queue its map lookup before commit.'
);
expectStandardEventTaskFeature(
    str_contains($view, 'standardEventTaskScheduleLabel')
        && str_contains($view, 'Generated work')
        && str_contains($edit, 'normalizeStandardEventTaskInput')
        && str_contains($edit, 'task_version')
        && str_contains($edit, 'hash_equals')
        && str_contains($form, 'name="due_anchor"')
        && str_contains($form, 'name="due_offset_days"')
        && str_contains($form, 'name="sort_order"'),
    'view and concurrency-safe edit screens should expose all reusable scheduling fields.'
);
expectStandardEventTaskFeature(
    str_contains($header, "'standard_tasks.php'")
        && str_contains($header, "'add_standard_task.php'")
        && str_contains($privileges, '.standard_event_tasks'),
    'standard-task pages should stay within Work Queue navigation and have least-privilege database access.'
);

echo "Standard event task feature tests passed.\n";
