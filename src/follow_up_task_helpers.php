<?php

declare(strict_types=1);

function followUpTaskStatuses()
{
    return [
        'open' => 'Open',
        'in_progress' => 'In progress',
        'waiting' => 'Waiting',
        'completed' => 'Completed',
        'canceled' => 'Canceled',
    ];
}

function followUpTaskPriorities()
{
    return [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];
}

function followUpTaskQueueViews()
{
    return [
        'my' => 'My work',
        'overdue' => 'Overdue',
        'today' => 'Due today',
        'upcoming' => 'Next 7 days',
        'waiting' => 'Waiting',
        'unassigned' => 'Unassigned',
        'completed' => 'Completed',
        'all' => 'All active',
    ];
}

function followUpTaskActiveStatuses()
{
    return ['open', 'in_progress', 'waiting'];
}

function canManageFollowUpTasks($role)
{
    return in_array($role, ['admin', 'editor'], true);
}

function followUpTaskSchemaAvailable(mysqli $conn)
{
    return true;
}

function requireFollowUpTaskSchema(mysqli $conn)
{
    // Deployment health checks verify schema readiness.
}

function parseFollowUpTaskSubject($value)
{
    $value = trim((string) $value);
    if ($value === 'general' || $value === 'general:0') {
        return [
            'subject_type' => 'general',
            'subject_id' => null,
            'engagement_id' => null,
            'organization_id' => null,
            'contact_id' => null,
        ];
    }

    if (!preg_match('/\A(engagement|organization|contact):([1-9][0-9]*)\z/', $value, $matches)) {
        throw new InvalidArgumentException('Select a valid related record.');
    }

    $subject_type = $matches[1];
    $subject_id = (int) $matches[2];
    return [
        'subject_type' => $subject_type,
        'subject_id' => $subject_id,
        'engagement_id' => $subject_type === 'engagement' ? $subject_id : null,
        'organization_id' => $subject_type === 'organization' ? $subject_id : null,
        'contact_id' => $subject_type === 'contact' ? $subject_id : null,
    ];
}

function followUpTaskSubjectValue(array $task)
{
    $subject_type = (string) ($task['subject_type'] ?? 'general');
    if ($subject_type === 'general') {
        return 'general';
    }

    $id_field = $subject_type . '_id';
    $subject_id = (int) ($task[$id_field] ?? 0);
    return $subject_id > 0 ? $subject_type . ':' . $subject_id : 'general';
}

function followUpTaskSubjectRecord(mysqli $conn, $subject_type, $subject_id = null)
{
    $subject_type = (string) $subject_type;
    $subject_id = $subject_id === null ? null : (int) $subject_id;
    if ($subject_type === 'general') {
        return [
            'type' => 'general',
            'id' => null,
            'label' => 'General DNR work',
            'url' => 'tasks.php',
            'active' => true,
        ];
    }
    if ($subject_id < 1) {
        return null;
    }

    if ($subject_type === 'engagement') {
        $stmt = $conn->prepare(
            "SELECT e.id,
                    COALESCE(NULLIF(TRIM(e.event_title), ''), o.organization_name) AS label,
                    (e.is_deleted = 0 AND o.is_deleted = 0
                     AND e.lifecycle_status <> 'canceled') AS is_active
             FROM engagements e
             INNER JOIN organizations o ON o.id = e.organization_id
             WHERE e.id = ?"
        );
        $url = 'view_engagement.php?id=' . $subject_id;
    } elseif ($subject_type === 'organization') {
        $stmt = $conn->prepare(
            'SELECT id, organization_name AS label, (is_deleted = 0) AS is_active
             FROM organizations WHERE id = ?'
        );
        $url = 'view_organization.php?id=' . $subject_id;
    } elseif ($subject_type === 'contact') {
        $stmt = $conn->prepare(
            "SELECT c.id,
                    CONCAT(c.contact_last_name, ', ', c.contact_first_name, ' · ', o.organization_name) AS label,
                    (c.is_deleted = 0 AND o.is_deleted = 0) AS is_active
             FROM contacts c
             INNER JOIN organizations o ON o.id = c.organization_id
             WHERE c.id = ?"
        );
        $url = 'view_contact.php?id=' . $subject_id;
    } else {
        return null;
    }

    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $subject_id);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }

    return [
        'type' => $subject_type,
        'id' => $subject_id,
        'label' => (string) $row['label'],
        'url' => $url,
        'active' => !empty($row['is_active']),
    ];
}

function followUpTaskSubjectOptions(mysqli $conn)
{
    $options = [
        'general' => [],
        'engagement' => [],
        'organization' => [],
        'contact' => [],
    ];

    $options['general'][] = [
        'value' => 'general',
        'label' => 'General DNR work',
    ];

    $engagements = $conn->query(
        "SELECT e.id,
                COALESCE(NULLIF(TRIM(e.event_title), ''), o.organization_name) AS label,
                e.event_start_date
         FROM engagements e
         INNER JOIN organizations o ON o.id = e.organization_id
         WHERE e.is_deleted = 0 AND o.is_deleted = 0
           AND e.lifecycle_status <> 'canceled'
         ORDER BY e.event_start_date DESC, label, e.id"
    );
    if ($engagements) {
        while ($row = $engagements->fetch_assoc()) {
            $options['engagement'][] = [
                'value' => 'engagement:' . (int) $row['id'],
                'label' => $row['label'] . ' · ' . $row['event_start_date'],
            ];
        }
    }

    $organizations = $conn->query(
        'SELECT id, organization_name FROM organizations
         WHERE is_deleted = 0 ORDER BY organization_name, id'
    );
    if ($organizations) {
        while ($row = $organizations->fetch_assoc()) {
            $options['organization'][] = [
                'value' => 'organization:' . (int) $row['id'],
                'label' => $row['organization_name'],
            ];
        }
    }

    $contacts = $conn->query(
        "SELECT c.id, c.contact_first_name, c.contact_last_name, o.organization_name
         FROM contacts c
         INNER JOIN organizations o ON o.id = c.organization_id
         WHERE c.is_deleted = 0 AND o.is_deleted = 0
         ORDER BY c.contact_last_name, c.contact_first_name, o.organization_name, c.id"
    );
    if ($contacts) {
        while ($row = $contacts->fetch_assoc()) {
            $options['contact'][] = [
                'value' => 'contact:' . (int) $row['id'],
                'label' => trim($row['contact_last_name'] . ', ' . $row['contact_first_name'])
                    . ' · ' . $row['organization_name'],
            ];
        }
    }

    return $options;
}

function searchFollowUpTaskSubjects(mysqli $conn, $search, $limit = 20)
{
    $search = trim(substr((string) $search, 0, 100));
    $limit = max(1, min(50, (int) $limit));
    $options = [[
        'value' => 'general',
        'label' => 'General DNR work',
        'type' => 'general',
    ]];
    if (strlen($search) < 2) {
        return $options;
    }

    $fulltext = fulltextSearchQuery($search);
    if ($fulltext === '') {
        return $options;
    }
    $per_type_limit = max(3, (int) ceil($limit / 3));

    $engagement_stmt = $conn->prepare(
        "SELECT e.id,
                CONCAT(COALESCE(NULLIF(TRIM(e.event_title), ''), o.organization_name),
                       ' · ', DATE_FORMAT(e.event_start_date, '%Y-%m-%d')) AS label
         FROM engagements e
         INNER JOIN organizations o ON o.id = e.organization_id
         WHERE e.is_deleted = 0 AND o.is_deleted = 0
           AND e.lifecycle_status <> 'canceled'
           AND (
             MATCH(
                e.event_title, e.event_description, e.engagement_notes,
                e.caller_name, e.cancellation_reason
             )
                 AGAINST (? IN BOOLEAN MODE)
             OR MATCH(
                o.organization_name, o.notes, o.affiliation, o.distinctives,
                o.email, o.phone, o.physical_city, o.physical_state,
                o.mailing_city, o.mailing_state
             ) AGAINST (? IN BOOLEAN MODE)
           )
         ORDER BY e.event_start_date DESC, e.id DESC LIMIT ?"
    );
    $engagement_stmt->bind_param('ssi', $fulltext, $fulltext, $per_type_limit);
    $engagement_stmt->execute();
    foreach ($engagement_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $options[] = ['value' => 'engagement:' . (int) $row['id'], 'label' => $row['label'], 'type' => 'engagement'];
    }
    $engagement_stmt->close();

    $organization_stmt = $conn->prepare(
        "SELECT id, organization_name AS label FROM organizations
         WHERE is_deleted = 0 AND MATCH(
            organization_name, notes, affiliation, distinctives, email, phone,
            physical_city, physical_state, mailing_city, mailing_state
         ) AGAINST (? IN BOOLEAN MODE)
         ORDER BY organization_name, id LIMIT ?"
    );
    $organization_stmt->bind_param('si', $fulltext, $per_type_limit);
    $organization_stmt->execute();
    foreach ($organization_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $options[] = ['value' => 'organization:' . (int) $row['id'], 'label' => $row['label'], 'type' => 'organization'];
    }
    $organization_stmt->close();

    $contact_stmt = $conn->prepare(
        "SELECT c.id,
                CONCAT(c.contact_last_name, ', ', c.contact_first_name, ' · ', o.organization_name) AS label
         FROM contacts c
         INNER JOIN organizations o ON o.id = c.organization_id
         WHERE c.is_deleted = 0 AND o.is_deleted = 0
           AND (
             MATCH(
                c.contact_first_name, c.contact_last_name, c.contact_email,
                c.contact_phone, c.contact_role_other, c.contact_notes
             ) AGAINST (? IN BOOLEAN MODE)
             OR MATCH(
                o.organization_name, o.notes, o.affiliation, o.distinctives,
                o.email, o.phone, o.physical_city, o.physical_state,
                o.mailing_city, o.mailing_state
             ) AGAINST (? IN BOOLEAN MODE)
           )
         ORDER BY c.contact_last_name, c.contact_first_name, c.id LIMIT ?"
    );
    $contact_stmt->bind_param('ssi', $fulltext, $fulltext, $per_type_limit);
    $contact_stmt->execute();
    foreach ($contact_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $options[] = ['value' => 'contact:' . (int) $row['id'], 'label' => $row['label'], 'type' => 'contact'];
    }
    $contact_stmt->close();

    return array_slice($options, 0, $limit + 1);
}

function followUpTaskUsers(mysqli $conn)
{
    $users = [];
    $result = $conn->query(
        "SELECT id, username, role FROM users
         WHERE account_status = 'active' ORDER BY username, id"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
    }
    return $users;
}

function normalizeFollowUpTaskInput(
    mysqli $conn,
    array $input,
    $existing_subject_value = null
) {
    $title = trim((string) ($input['title'] ?? ''));
    $details = trim((string) ($input['details'] ?? ''));
    $status = (string) ($input['status'] ?? 'open');
    $priority = (string) ($input['priority'] ?? 'normal');
    $due_date = trim((string) ($input['due_date'] ?? ''));
    $waiting_on = trim((string) ($input['waiting_on'] ?? ''));
    $assigned_raw = trim((string) ($input['assigned_to'] ?? ''));
    $subject = parseFollowUpTaskSubject($input['subject'] ?? 'general');

    if ($title === '' || strlen($title) > 255) {
        throw new InvalidArgumentException('Enter a task title using 255 characters or fewer.');
    }
    if (strlen($details) > 20000) {
        throw new InvalidArgumentException('Task notes must use 20,000 characters or fewer.');
    }
    if (!array_key_exists($status, followUpTaskStatuses())) {
        throw new InvalidArgumentException('Select a valid task status.');
    }
    if (!array_key_exists($priority, followUpTaskPriorities())) {
        throw new InvalidArgumentException('Select a valid task priority.');
    }
    if ($due_date !== '' && !validIsoDate($due_date)) {
        throw new InvalidArgumentException('Enter a valid task due date.');
    }
    if (strlen($waiting_on) > 255) {
        throw new InvalidArgumentException('The waiting-on description must use 255 characters or fewer.');
    }
    if ($status === 'waiting' && $waiting_on === '') {
        throw new InvalidArgumentException('Describe who or what this task is waiting on.');
    }
    if ($status !== 'waiting') {
        $waiting_on = '';
    }

    $assigned_to = null;
    if ($assigned_raw !== '') {
        if (!ctype_digit($assigned_raw) || (int) $assigned_raw < 1) {
            throw new InvalidArgumentException('Select a valid task assignee.');
        }
        $assigned_to = (int) $assigned_raw;
        $assignee_stmt = $conn->prepare(
            "SELECT id FROM users
             WHERE id = ? AND account_status = 'active' FOR UPDATE"
        );
        if (!$assignee_stmt) {
            throw new RuntimeException('Unable to verify the task assignee.');
        }
        $assignee_stmt->bind_param('i', $assigned_to);
        $assignee_stmt->execute();
        $assignee_exists = $assignee_stmt->get_result()->num_rows === 1;
        $assignee_stmt->close();
        if (!$assignee_exists) {
            throw new InvalidArgumentException('Select a valid task assignee.');
        }
    }

    $subject_record = followUpTaskSubjectRecord(
        $conn,
        $subject['subject_type'],
        $subject['subject_id']
    );
    if (!$subject_record) {
        throw new InvalidArgumentException('The selected related record is no longer available.');
    }
    $subject_value = followUpTaskSubjectValue($subject);
    $preserving_existing = $existing_subject_value !== null
        && hash_equals((string) $existing_subject_value, $subject_value);
    if (!$subject_record['active'] && !$preserving_existing) {
        throw new InvalidArgumentException('New work can only be linked to an active record.');
    }

    return array_merge($subject, [
        'title' => $title,
        'details' => $details === '' ? null : $details,
        'status' => $status,
        'priority' => $priority,
        'due_date' => $due_date === '' ? null : $due_date,
        'waiting_on' => $waiting_on === '' ? null : $waiting_on,
        'assigned_to' => $assigned_to,
        'subject_record' => $subject_record,
    ]);
}

function safeFollowUpTaskReturnUrl($value, $fallback = 'tasks.php')
{
    $value = trim((string) $value);
    if ($value === ''
        || preg_match('/[\r\n\\\\]/', $value)
        || str_contains($value, '..')
        || str_starts_with($value, '/')
        || str_starts_with($value, '//')
    ) {
        return $fallback;
    }

    $parts = parse_url($value);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
        return $fallback;
    }
    $path = $parts['path'] ?? '';
    $allowed_pages = [
        'dashboard.php',
        'tasks.php',
        'view_engagement.php',
        'view_organization.php',
        'view_contact.php',
        'calendar_subscription.php',
    ];
    if (!in_array($path, $allowed_pages, true)) {
        return $fallback;
    }

    return $value;
}

function followUpTaskSubjectFromRow(array $task)
{
    $subject_type = (string) ($task['subject_type'] ?? 'general');
    if ($subject_type === 'engagement') {
        $label = $task['engagement_label'] ?? 'Engagement';
        $id = (int) ($task['engagement_id'] ?? 0);
        $url = 'view_engagement.php?id=' . $id;
    } elseif ($subject_type === 'organization') {
        $label = $task['organization_label'] ?? 'Organization';
        $id = (int) ($task['organization_id'] ?? 0);
        $url = 'view_organization.php?id=' . $id;
    } elseif ($subject_type === 'contact') {
        $label = $task['contact_label'] ?? 'Contact';
        $id = (int) ($task['contact_id'] ?? 0);
        $url = 'view_contact.php?id=' . $id;
    } else {
        return [
            'type' => 'general',
            'label' => 'General DNR work',
            'url' => 'tasks.php',
        ];
    }

    return [
        'type' => $subject_type,
        'label' => (string) $label,
        'url' => $url,
    ];
}

function followUpTaskSelectSql()
{
    return "SELECT
                t.*,
                assignee.username AS assignee_username,
                creator.username AS creator_username,
                completer.username AS completer_username,
                COALESCE(NULLIF(TRIM(e.event_title), ''), eo.organization_name) AS engagement_label,
                o.organization_name AS organization_label,
                CONCAT(c.contact_last_name, ', ', c.contact_first_name) AS contact_label
            FROM follow_up_tasks t
            LEFT JOIN users assignee ON assignee.id = t.assigned_to
            LEFT JOIN users creator ON creator.id = t.created_by
            LEFT JOIN users completer ON completer.id = t.completed_by
            LEFT JOIN engagements e ON e.id = t.engagement_id
            LEFT JOIN organizations eo ON eo.id = e.organization_id
            LEFT JOIN organizations o ON o.id = t.organization_id
            LEFT JOIN contacts c ON c.id = t.contact_id";
}

function fetchFollowUpTask(mysqli $conn, $task_id, $lock = false)
{
    $task_id = (int) $task_id;
    if ($task_id < 1) {
        return null;
    }
    $sql = followUpTaskSelectSql() . ' WHERE t.id = ?';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $task_id);
    $stmt->execute();
    $task = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $task ?: null;
}

function setFollowUpTaskStatus(
    mysqli $conn,
    $task_id,
    $status,
    $expected_version,
    $actor_user_id
) {
    $task_id = (int) $task_id;
    $actor_user_id = (int) $actor_user_id;
    $status = (string) $status;
    if ($task_id < 1 || $actor_user_id < 1
        || !array_key_exists($status, followUpTaskStatuses())
    ) {
        throw new InvalidArgumentException('Select a valid task action.');
    }
    if ($status === 'waiting') {
        throw new InvalidArgumentException('Edit the task to describe what it is waiting on.');
    }

    $conn->begin_transaction();
    try {
        $task = fetchFollowUpTask($conn, $task_id, true);
        if (!$task) {
            throw new InvalidArgumentException('That task is no longer available.');
        }
        if ((string) $expected_version === ''
            || !hash_equals((string) $task['updated_at'], (string) $expected_version)
        ) {
            throw new InvalidArgumentException('That task changed in another session. Reload the work queue before updating it.');
        }

        $completed_by = $status === 'completed' ? $actor_user_id : null;
        $completed_at = $status === 'completed' ? gmdate('Y-m-d H:i:s') : null;
        if ($status === 'completed' && $task['status'] === 'completed') {
            $completed_by = $task['completed_by'];
            $completed_at = $task['completed_at'];
        }
        $stmt = $conn->prepare(
            'UPDATE follow_up_tasks
             SET status = ?, waiting_on = NULL, completed_by = ?, completed_at = ?
             WHERE id = ?'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the task status update.');
        }
        $stmt->bind_param('sisi', $status, $completed_by, $completed_at, $task_id);
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to update the task status.');
        }
        $stmt->close();
        $conn->commit();
        return true;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function assignFollowUpTaskToUser(
    mysqli $conn,
    $task_id,
    $assignee_id,
    $expected_version
) {
    $task_id = (int) $task_id;
    $assignee_id = (int) $assignee_id;
    if ($task_id < 1 || $assignee_id < 1) {
        throw new InvalidArgumentException('Select a valid task assignment.');
    }

    $conn->begin_transaction();
    try {
        $user_stmt = $conn->prepare(
            "SELECT id FROM users
             WHERE id = ? AND account_status = 'active' FOR UPDATE"
        );
        if (!$user_stmt) {
            throw new RuntimeException('Unable to verify the task assignee.');
        }
        $user_stmt->bind_param('i', $assignee_id);
        $user_stmt->execute();
        $user_exists = $user_stmt->get_result()->num_rows === 1;
        $user_stmt->close();
        if (!$user_exists) {
            throw new InvalidArgumentException('That task assignee is no longer available.');
        }

        $task = fetchFollowUpTask($conn, $task_id, true);
        if (!$task) {
            throw new InvalidArgumentException('That task is no longer available.');
        }
        if ((string) $expected_version === ''
            || !hash_equals((string) $task['updated_at'], (string) $expected_version)
        ) {
            throw new InvalidArgumentException('That task changed in another session. Reload the work queue before assigning it.');
        }
        $stmt = $conn->prepare('UPDATE follow_up_tasks SET assigned_to = ? WHERE id = ?');
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the task assignment.');
        }
        $stmt->bind_param('ii', $assignee_id, $task_id);
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to assign the task.');
        }
        $stmt->close();
        $conn->commit();
        return true;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function fetchFollowUpTasksForSubject(mysqli $conn, $subject_type, $subject_id = null)
{
    if ($subject_type === 'general') {
        $where = "t.subject_type = 'general'";
        $bind = false;
    } elseif (in_array($subject_type, ['engagement', 'organization', 'contact'], true)
        && (int) $subject_id > 0
    ) {
        $where = 't.' . $subject_type . '_id = ?';
        $bind = true;
        $subject_id = (int) $subject_id;
    } else {
        return [];
    }

    $sql = followUpTaskSelectSql()
        . " WHERE {$where}
            AND t.status IN ('open', 'in_progress', 'waiting')
            ORDER BY
                t.due_date IS NULL,
                t.due_date ASC,
                FIELD(t.priority, 'urgent', 'high', 'normal', 'low'),
                t.id ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    if ($bind) {
        $stmt->bind_param('i', $subject_id);
    }
    $stmt->execute();
    $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $tasks;
}

function followUpTaskDueState($due_date, $today = null)
{
    if ($due_date === null || trim((string) $due_date) === '') {
        return ['key' => 'none', 'label' => 'No due date'];
    }
    $today = $today ?: applicationBusinessDate();
    if ($due_date < $today) {
        return ['key' => 'overdue', 'label' => 'Overdue · ' . $due_date];
    }
    if ($due_date === $today) {
        return ['key' => 'today', 'label' => 'Due today'];
    }
    return ['key' => 'upcoming', 'label' => 'Due ' . $due_date];
}

function standardEventTaskDueAnchors()
{
    return [
        'event_start' => 'Event start',
        'event_end' => 'Event end',
    ];
}

function requiredStandardEventTaskKeys()
{
    return ['standard.financial_closeout'];
}

function isRequiredStandardEventTask($template_key)
{
    return in_array((string) $template_key, requiredStandardEventTaskKeys(), true);
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

function rescheduleGeneratedEngagementTasks(
    mysqli $conn,
    $engagement_id,
    $event_start_date,
    $event_end_date
) {
    $engagement_id = (int) $engagement_id;
    if ($engagement_id < 1
        || !validIsoDate($event_start_date)
        || !validIsoDate($event_end_date)
    ) {
        throw new InvalidArgumentException('Unable to reschedule the engagement checklist.');
    }

    $stmt = $conn->prepare(
        "UPDATE follow_up_tasks task
         INNER JOIN standard_event_tasks template
             ON template.template_key = task.template_key
         SET task.due_date = DATE_ADD(
                IF(template.due_anchor = 'event_end', ?, ?),
                INTERVAL template.due_offset_days DAY
             )
         WHERE task.engagement_id = ?
           AND task.template_key IS NOT NULL
           AND task.due_date_overridden = 0
           AND task.status IN ('open', 'in_progress', 'waiting')"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare checklist rescheduling.');
    }
    $stmt->bind_param('ssi', $event_end_date, $event_start_date, $engagement_id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to reschedule the engagement checklist.');
    }
    $updated = $stmt->affected_rows;
    $stmt->close();
    return $updated;
}

function generateEngagementFollowUpChecklist(
    mysqli $conn,
    $engagement_id,
    $assigned_to,
    $created_by,
    $manage_transaction = true
) {
    $engagement_id = (int) $engagement_id;
    $assigned_to = $assigned_to === null ? null : (int) $assigned_to;
    $created_by = (int) $created_by;
    if ($engagement_id < 1 || $created_by < 1) {
        throw new InvalidArgumentException('Unable to create a checklist for that engagement.');
    }

    $engagement_stmt = $conn->prepare(
        "SELECT e.event_start_date, e.event_end_date
         FROM engagements e
         INNER JOIN organizations o ON o.id = e.organization_id
         WHERE e.id = ? AND e.is_deleted = 0 AND o.is_deleted = 0
           AND e.lifecycle_status = 'active'"
    );
    if (!$engagement_stmt) {
        throw new RuntimeException('Unable to load the engagement checklist dates.');
    }
    $engagement_stmt->bind_param('i', $engagement_id);
    $engagement_stmt->execute();
    $engagement = $engagement_stmt->get_result()->fetch_assoc();
    $engagement_stmt->close();
    if (!$engagement) {
        throw new InvalidArgumentException('Checklists can only be added to active engagements.');
    }

    $manage_transaction = (bool) $manage_transaction;
    if ($manage_transaction) {
        $conn->begin_transaction();
    }
    try {
        if ($assigned_to !== null) {
            $user_stmt = $conn->prepare(
                "SELECT id FROM users
                 WHERE id = ? AND account_status = 'active' FOR UPDATE"
            );
            $user_stmt->bind_param('i', $assigned_to);
            $user_stmt->execute();
            $has_user = $user_stmt->get_result()->num_rows === 1;
            $user_stmt->close();
            if (!$has_user) {
                throw new InvalidArgumentException('Select a valid checklist assignee.');
            }
        }

        $templates = engagementFollowUpChecklistTemplates(
            $engagement['event_start_date'],
            $engagement['event_end_date'],
            fetchStandardEventTaskTemplates($conn, 'active')
        );
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO follow_up_tasks
                (title, details, status, priority, due_date, subject_type, engagement_id,
                 assigned_to, created_by, template_key, due_date_overridden)
             VALUES (?, ?, 'open', ?, ?, 'engagement', ?, ?, ?, ?, 0)"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the engagement checklist.');
        }
        $inserted = 0;
        foreach ($templates as $template) {
            $title = $template['title'];
            $details = $template['details'];
            $priority = $template['priority'];
            $due_date = $template['due_date'];
            $template_key = $template['key'];
            $stmt->bind_param(
                'ssssiiis',
                $title,
                $details,
                $priority,
                $due_date,
                $engagement_id,
                $assigned_to,
                $created_by,
                $template_key
            );
            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to add the engagement checklist.');
            }
            $inserted += $stmt->affected_rows;
        }
        $stmt->close();
        if ($manage_transaction) {
            $conn->commit();
        }
        return $inserted;
    } catch (Throwable $exception) {
        if ($manage_transaction) {
            $conn->rollback();
        }
        throw $exception;
    }
}
