<?php
declare(strict_types=1);

require_once __DIR__.'/../src/functions.php';
require_once __DIR__.'/../src/audience_store.php';

$checks=0;
$expect=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);++$checks;};

$chicago=audienceInteractionMapCoordinate('US','Illinois','Chicago');
$expect($chicago!==null&&$chicago['precision']==='city'&&abs($chicago['latitude']-41.88)<1,'Known city gets an approximate city pin');
$madison=audienceInteractionMapCoordinate('US','Wisconsin','Madison');
$expect($madison!==null&&$madison['precision']==='city','Sample city is covered by offline reference');
$fallback=audienceInteractionMapCoordinate('US','Unknown region','Unknown city');
$expect($fallback!==null&&$fallback['precision']==='country','Unknown city uses a country label point');
$expect(audienceInteractionMapCoordinate('ZZ','','')===null,'Unknown country has no invented pin');

$rows=[
    ['source'=>'message','event_type'=>'open','country_code'=>'US','region'=>'Illinois','city'=>'Chicago','observations'=>2],
    ['source'=>'message','event_type'=>'click','country_code'=>'US','region'=>'Illinois','city'=>'Chicago','observations'=>1],
    ['source'=>'contact','event_type'=>'preferences','country_code'=>'CA','region'=>'Ontario','city'=>'Toronto','observations'=>1],
    ['source'=>'contact','event_type'=>'status','country_code'=>'US','region'=>'Unknown region','city'=>'Unknown city','observations'=>2],
    ['source'=>'contact','event_type'=>'survey-response','country_code'=>'ZZ','region'=>'','city'=>'','observations'=>1],
];
$result=audienceInteractionMapSummarize($rows);
$expect($result['observations']===7&&$result['unmapped']===1,'All observations and unmappable locations are counted');
$expect(count($result['pins'])===3,'City and country locations form separate aggregate pins');
$expect($result['unmapped_types']===['Survey responses'=>1],'Unmappable observations retain their interaction type for filtered totals');
$expect($result['pins'][0]['types']['Email opens']===2&&$result['pins'][0]['types']['Email clicks']===1,'Message interactions aggregate by type');
$expect($result['pins'][1]['precision']==='country'&&$result['pins'][1]['reported_places']!==[],'Country fallback explains the reported place');

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
$expect(str_contains($html,'<option value="">All interactions</option>'),'Map filter offers a reset to all interactions');
foreach(audienceInteractionMapTypes() as$label)$expect(str_contains($html,'<option value="'.$h($label).'">'.$h($label).'</option>'),'Map filter offers '.$label);
preg_match('/<script type="application\/json" id="interaction-location-map-data">(.*?)<\/script>/s',$html,$payloadMatch);
$payload=json_decode($payloadMatch[1]??'',true,512,JSON_THROW_ON_ERROR);
$expect($payload['unmapped']===1&&$payload['unmappedTypes']===['Survey responses'=>1],'Rendered map payload includes per-type unmapped counts');

echo "Audience interaction map: {$checks} checks passed.\n";
