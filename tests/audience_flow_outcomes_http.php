<?php
// Included by the disposable flow HTTP suite, using its scoped editor and store.
$outcomeFlow = $newFlow($d, 'Outcome summary fixture');
$outcomeFlowId = (int) $outcomeFlow['id'];
$stop($outcomeFlowId);
$outcomeCases = [
    ['completed', '', 'completed'],
    ['exited', 'Exit step', 'completed'],
    ['exited', 'Connection archived', 'completed'],
    ['exited', 'Exit conditions met', 'exited'],
    ['exited', 'Removed manually', 'exited'],
    ['active', '', 'active'],
    ['failed', 'Synthetic action failure', 'failed'],
];
$outcomeRuns = [];
foreach ($outcomeCases as $index=>[$rawState, $reason, $outcome]) {
    $fs->write('INSERT INTO marketing_flow_runs
        (audience_id,flow_id,revision_id,contact_id,entry_key,node_key,state,due_at,ended_at,error_message)
        VALUES (?,?,?,?,?,?,?,?,?,?)', [1, $outcomeFlowId, $outcomeFlow['published_revision'], $flowPerson,
            'outcome-'.$index, 'tag', $rawState, gmdate('Y-m-d H:i:s', $clock),
            in_array($rawState, ['completed','exited'], true) ? gmdate('Y-m-d H:i:s', $clock) : null, $reason]);
    $outcomeRuns[] = (int)$conn->insert_id;
}
$fs->write("INSERT INTO marketing_flow_steps(audience_id,run_id,node_key,state,result_json)
    VALUES (1,?,'tag','completed',JSON_OBJECT()), (1,?,'tag','completed',JSON_OBJECT()), (1,?,'exit','completed',JSON_OBJECT())",
    [$outcomeRuns[0], $outcomeRuns[1], $outcomeRuns[0]]);
$flowSelects = static fn(): int => (int)$conn->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch_row()[1];
$beforeFlowReads = $flowSelects();
$nodeCounts = $fs->flowNodeCounts($outcomeFlowId);
audienceHttpExpect($flowSelects() - $beforeFlowReads === 1 && (int)array_column($nodeCounts, 'n', 'node_key')['tag'] === 2,
    'Map step counts use one aggregate query');
$beforeFlowReads = $flowSelects();
$fs->flowReport($outcomeFlowId, ['state'=>'failed']);
$oneRunReads = $flowSelects() - $beforeFlowReads;
$beforeFlowReads = $flowSelects();
$fullReport = $fs->flowReport($outcomeFlowId);
audienceHttpExpect($flowSelects() - $beforeFlowReads === $oneRunReads && $fullReport['nodes'] === $nodeCounts,
    'Loading histories for seven runs uses the same query count as one and preserves map counts');
$stepsByRun = array_column($fullReport['runs'], 'steps', 'id');
audienceHttpExpect(array_column($stepsByRun[$outcomeRuns[0]], 'node_key') === ['tag','exit']
    && array_column($stepsByRun[$outcomeRuns[1]], 'node_key') === ['tag'] && $stepsByRun[$outcomeRuns[2]] === [],
    'The report keeps each run’s steps in execution order and includes empty histories');
$outcomeExpected = ['started'=>7,'completed'=>3,'exited'=>2,'active'=>1,'failed'=>1];
audienceHttpExpect($fs->flowRunTotals([$outcomeFlowId])[$outcomeFlowId] === $outcomeExpected,
    'Every entry belongs to exactly one outcome, including historical successful exits');
audienceHttpExpect((new AudienceStore($conn, 2, 1, 'Other audience'))->flowRunTotals([$outcomeFlowId])[$outcomeFlowId]['started'] === 0,
    'Outcome counts cannot expose another audience');
$outcomeOverview = $editor('audience.php?view=automations&q=Outcome%20summary%20fixture');
foreach (['completed','exited','active','failed'] as $outcome) {
    $expectedCount = $outcomeExpected[$outcome];
    $filtered = $fs->flowReport($outcomeFlowId, ['state'=>$outcome]);
    audienceHttpExpect($filtered['total'] === $expectedCount
        && !array_filter($filtered['runs'], static fn($run)=>audienceFlowRunOutcome($run) !== $outcome),
        'Report filter matches summary membership: '.$outcome);
    audienceHttpExpect(array_column($filtered['totals'], 'n', 'state') === array_diff_key($outcomeExpected, ['started'=>true]),
        'Filtering retains the complete outcome totals');
    audienceHttpExpect(str_contains($outcomeOverview['body'], 'aria-label="'.AUDIENCE_FLOW_RUN_LABELS[$outcome].' — '.$expectedCount.' runs"'),
        'Overview shows the matching outcome count: '.$outcome);
    $outcomePath = 'audience.php?view=flow-report&id='.$outcomeFlowId.'&state='.$outcome;
    $outcomePage = $editor($outcomePath);
    audienceHttpExpect($outcomePage['status'] === 200
        && substr_count($outcomePage['body'], 'class="flow-run-state" data-state="'.$outcome.'"') === $expectedCount,
        'Filtered report renders human-readable outcomes: '.$outcome);
    $outcomeCsv = $editor($outcomePath.'&download=csv');
    $csvLines = preg_split('/\r?\n/', trim($outcomeCsv['body']));
    $csvHeader = str_getcsv(array_shift($csvLines), ',', '"', '');
    $csvOutcomes = array_map(static fn($line)=>str_getcsv($line, ',', '"', '')[6] ?? '', $csvLines);
    audienceHttpExpect($outcomeCsv['status'] === 200 && end($csvHeader) === 'Outcome'
        && count($csvOutcomes) === $expectedCount
        && !array_diff($csvOutcomes, [AUDIENCE_FLOW_RUN_LABELS[$outcome]]),
        'CSV outcome and filter agree with the report: '.$outcome);
}
$successfulPage = $editor('audience.php?view=flow-report&id='.$outcomeFlowId.'&state=completed');
audienceHttpExpect(str_contains($successfulPage['body'], 'class="audience-notice success">Exit step')
    && str_contains($successfulPage['body'], 'class="audience-notice success">Connection archived')
    && !str_contains($successfulPage['body'], 'class="audience-notice error"'),
    'Successful exits are finished results without red error notices');
// Later map edits cannot reinterpret the historical reason stored with each run.
$pausedOutcomeFlow = $fs->flow($outcomeFlowId);
$editedOutcomeDoc = $d;
$editedOutcomeDoc['nodes'] = [['id'=>'different','type'=>'exit']];
$editedOutcomeDoc['start'] = 'different';
$fs->saveFlow('Outcome summary fixture', $editedOutcomeDoc, $outcomeFlowId, (int) $pausedOutcomeFlow['version']);
audienceHttpExpect($fs->flowRunTotals([$outcomeFlowId])[$outcomeFlowId] === $outcomeExpected,
    'Editing the current map preserves historical outcome counts');
