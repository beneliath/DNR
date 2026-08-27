<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$sourceDirectory = trim((string) (getenv('DNR_APPLICATION_SOURCE_DIR') ?: ''));
if ($sourceDirectory === '') {
    $sourceDirectory = is_file('/var/www/html/config.php')
        ? '/var/www/html'
        : dirname(__DIR__) . '/src';
}
require_once $sourceDirectory . '/config.php';

$tasks = deploymentConfig()->list('standard_event_tasks');
if ($tasks === []) {
    fwrite(STDOUT, "The deployment profile defines no standard event tasks. Nothing changed.\n");
    exit(0);
}

$statement = $conn->prepare(
    'INSERT INTO standard_event_tasks
        (template_key, title, details, priority, due_anchor,
         due_offset_days, sort_order, is_required)
     VALUES (?, ?, NULLIF(?, \'\'), ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
        is_required = GREATEST(is_required, VALUES(is_required)),
        is_archived = IF(VALUES(is_required) = 1, 0, is_archived),
        archived_by = IF(VALUES(is_required) = 1, NULL, archived_by),
        archived_at = IF(VALUES(is_required) = 1, NULL, archived_at)'
);
if (!$statement) {
    throw new RuntimeException('Unable to prepare the standard-task seed operation.');
}

$inserted = 0;
$requiredPoliciesApplied = 0;
$conn->begin_transaction();
try {
    foreach ($tasks as $task) {
        if (!is_array($task)) {
            throw new RuntimeException('The validated deployment task list is inconsistent.');
        }
        $key = (string) $task['key'];
        $title = (string) $task['title'];
        $details = (string) ($task['details'] ?? '');
        $priority = (string) $task['priority'];
        $dueAnchor = (string) $task['due_anchor'];
        $dueOffsetDays = (int) $task['due_offset_days'];
        $sortOrder = (int) $task['sort_order'];
        $isRequired = !empty($task['required']) ? 1 : 0;
        $statement->bind_param(
            'sssssiii',
            $key,
            $title,
            $details,
            $priority,
            $dueAnchor,
            $dueOffsetDays,
            $sortOrder,
            $isRequired
        );
        if (!$statement->execute()) {
            throw new RuntimeException("Unable to seed standard task {$key}.");
        }
        if ($statement->affected_rows === 1) {
            $inserted++;
        } elseif ($statement->affected_rows === 2 && $isRequired === 1) {
            $requiredPoliciesApplied++;
        }
    }
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    $statement->close();
    throw $exception;
}
$statement->close();

fwrite(
    STDOUT,
    "Standard-task seed complete: {$inserted} inserted, "
    . "{$requiredPoliciesApplied} required policies applied. Existing definitions were not overwritten.\n"
);
