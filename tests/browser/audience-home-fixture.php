<?php
declare(strict_types=1);
// Render the real Home templates with fictional aggregate data and no database.
require __DIR__.'/../../src/functions.php';
require __DIR__.'/../../src/audience_store.php';

$_SESSION=['user_id'=>11,'username'=>'Fixture User','role'=>'admin','_csrf_token'=>'fixture-token'];
$h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$url=static fn(array $params=[])=>'audience.php?'.http_build_query($params);
$view='home';$section='email';$pageTitle='Home';$displayName='Fixture User';$actor=11;$audienceId=1;
$themeKey='home-paint-fixture';$canManage=$canDelete=true;$message=$error='';$csrf=csrfInput();
$catalog=[];$rules=['match'=>'all','rules'=>[]];$filters=audienceListFilters([]);$segment=$job=null;
$window=audienceAnalyticsWindow([]);$presets=audienceAnalyticsPresets();$rangeKey='30';$showDrafts=false;
$store=new class {
    use AudienceAnalytics;
    public function analyticsAudienceHistory(array $w,string $source='',bool $bySource=true):array
    {
        return ['current'=>[['day'=>$w['start'],'source'=>'','added'=>15,'subscriptions'=>10,'optouts'=>2,'net'=>8]],'previous'=>[]];
    }
};
$totals=$store->analyticsTotals([['accepted'=>100,'delivered'=>98,'receipt'=>100,'failed'=>2,'opens'=>45,'clicks'=>12]]);
$overview=['totals'=>$totals,'previous'=>$store->analyticsTotals([]),'includes_samples'=>false,
    'audience'=>['visible_total'=>10000,'subscribed'=>7500,'added'=>15],
    'campaigns'=>[['id'=>1,'name'=>'Community Update','status'=>'sent','metrics'=>$totals]]];

echo '<!doctype html><html lang="en">';
renderPageHead('Home paint fixture',['styles'=>['assets/css/pages/audience.min.css','assets/css/pages/audience-analytics.min.css'],
    'scripts'=>['assets/js/'.(($argv[1]??'')==='legacy'?'audience':'audience-home').'.min.js','assets/js/audience-analytics.min.js']]);
require __DIR__.'/../../src/templates/audience/workspace.php';
echo '</html>';
