<?php

declare(strict_types=1);

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

    $existing_subject = $existing_subject_value === null
        ? null
        : parseFollowUpTaskSubject($existing_subject_value);
    lockFollowUpTaskInquiries($conn, [
        $existing_subject['inquiry_id'] ?? null,
        $subject['inquiry_id'] ?? null,
    ]);
    lockFollowUpTaskEngagements($conn, [
        $existing_subject['engagement_id'] ?? null,
        $subject['engagement_id'] ?? null,
    ]);

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
