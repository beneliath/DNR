<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$options=getopt('',['file:','timezone:','bot-filter:','source-id:','account:','actor:','apply','parse-only','exclude-daily-devotionals']);
$source=getenv('DNR_TEST_SOURCE_DIR')?:'/var/www/html';
require_once $source.'/audience_helpers.php';require_once $source.'/audience_analytics.php';require_once $source.'/audience_mailchimp_history.php';
try{
    $file=$options['file']??'';if(!is_string($file)||!is_file($file)||is_link($file)||filesize($file)>512*1024*1024)throw new InvalidArgumentException('Choose a regular Mailchimp reports CSV or ZIP under 512 MB.');
    $zone=$options['timezone']??'';$filter=$options['bot-filter']??'';$reports=[];$skipped=0;$bytes=0;
    $consume=static function(string $contents,string $name)use(&$reports,&$skipped,$zone,$filter,$options):void{
        $header=str_getcsv(explode("\n",preg_replace('/^\xEF\xBB\xBF/','',$contents),2)[0],',','"','');
        if(in_array('Unique Id',$header,true)&&in_array('Send Date',$header,true)){array_push($reports,...audienceMailchimpCampaigns($contents,$zone,$filter));return;}
        if($header!==['Email Campaign Report']){$skipped++;return;}
        try{$reports[]=audienceMailchimpReport($contents,$zone,$filter,$options['source-id']??'');}
        catch(InvalidArgumentException $e){throw new InvalidArgumentException('Invalid report in '.mb_substr(basename($name),0,100).': '.$e->getMessage());}
    };
    if(strtolower(pathinfo($file,PATHINFO_EXTENSION))==='zip'){
        if(!class_exists('ZipArchive'))throw new RuntimeException('ZIP support is required for a report bundle.');
        if(isset($options['source-id']))throw new InvalidArgumentException('A bundle must not share one vendor campaign identifier.');
        $zip=new ZipArchive();if($zip->open($file)!==true||$zip->numFiles>50000)throw new InvalidArgumentException('Unsupported or oversized report archive.');
        for($i=0;$i<$zip->numFiles;$i++){
            $entry=$zip->statIndex($i);if(!$entry||strtolower(pathinfo($entry['name'],PATHINFO_EXTENSION))!=='csv')continue;
            $stream=$zip->getStream($entry['name']);if(!$stream)throw new InvalidArgumentException('Unreadable archive entry.');$prefix=fread($stream,256);fclose($stream);
            if(!str_contains($prefix,'Email Campaign Report')&&!str_contains($prefix,'Send Date')){$skipped++;continue;}
            $bytes+=$entry['size'];if($entry['size']>16*1024*1024||$bytes>100*1024*1024||count($reports)>=20000)throw new InvalidArgumentException('Report archive exceeds safe import limits.');
            $contents=$zip->getFromIndex($i,16*1024*1024+1);if($contents===false||strlen($contents)!==$entry['size'])throw new InvalidArgumentException('A report archive entry is incomplete.');
            $consume($contents,$entry['name']);
        }$zip->close();
    }else{if(filesize($file)>16*1024*1024)throw new InvalidArgumentException('Campaign summary CSV exceeds 16 MB.');$consume(file_get_contents($file),basename($file));}
    if(!$reports)throw new InvalidArgumentException('No supported campaign summary reports were found. No data changed.');
    $parsed=count($reports);if(isset($options['exclude-daily-devotionals']))$reports=array_values(array_filter($reports,static fn($report)=>!audienceMailchimpDailyDevotional($report)));
    if(!$reports)throw new InvalidArgumentException('No campaigns remain in the requested import scope. No data changed.');
    $hash=hash_file('sha256',$file);$dates=array_column($reports,'sent_at');
    $summary=['reports'=>count($reports),'source_reports'=>$parsed,'excluded_daily_devotionals'=>$parsed-count($reports),'skipped_non_summary_csv'=>$skipped,'first_sent_utc'=>min($dates),'last_sent_utc'=>max($dates),'source_timezone'=>$zone,'source_audiences'=>array_values(array_unique(array_map(static fn($r)=>$r['provenance']['source_audience']??'Not included in CSV',$reports))),'bot_filter'=>$filter,'bundle_sha256'=>$hash,'metrics'=>audienceMailchimpTotals($reports)];
    if(isset($options['parse-only'])){if(isset($options['apply']))throw new InvalidArgumentException('Parse-only cannot apply an import.');echo json_encode($summary,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";exit;}
    require_once $source.'/bootstrap.php';require_once $source.'/audience_store.php';
    if(($options['account']??'')!==currentAccountKey())throw new RuntimeException('Expected account does not match this deployment. No data changed.');
    $actor=filter_var($options['actor']??'',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$db=applicationDatabaseConnection();
    $user=$actor?$db->execute_query("SELECT id FROM users WHERE id=? AND role='admin' AND account_status='active'",[$actor])->fetch_assoc():null;
    if(!$user)throw new RuntimeException('Specify an active administrator actor. No data changed.');
    $store=new AudienceStore($db,1,$actor,'Mailchimp history import');
    $result=$store->importMailchimpHistory($reports,$hash,basename($file),isset($options['apply']));
    echo json_encode(['account'=>currentAccountKey(),'audience_id'=>1]+$result+$summary,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}
