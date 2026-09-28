<?php
/** Read-only query-plan and latency capture. Run against a restored performance dataset. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
if (getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    fwrite(STDERR,"Run in a disposable restored database; production benchmarking is intentionally refused.\n"); exit(64);
}
require_once '/var/www/html/config.php';
$seed=in_array('--seed-synthetic',$argv,true);
if($seed) {
    $conn->begin_transaction();
    $prefix='Benchmark '.bin2hex(random_bytes(4)).' ';
    for($batch=0;$batch<40;$batch++) {
        $values=[];
        for($i=0;$i<500;$i++) $values[]=$prefix.sprintf('%05d',$batch*500+$i);
        $conn->execute_query('INSERT INTO organizations (organization_name) VALUES '.implode(',',array_fill(0,count($values),'(?)')),$values);
    }
}
try {
$cursor=$conn->query('SELECT id,organization_name FROM organizations WHERE is_deleted=0 ORDER BY organization_name,id LIMIT 1 OFFSET 10000')->fetch_assoc();
$queries=[
    'organizations_first_page'=>['SELECT id,organization_name FROM organizations WHERE is_deleted=0 ORDER BY organization_name,id LIMIT 25',[]],
    'network_last_5001'=>['SELECT recorded_at,id FROM network_performance_samples WHERE recorded_at >= UTC_TIMESTAMP()-INTERVAL 1 DAY ORDER BY recorded_at DESC,id DESC LIMIT 5001',[]],
    'pending_document_jobs'=>["SELECT id FROM document_scan_jobs WHERE state IN ('queued','scanning') AND retry_at<=UTC_TIMESTAMP(6) ORDER BY retry_at,id LIMIT 1",[]],
    'old_statistics_batch'=>['SELECT link_id,visit_hour FROM short_link_stats WHERE visit_hour < UTC_TIMESTAMP()-INTERVAL 400 DAY ORDER BY visit_hour,link_id LIMIT 1000',[]],
];
if ($cursor) $queries['organizations_deep_cursor']=['SELECT id,organization_name FROM organizations WHERE is_deleted=0 AND (organization_name>? OR (organization_name=? AND id>?)) ORDER BY organization_name,id LIMIT 25',[$cursor['organization_name'],$cursor['organization_name'],$cursor['id']]];
$report=['captured_at'=>gmdate('c'),'database_version'=>$conn->query('SELECT VERSION()')->fetch_row()[0],'queries'=>[]];
foreach ($queries as $name=>[$sql,$parameters]) {
    $times=[]; $rows=0;
    for($attempt=0;$attempt<7;$attempt++) {
        $start=hrtime(true); $result=$conn->execute_query($sql,$parameters); $rows=$result->num_rows; $result->free();
        if($attempt>0) $times[]=(hrtime(true)-$start)/1e6;
    }
    sort($times);
    $plan=$conn->execute_query('EXPLAIN ANALYZE '.$sql,$parameters)->fetch_row()[0];
    $report['queries'][$name]=['rows'=>$rows,'median_ms'=>round(($times[2]+$times[3])/2,3),'max_ms'=>round(max($times),3),'plan'=>$plan];
}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
} finally { if($seed) $conn->rollback(); }
