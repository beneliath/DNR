<?php
declare(strict_types=1);
require_once __DIR__.'/../src/audience_helpers.php';require_once __DIR__.'/../src/audience_analytics.php';require_once __DIR__.'/../src/audience_mailchimp_history.php';
$checks=0;$expect=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$reject=static function(callable $call,string $message)use($expect):void{$failed=false;try{$call();}catch(InvalidArgumentException){$failed=true;}$expect($failed,$message);};
$csv=file_get_contents(__DIR__.'/fixtures/mailchimp-history/campaign.csv');
$parse=static fn(string $text,string $zone='America/Chicago',string $filter='filtered')=>audienceMailchimpReport($text,$zone,$filter);
$r=$parse($csv);$expect($r['sent_at']==='2026-10-15 14:01:00','Chicago export dates become UTC without changing the campaign date');
$expect($r['metrics']['accepted']===1000&&$r['metrics']['opens']===196&&$r['metrics']['total_opens']===240,'Unique and total observations stay distinct');
$expect(!str_contains(json_encode($r),'secret')&&!str_contains(json_encode($r),'https://'),'URL/recipient sections are excluded from retained history');
$expect($r['provenance']['reported_percentages']['opens']===20.0&&$r['provenance']['bot_filter']==='filtered','Original report percentages and filtering remain explicit');
$expect($r['source_key']===$parse("\xEF\xBB\xBF".$csv)['source_key'],'UTF-8 BOM does not change campaign identity');
$expect(audienceMailchimpReport($csv,'America/Chicago','unknown','abc123')['source_key']==='id:abc123','A supplied vendor ID retains its separate identity');
$totals=audienceMailchimpTotals([$r]);$expect($totals['open_rate']===20.0&&$totals['click_rate']===5.0,'Mailchimp engagement uses successful deliveries');
$empty=audienceMailchimpTotals([]);$expect($empty['open_rate']===null&&$empty['delivery_rate']===null,'Empty history remains unknown');
$second=$r;$second['metrics']=array_replace($r['metrics'],['accepted'=>100,'delivered'=>100,'opens'=>100,'clicks'=>0,'bounced'=>0]);
$expect(audienceMailchimpTotals([$r,$second])['open_rate']===27.41,'Aggregate rates are weighted counts rather than averages of percentages');
foreach([
 str_replace('Email Campaign Report','Contact list',$csv),str_replace('Successful Deliveries,980','Successful Deliveries,981',$csv),str_replace('196 (20%)','981 (20%)',$csv),str_replace('Total Clicks,70','Total Clicks,3',$csv),str_replace('Total Recipients,"1,000"','Total Recipients,"1,00"',$csv),str_replace('Total Unsubs,4','Total Unsubs,-1',$csv),str_replace('Total Unsubs,4','Total Unsubs,4' . "\nTotal Unsubs,4",$csv),str_replace('Thursday, October 15, 2026','Thursday, February 30, 2026',$csv),str_replace('9:01 AM','25:01 AM',$csv),str_replace('Title,Example community update',"Title,\"bad\0title\"",$csv),str_repeat('x',2*1024*1024+1)
]as$bad)$reject(static fn()=>$parse($bad),'Malformed or inconsistent source data is rejected');
$reject(static fn()=>$parse($csv,'Unknown/Timezone'),'Unverified timezone is rejected');$reject(static fn()=>$parse($csv,'America/Chicago','invented'),'Unverified filtering mode is rejected');
$expect($parse($csv,'America/Chicago','unknown')['provenance']['bot_filter']==='unknown','Missing provider filtering is not reclassified as bot-free');
$bulk=file_get_contents(__DIR__.'/fixtures/mailchimp-history/campaigns.csv');$records=audienceMailchimpCampaigns($bulk,'America/Chicago','unknown');
$expect(count($records)===1&&$records[0]['source_key']==='id:example123','Bulk campaign identity uses the exported stable vendor ID');
$expect($records[0]['provenance']['source_audience']==='Original example audience'&&$records[0]['metrics']['soft_bounces']===5,'Bulk history retains its original audience and bounce detail');
$expect($records[0]['sent_at']===$r['sent_at']&&$records[0]['metrics']['opens']===$r['metrics']['opens'],'Individual and bulk report counts and dates reconcile');
$expect(!str_contains(json_encode($records),'Total Revenue'),'Unrelated commerce export fields do not become invented native conversions');
foreach([str_replace('Unique Id','Wrong Id',$bulk),str_replace('example123','../unsafe',$bulk),str_replace('example123','',$bulk),str_replace('Oct 15, 2026 09:01 am','Oct 99, 2026 09:01 am',$bulk),str_replace(',5,15,20,',',5,14,20,',$bulk),str_replace('20%,240','120%,240',$bulk)]as$bad)$reject(static fn()=>audienceMailchimpCampaigns($bad,'America/Chicago','unknown'),'Malformed aggregate source is rejected before any writes');
$expect(audienceMailchimpDailyDevotional(array_replace($r,['title'=>'DD_03152021'])),'DD prefixes identify devotionals even on the master audience');
$expect(audienceMailchimpDailyDevotional(array_replace($r,['title'=>'Daily Devotions March'])),'Explicit devotional titles are excluded');
$expect(audienceMailchimpDailyDevotional(array_replace($r,['subject'=>'Your daily devotional'])),'Explicit devotional subjects are excluded');
$expect(!audienceMailchimpDailyDevotional(array_replace($r,['title'=>'Shana Tovah 5776','subject'=>'Shana Tovah','provenance'=>['source_audience'=>'Daily Devotions by Olivier Jack Melnick']])),'Holiday messages to the old devotional audience remain included');
echo "Mailchimp history parsing and metric checks passed ($checks checks).\n";
