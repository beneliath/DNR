<?php

declare(strict_types=1);

use Dnr\Domain\FinancialReportInput;

/** @return array<string, mixed>|null */
function fetchEngagementFinancialReport(mysqli $conn, int $engagement_id): ?array
{
    $stmt = $conn->prepare(
        'SELECT report.*,
                closer.username AS closed_by_username,
                updater.username AS updated_by_username
         FROM engagement_financial_reports report
         LEFT JOIN users closer ON closer.id = report.closed_by
         LEFT JOIN users updater ON updater.id = report.updated_by
         WHERE report.engagement_id = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the financial report query.');
    }
    $stmt->bind_param('i', $engagement_id);
    $stmt->execute();
    $report = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $report;
}

/**
 * @return array{
 *     last_presentation_date: string|null,
 *     blocking_tasks: list<array<string, mixed>>
 * }
 */
function fetchEngagementCloseoutTaskReadiness(
    mysqli $conn,
    int $engagement_id,
    bool $lock = false
): array {
    if ($engagement_id < 1) {
        throw new InvalidArgumentException('Select a valid engagement.');
    }

    $presentation_sql =
        'SELECT presentation_date
         FROM presentations
         WHERE engagement_id = ?
           AND is_archived = 0
           AND presentation_date IS NOT NULL
         ORDER BY presentation_date DESC, id DESC
         LIMIT 1';
    if ($lock) {
        $presentation_sql .= ' FOR UPDATE';
    }
    $presentation_stmt = $conn->prepare($presentation_sql);
    if (!$presentation_stmt) {
        throw new RuntimeException('Unable to prepare the engagement presentation cutoff.');
    }
    $presentation_stmt->bind_param('i', $engagement_id);
    $presentation_stmt->execute();
    $presentation = $presentation_stmt->get_result()->fetch_assoc();
    $presentation_stmt->close();

    $last_presentation_date = $presentation
        ? (string) $presentation['presentation_date']
        : null;
    if ($last_presentation_date === null) {
        return [
            'last_presentation_date' => null,
            'blocking_tasks' => [],
        ];
    }

    $task_sql =
        "SELECT id, title, status, due_date
         FROM follow_up_tasks
         WHERE engagement_id = ?
           AND due_date <= ?
           AND status <> 'completed'
         ORDER BY due_date, id";
    if ($lock) {
        $task_sql .= ' FOR UPDATE';
    }
    $task_stmt = $conn->prepare($task_sql);
    if (!$task_stmt) {
        throw new RuntimeException('Unable to prepare the engagement closeout tasks.');
    }
    $task_stmt->bind_param('is', $engagement_id, $last_presentation_date);
    $task_stmt->execute();
    $blocking_tasks = $task_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $task_stmt->close();

    return [
        'last_presentation_date' => $last_presentation_date,
        'blocking_tasks' => $blocking_tasks,
    ];
}

/**
 * @param array{
 *     last_presentation_date: string|null,
 *     blocking_tasks: list<array<string, mixed>>
 * } $readiness
 */
function engagementCloseoutTaskHoldMessage(array $readiness): string
{
    $task_count = count($readiness['blocking_tasks']);
    if ($task_count === 0 || $readiness['last_presentation_date'] === null) {
        return '';
    }

    return $task_count . ' task' . ($task_count === 1 ? '' : 's')
        . ' due on or before the last presentation ('
        . $readiness['last_presentation_date']
        . ') must be marked completed before this event can be closed out.';
}

/** @param array<string, mixed> $report */
function financialReportTotal(array $report): string
{
    return FinancialReportInput::total([
        (string) ($report['giving_income_received'] ?? '0.00'),
        (string) ($report['lodging_received'] ?? '0.00'),
        (string) ($report['travel_received'] ?? '0.00'),
    ]);
}

function formatFinancialAmount(mixed $amount): string
{
    if (!is_scalar($amount)) {
        throw new InvalidArgumentException('Unable to format an invalid financial amount.');
    }
    $value = trim((string) $amount);
    if (preg_match('/\A([0-9]+)(?:\.([0-9]+))?\z/', $value, $matches) !== 1) {
        throw new InvalidArgumentException('Unable to format an invalid financial amount.');
    }

    $whole = ltrim($matches[1], '0');
    $whole = $whole === '' ? '0' : $whole;
    $fraction = str_pad($matches[2] ?? '', 3, '0');
    $cents = (int) substr($fraction, 0, 2);
    if ((int) $fraction[2] >= 5) {
        $cents++;
    }
    if ($cents === 100) {
        $carry = true;
        for ($index = strlen($whole) - 1; $index >= 0 && $carry; $index--) {
            if ($whole[$index] === '9') {
                $whole[$index] = '0';
            } else {
                $whole[$index] = chr(ord($whole[$index]) + 1);
                $carry = false;
            }
        }
        if ($carry) {
            $whole = '1' . $whole;
        }
        $cents = 0;
    }
    $grouped_whole = preg_replace('/\B(?=(?:[0-9]{3})+(?![0-9]))/', ',', $whole);
    if (!is_string($grouped_whole)) {
        throw new RuntimeException('Unable to format a financial amount.');
    }

    return '$' . $grouped_whole . '.' . str_pad((string) $cents, 2, '0', STR_PAD_LEFT);
}

/**
 * @param list<int> $organization_ids
 * @return array<int, array<string, mixed>>
 */
function fetchOrganizationFinancialSummaries(mysqli $conn, array $organization_ids): array
{
    $organization_ids = array_values(array_unique(array_filter(
        array_map('intval', $organization_ids),
        static fn(int $id): bool => $id > 0
    )));
    if ($organization_ids === []) {
        return [];
    }

    $summaries = [];
    foreach ($organization_ids as $organization_id) {
        $summaries[$organization_id] = [
            'closed_event_count' => 0,
            'lifetime_giving' => '0.00',
            'average_event_giving' => '0.00',
            'lifetime_lodging' => '0.00',
            'lifetime_travel' => '0.00',
            'last_event_giving' => null,
            'last_event_id' => null,
            'last_event_title' => null,
            'last_event_start_date' => null,
            'last_event_end_date' => null,
        ];
    }

    $placeholders = implode(', ', array_fill(0, count($organization_ids), '?'));
    $aggregate_stmt = $conn->prepare(
        "SELECT engagement.organization_id,
                COUNT(*) AS closed_event_count,
                SUM(report.giving_income_received) AS lifetime_giving,
                AVG(report.giving_income_received) AS average_event_giving,
                SUM(report.lodging_received) AS lifetime_lodging,
                SUM(report.travel_received) AS lifetime_travel
         FROM engagement_financial_reports report
         INNER JOIN engagements engagement ON engagement.id = report.engagement_id
         WHERE engagement.organization_id IN ({$placeholders})
         GROUP BY engagement.organization_id"
    );
    if (!$aggregate_stmt) {
        throw new RuntimeException('Unable to prepare organization financial totals.');
    }
    $aggregate_types = str_repeat('i', count($organization_ids));
    $aggregate_bind = [$aggregate_types];
    foreach ($organization_ids as &$organization_id) {
        $aggregate_bind[] = &$organization_id;
    }
    unset($organization_id);
    $aggregate_stmt->bind_param(...$aggregate_bind);
    $aggregate_stmt->execute();
    foreach ($aggregate_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $organization_id = (int) $row['organization_id'];
        $summaries[$organization_id] = array_merge($summaries[$organization_id], $row);
    }
    $aggregate_stmt->close();

    $latest_stmt = $conn->prepare(
        "SELECT ranked.organization_id, ranked.engagement_id, ranked.event_title,
                ranked.event_start_date, ranked.event_end_date,
                ranked.giving_income_received
         FROM (
             SELECT engagement.organization_id,
                    engagement.id AS engagement_id,
                    engagement.event_title,
                    engagement.event_start_date,
                    engagement.event_end_date,
                    report.giving_income_received,
                    ROW_NUMBER() OVER (
                        PARTITION BY engagement.organization_id
                        ORDER BY engagement.event_end_date DESC,
                                 engagement.event_start_date DESC,
                                 engagement.id DESC
                    ) AS financial_position
             FROM engagement_financial_reports report
             INNER JOIN engagements engagement ON engagement.id = report.engagement_id
             WHERE engagement.organization_id IN ({$placeholders})
         ) ranked
         WHERE ranked.financial_position = 1"
    );
    if (!$latest_stmt) {
        throw new RuntimeException('Unable to prepare latest organization giving.');
    }
    $latest_types = str_repeat('i', count($organization_ids));
    $latest_bind = [$latest_types];
    foreach ($organization_ids as &$organization_id) {
        $latest_bind[] = &$organization_id;
    }
    unset($organization_id);
    $latest_stmt->bind_param(...$latest_bind);
    $latest_stmt->execute();
    foreach ($latest_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $organization_id = (int) $row['organization_id'];
        $summaries[$organization_id]['last_event_giving'] = $row['giving_income_received'];
        $summaries[$organization_id]['last_event_id'] = (int) $row['engagement_id'];
        $summaries[$organization_id]['last_event_title'] = $row['event_title'];
        $summaries[$organization_id]['last_event_start_date'] = $row['event_start_date'];
        $summaries[$organization_id]['last_event_end_date'] = $row['event_end_date'];
    }
    $latest_stmt->close();

    return $summaries;
}

/** @return array<string, mixed> */
function fetchOrganizationFinancialSummary(mysqli $conn, int $organization_id): array
{
    $summaries = fetchOrganizationFinancialSummaries($conn, [$organization_id]);
    return $summaries[$organization_id];
}

/** @return list<array<string, mixed>> */
function fetchOrganizationFinancialHistory(
    mysqli $conn,
    int $organization_id,
    int $limit = 25
): array {
    $limit = max(1, min(100, $limit));
    $stmt = $conn->prepare(
        "SELECT engagement.id AS engagement_id,
                engagement.event_title,
                engagement.event_start_date,
                engagement.event_end_date,
                engagement.is_deleted,
                report.giving_income_received,
                report.lodging_received,
                report.travel_received,
                report.closed_at
         FROM engagement_financial_reports report
         INNER JOIN engagements engagement ON engagement.id = report.engagement_id
         WHERE engagement.organization_id = ?
         ORDER BY engagement.event_end_date DESC,
                  engagement.event_start_date DESC,
                  engagement.id DESC
         LIMIT {$limit}"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare organization financial history.');
    }
    $stmt->bind_param('i', $organization_id);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $history;
}
