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
$closeout_migration = $read('migrations/20260823_add_financial_closeout_standard_task.sql');
$generalization_migration = $read('migrations/20260827_generalize_standard_event_tasks.sql');
$seed_command = $read('scripts/seed_standard_tasks.php');
$deployment_profile = $read('deployments/moed/application.yaml');
$migration_order = $read('migrations/order.txt');
expectStandardEventTaskFeature(
    str_contains($migration, 'CREATE TABLE IF NOT EXISTS standard_event_tasks')
        && str_contains($migration, 'due_offset_days SMALLINT')
        && str_contains($migration, 'is_archived TINYINT(1)')
        && str_contains($migration, 'chk_standard_event_task_archive')
        && str_contains($migration, 'uq_standard_event_task_key')
        && str_contains($migration, 'audit_standard_event_tasks_after_insert')
        && substr_count($migration, "('standard.") >= 9,
    'the forward migration should seed, constrain, and audit configurable standard event tasks.'
);
expectStandardEventTaskFeature(
    str_contains($generalization_migration, 'ADD COLUMN is_required')
        && str_contains($generalization_migration, 'chk_standard_event_task_required_active')
        && str_contains($migration_order, '20260827_generalize_standard_event_tasks.sql')
        && str_contains($seed_command, "deploymentConfig()->list('standard_event_tasks')")
        && str_contains($seed_command, 'ON DUPLICATE KEY UPDATE')
        && !str_contains($seed_command, 'title = VALUES(title)')
        && str_contains($deployment_profile, 'required: true'),
    'required policy should be persisted and deployment seeds should never overwrite task content.'
);
expectStandardEventTaskFeature(
    str_contains($closeout_migration, "'standard.financial_closeout'")
        && str_contains($closeout_migration, "'Complete the event financial closeout'")
        && str_contains($closeout_migration, "'high', 'event_end', 7")
        && str_contains($closeout_migration, 'ON DUPLICATE KEY UPDATE')
        && str_contains($closeout_migration, 'is_archived = 0')
        && str_contains(
            $migration_order,
            '20260823_add_financial_closeout_standard_task.sql'
        ),
    'an upgrade-safe migration should enforce the required one-week financial closeout reminder.'
);

$helpers = $read('src/follow_up_task_helpers.php');
$queue = $read('src/tasks.php');
$list = $read('src/standard_tasks.php');
$listStyles = $read('src/assets/css/pages/standard_tasks.css');
$add = $read('src/add_standard_task.php');
$addStyles = $read('src/assets/css/pages/add_standard_task.css');
$view = $read('src/view_standard_task.php');
$edit = $read('src/edit_standard_task.php');
$new_engagement = $read('src/index.php');
$edit_engagement = $read('src/edit_engagement.php');
$form = $read('src/templates/standard_event_task_form.php');
$header = $read('src/templates/header.php');
$privileges = $read('scripts/configure_database_privileges.sh');
$checklist_position = strpos($new_engagement, 'generateEngagementFollowUpChecklist(');
$map_queue_position = strpos($new_engagement, 'queueEngagementMapAddress(', $checklist_position ?: 0);
$engagement_commit_position = strpos($new_engagement, '$conn->commit()', $map_queue_position ?: 0);

expectStandardEventTaskFeature(
    str_contains($helpers, "FROM standard_event_tasks template")
        && str_contains($helpers, "fetchStandardEventTaskTemplates(\$conn, 'active')")
        && str_contains($helpers, "return !empty(\$template['is_required'])")
        && str_contains($helpers, 'function isRequiredStandardEventTask')
        && str_contains($helpers, "\$template['details']")
        && str_contains($helpers, "INSERT IGNORE INTO follow_up_tasks"),
    'checklist generation should copy only active persisted definitions, including their notes.'
);
expectStandardEventTaskFeature(
    str_contains($queue, 'href="standard_tasks.php"')
        && str_contains($list, 'href="add_standard_task.php"')
        && str_contains($list, "['archive', 'restore']")
        && str_contains($list, "requireRecentAdminElevation('standard_tasks.php?status=archived')")
        && str_contains($list, 'isRequiredStandardEventTask')
        && str_contains($list, 'Required task')
        && str_contains($list, 'DELETE FROM standard_event_tasks WHERE id = ? AND is_archived = 1')
        && str_contains($list, 'Existing event tasks were not changed'),
    'the Work Queue should expose archive, restore, and guarded permanent deletion workflows.'
);
expectStandardEventTaskFeature(
    str_contains($list, "'assets/css/pages/standard_tasks.min.css'")
        && str_contains($list, '<body class="standard-tasks-body">')
        && str_contains($list, '<main class="container standard-tasks-page">')
        && str_contains($list, 'class="page-heading standard-tasks-heading"')
        && preg_match('/\.standard-tasks-page\s*\{[^}]*width:\s*min\(100%,\s*var\(--app-content-max\)\);[^}]*max-width:\s*var\(--app-content-max\);[^}]*padding-inline:\s*var\(--app-content-padding\);/s', $listStyles) === 1
        && preg_match('/\.standard-tasks-heading h1\s*\{[^}]*font-size:\s*clamp\(1\.8rem,\s*3vw,\s*2\.3rem\);/s', $listStyles) === 1
        && preg_match('/\.standard-tasks-body \.app-footer\s*\{[^}]*max-width:\s*var\(--app-content-max\);/s', $listStyles) === 1,
    'the Standard Event Tasks page should use the Dashboard content width, heading scale, and footer alignment.'
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
    str_contains($add, "'assets/css/pages/add_standard_task.min.css'")
        && str_contains($add, '<body class="add-standard-task-body">')
        && str_contains($add, '<main class="container add-standard-task-page">')
        && str_contains($add, 'form-page-heading add-standard-task-heading')
        && preg_match('/\.add-standard-task-page\s*\{[^}]*width:\s*min\(100%,\s*var\(--app-content-max\)\);[^}]*max-width:\s*var\(--app-content-max\);[^}]*padding-inline:\s*var\(--app-content-padding\);/s', $addStyles) === 1
        && preg_match('/\.add-standard-task-heading h1\s*\{[^}]*font-size:\s*clamp\(1\.8rem,\s*3vw,\s*2\.3rem\);/s', $addStyles) === 1
        && preg_match('/\.add-standard-task-body \.app-footer\s*\{[^}]*max-width:\s*var\(--app-content-max\);/s', $addStyles) === 1,
    'the Add Standard Event Task page should use the Dashboard content width, heading scale, and footer alignment.'
);
expectStandardEventTaskFeature(
    str_contains($new_engagement, "include 'follow_up_task_helpers.php'")
        && str_contains($new_engagement, 'generateEngagementFollowUpChecklist(')
        && str_contains($new_engagement, 'initialEngagementChecklistAssigneeId(')
        && str_contains($new_engagement, '$standard_task_assignee_id')
        && str_contains($new_engagement, '$caller_user_id === null')
        && str_contains($new_engagement, '$standard_task_count')
        && $checklist_position !== false
        && $map_queue_position !== false
        && $engagement_commit_position !== false
        && $checklist_position < $map_queue_position
        && $map_queue_position < $engagement_commit_position,
    'new engagement creation should atomically generate standard tasks and queue its map lookup before commit.'
);
expectStandardEventTaskFeature(
    !str_contains($edit_engagement, 'generateEngagementFollowUpChecklist(')
        && !str_contains($edit_engagement, 'assigned_to'),
    'editing an engagement, including its Caller, must not regenerate or reassign its initial standard tasks.'
);
expectStandardEventTaskFeature(
    str_contains($view, 'standardEventTaskScheduleLabel')
        && str_contains($view, 'This required definition cannot be edited, archived, or deleted')
        && str_contains($view, 'Generated work')
        && str_contains($edit, 'normalizeStandardEventTaskInput')
        && str_contains($edit, 'required standard task cannot be edited')
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
