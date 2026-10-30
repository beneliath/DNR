<?php

declare(strict_types=1);

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
        'view_inquiry.php',
        'view_calendar.php',
        'close_engagement.php',
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
    } elseif ($subject_type === 'inquiry') {
        $label = $task['inquiry_label'] ?? 'Inquiry';
        $id = (int) ($task['inquiry_id'] ?? 0);
        $url = 'view_inquiry.php?id=' . $id;
    } else {
        return [
            'type' => 'general',
            'label' => applicationGeneralWorkLabel(),
            'url' => 'tasks.php',
        ];
    }

    return [
        'type' => $subject_type,
        'label' => (string) $label,
        'url' => $url,
    ];
}

function followUpTaskDueState($due_date, $today = null)
{
    if ($due_date === null || trim((string) $due_date) === '') {
        return ['key' => 'none', 'label' => 'No Due Date'];
    }
    $today = $today ?: applicationBusinessDate();
    if ($due_date < $today) {
        return ['key' => 'overdue', 'label' => 'Overdue · ' . $due_date];
    }
    if ($due_date === $today) {
        return ['key' => 'today', 'label' => 'Due Today'];
    }
    $tomorrow = (new DateTimeImmutable($today, applicationTimezone()))->modify('+1 day')->format('Y-m-d');
    return ['key' => 'upcoming', 'label' => $due_date === $tomorrow ? 'Due Tomorrow' : 'Due ' . $due_date];
}

function followUpTaskDuePresentation($due_date, $status, $today = null, bool $archived = false)
{
    $today = $today ?: applicationBusinessDate();
    if ($due_date === null || trim((string) $due_date) === '') {
        return ['date_label' => 'No Due Date', 'detail' => '', 'days_overdue' => 0];
    }
    $date = new DateTimeImmutable((string) $due_date, applicationTimezone());
    $business_day = new DateTimeImmutable($today, applicationTimezone());
    $days_overdue = $date < $business_day ? (int) $date->diff($business_day)->days : 0;
    $active = !$archived && in_array($status, followUpTaskActiveStatuses(), true);
    $detail = match (true) {
        !$active => $archived ? 'Archived' : (followUpTaskStatuses()[$status] ?? ''),
        $days_overdue > 0 => $days_overdue . ' day' . ($days_overdue === 1 ? '' : 's') . ' overdue',
        $due_date === $today => 'Due Today',
        $date == $business_day->modify('+1 day') => 'Due Tomorrow',
        default => 'Upcoming',
    };
    return [
        'date_label' => $date->format($date->format('Y') === $business_day->format('Y') ? 'M j' : 'M j, Y'),
        'detail' => $detail,
        'days_overdue' => $active ? $days_overdue : 0,
    ];
}

function standardTaskGenerationMessage(int $count): string
{
    return $count > 0
        ? $count . ' task' . ($count === 1 ? ' was' : 's were')
            . ' added to active, open engagements. Assigned to each active Caller, or to you when no active Caller is assigned.'
        : 'No tasks were added. Eligible engagements already have this task, or none are active with an open financial closeout.';
}
