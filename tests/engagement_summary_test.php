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
        "SUM(is_deleted = 0 AND confirmation_status = 'work_in_progress') AS work_in_progress_count"
    ),
    'the summary query should count active work-in-progress engagements.'
);
expectEngagementSummary(
    str_contains($engagements_source, '<small>Work in Progress</small>')
        && str_contains($engagements_source, "\$summary['work_in_progress']")
        && !str_contains($engagements_source, '<small>Upcoming</small>')
        && !str_contains($engagements_source, 'upcoming_count'),
    'the Upcoming card should be replaced by the Work in Progress count.'
);

echo "Engagement summary tests passed.\n";
