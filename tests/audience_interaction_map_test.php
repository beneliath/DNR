<?php
declare(strict_types=1);

require_once __DIR__.'/../src/functions.php';
require_once __DIR__.'/../src/audience_store.php';

$checks=0;
$expect=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);++$checks;};

$rows=[
    ['source'=>'message','event_type'=>'open','country_code'=>'US','region'=>'Illinois','city'=>'Chicago','observations'=>2],
    ['source'=>'message','event_type'=>'click','country_code'=>'US','region'=>'Wisconsin','city'=>'Madison','observations'=>1],
    ['source'=>'contact','event_type'=>'preferences','country_code'=>'CA','region'=>'Ontario','city'=>'Toronto','observations'=>1],
    ['source'=>'contact','event_type'=>'status','country_code'=>'US','observations'=>2],
    ['source'=>'contact','event_type'=>'survey-response','country_code'=>'ZZ','region'=>'','city'=>'','observations'=>1],
];
$result=audienceInteractionMapSummarize($rows);
$expect($result['observations']===7&&$result['unmapped']===1,'All observations and unmappable locations are counted');
$expect(count($result['pins'])===2,'Different cities and country-only events in the same country share one pin');
$expect($result['unmapped_types']===['Survey Responses'=>1],'Unmappable observations retain their interaction type for filtered totals');
$us=$result['pins'][0];
$expect($us['label']==='United States of America'&&$us['observations']===5&&[$us['longitude'],$us['latitude']]===audienceInteractionMapCoordinate('US'),'The US pin shows its total at the country label point');
$expect($us['types']['Email Opens']===2&&$us['types']['Email Clicks']===1&&$us['types']['Unsubscribes']===2,'Contact and message interactions retain their per-type counts');

$allTypes=[];
foreach(audienceInteractionMapTypes() as$key=>$label){
    [$source,$eventType]=explode(':',$key,2);
    $allTypes[]=['source'=>$source,'event_type'=>$eventType,'country_code'=>'US','region'=>'Illinois','city'=>'Chicago','observations'=>1];
    $allTypes[]=['source'=>$source,'event_type'=>$eventType,'country_code'=>'ZZ','observations'=>2];
}
$typedResult=audienceInteractionMapSummarize($allTypes);
$expect($typedResult['pins'][0]['types']===array_fill_keys(array_values(audienceInteractionMapTypes()),1),'Every selectable interaction type is represented in mapped counts');
$expect($typedResult['unmapped_types']===array_fill_keys(array_values(audienceInteractionMapTypes()),2),'Every selectable interaction type is represented in unmapped counts');
$expect(audienceInteractionMapSummarize([])['unmapped_types']===[],'Empty reports have no unmapped type counts');

$interactionLocations=$result;
$interactionMapProvider=['rasterTileUrl'=>'https://example.test/{z}/{x}/{y}.png'];
$h=static fn($value):string=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
ob_start();
include __DIR__.'/../src/templates/audience/analytics-interaction-locations.php';
$html=ob_get_clean();
$expect(str_contains($html,'<option value="">All Interactions</option>'),'Map filter offers a reset to all interactions');
foreach(audienceInteractionMapTypes() as$label)$expect(str_contains($html,'<option value="'.$h($label).'">'.$h($label).'</option>'),'Map filter offers '.$label);
preg_match('/<script type="application\/json" id="interaction-location-map-data">(.*?)<\/script>/s',$html,$payloadMatch);
$payload=json_decode($payloadMatch[1]??'',true,512,JSON_THROW_ON_ERROR);
$expect($payload['unmapped']===1&&$payload['unmappedTypes']===['Survey Responses'=>1],'Rendered map payload includes per-type unmapped counts');
$expect(str_contains($html,'Interactions by Country')&&str_contains($html,'2 countries · 6 mapped interactions'),'The report table and counts describe country totals');
$expect(!preg_match('/Chicago|Illinois|Madison|Wisconsin|Toronto|Ontario|reported_places/', $html),'Neither the table nor its embedded map data contains detailed locations');

echo "Audience interaction map: {$checks} checks passed.\n";
