<?php
$isTimeline = $dimension === 'timeline';
$previousPeriod = null;
$periodBand = -1;
$tableRows = $isTimeline ? $report['timeline_resources'] : $report[$dimension];
?>
<details class="stats-data">
    <summary><?php echo $h($dimensionTitle); ?> data</summary>
    <div class="stats-table-scroll" role="region" aria-label="<?php echo $h($dimensionTitle); ?> data table" tabindex="0">
        <table class="data-table">
            <caption class="stats-screen-reader"><?php echo $h($dimensionTitle); ?> in the selected period</caption>
            <thead><tr><th scope="col"><?php echo $dimension === 'timeline' ? 'Period (UTC)' : $h($dimensionTitle); ?></th><?php if ($isTimeline): ?><th scope="col">Event Name</th><th scope="col">Event Presentation</th><th scope="col">Event Resource</th><?php endif; ?><th scope="col" class="stats-number">Visits</th><th scope="col" class="stats-number">Share</th></tr></thead>
            <tbody>
            <?php foreach ($tableRows as $row):
                if ($isTimeline && $row['label'] !== $previousPeriod) {
                    $previousPeriod = $row['label'];
                    $periodBand++;
                }
            ?>
                <tr<?php if ($isTimeline): ?> data-stats-period="<?php echo $h($row['label']); ?>" data-period-band="<?php echo $periodBand % 2; ?>" tabindex="0"<?php endif; ?>><th scope="row" <?php echo $dimension === 'country' ? 'data-country="' . $h($row['label']) . '"' : ''; ?>><?php echo $h($dimension === 'country' && $row['label'] === 'ZZ' ? 'Unknown' : $row['label']); ?></th>
                    <?php if ($isTimeline): ?>
                        <td class="stats-context"><?php if (isset($row['link_id'])): ?><a href="view_engagement.php?id=<?php echo (int) $row['engagement_id']; ?>"><?php echo $h($row['event_title'] ?: 'Untitled event'); ?></a><?php else: ?>—<?php endif; ?></td>
                        <td class="stats-context"><?php if (isset($row['link_id'])): ?><a href="view_engagement.php?id=<?php echo (int) $row['engagement_id']; ?>#presentation-<?php echo (int) $row['presentation_id']; ?>"><?php echo $h($row['topic_title'] ?: 'Untitled presentation'); ?></a><?php else: ?>—<?php endif; ?></td>
                        <td class="stats-context"><?php if (isset($row['link_id'])): ?><a href="short_links.php?<?php echo $h(http_build_query(['id' => (int) $row['link_id'], 'from' => $from, 'to' => $to])); ?>"><?php echo $h(shortLinkLabel($row)); ?> link</a><?php else: ?>—<?php endif; ?></td>
                    <?php endif; ?>
                    <td><?php echo number_format($row['total']); ?></td><td><?php echo $report['total'] ? number_format(100 * $row['total'] / $report['total'], 1) : '0'; ?>%</td></tr>
            <?php endforeach; ?>
            <?php if (!$tableRows): ?><tr><td colspan="<?php echo $isTimeline ? 6 : 3; ?>">No visits in this period</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</details>
