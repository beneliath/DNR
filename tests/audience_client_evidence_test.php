<?php
declare(strict_types=1);
require_once __DIR__.'/../src/short_link_helpers.php';
require_once __DIR__.'/../src/audience_analytics_reports.php';
require_once __DIR__.'/../src/audience_pagination.php';

$checks=0;
$expect=static function(bool $ok,string $message)use(&$checks):void{
    if(!$ok)throw new RuntimeException($message);
    $checks++;
};
$iphone='Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) ';
$android='Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) ';
$windows='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) ';
$chrome='Chrome/128.0.0.0 Safari/537.36';
foreach([
    [$iphone.'Version/18.0 Mobile/15E148 Safari/604.1','Mobile Safari','iOS','Smartphone'],
    [$iphone.'CriOS/128.0.6613.98 Mobile/15E148 Safari/604.1','Chrome Mobile iOS','iOS','Smartphone'],
    [$iphone.'FxiOS/130.0 Mobile/15E148 Safari/605.1.15','Firefox Mobile iOS','iOS','Smartphone'],
    [$android.'Chrome/128.0.0.0 Mobile Safari/537.36','Chrome Mobile','Android','Smartphone'],
    [$android.'SamsungBrowser/26.0 Chrome/128.0.0.0 Mobile Safari/537.36','Samsung Browser','Android','Smartphone'],
    [$android.'Chrome/128.0.0.0 Mobile Safari/537.36 DuckDuckGo/5.190.0','DuckDuckGo Privacy Browser','Android','Smartphone'],
    [$android.'Chrome/128.0.0.0 Mobile Safari/537.36 Brave/1.69.0','Brave','Android','Smartphone'],
    [$windows.$chrome,'Chrome','Windows','Desktop'],
    [$windows.$chrome.' Edg/128.0.2739.79','Microsoft Edge','Windows','Desktop'],
    [$windows.$chrome.' OPR/114.0.0.0','Opera','Windows','Desktop'],
    ['Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0','Firefox','GNU/Linux','Desktop'],
    ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15','Safari','Mac','Desktop'],
    ['Mozilla/5.0 (X11; CrOS x86_64 15964.0.0) AppleWebKit/537.36 (KHTML, like Gecko) '.$chrome,'Chrome','Chrome OS','Desktop'],
    ['Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1','Mobile Safari','iPadOS','Tablet'],
    ['Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) '.$chrome,'Chrome','Android','Tablet'],
    ['Mail/16.0 (Macintosh; Intel Mac OS X 10_15_7)','Apple Mail','Mac','Desktop'],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Thunderbird/128.0','Thunderbird','Windows','Desktop'],
    ['Outlook-iOS/2.0','Microsoft Outlook','iOS','Unknown'],
]as[$agent,$client,$os,$device]){
    $actual=audienceAnalyticsClientEvidence($agent,'US');
    $expect($actual===['device'=>$device,'client'=>$client,'os'=>$os,'country'=>'US'],"Expected $client/$os/$device; got ".json_encode($actual));
    $speaker=shortLinkVisitDimensions(['HTTP_USER_AGENT'=>$agent]);
    $expect($speaker!==null&&$speaker['browser']===$actual['client']&&$speaker['os']===$actual['os'],'MessageMonkey and Speaker Engagement must agree on browser and OS names');
}
foreach(['','Mozilla/5.0','unidentified-client/1.0',str_repeat(' ',2048).$windows.$chrome]as$agent){
    $expect(audienceAnalyticsClientEvidence($agent,null)===['device'=>'Unknown','client'=>'Unknown','os'=>'Unknown','country'=>'Unknown'],'Missing, unknown and oversized agents cannot invent a desktop or expose the detector sentinel');
}
foreach([
    [$windows.$chrome.' (via ggpht.com GoogleImageProxy)','Gmail image proxy'],
    [$windows.$chrome.' YahooMailProxy','Yahoo Mail image proxy'],
    [$windows.$chrome.' ImageProxy/1.0','Image proxy'],
]as[$agent,$client]){
    $actual=audienceAnalyticsClientEvidence($agent,'US');
    $expect($actual['client']===$client&&$actual['device']==='Unknown'&&$actual['os']==='Unknown','Proxy identifiers override misleading browser/OS tokens');
}
foreach(['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',$windows.'HeadlessChrome/128.0.0.0 Safari/537.36','curl/8.0.0']as$agent){
    $actual=audienceAnalyticsClientEvidence($agent,null);
    $expect($actual['device']==='Unknown'&&$actual['os']==='Unknown','Automated fetchers must not supply recipient device or OS evidence');
}
$actual=audienceAnalyticsClientEvidence($iphone.'Version/18.0 Mobile/15E148 Safari/604.1',null,'https://user:secret@example.test/news?email=private@example.test#token');
$expect($actual===['device'=>'Smartphone','client'=>'Mobile Safari','os'=>'iOS','country'=>'Unknown','link_label'=>'example.test/news'],'Stored evidence contains only classification names and a destination without credentials, queries or fragments');

$section='devices';$h=static fn($value):string=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$reportInput=['client_page'=>2,'client_size'=>10,'os_page'=>999,'os_size'=>50];
$reportLink=static fn(array $changes=[]):string=>'audience.php?'.http_build_query(array_replace(['view'=>'analytics','section'=>'devices','bots'=>'all','start'=>'2026-11-01','end'=>'2026-11-04','messages'=>[1,2]],$changes));
$evidence=['country'=>[],'client'=>[['label'=>'Chrome Mobile iOS','messages'=>1,'observations'=>2]],'os'=>[['label'=>'iOS','messages'=>1,'observations'=>2],['label'=>'Unknown','messages'=>3,'observations'=>4]],'device'=>[['label'=>'Smartphone','messages'=>1,'observations'=>2]]];
ob_start();include __DIR__.'/../src/templates/audience/analytics-reports.php';$html=ob_get_clean();
$expect(str_contains($html,'<h2>Operating Systems</h2>')&&str_contains($html,'<th scope="row">iOS</th>')&&str_contains($html,'Chrome Mobile iOS'),'Locations & Clients renders browser, OS and device evidence');
$expect(str_contains($html,'details that were not recorded remain Unknown'),'The report explains historical classification limits');
for($i=1;$i<=27;$i++)$evidence['client'][]=['label'=>'Client '.$i,'messages'=>$i,'observations'=>$i];
ob_start();include __DIR__.'/../src/templates/audience/analytics-reports.php';$html=ob_get_clean();
$doc=new DOMDocument();@$doc->loadHTML('<?xml encoding="UTF-8">'.$html);$xpath=new DOMXPath($doc);
$expect($xpath->query('//section[h2="Email and Browsing"]//tbody/tr')->length===10,'Browser evidence uses the selected page size');
$expect($xpath->query('//section[h2="Operating Systems"]//tbody/tr')->length===2,'Each evidence table clamps its page independently');
$expect($xpath->query('//section[h2="Locations"]//button[@disabled]')->length===2,'Empty evidence has disabled previous and next controls');
foreach($xpath->query('//section[h2="Email and Browsing"]//nav//a')as$link){
    parse_str((string)parse_url($link->getAttribute('href'),PHP_URL_QUERY),$params);
    $expect($params['os_size']==='50'&&$params['client_size']==='10'&&$params['bots']==='all'&&$params['messages']===['1','2'],'Evidence pagination preserves filters and other table choices');
}
$expect($xpath->query('//input[@name="client_page" and @value="1"]')->length>0,'Changing an evidence page size resets its page');
echo "Audience client detection passed ($checks checks).\n";
