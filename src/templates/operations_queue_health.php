<?php /** @var array $queue_health */ ?>
<section class="record-section" aria-labelledby="queue-health-heading">
    <h2 id="queue-health-heading">Queue Health</h2>
    <p><strong><?= $queue_health['healthy'] ? 'Work is progressing normally.' : 'Attention required.' ?></strong>
        These results measure queued work and delivery outcomes independently of worker heartbeats.
        Waiting counts include work due now; future schedules and paused campaigns are excluded.
        Failures and jobs completed with errors cover the last 24 hours. Uncertain deliveries remain visible until resolved.</p>
    <div class="data-table-scroll">
        <table class="data-table">
            <thead><tr><th scope="col">Queue</th><th scope="col">Waiting / Retry</th><th scope="col">Processing</th><th scope="col">Oldest Due</th><th scope="col">Failed / Partial</th><th scope="col">Uncertain</th><th scope="col">Stalled</th></tr></thead>
            <tbody>
            <?php foreach ($queue_health['queues'] as $queue): ?>
                <tr>
                    <th scope="row"><a href="<?= htmlspecialchars($queue['url'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($queue['label'], ENT_QUOTES, 'UTF-8') ?></a></th>
                    <td><?= $queue['queued'] ?> / <?= $queue['retry'] ?></td><td><?= $queue['processing'] ?></td>
                    <td><?= $queue['queued'] + $queue['retry'] > 0 ? (int) floor($queue['oldest_due_seconds'] / 60) . ' min' : '—' ?></td>
                    <td><?= $queue['failed'] ?> / <?= $queue['partial'] ?></td><td><?= $queue['uncertain'] ?></td><td><?= $queue['stalled'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php foreach ($queue_health['queues'] as $queue): ?>
        <?php if ($queue['issues']): ?><p><strong><?= htmlspecialchars($queue['label'], ENT_QUOTES, 'UTF-8') ?>:</strong> <?= htmlspecialchars($queue['action'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <?php endforeach; ?>
    <p>Attention thresholds: 5 minutes without bulk-job progress; 15 minutes for overdue delivery or automation work. Review delivery receipts before resending an uncertain message.</p>
</section>
