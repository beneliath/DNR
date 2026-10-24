<?php
declare(strict_types=1);
require_once __DIR__.'/../src/audience_store.php';
$checks=0;
$expect=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;};
$now=strtotime('2026-10-16 12:00 UTC');
foreach([
    ['America/Chicago','2026-11-01 00:30','2026-11-01 05:30:00'],
    ['America/Chicago','2026-11-01 02:30','2026-11-01 08:30:00'],
    ['America/Chicago','2027-03-14 03:00','2027-03-14 08:00:00'],
    ['America/New_York','2026-11-01 02:30','2026-11-01 07:30:00'],
    ['Asia/Kolkata','2026-10-20 03:00','2026-10-19 21:30:00'],
    ['Pacific/Auckland','2026-10-20 09:00','2026-10-19 20:00:00']
]as[$zone,$local,$utc])$expect(audienceDispatchDue(['mode'=>'schedule','timezone'=>$zone,'local'=>$local],$now)===$utc,$zone.' converts correctly across UTC dates and DST');
foreach([
    ['America/Chicago','2026-11-01 01:30','occurs twice'],
    ['America/New_York','2026-11-01 01:30','occurs twice'],
    ['America/Chicago','2027-03-14 02:30','does not exist']
]as[$zone,$local,$reason]){
    try{audienceDispatchDue(['mode'=>'schedule','timezone'=>$zone,'local'=>$local],$now);throw new RuntimeException('Ambiguous or nonexistent local time accepted');}
    catch(InvalidArgumentException $e){$expect(str_contains($e->getMessage(),$reason),'DST failure explains the actual clock boundary');}
}
echo "Scheduling boundary checks passed: $checks (DST gaps/repeats, half-hour offsets and UTC date rollover).\n";
