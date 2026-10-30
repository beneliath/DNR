<?php

declare(strict_types=1);

function standardEventTaskDueAnchors()
{
    return [
        'event_start' => 'Event Start',
        'event_end' => 'Event End',
    ];
}

function isRequiredStandardEventTask(array $template)
{
    return !empty($template['is_required']);
}

function standardEventTaskScheduleLabel($due_anchor, $due_offset_days)
{
    $anchors = standardEventTaskDueAnchors();
    $due_anchor = (string) $due_anchor;
    $due_offset_days = (int) $due_offset_days;
    if (!isset($anchors[$due_anchor])) {
        return 'Invalid schedule';
    }
    if ($due_offset_days === 0) {
        return 'On ' . strtolower($anchors[$due_anchor]);
    }

    $day_label = abs($due_offset_days) === 1 ? 'day' : 'days';
    $direction = $due_offset_days < 0 ? 'before' : 'after';
    return abs($due_offset_days) . ' ' . $day_label . ' ' . $direction
        . ' ' . strtolower($anchors[$due_anchor]);
}

function normalizeStandardEventTaskInput(array $input)
{
    $title = trim((string) ($input['title'] ?? ''));
    $details = trim((string) ($input['details'] ?? ''));
    $priority = (string) ($input['priority'] ?? 'normal');
    $due_anchor = (string) ($input['due_anchor'] ?? 'event_start');
    $offset_raw = trim((string) ($input['due_offset_days'] ?? ''));
    $sort_raw = trim((string) ($input['sort_order'] ?? ''));

    if ($title === '' || strlen($title) > 255) {
        throw new InvalidArgumentException('Enter a task title using 255 characters or fewer.');
    }
    if (strlen($details) > 20000) {
        throw new InvalidArgumentException('Task notes must use 20,000 characters or fewer.');
    }
    if (!array_key_exists($priority, followUpTaskPriorities())) {
        throw new InvalidArgumentException('Select a valid task priority.');
    }
    if (!array_key_exists($due_anchor, standardEventTaskDueAnchors())) {
        throw new InvalidArgumentException('Select a valid event date for the due-date rule.');
    }
    if (!preg_match('/\A-?[0-9]+\z/', $offset_raw)
        || (int) $offset_raw < -3650
        || (int) $offset_raw > 3650
    ) {
        throw new InvalidArgumentException('Enter a due-date offset from -3650 through 3650 days.');
    }
    if (!preg_match('/\A[0-9]+\z/', $sort_raw)
        || (int) $sort_raw > 65535
    ) {
        throw new InvalidArgumentException('Enter a display order from 0 through 65535.');
    }

    return [
        'title' => $title,
        'details' => $details === '' ? null : $details,
        'priority' => $priority,
        'due_anchor' => $due_anchor,
        'due_offset_days' => (int) $offset_raw,
        'sort_order' => (int) $sort_raw,
    ];
}

function createStandardEventTask(mysqli $conn, array $input, $created_by)
{
    $created_by = (int) $created_by;
    if ($created_by < 1) {
        throw new InvalidArgumentException('Select a valid standard-task creator.');
    }
    $normalized = normalizeStandardEventTaskInput($input);
    $template_key = 'custom.' . bin2hex(random_bytes(16));
    $stmt = $conn->prepare(
        'INSERT INTO standard_event_tasks
            (template_key, title, details, priority, due_anchor,
             due_offset_days, sort_order, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the standard task.');
    }
    $stmt->bind_param(
        'sssssiii',
        $template_key,
        $normalized['title'],
        $normalized['details'],
        $normalized['priority'],
        $normalized['due_anchor'],
        $normalized['due_offset_days'],
        $normalized['sort_order'],
        $created_by
    );
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to save the standard task.');
    }
    $template_id = (int) $conn->insert_id;
    $stmt->close();
    return $template_id;
}

function standardEventTaskSelectSql()
{
    return "SELECT
                template.*,
                creator.username AS creator_username,
                archiver.username AS archiver_username,
                (SELECT COUNT(*) FROM follow_up_tasks generated_task
                 WHERE generated_task.template_key = template.template_key) AS generated_count
            FROM standard_event_tasks template
            LEFT JOIN users creator ON creator.id = template.created_by
            LEFT JOIN users archiver ON archiver.id = template.archived_by";
}

function fetchStandardEventTask(mysqli $conn, $template_id, $lock = false)
{
    $template_id = (int) $template_id;
    if ($template_id < 1) {
        return null;
    }
    $sql = standardEventTaskSelectSql() . ' WHERE template.id = ?';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $template_id);
    $stmt->execute();
    $template = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $template ?: null;
}

function fetchStandardEventTaskTemplates(mysqli $conn, $status = 'active')
{
    if (!in_array($status, ['active', 'archived', 'all'], true)) {
        throw new InvalidArgumentException('Select a valid standard-task view.');
    }
    $where = $status === 'all'
        ? ''
        : ' WHERE template.is_archived = ' . ($status === 'archived' ? '1' : '0');
    $result = $conn->query(
        standardEventTaskSelectSql()
        . $where
        . ' ORDER BY template.sort_order, template.id'
    );
    if (!$result) {
        throw new RuntimeException('Unable to load the standard event tasks.');
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}

function engagementFollowUpChecklistTemplates(
    $event_start_date,
    $event_end_date,
    array $templates
) {
    if (!validIsoDate($event_start_date) || !validIsoDate($event_end_date)) {
        throw new InvalidArgumentException('The engagement needs a valid date range before adding a checklist.');
    }
    $anchors = [
        'event_start' => DateTimeImmutable::createFromFormat('!Y-m-d', $event_start_date),
        'event_end' => DateTimeImmutable::createFromFormat('!Y-m-d', $event_end_date),
    ];
    $scheduled = [];
    foreach ($templates as $template) {
        $due_anchor = (string) ($template['due_anchor'] ?? '');
        $due_offset_days = (int) ($template['due_offset_days'] ?? 0);
        if (!isset($anchors[$due_anchor])) {
            throw new RuntimeException('A standard event task has an invalid due-date rule.');
        }
        $modifier = ($due_offset_days >= 0 ? '+' : '') . $due_offset_days . ' days';
        $scheduled[] = [
            'key' => (string) $template['template_key'],
            'title' => (string) $template['title'],
            'details' => $template['details'] ?? null,
            'due_date' => $anchors[$due_anchor]->modify($modifier)->format('Y-m-d'),
            'priority' => (string) $template['priority'],
        ];
    }
    return $scheduled;
}
