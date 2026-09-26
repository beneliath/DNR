<?php
declare(strict_types=1);
putenv('DNR_TIMEZONE=America/Chicago');
require_once __DIR__ . '/../src/short_link_helpers.php';
function expectLocalTime(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, $message . "\n"); exit(1); }
}
expectLocalTime(shortLinkStatsDates('2026-03-08', '2026-03-08') === ['2026-03-08 06:00:00', '2026-03-09 05:00:00'], 'Spring transition selects a 23-hour local day');
expectLocalTime(shortLinkStatsDates('2026-11-01', '2026-11-01') === ['2026-11-01 05:00:00', '2026-11-02 06:00:00'], 'Fall transition selects a 25-hour local day');
[$start, $end] = shortLinkStatsDates('2026-09-11', '2026-09-12');
$base = ['link_id'=>1,'link_type'=>'notes','custom_label'=>null,'event_title'=>'Event','topic_title'=>'Talk','engagement_id'=>1,'presentation_id'=>1];
$hours = [
    $base + ['label'=>'2026-09-12 04:00:00','total'=>2],
    $base + ['label'=>'2026-09-12 05:00:00','total'=>3],
    $base + ['label'=>'2026-09-12 06:00:00','total'=>4],
];
$stats = shortLinkLocalVisitBuckets($hours, $start, $end);
expectLocalTime($stats['day'] === [['label'=>'2026-09-11','total'=>2],['label'=>'2026-09-12','total'=>7]], 'UTC midnight does not move visits into the wrong local date');
$stats += ['total'=>9,'referrer'=>[],'browser'=>[],'os'=>[],'country'=>[]];
$report = shortLinkReportData($stats, $start, $end);
expectLocalTime(array_column($report['timeline'],'label') === ['2026-09-11','2026-09-12'], 'Local buckets align with the selected date range');
expectLocalTime(array_column($report['timeline_resources'],'total') === [2,7], 'Resources and chart share the local date totals');
expectLocalTime($report['timezone'] === 'America/Chicago', 'Chart tooltip carries the display zone');
expectLocalTime(applicationTimestampLabel('2026-09-12 04:30:00','Y-m-d H:i T') === '2026-09-11 23:30 CDT', 'UTC timestamps render locally');
expectLocalTime(applicationTimestampLabel(null) === '' && applicationTimestampLabel('') === '', 'Missing timestamps never display the current time');
expectLocalTime(applicationDateBoundaryUtc('2026-11-01', true) === '2026-11-02 06:00:00', 'Audit end date advances locally before converting to UTC');
echo "Local time statistics tests passed.\n";
