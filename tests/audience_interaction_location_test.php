<?php
declare(strict_types=1);

require_once __DIR__.'/../src/functions.php';
require_once __DIR__.'/../src/audience_store.php';

$checks=0;
$expect=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);++$checks;};
$oldProxy=getenv('DNR_TRUSTED_PROXY_IPS');
$oldCloudflare=getenv('DNR_TRUSTED_CLOUDFLARE_PROXY_IPS');
putenv('DNR_TRUSTED_PROXY_IPS=10.0.0.1');
putenv('DNR_TRUSTED_CLOUDFLARE_PROXY_IPS=10.1.0.0/24');
try {
    $edge=['REMOTE_ADDR'=>'10.0.0.1','HTTP_X_FORWARDED_FOR'=>'8.8.8.8, 10.1.0.2',
        'HTTP_CF_CONNECTING_IP'=>'8.8.8.8','HTTP_CF_RAY'=>'0123456789abcdef-ORD','HTTP_CF_IPCOUNTRY'=>'US'];
    $city=audienceInteractionLocation($edge,static fn()=>[
        'country'=>['iso_code'=>'US'],
        'subdivisions'=>[['names'=>['en'=>'Illinois']]],
        'city'=>['names'=>['en'=>'Chicago']],
    ],'fixture-city.mmdb');
    $expect($city['ip_address']==='8.8.8.8'&&$city['country_code']==='US'&&$city['region']==='Illinois'&&$city['city']==='Chicago','Trusted edge address uses the local City lookup');
    $expect(audienceInteractionPlace($city)==='Chicago, Illinois, United States of America','Recorded location has a readable label');
    $country=audienceInteractionLocation($edge,null,'');
    $expect($country['country_code']==='US'&&$country['source']==='trusted edge country'&&!isset($country['city']),'Trusted edge country is a fallback, not invented city data');
    $direct=audienceInteractionLocation(array_replace($edge,['REMOTE_ADDR'=>'8.8.8.8','HTTP_X_FORWARDED_FOR'=>'1.1.1.1']),null,'');
    $expect($direct['ip_address']==='8.8.8.8'&&!isset($direct['country_code']),'Untrusted forwarding and country headers cannot supply location');
    $intervening=audienceInteractionLocation(array_replace($edge,['HTTP_X_FORWARDED_FOR'=>'10.1.0.2, 8.8.8.8']),null,'');
    $expect($intervening['ip_address']==='8.8.8.8'&&!isset($intervening['country_code']),'An untrusted intervening hop rejects edge country attribution');
    $local=audienceInteractionLocation(['REMOTE_ADDR'=>'127.0.0.1'],static function(){throw new RuntimeException('Private IP was looked up');},'fixture-city.mmdb');
    $expect($local===['ip_address'=>'127.0.0.1'],'Private addresses are recorded without a false geolocation');
    $expect(audienceInteractionLocation(['REMOTE_ADDR'=>'invalid'])===[],'Invalid network addresses are not recorded');
    $expect(audienceInteractionGeoRecord(['country'=>['iso_code'=>'ZZ'],'city'=>['names'=>['en'=>'Nowhere']]])===[],'Unknown countries cannot carry city data');
    $expect(audienceInteractionGeoRecord(['country'=>['iso_code'=>'CA'],'city'=>['names'=>['en'=>"Tor\nonto"]]])['city']==='Tor onto','City labels cannot contain controls');
} finally {
    $oldProxy===false?putenv('DNR_TRUSTED_PROXY_IPS'):putenv('DNR_TRUSTED_PROXY_IPS='.$oldProxy);
    $oldCloudflare===false?putenv('DNR_TRUSTED_CLOUDFLARE_PROXY_IPS'):putenv('DNR_TRUSTED_CLOUDFLARE_PROXY_IPS='.$oldCloudflare);
}
echo "Audience interaction location: {$checks} checks passed.\n";
