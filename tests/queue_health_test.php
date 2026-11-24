<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/queue_health_helpers.php';
function queueHealthExpect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$report = queueHealthSummary('test', 'Test', 'operations.php', ['queued' => 3, 'oldest_due_seconds' => 899], 900);
queueHealthExpect($report['issues'] === [], 'A recent queue is healthy');
$report = queueHealthSummary('test', 'Test', 'operations.php', ['queued' => 1, 'oldest_due_seconds' => 900, 'stalled' => 1, 'uncertain' => 1, 'failed' => 2, 'partial' => 1], 900);
queueHealthExpect($report['issues'] === ['uncertain', 'failed', 'partial', 'stalled', 'overdue'], 'All outcome conditions are reported independently');
queueHealthExpect(str_contains($report['action'], 'never retried automatically'), 'Uncertain mail requires receipt review');
$report = queueHealthSummary('test', 'Test', 'operations.php', ['queued' => null, 'oldest_due_seconds' => 1000], 900);
queueHealthExpect($report['issues'] === [] && $report['queued'] === 0, 'Empty queues normalize SQL null aggregates');
echo "Queue health threshold tests passed.\n";
