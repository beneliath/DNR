<?php
require_once __DIR__.'/../src/audience_design.php';
require_once __DIR__.'/../src/audience_collection.php';
$checks=0;$banners=[];
function starterCatalogExpect(bool $ok,string $label):void{global $checks;++$checks;if(!$ok)throw new RuntimeException($label);}
foreach(audienceDesignStarters() as $key=>$starter) {
    starterCatalogExpect(!preg_match('/\b(?:products?|purchase\w*)\b/i',json_encode($starter,JSON_THROW_ON_ERROR)),'Email starter follows the selected catalogue scope');
    starterCatalogExpect(!in_array($starter['category'],['Sell services','Transactional'],true),'Removed categories have no email starters');
    if($key!=='blank'){
        $banner=$starter['design']['rows'][0]['columns'][0][0];
        starterCatalogExpect($banner['type']==='image'&&$banner['alt']!==''&&str_starts_with($banner['image'],'data:image/jpeg;base64,'),'Each designed starter has an accessible bundled banner');
        $banners[]=hash('sha256',$banner['image']);
    }
}
starterCatalogExpect(count($banners)===14&&count(array_unique($banners))===14,'All fourteen designed starters use different banner images');
foreach(AUDIENCE_SURVEY_STARTERS as $key=>$starter) {
    starterCatalogExpect(!preg_match('/\b(?:products?|purchase\w*)\b/i',json_encode($starter,JSON_THROW_ON_ERROR)),'Survey starter follows the selected catalogue scope');
    $doc=audienceCollectionStarter('survey',$key,$starter[1]);
    starterCatalogExpect(count($doc['questions'])>0,'Retained survey starter has usable questions');
}
starterCatalogExpect(AUDIENCE_SURVEY_STARTERS['channels'][0]==='Effectiveness','Neutral effectiveness category');
echo "MessageMonkey starter catalogues: $checks checks passed.\n";
