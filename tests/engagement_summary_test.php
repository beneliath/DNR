<?php

function expectEngagementSummary($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "Engagement summary test failed: {$message}\n");
        exit(1);
    }
}

$engagements_source = file_get_contents(__DIR__ . '/../src/engagements.php');

expectEngagementSummary(
    str_contains(
        $engagements_source,
        "SUM(is_deleted = 0 AND lifecycle_status = 'active') AS active_count"
    ),
    'the summary query should count operationally active engagements.'
);
expectEngagementSummary(
    str_contains($engagements_source, '<small>Active</small>')
        && str_contains($engagements_source, "\$summary['active']")
        && str_contains($engagements_source, '<small>Postponed</small>')
        && str_contains($engagements_source, '<small>Canceled</small>')
        && str_contains($engagements_source, '<small>Completed</small>'),
    'the summary cards should report each lifecycle state.'
);

echo "Engagement summary tests passed.\n";
