<?php
/** @var Closure(array<string,mixed>): string $flowLink */
/** @var int $id */
$totals=array_column($report['totals'],'n','state');
$runLabels=AUDIENCE_FLOW_RUN_LABELS;
$stepLabels=['waiting'=>'Waiting','completed'=>'Completed','skipped'=>'Skipped','failed'=>'Needs Attention'];
$reportState=is_string($_GET['state']??null)&&isset($runLabels[$_GET['state']])?$_GET['state']:'';
$nodeNames=array_column($flow['document']['nodes'],'label','id');
$reportNode=is_string($_GET['node']??null)&&isset($nodeNames[$_GET['node']])?$_GET['node']:'';
$reportFiltered=$reportState!==''||$reportNode!=='';
$reportLink=static fn(array $p=[])=>$flowLink(array_replace(['id'=>$id,'state'=>$reportState,'node'=>$reportNode,'per_page'=>$report['per_page']??25],$p));
$nodeTotals=[];foreach($report['nodes']as$row)$nodeTotals[$row['node_key']][$row['state']]=(int)$row['n'];
?>
<div class="flow-report">
    <nav class="campaign-tabs" aria-label="Flow Views"><a href="<?= $h($flow['deleted_at']?$url(['view'=>'automations','trash'=>'1']):$url(['view'=>'flow','id'=>$id])) ?>"><?= $flow['deleted_at']?'Archived Flows':'Flow Map' ?></a><a href="<?= $h($flowLink(['id'=>$id])) ?>" aria-current="page">Report</a></nav>
    <section class="flow-report-totals" aria-label="Execution Totals">
        <?php foreach(['started'=>'Started']+$runLabels as$key=>$label): ?>
        <?php $count = $key==='started' ? array_sum(array_map('intval',$totals)) : (int)($totals[$key]??0); ?>
        <article data-outcome="<?= $h($key) ?>"<?= $count ? ' class="has-runs"' : '' ?> title="<?= $h(AUDIENCE_FLOW_RUN_DESCRIPTIONS[$key]) ?>"><h2><?= $label ?></h2><strong><?= number_format($count) ?></strong></article>
        <?php endforeach; ?>
    </section>
    <p class="flow-outcome-help"><?= $h(AUDIENCE_FLOW_RUN_DESCRIPTIONS['completed']) ?></p>
    <div class="flow-report-columns">
        <section class="flow-report-panel flow-report-steps" aria-labelledby="flow-steps-heading">
            <header class="flow-report-panel-heading"><div><h2 id="flow-steps-heading">Step Analysis</h2><p>Select a step to filter connection activity</p></div></header>
            <div class="audience-table-scroll"><table class="data-table flow-report-table">
                <caption class="sr-only">Executions by step and outcome</caption>
                <thead><tr><th scope="col">Step</th><?php foreach($stepLabels as$label): ?><th scope="col"><?= $label ?></th><?php endforeach; ?></tr></thead>
                <tbody><?php foreach($flow['document']['nodes']as$n): ?>
                <tr<?= $reportNode===$n['id']?' class="is-current"':'' ?>>
                    <th scope="row"><a href="<?= $h($reportLink(['node'=>$n['id'],'page'=>1])) ?>"<?= $reportNode===$n['id']?' aria-current="true"':'' ?>><?= audienceFlowIcon($n['type']) ?><span><?= $h($n['label']) ?></span></a></th>
                    <?php foreach($stepLabels as$key=>$label): $count=$nodeTotals[$n['id']][$key]??0; ?><td<?= !$count?' class="flow-report-zero"':'' ?>><?= number_format($count) ?></td><?php endforeach; ?>
                </tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <?php if(!$flow['document']['nodes']): ?><p class="flow-report-note">Add steps in the flow map to see their results here</p><?php endif; ?>
        </section>

        <section class="flow-report-panel flow-report-activity" aria-labelledby="flow-activity-heading">
            <header class="flow-report-panel-heading"><div><h2 id="flow-activity-heading">Connection Activity <span class="flow-report-count"><?= number_format($report['total']) ?></span></h2></div>
                <?php if($canManage): ?><div class="flow-report-actions"><a class="audience-button" href="<?= $h($reportLink(['download'=>'csv'])) ?>">Export CSV</a><?php if(!$flow['deleted_at']): ?><button type="button" class="primary" data-open-dialog="flow-add-person"<?= $flow['state']!=='running'?' disabled title="Turn this flow on before adding connections"':'' ?>>Add Connection</button><?php endif; ?></div><?php endif; ?>
            </header>
            <form method="get" class="flow-report-filters">
                <?php audienceInputs(['view'=>'flow-report','id'=>$id]); ?>
                <?php audienceSelectFilter('Status','state',[''=>'All Statuses']+$runLabels,$reportState); ?>
                <label>Step<select name="node"><option value="">All Steps</option><?php foreach($flow['document']['nodes']as$n): ?><option value="<?= $h($n['id']) ?>"<?= $reportNode===$n['id']?' selected':'' ?>><?= $h($n['label']) ?></option><?php endforeach; ?></select></label>
                <button type="submit">Apply</button><?php if($reportFiltered): ?><a href="<?= $h($flowLink(['id'=>$id])) ?>">Clear Filters</a><?php endif; ?>
            </form>
            <?php if(!$report['runs']): ?>
            <div class="flow-report-empty"><?= audienceFlowIcon($reportFiltered?'condition':'trigger') ?><h3><?= $reportFiltered?'No Matching Activity':'No Connection Activity Yet' ?></h3><p><?= $reportFiltered?'Try a different status or step, or clear your filters.':'Connections appear here after they enter this flow.' ?></p></div>
            <?php else: ?>
            <div class="flow-report-runs">
                <?php foreach($report['runs']as$r): $personName=$r['deleted_at']?'Removed connection':trim($r['first_name'].' '.$r['last_name']); $outcome=audienceFlowRunOutcome($r); ?>
                <details class="flow-run">
                    <summary><span class="flow-run-person"><strong><?= $h($personName?:$r['email']) ?></strong><?php if($personName&&!$r['deleted_at']): ?><small><?= $h($r['email']) ?></small><?php endif; ?></span><span class="flow-run-state" data-state="<?= $h($outcome) ?>"><?= $h($runLabels[$outcome]??\Dnr\Domain\ReferenceData::label($outcome)) ?></span><time datetime="<?= $h(str_replace(' ','T',$r['started_at']).'Z') ?>"><?= $h(formatUserTimestamp($r['started_at'],'Y-m-d H:i:s')) ?></time></summary>
                    <div class="flow-run-body">
                        <?php if($r['error_message']!==''): ?><p class="audience-notice<?= $outcome==='completed'?' success':($outcome==='failed'?' error':'') ?>"><?= $h($r['error_message']) ?></p><?php endif; ?>
                        <p class="flow-run-meta"><span>Execution <?= (int)$r['id'] ?></span><span>Revision <?= (int)$r['revision_id'] ?></span></p>
                        <ol><?php foreach($r['steps']as$step): ?><li><strong><?= $h($nodeNames[$step['node_key']]??$step['node_key']) ?></strong> · <?= $h($stepLabels[$step['state']]??\Dnr\Domain\ReferenceData::label($step['state'])) ?> · <?= $h(formatUserTimestamp($step['created_at'],'Y-m-d H:i:s')) ?><?php $detail=audienceJson($step['result_json']);if(isset($detail['path']))echo ' · Path '.$h($detail['path']);if(isset($detail['receipt']))echo ' · Receipt '.$h($detail['receipt']);if(count($detail['history']??[])>1)echo ' · '.$h(implode(' → ',array_column($detail['history'],'state'))); ?></li><?php endforeach; ?></ol>
                        <?php if($canManage&&!$flow['deleted_at']): ?><div class="audience-actions"><?php if($r['state']==='failed'): ?><form method="post"><?php $flowForm('flow_retry',['run_id'=>$r['id']]); ?><button>Retry Failed Data Step</button></form><?php endif; ?><?php if(in_array($r['state'],['active','failed'],true)): ?><form method="post" data-confirm="Remove this connection from the flow and cancel queued actions?"><?php $flowForm('flow_exit',['run_id'=>$r['id']]); ?><button>Remove from Flow</button></form><?php endif; ?><?php if(!$r['deleted_at']): ?><a class="audience-button" href="<?= $h($url(['view'=>'contact','id'=>$r['contact_id']])) ?>">View Connection</a><?php endif; ?></div><?php endif; ?>
                    </div>
                </details>
                <?php endforeach; ?>
            </div>
            <?php audiencePagination(['total'=>$report['total'],'page'=>$report['page'],'size'=>$report['per_page']??25,'label'=>'Execution Pages','url'=>$reportLink]); ?>
            <?php endif; ?>
        </section>
    </div>
</div>
