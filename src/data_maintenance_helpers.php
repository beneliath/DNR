<?php

declare(strict_types=1);
require_once __DIR__.'/data_consistency_helpers.php';

/** Ordinary conversations expire after 90 days; explicit zero disables retention. */
function aiHistoryRetentionDays(): int
{
    $value = getenv('DNR_AI_HISTORY_RETENTION_DAYS');
    if ($value === false || $value === '') return 90;
    if ($value === '0') return 0;
    $days = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 30, 'max_range' => 36500]]);
    if ($days === false) throw new RuntimeException('AI history retention must be 0 (disabled) or 30–36500 days.');
    return $days;
}

/** Each pass does bounded work. No cleanup transaction runs on a user request. */
function maintainApplicationData(mysqli $conn, int $batch = 1000): array
{
    $batch = max(1, min(10000, $batch));
    $conn->execute_query('DELETE FROM network_performance_samples
        WHERE recorded_at < UTC_TIMESTAMP(6) - INTERVAL 30 DAY ORDER BY recorded_at, id LIMIT ?', [$batch]);
    $result = ['network_pruned' => $conn->affected_rows, 'ai_pruned' => 0];
    $result['network_backlog'] = (bool) $conn->query('SELECT EXISTS(SELECT 1 FROM network_performance_samples
        WHERE recorded_at < UTC_TIMESTAMP(6) - INTERVAL 30 DAY)')->fetch_row()[0];
    $days = aiHistoryRetentionDays();
    if ($days > 0) {
        // Preserve reviewed/annotated examples and feedback awaiting investigation.
        // Active jobs are never expired by retention, even if their timestamps are old.
        $conn->execute_query("DELETE FROM ai_coach_requests
            WHERE created_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL ? DAY)
              AND outcome <> 'pending' AND review_status = 'unreviewed'
              AND review_version = 0 AND feedback_version = 0 AND user_feedback IS NULL
              AND NOT EXISTS (SELECT 1 FROM ai_coach_jobs j
                  WHERE j.request_id = ai_coach_requests.id AND j.state IN ('queued', 'generating'))
            ORDER BY created_at, id LIMIT ?", [$days, $batch]);
        $result['ai_pruned'] = $conn->affected_rows;
    }
    $conn->execute_query("DELETE FROM document_scan_jobs WHERE state IN ('clean','rejected','superseded')
        AND updated_at < UTC_TIMESTAMP(6) - INTERVAL 30 DAY ORDER BY updated_at,id LIMIT ?",[$batch]);
    $result['scan_history_pruned'] = $conn->affected_rows;
    $result['ai_retention_days'] = $days;
    $result['statistics_archived'] = archiveShortLinkStatistics($conn, min(1000, $batch));
    $conn->execute_query('UPDATE record_merge_journal SET snapshot_ciphertext=NULL WHERE expires_at<UTC_TIMESTAMP(6) AND snapshot_ciphertext IS NOT NULL ORDER BY expires_at,id LIMIT ?',[$batch]);
    $result['merge_snapshots_expired']=$conn->affected_rows;
    $result['consistency_refreshed']=refreshApplicationDataConsistency($conn);
    return $result;
}

/** Retain exact hourly/link totals, while retiring old high-cardinality dimensions. */
function archiveShortLinkStatistics(mysqli $conn, int $batch = 1000): int
{
    $configured = getenv('DNR_STATISTICS_DETAIL_DAYS') ?: '400';
    $days = filter_var($configured, FILTER_VALIDATE_INT, ['options' => ['min_range' => 366, 'max_range' => 36500]]);
    if ($days === false) throw new RuntimeException('Statistics detail retention must be 366–36500 days.');
    $conn->begin_transaction();
    try {
        $rows = $conn->execute_query('SELECT link_id, visit_hour, browser, os, country, referrer, visits
            FROM short_link_stats WHERE visit_hour < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)
            ORDER BY visit_hour, link_id LIMIT ? FOR UPDATE', [$days, max(1, min(1000, $batch))])->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $row) {
            $conn->execute_query('INSERT INTO short_link_stats_archive (link_id, visit_hour, visits) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE visits = visits + ?', [$row['link_id'], $row['visit_hour'], $row['visits'], $row['visits']]);
            $conn->execute_query('DELETE FROM short_link_stats WHERE link_id=? AND visit_hour=? AND browser=? AND os=? AND country=? AND referrer=?',
                [$row['link_id'], $row['visit_hour'], $row['browser'], $row['os'], $row['country'], $row['referrer']]);
        }
        $conn->commit();
        return count($rows);
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
