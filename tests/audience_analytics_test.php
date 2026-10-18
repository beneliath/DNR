<?php
declare(strict_types=1);
require_once __DIR__.'/../src/audience_helpers.php';require_once __DIR__.'/../src/audience_analytics.php';
$checks=0;$expect=static function(bool $condition,string $message)use(&$checks):void{if(!$condition)throw new RuntimeException($message);$checks++;};
$now=new DateTimeImmutable('2026-10-17T22:00:00Z');
$home=audienceAnalyticsPresets(true,$now);$analytics=audienceAnalyticsPresets(false,$now);
$expect($home[30]['start']==='2026-09-17'&&$home[30]['end']==='2026-10-16','Home rolling periods exclude today, matching the observed reference');
$expect($analytics[90]['start']==='2026-07-20'&&$analytics[90]['end']==='2026-10-17','Analytics rolling periods include today');
$expect($home['week']['start']==='2026-10-04'&&$home['week']['end']==='2026-10-10','Calendar week is Sunday through Saturday');
$expect($home['month']['start']==='2026-09-01'&&$home['month']['end']==='2026-09-30','Previous calendar month is exact');
$expect($home['quarter']['start']==='2026-07-01'&&$home['quarter']['end']==='2026-09-30','Previous quarter is exact');
$expect($home['qtd']['end']==='2026-10-16'&&$home['ytd']['start']==='2026-01-01','Partial calendar presets stop yesterday');
$window=audienceAnalyticsWindow(['start'=>'2026-09-17','end'=>'2026-10-16'],$now);
$expect($window['days']===30&&$window['previous_from']==='2026-08-18 00:00:00'&&$window['previous_until']==='2026-09-17 00:00:00','Comparison interval is adjacent, equal and half open');
$expect(audienceAnalyticsRate(1,0)===null&&audienceAnalyticsRate(1,4)===25.0,'Zero denominators remain unknown');
$expect(str_contains(audienceAnalyticsTrend(36,30,'accepted'),'Up 20.0%'),'Count change uses relative percentage');
$expect(str_contains(audienceAnalyticsTrend(50,40,'open_rate'),'Up 25.0%'),'Rate change also uses relative percentage');
$expect(str_contains(audienceAnalyticsTrend(0,1,'unsubscribed'),'improved'),'Fewer unsubscribes is an improvement');
$expect(!str_contains(audienceAnalyticsTrend(10,0,'accepted'),'<svg'),'Missing baseline never invents an infinite trend');
$expect(!str_contains(audienceAnalyticsTrend(20,10,'accepted',false),'<svg'),'Disabled comparison has no trend');
$failed=false;try{audienceAnalyticsWindow(['messages'=>['1 OR 1=1']],$now);}catch(InvalidArgumentException){$failed=true;}$expect($failed,'Message selector IDs are strictly typed');
$d=audienceAnalyticsReportDefinition(['dataset'=>'email','columns'=>['accepted','click_rate'],'plots'=>['click_rate']]);
$expect($d['dimension']==='campaign'&&$d['plots']===['click_rate'],'Custom report metrics and plots are explicit');
foreach([['dataset'=>'invented'],['dimension'=>'recipient-email'],['columns'=>[]],['columns'=>['password']],['plots'=>['accepted','delivered','opens']],['columns'=>['opens'],'plots'=>['accepted']]]as$bad){$failed=false;try{audienceAnalyticsReportDefinition($bad);}catch(InvalidArgumentException){$failed=true;}$expect($failed,'Unsupported report definitions are rejected');}
$failed=false;try{audienceAnalyticsWindow(['segment'=>['1']],$now);}catch(InvalidArgumentException){$failed=true;}$expect($failed,'Malformed segment selectors are rejected');
$client=audienceAnalyticsClientEvidence('Mozilla/5.0 (iPhone) Safari/604.1','IL','https://example.test/news?email=private@example.test#token');
$expect($client===['device'=>'Mobile','client'=>'Safari','country'=>'IL','link_label'=>'example.test/news'],'Tracking evidence is coarse and strips URL queries/fragments');
$expect(audienceAnalyticsClientEvidence('',null)['country']==='Unknown','Missing location is never inferred');
echo "Analytics window and trend validation passed ($checks checks).\n";
