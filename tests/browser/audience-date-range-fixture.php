<?php
declare(strict_types=1);
// Exercise the production preferences and calendar controls without a database.
require __DIR__.'/../../src/functions.php';
require __DIR__.'/../../src/audience_store.php';
function audienceInputs(array $values,string $prefix=''):void
{
    foreach($values as$key=>$value){
        $name=$prefix===''?(string)$key:$prefix.'['.$key.']';
        if(is_array($value))audienceInputs($value,$name);
        else echo '<input type="hidden" name="'.htmlspecialchars($name,ENT_QUOTES,'UTF-8').'" value="'.htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8').'">';
    }
}
$_SESSION=['user_id'=>11,'username'=>'Fixture User','role'=>'reviewer','_csrf_token'=>'fixture-token'];
$view=($_GET['view']??'')==='home'?'home':'analytics';
$section=audienceText($_GET,'section',20)?:'email';
$input=audienceRememberAnalyticsRange($_GET,$view,$section,11);
$presets=audienceAnalyticsPresets();
$rangeKey=audienceText($input,'range',20)?:((isset($input['start'])||isset($input['end']))?'custom':($view==='home'?'30':($section==='locations'?'7':'90')));
if($rangeKey!=='custom')$input=array_replace($input,$presets[$rangeKey]);
$window=audienceAnalyticsWindow($input);
$showDrafts=false;$messageOptions=[];$definition=null;
$reportQuery=['view'=>$view,'section'=>$section,'range'=>$rangeKey,'start'=>$window['start'],'end'=>$window['end'],'compare'=>$window['compare'],'bots'=>$window['bots'],'messages_mode'=>'all'];
if(isset($input['report']))$reportQuery['report']=$input['report'];
$reportListParams=$reportQuery;
$h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
echo '<!doctype html><html lang="en">';
renderPageHead('MessageMonkey date preference fixture',['styles'=>['assets/css/pages/audience.min.css','assets/css/pages/audience-analytics.min.css'],'scripts'=>['assets/js/audience-analytics.min.js']]);
echo '<body class="audience-app"><main><nav aria-label="Fixture navigation"><a href="audience.php?view=home">Home</a> ';
foreach(['email','audience','conversions','reports','custom','activity','links','devices','locations','insights']as$item){
    echo '<a href="audience.php?view=analytics&amp;section='.$item.'">'.$item.'</a> ';
}
echo '</nav>';
include __DIR__.'/../../src/templates/audience/analytics-filters.php';
if($view==='home')include __DIR__.'/../../src/templates/audience/analytics-range-summary.php';
echo '</main></body></html>';
