<?php
declare(strict_types=1);
require_once __DIR__.'/../src/functions.php';
require_once __DIR__.'/../src/audience_helpers.php';
require_once __DIR__.'/../src/audience_analytics.php';

$checks=0;
$expect=static function(bool $ok,string $message)use(&$checks):void{
    if(!$ok)throw new RuntimeException($message);
    $checks++;
};
$now=new DateTimeImmutable('2026-11-15T12:00:00Z');
$remember=static fn(array $input,string $view='analytics',string $section='email',int $user=31,?DateTimeImmutable $at=null):array=>audienceRememberAnalyticsRange($input,$view,$section,$user,$at??$now);
$sections=['email','audience','conversions','reports','custom','activity','links','devices','insights','locations'];
$account=getenv('DNR_ACCOUNT_KEY');
try{
    putenv('DNR_ACCOUNT_KEY=range-preference-one');$_COOKIE=[];
    $expect($remember([],'home')===[]&&$_COOKIE===[],'Visiting Home does not save its default');
    foreach($sections as$section)$expect($remember([],'analytics',$section)===[]&&$_COOKIE===[],'An unchosen Analytics default is not saved: '.$section);
    $request=['range'=>'7','start'=>'2026-11-09','end'=>'2026-11-15','date_range_change'=>'1'];
    $applied=$remember($request,'home');
    $expect(!isset($applied['date_range_change']),'The submission marker is not carried into other controls');
    $homeCookie=array_key_first($_COOKIE);
    $expect(json_decode($_COOKIE[$homeCookie],true)===['range'=>'7'],'A relative selection stores its preset instead of fixed dates');
    $expect($remember([],'home')===['range'=>'7'],'Home restores its selected preset');
    $tomorrow=$now->modify('+1 day');$restored=$remember([],'home','email',31,$tomorrow);
    $window=audienceAnalyticsWindow(audienceAnalyticsPresets($tomorrow)[$restored['range']],$tomorrow);
    $expect($window['start']==='2026-11-10'&&$window['end']==='2026-11-16','A remembered rolling preset advances on the next visit');

    $choices=[];
    foreach($sections as$index=>$section){
        $choices[$section]=['range'=>'custom','start'=>sprintf('2026-10-%02d',$index+1),'end'=>'2026-10-31'];
        $remember($choices[$section]+['date_range_change'=>'1'],'analytics',$section);
    }
    foreach($sections as$section){
        $expect($remember([],'analytics',$section)===$choices[$section],'Each Analytics view restores its own exact custom dates: '.$section);
        $filters=['compare'=>'none','messages'=>['7'],'page'=>2];
        $expect($remember($filters,'analytics',$section)===array_replace($filters,$choices[$section]),'Restoring dates retains unrelated filters: '.$section);
    }
    $expect($remember([],'home')===['range'=>'7'],'Analytics selections do not change Home');
    $explicit=['range'=>'30','start'=>'2026-10-17','end'=>'2026-11-15','download'=>'csv'];
    $expect($remember($explicit)===$explicit&&$remember([])===$choices['email'],'Explicit bookmarks and exports override this visit without replacing the preference');
    $legacy=['start'=>'2026-09-01','end'=>'2026-09-30'];
    $expect($remember($legacy)===$legacy,'Legacy URLs with explicit dates take precedence');
    $remember(['range'=>'month','date_range_change'=>'1']);
    $expect($remember([])===['range'=>'month'],'Applying another preset replaces the last custom selection');
    $december=new DateTimeImmutable('2026-12-03T12:00:00Z');$restored=$remember([],'analytics','email',31,$december);
    $expect(audienceAnalyticsPresets($december)[$restored['range']]['start']==='2026-11-01','Last Month advances across a month boundary');

    $report=['report'=>'saved-report-one'];
    $expect($remember($report,'analytics','custom')===$report,'A saved report starts with its own definition, not the generic builder preference');
    $remember($report+['range'=>'365','date_range_change'=>'1'],'analytics','custom');
    $expect($remember($report,'analytics','custom')===$report+['range'=>'365'],'The selected range is restored for the same saved report');
    $otherReport=['report'=>'saved-report-two'];
    $expect($remember($otherReport,'analytics','custom')===$otherReport,'Another saved report retains its own default');
    $expect($remember([],'analytics','custom')===$choices['custom'],'A saved report does not replace the generic report builder preference');
    $expect($remember(['report'=>''],'analytics','custom')===['report'=>'']+$choices['custom'],'An empty report key uses the generic builder preference');
    $expect($remember(['report'=>' saved-report-one '],'analytics','custom')===['report'=>' saved-report-one ','range'=>'365'],'The report preference uses the same normalized key as report access');

    $expect($remember([],'home','email',32)===[]&&$remember([],'analytics','email',32)===[],'Users do not inherit each other’s date ranges');
    putenv('DNR_ACCOUNT_KEY=range-preference-two');
    $expect($remember([],'home')===[]&&$remember([])===[],'Accounts do not inherit each other’s date ranges');
    $remember(['range'=>'90','date_range_change'=>'1'],'home');
    putenv('DNR_ACCOUNT_KEY=range-preference-one');
    $expect($remember([],'home')===['range'=>'7'],'Returning to the first account restores its own selection');
    $before=$_COOKIE;
    foreach([
        ['range'=>[]],['range'=>'unsupported'],['range'=>'custom'],
        ['range'=>'custom','start'=>[],'end'=>'2026-11-01'],
        ['range'=>'custom','start'=>'','end'=>'2026-11-01'],
        ['range'=>'custom','start'=>'2026-02-30','end'=>'2026-11-01'],
        ['range'=>'custom','start'=>'2026-11-02','end'=>'2026-11-01'],
        ['range'=>'custom','start'=>'2026-11-01','end'=>'2099-01-01'],
        ['range'=>'custom','start'=>'2000-01-01','end'=>'2026-11-01'],
        ['range'=>'30','bots'=>'unsupported'],
    ]as$invalid){
        $remember($invalid+['date_range_change'=>'1']);
        $expect($_COOKIE===$before&&$remember([])===['range'=>'month'],'Rejected selections do not erase the remembered range');
    }
    foreach([[], 'broken JSON', 'null', '42', '[]', '{"range":[]}', '{"range":"unsupported"}', '{"range":"custom","start":"2026-02-30","end":"2026-03-01"}']as$invalid){
        $_COOKIE[$homeCookie]=$invalid;
        $expect($remember([],'home')===[],'Malformed stored ranges fall back to the page default');
    }
    $_COOKIE=[];
    $remember(['range'=>'7','date_range_change'=>'1'],'home','email',0);
    $expect($_COOKIE===[],'Anonymous requests cannot save preferences');
}finally{
    putenv($account===false?'DNR_ACCOUNT_KEY':'DNR_ACCOUNT_KEY='.$account);
}
echo "MessageMonkey date range preferences: $checks checks passed.\n";
