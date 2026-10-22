<?php
declare(strict_types=1);
$script=dirname(__DIR__).'/scripts/test_account_seed.php';
foreach (['2026-10-12'=>['2026-07-12','2027-07-12'],'2026-01-31'=>['2025-10-31','2026-10-31'],'2028-02-29'=>['2027-11-29','2028-11-29']] as $asOf=>$bounds) {
    $process=proc_open([PHP_BINARY,$script,'--as-of='.$asOf],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $plan=json_decode(stream_get_contents($pipes[1]),true,32,JSON_THROW_ON_ERROR);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==0 || $plan['window']!==$bounds)throw new RuntimeException('Incorrect calendar-month window: '.$asOf);
    $dates=$plan['event_dates'];
    if(min($dates)!==$bounds[0] || max($dates)!==$bounds[1] || count(array_unique(array_map(static fn($d)=>substr($d,0,7),$dates)))!==13)throw new RuntimeException('Demo must span every month, including both window boundaries.');
    foreach($dates as $d)if($d<$bounds[0] || $d>$bounds[1])throw new RuntimeException('Demo event outside the window.');
}
$process=proc_open([PHP_BINARY,$script,'--apply'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,
    array_merge(getenv(),['DNR_ACCOUNT_KEY'=>'shalom-in-messiah','DNR_ACCOUNT_MODE'=>'member']));
$out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);
if(proc_close($process)===0 || !str_contains($out,'Refusing to seed any account other than the Test Account member'))throw new RuntimeException('Primary-account guard must run before connecting to a database.');
echo "PASS: demo plan spans 13 calendar months, clamps month-end/leap dates, and refuses the inspiration account.\n";
