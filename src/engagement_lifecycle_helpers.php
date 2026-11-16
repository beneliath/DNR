<?php

declare(strict_types=1);

/** @return array<string, string> */
function engagementLifecycleStatuses(): array
{
    return [
        'active' => 'Scheduled',
        'postponed' => 'Postponed',
        'canceled' => 'Canceled',
        'completed' => 'Completed',
    ];
}

function engagementLifecycleLabel(mixed $status): string
{
    return engagementLifecycleStatuses()[trim((string) $status)] ?? 'Lifecycle Not Set';
}

/** @param array<string, mixed> $engagement */
function engagementReferenceLabel(array $engagement): string
{
    $title = trim((string) ($engagement['event_title'] ?? ''));
    $organization = trim((string) ($engagement['organization_name'] ?? ''));
    $date = trim((string) ($engagement['event_start_date'] ?? ''));
    $label = $title !== '' ? $title : ($organization !== '' ? $organization : 'Untitled Engagement');
    return $date !== '' ? $label . ' · ' . $date : $label;
}

/** @return list<array<string, mixed>> */
function fetchEngagementRescheduleCandidates(
    mysqli $conn,
    int $organization_id,
    ?int $exclude_engagement_id = null
): array {
    if ($organization_id < 1) {
        return [];
    }

    $sql = "SELECT engagement.id, engagement.event_title,
                   engagement.event_start_date, engagement.event_end_date,
                   engagement.lifecycle_status, organization.organization_name
            FROM engagements engagement
            INNER JOIN organizations organization
                    ON organization.id = engagement.organization_id
            WHERE engagement.organization_id = ?
              AND engagement.is_deleted = 0
              AND engagement.lifecycle_status <> 'canceled'";
    if ($exclude_engagement_id !== null && $exclude_engagement_id > 0) {
        $sql .= ' AND engagement.id <> ?';
    }
    $sql .= ' ORDER BY engagement.event_start_date DESC, engagement.id DESC LIMIT 500';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the rescheduled-event options.');
    }
    if ($exclude_engagement_id !== null && $exclude_engagement_id > 0) {
        $stmt->bind_param('ii', $organization_id, $exclude_engagement_id);
    } else {
        $stmt->bind_param('i', $organization_id);
    }
    $stmt->execute();
    $candidates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $candidates;
}

function validateEngagementRescheduleLink(
    mysqli $conn,
    int $organization_id,
    string $lifecycle_status,
    ?int $rescheduled_to_engagement_id,
    ?int $current_engagement_id = null
): void {
    if ($rescheduled_to_engagement_id === null) {
        return;
    }
    if (!in_array($lifecycle_status, ['postponed', 'canceled'], true)) {
        throw new InvalidArgumentException(
            'Only postponed or canceled engagements can link to a rescheduled event.'
        );
    }
    if ($current_engagement_id !== null
        && $rescheduled_to_engagement_id === $current_engagement_id
    ) {
        throw new InvalidArgumentException('An engagement cannot be rescheduled to itself.');
    }

    $visited = [];
    $next_id = $rescheduled_to_engagement_id;
    for ($depth = 0; $depth < 100; $depth++) {
        if (isset($visited[$next_id])) {
            throw new InvalidArgumentException('The rescheduled-event links cannot form a cycle.');
        }
        if ($current_engagement_id !== null && $next_id === $current_engagement_id) {
            throw new InvalidArgumentException('The rescheduled-event links cannot form a cycle.');
        }
        $visited[$next_id] = true;

        $stmt = $conn->prepare(
            'SELECT id, organization_id, lifecycle_status, is_deleted,
                    rescheduled_to_engagement_id
             FROM engagements
             WHERE id = ?
             FOR UPDATE'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to validate the rescheduled event.');
        }
        $stmt->bind_param('i', $next_id);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$target
            || !empty($target['is_deleted'])
            || (int) $target['organization_id'] !== $organization_id
        ) {
            throw new InvalidArgumentException(
                'Select a rescheduled event from the same active organization.'
            );
        }
        if ($next_id === $rescheduled_to_engagement_id
            && (string) $target['lifecycle_status'] === 'canceled'
        ) {
            throw new InvalidArgumentException('A canceled engagement cannot be the replacement event.');
        }

        $linked_id = (int) ($target['rescheduled_to_engagement_id'] ?? 0);
        if ($linked_id < 1) {
            return;
        }
        $next_id = $linked_id;
    }

    throw new InvalidArgumentException('The rescheduled-event chain is too long.');
}

function validateEngagementLifecycleStatus(
    mysqli $conn,
    ?int $engagement_id,
    string $lifecycle_status
): void {
    if (!array_key_exists($lifecycle_status, engagementLifecycleStatuses())) {
        throw new InvalidArgumentException('Select a valid engagement lifecycle state.');
    }
    if ($engagement_id === null || $engagement_id < 1) {
        return;
    }

    $stmt = $conn->prepare(
        'SELECT engagement_id
         FROM engagement_financial_reports
         WHERE engagement_id = ?
         FOR UPDATE'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to validate the engagement lifecycle.');
    }
    $stmt->bind_param('i', $engagement_id);
    $stmt->execute();
    $has_final_report = $stmt->get_result()->num_rows === 1;
    $stmt->close();
    if ($has_final_report && $lifecycle_status !== 'completed') {
        throw new InvalidArgumentException(
            'An engagement with a final financial report must remain completed.'
        );
    }
}

/** @return array<string, mixed>|null */
function fetchEngagementRescheduleTarget(mysqli $conn, int $engagement_id): ?array
{
    $stmt = $conn->prepare(
        'SELECT target.id, target.event_title, target.event_start_date,
                target.event_end_date, target.lifecycle_status,
                organization.organization_name
         FROM engagements source
         INNER JOIN engagements target
                 ON target.id = source.rescheduled_to_engagement_id
         INNER JOIN organizations organization
                 ON organization.id = target.organization_id
         WHERE source.id = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the rescheduled-event link.');
    }
    $stmt->bind_param('i', $engagement_id);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $target;
}

/** @return list<array<string, mixed>> */
function fetchEngagementRescheduleSources(mysqli $conn, int $engagement_id): array
{
    $stmt = $conn->prepare(
        'SELECT source.id, source.event_title, source.event_start_date,
                source.event_end_date, source.lifecycle_status,
                organization.organization_name
         FROM engagements source
         INNER JOIN organizations organization
                 ON organization.id = source.organization_id
         WHERE source.rescheduled_to_engagement_id = ?
         ORDER BY source.event_start_date DESC, source.id DESC'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the prior-event links.');
    }
    $stmt->bind_param('i', $engagement_id);
    $stmt->execute();
    $sources = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $sources;
}

/** Stop pending work and archive every task, preserving completed work and prior archive history. */
function cancelEngagementFollowUpTasks(mysqli $conn, int $engagement_id, int $actor_user_id): int
{
    if ($actor_user_id < 1) {
        throw new InvalidArgumentException('A signed-in editor is required to archive engagement tasks.');
    }
    $stmt = $conn->prepare(
        "UPDATE follow_up_tasks
         SET waiting_on = IF(status IN ('open', 'in_progress', 'waiting'), NULL, waiting_on),
             completed_by = IF(status IN ('open', 'in_progress', 'waiting'), NULL, completed_by),
             completed_at = IF(status IN ('open', 'in_progress', 'waiting'), NULL, completed_at),
             status = IF(status IN ('open', 'in_progress', 'waiting'), 'canceled', status),
             is_archived = 1, archived_by = ?, archived_at = UTC_TIMESTAMP(),
             updated_at = GREATEST(CURRENT_TIMESTAMP(6), DATE_ADD(updated_at, INTERVAL 1 MICROSECOND))
         WHERE engagement_id = ? AND is_archived = 0"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the engagement task cancellation.');
    }
    $stmt->bind_param('ii', $actor_user_id, $engagement_id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to cancel the engagement tasks.');
    }
    $canceled_count = $stmt->affected_rows;
    $stmt->close();
    return $canceled_count;
}

/** Commit the lifecycle transition and all task archives together after checking the displayed version. */
function cancelEngagementAndArchiveTasks(
    mysqli $conn,
    int $engagement_id,
    int $actor_user_id,
    string $expected_version,
    string $reason
): int {
    $reason = trim($reason);
    if ($engagement_id < 1 || $actor_user_id < 1 || $expected_version === '') {
        throw new InvalidArgumentException('Reload the engagement list before canceling this engagement.');
    }
    if ($reason === '' || mb_strlen($reason) > 1000) {
        throw new InvalidArgumentException('Enter a cancellation reason of 1 to 1,000 characters.');
    }
    $conn->begin_transaction();
    try {
        $engagement = $conn->execute_query(
            'SELECT lifecycle_status, updated_at, is_deleted FROM engagements WHERE id = ? FOR UPDATE',
            [$engagement_id]
        )->fetch_assoc();
        if (!$engagement || !empty($engagement['is_deleted'])) {
            throw new InvalidArgumentException('This engagement is unavailable or archived. Reload the list.');
        }
        if (!hash_equals((string) $engagement['updated_at'], $expected_version)
            || $engagement['lifecycle_status'] === 'canceled') {
            throw new InvalidArgumentException('This engagement changed after the list was opened. Reload before canceling it.');
        }
        validateEngagementLifecycleStatus($conn, $engagement_id, 'canceled');
        $conn->execute_query(
            "UPDATE engagements SET lifecycle_status = 'canceled', cancellation_reason = ?,
                lifecycle_changed_by = ?, lifecycle_changed_at = UTC_TIMESTAMP(6),
                updated_at = GREATEST(CURRENT_TIMESTAMP(6), DATE_ADD(updated_at, INTERVAL 1 MICROSECOND))
             WHERE id = ?",
            [$reason, $actor_user_id, $engagement_id]
        );
        $archived_count = cancelEngagementFollowUpTasks($conn, $engagement_id, $actor_user_id);
        $conn->commit();
        return $archived_count;
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
