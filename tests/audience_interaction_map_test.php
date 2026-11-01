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
$expect($result['pins'][0]['types']['Email opens']===2&&$result['pins'][0]['types']['Email clicks']===1,'Message interactions aggregate by type');
$expect($result['pins'][1]['precision']==='country'&&$result['pins'][1]['reported_places']!==[],'Country fallback explains the reported place');

echo "Audience interaction map: {$checks} checks passed.\n";
