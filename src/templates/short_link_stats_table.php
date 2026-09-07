<?php // All values come from the same report buckets as the charts. ?>
<details class="stats-data">
    <summary><?php echo $h($dimensionTitle); ?> data</summary>
    <div class="stats-table-scroll" role="region" aria-label="<?php echo $h($dimensionTitle); ?> data table" tabindex="0">
        <table class="data-table">
            <caption class="stats-screen-reader"><?php echo $h($dimensionTitle); ?> in the selected period</caption>
            <thead><tr><th scope="col"><?php echo $dimension === 'timeline' ? 'Period (UTC)' : $h($dimensionTitle); ?></th><th scope="col">Visits</th><th scope="col">Share</th></tr></thead>
            <tbody>
            <?php foreach ($report[$dimension] as $row): ?>
                <tr><th scope="row" <?php echo $dimension === 'country' ? 'data-country="' . $h($row['label']) . '"' : ''; ?>><?php echo $h($dimension === 'country' && $row['label'] === 'ZZ' ? 'Unknown' : $row['label']); ?></th><td><?php echo number_format($row['total']); ?></td><td><?php echo $report['total'] ? number_format(100 * $row['total'] / $report['total'], 1) : '0'; ?>%</td></tr>
            <?php endforeach; ?>
            <?php if (!$report[$dimension]): ?><tr><td colspan="3">No visits in this period</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</details>
