<?php

declare(strict_types=1);

/**
 * @param array<string, mixed> $engagement
 * @return list<string>
 */
function dashboardEngagementReadinessIssues(array $engagement): array
{
    $issues = [];
    if (($engagement['confirmation_status'] ?? '') !== 'confirmed') {
        $issues[] = 'Not confirmed';
    }

    $has_address = false;
    foreach ([
        'event_address_line_1',
        'event_address_line_2',
        'event_city',
        'event_state',
        'event_zipcode',
        'event_country',
    ] as $address_field) {
        if (trim((string) ($engagement[$address_field] ?? '')) !== '') {
            $has_address = true;
            break;
        }
    }
    if (!$has_address) {
        $issues[] = 'Venue address missing';
    }
    if ((int) ($engagement['active_presentation_count'] ?? 0) < 1) {
        $issues[] = 'No presentations';
    }
    if ((int) ($engagement['assigned_contact_count'] ?? 0) < 1) {
        $issues[] = 'No event contacts assigned';
    }

    return $issues;
}

/** @param array<string, mixed> $session */
function dashboardGreetingName(array $session): string
{
    $first_name = trim((string) ($session['profile_first_name'] ?? ''));
    if ($first_name !== '') {
        return $first_name;
    }

    $display_name = trim((string) ($session['profile_display_name'] ?? ''));
    if ($display_name !== '') {
        $parts = preg_split('/\s+/u', $display_name, 2);
        if (is_array($parts) && $parts[0] !== '') {
            return (string) $parts[0];
        }
    }

    $username = trim((string) ($session['username'] ?? ''));
    return $username !== '' ? $username : 'there';
}

/** @param array<string, mixed> $engagement */
function dashboardEngagementLabel(array $engagement): string
{
    $event_title = trim((string) ($engagement['event_title'] ?? ''));
    if ($event_title !== '') {
        return $event_title;
    }
    $organization_name = trim((string) ($engagement['organization_name'] ?? ''));
    return $organization_name !== '' ? $organization_name : 'Untitled engagement';
}

function dashboardDateRangeLabel(mixed $start_date, mixed $end_date): string
{
    $start_value = trim((string) $start_date);
    $end_value = trim((string) $end_date);
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $start_value);
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $end_value);

    if (!$start instanceof DateTimeImmutable) {
        return $start_value !== '' ? $start_value : 'Date not set';
    }
    if (!$end instanceof DateTimeImmutable || $end < $start) {
        $end = $start;
    }
    if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
        return $start->format('M j, Y');
    }
    if ($start->format('Y') === $end->format('Y')) {
        return $start->format('M j') . ' – ' . $end->format('M j, Y');
    }
    return $start->format('M j, Y') . ' – ' . $end->format('M j, Y');
}

function dashboardConfirmationStatusLabel(mixed $status): string
{
    $status = trim((string) $status);
    return match ($status) {
        'work_in_progress' => 'Work in progress',
        'under_review' => 'Under review',
        'confirmed' => 'Confirmed',
        default => 'Status not set',
    };
}

/** @return list<array<string, mixed>> */
function fetchDashboardUpcomingEngagements(
    mysqli $conn,
    string $window_start,
    string $window_end,
    int $limit = 40
): array {
    $limit = max(1, min(100, $limit));
    $stmt = $conn->prepare(
        "SELECT e.id, e.event_title, e.event_start_date, e.event_end_date,
                e.confirmation_status,
                e.event_address_line_1, e.event_address_line_2,
                e.event_city, e.event_state, e.event_zipcode, e.event_country,
                o.organization_name,
                (SELECT COUNT(*) FROM presentations presentation
                 WHERE presentation.engagement_id = e.id
                   AND presentation.is_archived = 0) AS active_presentation_count,
                (SELECT COUNT(DISTINCT event_contact.contact_id)
                 FROM engagement_contacts event_contact
                 INNER JOIN contacts contact
                         ON contact.id = event_contact.contact_id
                        AND contact.organization_id = e.organization_id
                        AND contact.is_deleted = 0
                 WHERE event_contact.engagement_id = e.id) AS assigned_contact_count,
                COUNT(*) OVER() AS dashboard_total
         FROM engagements e
         INNER JOIN organizations o ON o.id = e.organization_id
         WHERE e.is_deleted = 0
           AND e.lifecycle_status = 'active'
           AND COALESCE(e.event_end_date, e.event_start_date) >= ?
           AND e.event_start_date <= ?
         ORDER BY e.event_start_date, e.id
         LIMIT {$limit}"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare upcoming dashboard engagements.');
    }
    $stmt->bind_param('ss', $window_start, $window_end);
    $stmt->execute();
    $engagements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $engagements;
}

/** @return array{active: int, overdue: int, today: int} */
function fetchDashboardTaskSummary(mysqli $conn, int $user_id, string $business_date): array
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS active_count,
                SUM(due_date < ?) AS overdue_count,
                SUM(due_date = ?) AS today_count
         FROM follow_up_tasks
         WHERE assigned_to = ?
           AND status IN ('open', 'in_progress', 'waiting')"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare dashboard task totals.');
    }
    $stmt->bind_param('ssi', $business_date, $business_date, $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return [
        'active' => (int) ($row['active_count'] ?? 0),
        'overdue' => (int) ($row['overdue_count'] ?? 0),
        'today' => (int) ($row['today_count'] ?? 0),
    ];
}

/** @return list<array<string, mixed>> */
function fetchDashboardMyTasks(mysqli $conn, int $user_id, int $limit = 8): array
{
    $limit = max(1, min(50, $limit));
    $stmt = $conn->prepare(
        "SELECT t.id, t.title, t.status, t.priority, t.due_date,
                t.subject_type, t.engagement_id, t.organization_id, t.contact_id,
                COALESCE(NULLIF(TRIM(e.event_title), ''), eo.organization_name)
                    AS engagement_label,
                o.organization_name AS organization_label,
                CONCAT(c.contact_last_name, ', ', c.contact_first_name) AS contact_label
         FROM follow_up_tasks t
         LEFT JOIN engagements e ON e.id = t.engagement_id
         LEFT JOIN organizations eo ON eo.id = e.organization_id
         LEFT JOIN organizations o ON o.id = t.organization_id
         LEFT JOIN contacts c ON c.id = t.contact_id
         WHERE t.assigned_to = ?
           AND t.status IN ('open', 'in_progress', 'waiting')
         ORDER BY COALESCE(t.due_date, '9999-12-31'),
                  FIELD(t.priority, 'urgent', 'high', 'normal', 'low'),
                  t.id
         LIMIT {$limit}"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare dashboard tasks.');
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $tasks;
}

/** @return list<array<string, mixed>> */
function fetchDashboardFinancialCloseouts(
    mysqli $conn,
    string $business_date,
    int $limit = 8
): array {
    $limit = max(1, min(50, $limit));
    $stmt = $conn->prepare(
        "SELECT e.id, e.event_title, e.event_start_date, e.event_end_date,
                o.organization_name,
                DATEDIFF(?, COALESCE(e.event_end_date, e.event_start_date)) AS days_overdue,
                COUNT(*) OVER() AS dashboard_total
         FROM engagements e
         INNER JOIN organizations o ON o.id = e.organization_id
         LEFT JOIN engagement_financial_reports report
                ON report.engagement_id = e.id
         WHERE e.is_deleted = 0
           AND e.lifecycle_status IN ('active', 'completed')
           AND COALESCE(e.event_end_date, e.event_start_date) < ?
           AND report.engagement_id IS NULL
         ORDER BY COALESCE(e.event_end_date, e.event_start_date) DESC, e.id DESC
         LIMIT {$limit}"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare dashboard financial closeouts.');
    }
    $stmt->bind_param('ss', $business_date, $business_date);
    $stmt->execute();
    $closeouts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $closeouts;
}

function fetchDashboardInboundReviewCount(mysqli $conn): int
{
    $result = $conn->query(
        "SELECT COUNT(*) AS review_count
         FROM inbound_email_messages
         WHERE status = 'review'"
    );
    if (!$result) {
        throw new RuntimeException('Unable to load the inbound-mail review total.');
    }
    return (int) ($result->fetch_assoc()['review_count'] ?? 0);
}
