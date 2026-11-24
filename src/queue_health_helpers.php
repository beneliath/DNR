<?php
declare(strict_types=1);

/** Queue outcomes are independent of worker heartbeats; no payloads leave this report. */
function applicationQueueHealth(mysqli $db): array
{
    $queues = [];
    // Identifiers and expressions below are fixed application definitions, never input.
    $definitions = [
        ['account_mail', 'Account email', 'email_outbox', 'status', 'pending', 'processing_started_at', 'updated_at', 'users.php'],
        ['task_mail', 'Task notifications', 'notification_outbox', 'status', 'pending', 'processing_started_at', 'updated_at', 'tasks.php'],
        ['correspondence', 'Correspondence', 'engagement_email_deliveries', 'status', 'pending', 'processing_started_at', 'updated_at', 'inbound_mail.php?status=all'],
        ['reimbursements', 'Reimbursement email', 'reimbursement_email_deliveries', 'status', 'pending', 'processing_started_at', 'COALESCE(processing_started_at,created_at)', 'reimbursement_requests.php'],
        ['audience_mail', 'MessageMonkey service email', 'marketing_service_mail', 'state', 'queued', 'started_at', 'COALESCE(started_at,created_at)', 'audience.php?view=delivery'],
    ];
    foreach ($definitions as [$key, $label, $table, $column, $pending, $started, $changed, $url]) {
        $uncertain = $column === 'status' ? 'delivery_uncertain' : 'uncertain';
        $row = $db->query("SELECT
            SUM($column='$pending' AND next_attempt_at<=UTC_TIMESTAMP()) queued,
            SUM($column='retry' AND next_attempt_at<=UTC_TIMESTAMP()) retry,
            SUM($column='processing') processing,
            SUM($column='failed' AND $changed>=UTC_TIMESTAMP()-INTERVAL 24 HOUR) failed,
            SUM($column='$uncertain') uncertain,
            MAX(CASE WHEN $column IN ('$pending','retry') AND next_attempt_at<=UTC_TIMESTAMP()
                THEN TIMESTAMPDIFF(SECOND,next_attempt_at,UTC_TIMESTAMP()) END) oldest_due_seconds,
            SUM($column='processing' AND ($started IS NULL OR $started<UTC_TIMESTAMP()-INTERVAL 15 MINUTE)) stalled
            FROM $table WHERE $column IN ('$pending','retry','processing','failed','$uncertain')")->fetch_assoc();
        $queues[] = queueHealthSummary($key, $label, $url, $row, 900);
    }
    $row = $db->query("SELECT
        SUM(r.state='queued' AND d.state IN ('scheduled','sending') AND GREATEST(d.due_at,r.next_attempt_at)<=UTC_TIMESTAMP()) queued,
        SUM(r.state='processing') processing,
        SUM(r.state='failed' AND COALESCE(r.started_at,d.created_at)>=UTC_TIMESTAMP()-INTERVAL 24 HOUR) failed,
        SUM(r.state='uncertain') uncertain,
        MAX(CASE WHEN r.state='queued' AND d.state IN ('scheduled','sending') AND GREATEST(d.due_at,r.next_attempt_at)<=UTC_TIMESTAMP()
            THEN TIMESTAMPDIFF(SECOND,GREATEST(d.due_at,r.next_attempt_at),UTC_TIMESTAMP()) END) oldest_due_seconds,
        SUM(r.state='processing' AND (r.started_at IS NULL OR r.started_at<UTC_TIMESTAMP()-INTERVAL 15 MINUTE)) stalled
        FROM marketing_campaign_recipients r JOIN marketing_campaign_deliveries d ON d.id=r.delivery_id AND d.audience_id=r.audience_id
        WHERE r.state IN ('queued','processing','failed','uncertain')")->fetch_assoc();
    $queues[] = queueHealthSummary('campaigns', 'Campaign deliveries', 'audience.php?view=campaigns', $row, 900);
    $row = $db->query("SELECT SUM(j.state='queued') queued,SUM(j.state='processing') processing,
        SUM(j.state='failed' AND j.updated_at>=UTC_TIMESTAMP()-INTERVAL 24 HOUR) failed,
        SUM(j.state='complete' AND j.errors>0 AND j.updated_at>=UTC_TIMESTAMP()-INTERVAL 24 HOUR) partial,
        MAX(CASE WHEN j.state='queued' THEN TIMESTAMPDIFF(SECOND,j.created_at,UTC_TIMESTAMP()) END) oldest_due_seconds,
        SUM(j.state='processing' AND j.updated_at<UTC_TIMESTAMP()-INTERVAL 5 MINUTE) stalled
        FROM marketing_jobs j WHERE j.state IN ('queued','processing','failed','complete')
        AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(j.params_json,'$.history_deleted')),'0') NOT IN ('1','true')")->fetch_assoc();
    $queues[] = queueHealthSummary('audience_jobs', 'Imports, exports and bulk changes', 'audience.php?view=jobs', $row, 300);
    $row = $db->query("SELECT SUM(r.state='active' AND f.state='running' AND r.due_at<=UTC_TIMESTAMP()) queued,
        SUM(r.state='failed' AND r.updated_at>=UTC_TIMESTAMP()-INTERVAL 24 HOUR) failed,
        MAX(CASE WHEN r.state='active' AND f.state='running' AND r.due_at<=UTC_TIMESTAMP()
            THEN TIMESTAMPDIFF(SECOND,r.due_at,UTC_TIMESTAMP()) END) oldest_due_seconds
        FROM marketing_flow_runs r JOIN marketing_flows f ON f.id=r.flow_id AND f.audience_id=r.audience_id
        WHERE r.state IN ('active','failed')")->fetch_assoc();
    $queues[] = queueHealthSummary('flows', 'Automations', 'audience.php?view=flows', $row, 900);
    return ['healthy' => !array_filter($queues, static fn(array $queue): bool => $queue['issues'] !== []),
        'checked_at' => gmdate('c'), 'queues' => $queues];
}

function queueHealthSummary(string $key, string $label, string $url, array $row, int $maximumAge): array
{
    $counts = [];
    foreach (['queued','retry','processing','failed','partial','uncertain','oldest_due_seconds','stalled'] as $field) {
        $counts[$field] = max(0, (int) ($row[$field] ?? 0));
    }
    $issues = [];
    if ($counts['uncertain'] > 0) $issues[] = 'uncertain';
    if ($counts['failed'] > 0) $issues[] = 'failed';
    if ($counts['partial'] > 0) $issues[] = 'partial';
    if ($counts['stalled'] > 0) $issues[] = 'stalled';
    if ($counts['oldest_due_seconds'] >= $maximumAge && $counts['queued'] + $counts['retry'] > 0) $issues[] = 'overdue';
    $actions = [];
    if (in_array('uncertain', $issues, true)) $actions[] = 'Check provider receipts before any resend; uncertain deliveries are never retried automatically.';
    if (in_array('failed', $issues, true) || in_array('partial', $issues, true)) $actions[] = 'Review the failed delivery or job details and correct the cause before retrying.';
    if (in_array('stalled', $issues, true) || in_array('overdue', $issues, true)) $actions[] = 'Check the account worker, database connection and delivery limits; work has exceeded its progress threshold.';
    return ['key' => $key, 'label' => $label, 'url' => $url, 'issues' => $issues,
        'action' => implode(' ', $actions), 'maximum_age_seconds' => $maximumAge] + $counts;
}
