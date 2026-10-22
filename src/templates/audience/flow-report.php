<?php
/** @var Closure(array<string,mixed>): string $flowLink */
/** @var int $id */
$totals=array_column($report['totals'],'n','state');
$runLabels=['active'=>'In progress','completed'=>'Completed','exited'=>'Exited','failed'=>'Needs attention'];
$stepLabels=['waiting'=>'Waiting','completed'=>'Completed','skipped'=>'Skipped','failed'=>'Needs attention'];
$reportState=is_string($_GET['state']??null)&&isset($runLabels[$_GET['state']])?$_GET['state']:'';
$nodeNames=array_column($flow['document']['nodes'],'label','id');
$reportNode=is_string($_GET['node']??null)&&isset($nodeNames[$_GET['node']])?$_GET['node']:'';
$reportFiltered=$reportState!==''||$reportNode!=='';
$reportLink=static fn(array $p=[])=>$flowLink(array_replace(['id'=>$id,'state'=>$reportState,'node'=>$reportNode],$p));
$nodeTotals=[];foreach($report['nodes']as$row)$nodeTotals[$row['node_key']][$row['state']]=(int)$row['n'];
?>
<div class="flow-report">
    <nav class="campaign-tabs" aria-label="Flow views"><a href="<?= $h($url(['view'=>'flow','id'=>$id])) ?>">Flow map</a><a href="<?= $h($flowLink(['id'=>$id])) ?>" aria-current="page">Report</a></nav>
    <section class="flow-report-totals" aria-label="Execution totals">
        <?php foreach(['started'=>'Started']+$runLabels as$key=>$label): ?>
        <article><h2><?= $label ?></h2><strong><?= number_format($key==='started'?array_sum(array_map('intval',$totals)):(int)($totals[$key]??0)) ?></strong></article>
        <?php endforeach; ?>
    </section>
    <div class="flow-report-columns">
        <section class="flow-report-panel flow-report-steps" aria-labelledby="flow-steps-heading">
            <header class="flow-report-panel-heading"><div><h2 id="flow-steps-heading">Step analysis</h2><p>Select a step to filter connection activity</p></div></header>
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
            <header class="flow-report-panel-heading"><div><h2 id="flow-activity-heading">Connection activity <span class="flow-report-count"><?= number_format($report['total']) ?></span></h2></div>
                <?php if($canManage): ?><div class="flow-report-actions"><a class="audience-button" href="<?= $h($reportLink(['download'=>'csv'])) ?>">Export CSV</a><button type="button" class="primary" data-open-dialog="flow-add-person"<?= $flow['state']!=='running'?' disabled title="Turn this flow on before adding connections"':'' ?>>Add connection</button></div><?php endif; ?>
            </header>
            <form method="get" class="flow-report-filters">
                <?php audienceInputs(['view'=>'flow-report','id'=>$id]); ?>
                <label>Status<select name="state"><option value="">All statuses</option><?php foreach($runLabels as$key=>$label): ?><option value="<?= $key ?>"<?= $reportState===$key?' selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                <label>Step<select name="node"><option value="">All steps</option><?php foreach($flow['document']['nodes']as$n): ?><option value="<?= $h($n['id']) ?>"<?= $reportNode===$n['id']?' selected':'' ?>><?= $h($n['label']) ?></option><?php endforeach; ?></select></label>
                <button type="submit">Apply</button><?php if($reportFiltered): ?><a href="<?= $h($flowLink(['id'=>$id])) ?>">Clear filters</a><?php endif; ?>
            </form>
            <?php if(!$report['runs']): ?>
            <div class="flow-report-empty"><?= audienceFlowIcon($reportFiltered?'condition':'trigger') ?><h3><?= $reportFiltered?'No matching activity':'No connection activity yet' ?></h3><p><?= $reportFiltered?'Try a different status or step, or clear your filters.':'Connections appear here after they enter this flow.' ?></p></div>
            <?php else: ?>
            <div class="flow-report-runs">
                <?php foreach($report['runs']as$r): $personName=$r['deleted_at']?'Removed connection':trim($r['first_name'].' '.$r['last_name']); ?>
                <details class="flow-run">
                    <summary><span class="flow-run-person"><strong><?= $h($personName?:$r['email']) ?></strong><?php if($personName&&!$r['deleted_at']): ?><small><?= $h($r['email']) ?></small><?php endif; ?></span><span class="flow-run-state" data-state="<?= $h($r['state']) ?>"><?= $h($runLabels[$r['state']]??ucfirst($r['state'])) ?></span><time datetime="<?= $h(str_replace(' ','T',$r['started_at']).'Z') ?>"><?= $h($r['started_at']) ?> UTC</time></summary>
                    <div class="flow-run-body">
                        <?php if($r['error_message']!==''): ?><p class="audience-notice error"><?= $h($r['error_message']) ?></p><?php endif; ?>
                        <p class="flow-run-meta"><span>Execution <?= (int)$r['id'] ?></span><span>Revision <?= (int)$r['revision_id'] ?></span></p>
                        <ol><?php foreach($r['steps']as$step): ?><li><strong><?= $h($nodeNames[$step['node_key']]??$step['node_key']) ?></strong> · <?= $h($stepLabels[$step['state']]??ucfirst($step['state'])) ?> · <?= $h($step['created_at']) ?> UTC<?php $detail=audienceJson($step['result_json']);if(isset($detail['path']))echo ' · Path '.$h($detail['path']);if(isset($detail['receipt']))echo ' · Receipt '.$h($detail['receipt']);if(count($detail['history']??[])>1)echo ' · '.$h(implode(' → ',array_column($detail['history'],'state'))); ?></li><?php endforeach; ?></ol>
                        <?php if($canManage): ?><div class="audience-actions"><?php if($r['state']==='failed'): ?><form method="post"><?php $flowForm('flow_retry',['run_id'=>$r['id']]); ?><button>Retry failed data step</button></form><?php endif; ?><?php if(in_array($r['state'],['active','failed'],true)): ?><form method="post" data-confirm="Remove this connection from the flow and cancel queued actions?"><?php $flowForm('flow_exit',['run_id'=>$r['id']]); ?><button>Remove from flow</button></form><?php endif; ?><?php if(!$r['deleted_at']): ?><a class="audience-button" href="<?= $h($url(['view'=>'contact','id'=>$r['contact_id']])) ?>">View connection</a><?php endif; ?></div><?php endif; ?>
                    </div>
                </details>
                <?php endforeach; ?>
            </div>
            <nav class="audience-pagination" aria-label="Execution pages"><span><?= number_format(($report['page']-1)*25+1) ?>–<?= number_format(min($report['total'],$report['page']*25)) ?> of <?= number_format($report['total']) ?> executions</span><div><?php if($report['page']>1): ?><a href="<?= $h($reportLink(['page'=>$report['page']-1])) ?>" aria-label="Previous page">←</a><?php endif; ?><span><?= $report['page'] ?> / <?= $report['pages'] ?></span><?php if($report['page']<$report['pages']): ?><a href="<?= $h($reportLink(['page'=>$report['page']+1])) ?>" aria-label="Next page">→</a><?php endif; ?></div></nav>
            <?php endif; ?>
        </section>
    </div>
</div>
