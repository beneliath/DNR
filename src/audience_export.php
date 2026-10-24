<?php
declare(strict_types=1);

/** The provider-compatible columns follow the audience's saved field/category order. */
function audienceExportHeaders(array $params,string $status):array
{
    $statusColumns=match($status){
        'unsubscribed'=>['UNSUB_TIME','UNSUB_CAMPAIGN_TITLE','UNSUB_CAMPAIGN_ID','UNSUB_REASON','UNSUB_REASON_OTHER'],
        'cleaned'=>['CLEAN_TIME','CLEAN_CAMPAIGN_TITLE','CLEAN_CAMPAIGN_ID'],
        default=>['LAST_CHANGED'],
    };
    return ['Email Address','First Name','Last Name',...array_column($params['fields'],'label'),
        ...array_column($params['export_groups'],'name'),'MEMBER_RATING','OPTIN_TIME','OPTIN_IP',
        'CONFIRM_TIME','CONFIRM_IP','GMTOFF','DSTOFF','TIMEZONE','CC','REGION',...$statusColumns,
        'LEID','EUID','NOTES','TAGS','SOURCE'];
}

function audienceExportTimestamp(string $value):string
{
    return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/D',$value)&&!str_starts_with($value,'0000-')?substr($value,0,19):'';
}

/** Render only captured values; absent provider IDs, ratings and evidence stay empty. */
function audienceExportRecord(array $person,array $params):array
{
    $status=$person['email_status'];
    $sourceEvent=$person['export_source_event']??[];
    $sourceEvent=is_array($sourceEvent)?$sourceEvent:[];
    $metadata=$sourceEvent['metadata']??[];
    $metadata=is_array($metadata)?$metadata:[];
    $values=[];
    foreach(audienceExportHeaders(['fields'=>[],'export_groups'=>[]],$status)as$key){
        $value=$metadata[$key]??'';$values[$key]=is_scalar($value)?(string)$value:'';
    }
    foreach(['OPTIN_TIME','CONFIRM_TIME','LAST_CHANGED','UNSUB_TIME','CLEAN_TIME']as$key)
        if(isset($values[$key]))$values[$key]=audienceExportTimestamp($values[$key]);
    $values['TIMEZONE']=(string)($person['timezone']??'');
    if(!$sourceEvent||strcmp((string)$person['updated_at'],(string)($sourceEvent['created_at']??''))>0)
        $values['LAST_CHANGED']=audienceExportTimestamp((string)$person['updated_at']);
    $permission=$person['export_permission_event']??[];
    if(is_array($permission)&&(int)($permission['id']??0)>(int)($sourceEvent['id']??0)){
        $detail=$permission['details']??[];$detail=is_array($detail)?$detail:[];
        $eventStatus=$detail['to']??$detail['status']??'';
        if($eventStatus===$status){
            $prefix=match($status){'unsubscribed'=>'UNSUB','cleaned'=>'CLEAN',default=>''};
            if($prefix!==''){
                $values[$prefix.'_TIME']=audienceExportTimestamp((string)($permission['created_at']??''));
                foreach(['CAMPAIGN_TITLE','CAMPAIGN_ID','REASON','REASON_OTHER']as$key)
                    if(array_key_exists($prefix.'_'.$key,$values))$values[$prefix.'_'.$key]='';
            }elseif($status==='subscribed'&&!empty($detail['consent_note'])){
                $values['OPTIN_TIME']=audienceExportTimestamp((string)($permission['created_at']??''));
            }
        }
    }
    $notes=$person['export_notes']??[];$notes=is_array($notes)?$notes:[];
    usort($notes,static fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);
    $values['NOTES']=implode("\n",array_filter([(string)($values['NOTES']??''),(string)($person['notes']??''),...array_column($notes,'body')],static fn($v)=>$v!==''));
    $tagNames=array_values(array_intersect_key($params['tag_names'],array_flip($person['tag_ids'])));
    $values['TAGS']=implode(',',array_map(static fn($v)=>'"'.str_replace('"','""',(string)$v).'"',$tagNames));
    $values['SOURCE']=(string)($metadata['SOURCE']??$person['source']);
    $row=[(string)$person['email'],(string)$person['first_name'],(string)$person['last_name']];
    foreach($params['fields']as$field){
        $value=$person['custom'][$field['id']]??'';
        if(is_array($value))$value=implode(', ',$value);
        if($field['field_type']==='address')$value=str_replace(["\r\n","\r","\n"],'  ',(string)$value);
        $row[]=(string)$value;
    }
    foreach($params['export_groups']as$group){
        $selected=[];
        foreach($group['options']as$option)if(in_array((int)$option['id'],array_map('intval',$person['group_ids']),true))$selected[]=$option['name'];
        $row[]=implode(', ',$selected);
    }
    $fixed=3+count($params['fields'])+count($params['export_groups']);
    foreach(array_slice(audienceExportHeaders($params,$status),$fixed)as$key)$row[]=$values[$key]??'';
    return ['status'=>$status,'row'=>$row];
}

/** ZIP/CSV temporary files stay private and are removed even after a failed download. */
function audienceWithExportArchive(array $params,iterable $records,callable $consume):void
{
    $token=(string)($params['export_token']??'');
    if(!preg_match('/^[a-f0-9]{10}$/D',$token))throw new RuntimeException('Invalid audience export identifier.');
    $paths=[];$streams=[];$zip=null;$opened=false;
    $temporary=static function()use(&$paths):string{
        $path=tempnam(sys_get_temp_dir(),'audience-export-');
        if($path===false)throw new RuntimeException('Unable to prepare the audience export.');
        $paths[]=$path;return $path;
    };
    $create=static function(string $status)use(&$streams,$temporary,$params):void{
        $path=$temporary();$stream=fopen($path,'w+b');
        if($stream===false)throw new RuntimeException('Unable to prepare the audience CSV.');
        $headers=audienceExportHeaders($params,$status);
        $streams[$status]=['path'=>$path,'stream'=>$stream,'headers'=>$headers];
        if(fputcsv($stream,array_map('audienceCsvCell',$headers),',','"','',"\r\n")===false)throw new RuntimeException('Unable to write the audience CSV.');
    };
    try{
        foreach(['subscribed','non-subscribed','unsubscribed','cleaned']as$status)$create($status);
        foreach($records as$record){
            $status=$record['status']??'';
            if(!in_array($status,AUDIENCE_STATUSES,true))throw new RuntimeException('Invalid audience export status.');
            if(!isset($streams[$status]))$create($status);
            $entry=$streams[$status];$row=$record['row']??[];
            if(!is_array($row)||count($row)!==count($entry['headers']))throw new RuntimeException('Invalid audience export row.');
            $cells=[];
            foreach(array_values($row)as$i=>$value){
                $value=(string)$value;
                $numeric=in_array($entry['headers'][$i],['GMTOFF','DSTOFF'],true)&&preg_match('/^-?\d+(?:\.\d+)?$/D',$value);
                $cells[]=$numeric?$value:audienceCsvCell($value);
            }
            if(fputcsv($entry['stream'],$cells,',','"','',"\r\n")===false)throw new RuntimeException('Unable to write the audience CSV.');
        }
        $archive=$temporary();$zip=new ZipArchive();
        if($zip->open($archive,ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Unable to prepare the audience ZIP.');
        $opened=true;
        foreach($streams as$status=>$entry){
            if(!fflush($entry['stream']))throw new RuntimeException('Unable to finish the audience CSV.');
            fclose($entry['stream']);$streams[$status]['stream']=null;
            $prefix=$status==='non-subscribed'?'nonsubscribed':$status;
            $name=$prefix.'_email_audience_export_'.$token.'.csv';
            if(!$zip->addFile($entry['path'],$name)||!$zip->setCompressionName($name,ZipArchive::CM_DEFLATE))throw new RuntimeException('Unable to add the audience CSV.');
        }
        if(!$zip->close())throw new RuntimeException('Unable to finish the audience ZIP.');
        $opened=false;$consume($archive,'messagemonkey_audience_export_'.$token.'.zip');
    }finally{
        if($opened&&$zip)$zip->close();
        foreach($streams as$entry)if(is_resource($entry['stream']))fclose($entry['stream']);
        foreach($paths as$path)if(is_file($path))unlink($path);
    }
}
