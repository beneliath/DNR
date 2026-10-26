<?php
declare(strict_types=1);
require_once __DIR__.'/../src/audience_store.php';
$checks=0;
$expect=static function(bool $condition,string $message)use(&$checks):void{if(!$condition)throw new RuntimeException($message);++$checks;};
foreach(['2026-02-30 12:00:00','2026-10-26 25:61:00','2025-02-29 12:00:00','0000-01-01 00:00:00','2026-00-01 12:00:00','invalid']as$value)
    $expect(audienceExportTimestamp($value)==='','Invalid source timestamp is omitted: '.$value);
$expect(audienceExportTimestamp('2024-02-29 12:34:56.123456')==='2024-02-29 12:34:56','Real leap days and native fractional seconds remain supported');
$params=['export_token'=>'0123456789','fields'=>[
    ['id'=>1,'label'=>'Address','field_type'=>'address'],['id'=>2,'label'=>'Phone Number','field_type'=>'phone'],
    ['id'=>3,'label'=>'Full Name','field_type'=>'text'],['id'=>4,'label'=>'Date of Entry','field_type'=>'date'],['id'=>5,'label'=>'Fund','field_type'=>'text'],
],'export_groups'=>[
    ['name'=>'Antisemitism News Alerts','options'=>[['id'=>1,'name'=>'News Alerts']]],
    ['name'=>'1 Minute Daily Devotional','options'=>[['id'=>2,'name'=>'Daily Devotional']]],
    ['name'=>'Self-Subscribed Content','options'=>[['id'=>3,'name'=>'Prayer Letter'],['id'=>4,'name'=>'SMS messages']]],
],'tag_names'=>[1=>'First tag',2=>'Quotes "and", commas',3=>'Unicode שלום']];
$base=['email'=>'export@example.test','first_name'=>'Avery','last_name'=>'Rivéra','email_status'=>'subscribed',
    'source'=>'Import','timezone'=>'America/Chicago','created_at'=>'2026-10-01 12:00:00.000000','updated_at'=>'2026-10-01 12:00:00.000000',
    'custom'=>[1=>"10 Example Lane\n\nExample City\nIL\n60000\nUS",2=>'555-0100',3=>'=HYPERLINK("bad")',4=>'2024-01-02',5=>'General'],
    'tag_ids'=>[1,2,3],'group_ids'=>[1,3,4],'notes'=>'',
    'export_source_event'=>['id'=>7,'created_at'=>'2026-10-01 12:00:01.000000','metadata'=>[
        'MEMBER_RATING'=>'4','OPTIN_TIME'=>'2024-01-02 03:04:05','CONFIRM_TIME'=>'2024-01-03 03:04:05',
        'OPTIN_IP'=>'198.51.100.1','GMTOFF'=>'-6','DSTOFF'=>'-5','CC'=>'US','REGION'=>'IL','LAST_CHANGED'=>'2025-01-02 03:04:05',
        'LEID'=>'123456789','EUID'=>'abcdef1234','NOTES'=>'Original note','SOURCE'=>'Admin Add',
        'UNSUB_TIME'=>'2025-02-03 03:04:05','UNSUB_CAMPAIGN_TITLE'=>'Original campaign','UNSUB_CAMPAIGN_ID'=>'campaign123',
        'UNSUB_REASON'=>'No longer interested','UNSUB_REASON_OTHER'=>'','CLEAN_TIME'=>'2025-03-04 03:04:05',
        'CLEAN_CAMPAIGN_TITLE'=>'Bounced campaign','CLEAN_CAMPAIGN_ID'=>'campaign456',
    ]],
    'export_notes'=>[['id'=>2,'body'=>"Newer note\nSecond line"],['id'=>1,'body'=>'Earlier note']],
];
$common=['Email Address','First Name','Last Name','Address','Phone Number','Full Name','Date of Entry','Fund',
    'Antisemitism News Alerts','1 Minute Daily Devotional','Self-Subscribed Content','MEMBER_RATING','OPTIN_TIME',
    'OPTIN_IP','CONFIRM_TIME','CONFIRM_IP','GMTOFF','DSTOFF','TIMEZONE','CC','REGION'];
$ending=['LEID','EUID','NOTES','TAGS','SOURCE'];
$records=[];
foreach(['subscribed','non-subscribed','unsubscribed','cleaned']as$status){
    $columns=match($status){'unsubscribed'=>['UNSUB_TIME','UNSUB_CAMPAIGN_TITLE','UNSUB_CAMPAIGN_ID','UNSUB_REASON','UNSUB_REASON_OTHER'],'cleaned'=>['CLEAN_TIME','CLEAN_CAMPAIGN_TITLE','CLEAN_CAMPAIGN_ID'],default=>['LAST_CHANGED']};
    $expect(audienceExportHeaders($params,$status)===[...$common,...$columns,...$ending],'Exact Mailchimp column order for '.$status);
    $records[]=audienceExportRecord(array_replace($base,['email_status'=>$status]),$params);
}
$readRecord=static fn($r)=>array_combine(audienceExportHeaders($params,$r['status']),$r['row']);
$subscribed=$readRecord($records[0]);
$expect($subscribed['Address']==='10 Example Lane    Example City  IL  60000  US','Mailchimp address component spacing');
$expect($subscribed['Self-Subscribed Content']==='Prayer Letter, SMS messages'&&$subscribed['1 Minute Daily Devotional']==='','Each category gets its own selected-options column');
$expect($subscribed['TAGS']==='"First tag","Quotes ""and"", commas","Unicode שלום"','Tags use quoted comma-separated names');
$expect($subscribed['LEID']==='123456789'&&$subscribed['OPTIN_TIME']==='2024-01-02 03:04:05'&&$subscribed['LAST_CHANGED']==='2025-01-02 03:04:05','Captured import IDs and original dates are retained');
$expect($subscribed['NOTES']==="Original note\nEarlier note\nNewer note\nSecond line",'Native notes follow imported notes in creation order');
$native=array_replace($base,['export_source_event'=>null,'export_notes'=>[],'email_status'=>'non-subscribed']);
$nativeRow=$readRecord(audienceExportRecord($native,$params));
$expect($nativeRow['LEID']===''&&$nativeRow['MEMBER_RATING']===''&&$nativeRow['OPTIN_TIME']===''&&$nativeRow['CONFIRM_TIME']==='','No invented provider IDs, ratings or consent dates');
$expect($nativeRow['LAST_CHANGED']==='2026-10-01 12:00:00','Native timestamps use second precision');
$later=array_replace($base,['email_status'=>'unsubscribed','export_permission_event'=>['id'=>8,'created_at'=>'2026-10-02 12:01:02.999999','details'=>['to'=>'unsubscribed']]]);
$laterRow=$readRecord(audienceExportRecord($later,$params));
$expect($laterRow['UNSUB_TIME']==='2026-10-02 12:01:02'&&$laterRow['UNSUB_CAMPAIGN_ID']===''&&$laterRow['UNSUB_REASON']==='','A later native opt-out replaces stale imported opt-out evidence');
$renewed=array_replace($base,['export_permission_event'=>['id'=>8,'event_type'=>'subscription_changed','created_at'=>'2026-10-02 12:01:02','details'=>['to'=>'subscribed','consent_note'=>'New signed permission']]]);
$renewedRow=$readRecord(audienceExportRecord($renewed,$params));
$expect($renewedRow['OPTIN_TIME']==='2026-10-02 12:01:02'&&$renewedRow['OPTIN_IP']===''&&$renewedRow['CONFIRM_TIME']===''&&$renewedRow['CONFIRM_IP']==='','New manual permission does not inherit old provider IP or confirmation evidence');
$archivePath='';
audienceWithExportArchive($params,(static function()use($records):Generator{foreach($records as$r)yield $r;})(),static function($path,$name)use($expect,$params,$records,$common,$ending,&$archivePath):void{
    $archivePath=$path;$expect($name==='messagemonkey_audience_export_0123456789.zip','Shared identifier in the ZIP filename');
    $zip=new ZipArchive();$expect($zip->open($path)===true&&$zip->numFiles===4,'Four status CSV files in the archive');
    foreach($records as$record){
        $status=$record['status'];$prefix=$status==='non-subscribed'?'nonsubscribed':$status;
        $entry=$prefix.'_email_audience_export_0123456789.csv';$csv=$zip->getFromName($entry);
        $expect(is_string($csv)&&str_ends_with($csv,"\r\n")&&!str_starts_with($csv,"\xef\xbb\xbf"),'CSV uses CRLF and UTF-8 without a BOM: '.$status);
        $stream=fopen('php://temp','w+b');fwrite($stream,$csv);rewind($stream);
        $header=fgetcsv($stream,null,',','"','');$row=fgetcsv($stream,null,',','"','');$extra=fgetcsv($stream,null,',','"','');fclose($stream);
        $expect($header===audienceExportHeaders($params,$status)&&count($row)===count($header)&&$extra===false,'CSV headers, width and status partition: '.$status);
        $mapped=array_combine($header,$row);
        $expect($mapped['Full Name']==='\'=HYPERLINK("bad")'&&$mapped['GMTOFF']==='-6'&&$mapped['DSTOFF']==='-5','Spreadsheet protection retains numeric time-zone offsets');
        $expect($mapped['Last Name']==='Rivéra'&&str_contains($mapped['TAGS'],'שלום')&&str_contains($mapped['NOTES'],"\n"),'Unicode, quotes and embedded newlines survive CSV encoding');
        $expect($zip->statName($entry)['comp_method']===ZipArchive::CM_DEFLATE,'CSV is compressed using DEFLATE');
    }
    $zip->close();
});
$expect(!is_file($archivePath),'Temporary ZIP is removed after download');
audienceWithExportArchive($params,[],static function($path)use($expect,$params):void{
    $zip=new ZipArchive();$zip->open($path);$expect($zip->numFiles===4,'Empty selections still produce the four status files');
    $csv=$zip->getFromIndex(0);$expect(substr_count($csv,"\r\n")===1,'Empty status files contain the matching header');$zip->close();
});
audienceWithExportArchive($params,[audienceExportRecord(array_replace($native,['email_status'=>'pending']),$params)],static function($path)use($expect):void{
    $zip=new ZipArchive();$zip->open($path);$expect($zip->numFiles===5&&$zip->locateName('pending_email_audience_export_0123456789.csv')!==false,'Pending contacts remain pending in an additional CSV');$zip->close();
});
try{audienceWithExportArchive($params,[],static function($path)use(&$archivePath):void{$archivePath=$path;throw new RuntimeException('Synthetic interrupted download');});}catch(RuntimeException $e){$expect($e->getMessage()==='Synthetic interrupted download'&&!is_file($archivePath),'Interrupted downloads clean up temporary files');}
echo "Mailchimp audience archive: $checks checks passed.\n";
