<?php
declare(strict_types=1);
require_once __DIR__.'/../src/user_time_helpers.php';
$checks=0;
$expect=static function(bool $condition,string $why)use(&$checks):void{if(!$condition)throw new RuntimeException($why);++$checks;};
$original=date_default_timezone_get();date_default_timezone_set('Pacific/Honolulu');
try{
    $_SESSION=[];
    $expect(userDisplayTimezoneName()==='UTC','No preference means UTC, regardless of server timezone');
    $expect(formatUserTimestamp('2026-10-26 12:00:00','Y-m-d H:i:s')==='2026-10-26 12:00:00 UTC','Default timestamps are explicitly UTC');
    foreach(['America/Chicago','Asia/Kolkata','Pacific/Kiritimati','UTC']as$zone)$expect(validateUserTimezone($zone)===$zone,'Canonical timezone accepted: '.$zone);
    $expect(validateUserTimezone('')===null&&validateUserTimezone(null)===null,'Clearing the selection restores the default');
    foreach([[],false,'America/NotReal','../etc/passwd','+99:00',"UTC\n"]as$bad){
        try{validateUserTimezone($bad);throw new RuntimeException('Invalid timezone accepted');}catch(InvalidArgumentException){++$checks;}
    }
    $_SESSION['display_timezone']='America/Chicago';
    $expect(formatUserTimestamp('2026-10-26 12:00:00','Y-m-d H:i')==='2026-10-26 07:00 CDT','Selected timezone converts UTC instants');
    $expect(formatUserTimestamp('2026-03-08 07:59:59','H:i:s')==='01:59:59 CST'&&formatUserTimestamp('2026-03-08 08:00:00','H:i:s')==='03:00:00 CDT','Spring DST gap uses correct offsets');
    $expect(formatUserTimestamp('2026-11-01 06:30:00','H:i')==='01:30 CDT'&&formatUserTimestamp('2026-11-01 07:30:00','H:i')==='01:30 CST','Repeated autumn hours remain distinguishable');
    $expect(formatUserTimestamp('2026-10-26T07:00:00-05:00','H:i T')==='07:00 CDT','Explicit offsets are respected and timezone labels are not duplicated');
    $_SESSION['display_timezone']='Asia/Kolkata';
    $expect(formatUserTimestamp('2026-10-26 12:00:00','H:i')==='17:30 IST','Fractional offsets are supported');
    $_SESSION['display_timezone']='Pacific/Kiritimati';
    $expect(userDisplayInstant('2026-12-31 12:00:00')->format('Y-m-d H:i')==='2027-01-01 02:00','Timezone conversion crosses day and year boundaries');
    $expect(formatUserTimestamp('2026-10-26')==='2026-10-26','Date-only business values do not shift');
    $expect(userDisplayInstant('2026-02-30 12:00:00')===null&&userDisplayInstant('not a date')===null,'Invalid timestamps cannot silently roll to a different date');
    $_SESSION['display_timezone']='Invalid/Timezone';
    $expect(userDisplayTimezoneName()==='UTC','Invalid stored preferences safely fall back to UTC');
}finally{date_default_timezone_set($original);unset($_SESSION['display_timezone']);}
echo "User time helpers: $checks checks passed.\n";
