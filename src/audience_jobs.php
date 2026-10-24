<?php
declare(strict_types=1);
require_once __DIR__ . '/audience_import.php';

trait AudienceJobs
{
    public function deleteExportHistory(array $ids,bool $all=false):int
    {
        return $this->transaction(function()use($ids,$all){
            $ids=audienceIds($ids);if(!$all&&!$ids)throw new InvalidArgumentException('Select exports to delete.');
            if(count($ids)>5000)throw new InvalidArgumentException('Select at most 5,000 exports, or use Delete all.');
            $where="audience_id=? AND job_type='export' AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(params_json,'$.history_deleted')),'0') NOT IN ('1','true')";$params=[$this->audienceId];
            if(!$all){$where.=' AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')';array_push($params,...$ids);}
            $rows=$this->all("SELECT id,state FROM marketing_jobs WHERE $where FOR UPDATE",$params);
            if(!$all&&count($rows)!==count($ids))throw new InvalidArgumentException('A selected export is unavailable. Reload the history.');
            foreach($rows as$row)if(in_array($row['state'],['queued','processing'],true))throw new InvalidArgumentException('Cancel active exports before deleting their history.');
            foreach($rows as$row){$this->write("UPDATE marketing_job_items SET result_json=NULL,payload_json=JSON_OBJECT(),error_message='' WHERE job_id=?",[$row['id']]);$this->write("UPDATE marketing_jobs SET params_json=JSON_SET(params_json,'$.history_deleted',true) WHERE audience_id=? AND id=?",[$this->audienceId,$row['id']]);}
            return count($rows);
        });
    }
    public function userNotifications(): array
    {
        return $this->all("SELECT id,job_type,name,state,errors,updated_at FROM marketing_jobs
            WHERE audience_id=? AND actor_user_id=? AND state IN ('queued','processing','complete','failed')
              AND updated_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(params_json,'$.history_deleted')),'0') NOT IN ('1','true')
            ORDER BY updated_at DESC,id DESC LIMIT 10", [$this->audienceId,$this->actorId]);
    }
    public function job(int $id,bool $lock=false):array
    {
        $job=$this->one("SELECT * FROM marketing_jobs WHERE id=? AND audience_id=? AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(params_json,'$.history_deleted')),'0') NOT IN ('1','true')".($lock?' FOR UPDATE':''),[$id,$this->audienceId]);if(!$job)throw new InvalidArgumentException('This import or export is unavailable.');$job['params']=audienceJson($job['params_json']);return $job;
    }
    public function createImport(array $parsed,string $name):int
    {
        return $this->transaction(function()use($parsed,$name){
            $this->write('INSERT INTO marketing_jobs (audience_id,job_type,name,actor_user_id,actor_label,params_json,total) VALUES (?,\'import\',?,?,?,?,?)',[$this->audienceId,mb_substr($name,0,180),$this->actorId,$this->actorLabel,json_encode(['headers'=>$parsed['headers']],JSON_THROW_ON_ERROR),count($parsed['rows'])]);$id=(int)$this->db->insert_id;
            foreach($parsed['rows']as$i=>$row)$this->write('INSERT INTO marketing_job_items (job_id,source_row,payload_json) VALUES (?,?,?)',[$id,$i+2,json_encode($row,JSON_THROW_ON_ERROR)]);return $id;
        });
    }
    public function configureImport(int $id,array $input,bool $start):void
    {
        $this->transaction(function()use($id,$input,$start){$job=$this->job($id,true);if($job['job_type']!=='import'||$job['state']!=='draft')throw new InvalidArgumentException('This import is already being processed.');
            $mapping=$input['mapping']??($job['params']['mapping']??[]);if(!is_array($mapping)||count($mapping)>200)throw new InvalidArgumentException('Invalid field mapping.');
            $fields=$this->fields();$allowed=['skip','email','first_name','last_name','tags','groups','language','timezone'];foreach($fields as$field)$allowed[]='custom:'.$field['id'];
            $newFields=$input['new_fields']??[];foreach($mapping as$column=>&$target){if(!is_string($target)||!ctype_digit((string)$column)||(int)$column>=count($job['params']['headers']))throw new InvalidArgumentException('Invalid field mapping.');if($target==='new_text'&&$start){$label=audienceText($newFields,(string)$column);if($label==='')$label=$job['params']['headers'][(int)$column];$fieldId=$this->saveField(['label'=>$label,'merge_tag'=>'FIELD_'.strtoupper(bin2hex(random_bytes(4))),'field_type'=>'text','is_public'=>'1'],0,0);$target='custom:'.$fieldId;$allowed[]=$target;}elseif($target==='new_text')continue;if(!in_array($target,$allowed,true))throw new InvalidArgumentException('Choose an available field.');}unset($target);
            $used=array_filter($mapping,fn($v)=>$v!=='skip'&&$v!=='new_text');if(count($used)!==count(array_unique($used)))throw new InvalidArgumentException('Match each audience field only once.');if($start&&!in_array('email',$mapping,true))throw new InvalidArgumentException('Match the Email Address column.');
            $status=audienceText($input,'import_status')?:($job['params']['status']??'non-subscribed');if(!in_array($status,['non-subscribed','subscribed','unsubscribed'],true))throw new InvalidArgumentException('Choose a valid import subscription status.');
            $consent=audienceText($input,'consent_note',1000);if($consent==='')$consent=$job['params']['consent_note']??'';
            if($start&&$status==='subscribed'&&($consent===''||empty($input['permission_confirmed'])))throw new InvalidArgumentException('Confirm permission and describe how these connections subscribed.');
            $tags=audienceIds($input['tag_ids']??(($input['import_step']??'')==='tag'?[]:($job['params']['tag_ids']??[])));$groups=audienceIds($input['group_ids']??(($input['import_step']??'')==='tag'?[]:($job['params']['group_ids']??[])));
            $catalog=$this->catalog();if(array_diff($tags,array_map('intval',array_keys($catalog['tags']['options'])))||array_diff($groups,array_map('intval',array_keys($catalog['groups']['options']))))throw new InvalidArgumentException('A selected tag or group is unavailable.');
            $params=array_replace($job['params'],['mapping'=>$mapping,'status'=>$status,'consent_note'=>$consent,'update_existing'=>!empty($input['update_existing']),'tag_ids'=>$tags,'group_ids'=>$groups,'new_fields'=>$newFields]);
            $this->write('UPDATE marketing_jobs SET params_json=?,state=? WHERE id=?',[json_encode($params,JSON_THROW_ON_ERROR),$start?'queued':'draft',$id]);
        });
    }
    public function queueEmailUnsubscribe(string $input):int
    {
        $emails=audienceUnsubscribeAddresses($input);
        $placeholders=implode(',',array_fill(0,count($emails),'?'));
        return $this->queueSelection('bulk','Unsubscribe emails',[
            "c.audience_id=? AND c.deleted_at IS NULL AND c.email_status NOT IN ('unsubscribed','cleaned') AND c.email IN ($placeholders)",
            [$this->audienceId,...$emails],
        ],['action'=>'unsubscribe']);
    }
    public function queueSelection(string $type,string $name,array $where,array $params=[]):int
    {
        if(!in_array($type,['export','bulk'],true))throw new InvalidArgumentException('Invalid operation.');
        return $this->transaction(function()use($type,$name,$where,$params){
            if($type==='bulk'){
                $action=$params['action']??'';
                if(!in_array($action,['archive','restore','unsubscribe','tag','untag','group','ungroup','delete'],true))throw new InvalidArgumentException('Choose a supported connections action.');
                if(in_array($action,['tag','untag'],true)&&!$this->one('SELECT id FROM marketing_tags WHERE id=? AND audience_id=?',[(int)($params['tag_id']??0),$this->audienceId]))throw new InvalidArgumentException('Choose an available tag.');
                if(in_array($action,['group','ungroup'],true)&&!$this->one('SELECT o.id FROM marketing_group_options o JOIN marketing_group_categories g ON g.id=o.category_id WHERE o.id=? AND g.audience_id=?',[(int)($params['option_id']??0),$this->audienceId]))throw new InvalidArgumentException('Choose an available group.');
            }
            $params['fields']=$this->fields();$params['tag_names']=array_column($this->tags(),'name','id');$params['group_names']=$this->catalog()['groups']['options'];
            $this->write('INSERT INTO marketing_jobs (audience_id,job_type,name,state,actor_user_id,actor_label,params_json) VALUES (?,?,?,\'queued\',?,?,?)',[$this->audienceId,$type,$name,$this->actorId,$this->actorLabel,json_encode($params,JSON_THROW_ON_ERROR)]);$id=(int)$this->db->insert_id;
            [$sql,$values]=$where;
            // Freeze export values as well as membership, so later edits do not change a download.
            // Bulk writes retain versions and reject stale records instead of overwriting them.
            $snapshot="JSON_OBJECT('version',c.version)";
            if($type==='export')$snapshot="JSON_OBJECT('version',c.version,'person',JSON_OBJECT('id',c.id,'version',c.version,'email',c.email,'first_name',c.first_name,'last_name',c.last_name,'email_status',c.email_status,'source',c.source,'is_archived',c.is_archived,'language',c.language,'timezone',c.timezone,'created_at',c.created_at,'updated_at',c.updated_at,'custom',c.custom_json,'tag_ids',COALESCE((SELECT JSON_ARRAYAGG(ct.tag_id) FROM marketing_contact_tags ct WHERE ct.contact_id=c.id),JSON_ARRAY()),'group_ids',COALESCE((SELECT JSON_ARRAYAGG(cg.option_id) FROM marketing_contact_groups cg WHERE cg.contact_id=c.id),JSON_ARRAY())))";
            $this->write("INSERT INTO marketing_job_items (job_id,source_row,contact_id,payload_json) SELECT ?,c.id,c.id,$snapshot FROM marketing_contacts c WHERE $sql",[$id,...$values]);
            $this->write('UPDATE marketing_jobs SET total=(SELECT COUNT(*) FROM marketing_job_items WHERE job_id=?) WHERE id=?',[$id,$id]);return $id;
        });
    }
    public function processJob(int $id,int $limit=40):array
    {
        return $this->transaction(function()use($id,$limit){
            $job=$this->job($id,true);if(!in_array($job['state'],['queued','processing'],true))return $job;
            $this->write("UPDATE marketing_jobs SET state='processing' WHERE id=?",[$id]);
            $items=$this->all('SELECT * FROM marketing_job_items WHERE job_id=? AND state=\'pending\' ORDER BY id LIMIT ?',[$id,max(1,min(100,$limit))]);
            foreach($items as$item){$state='updated';$error='';$result=null;$personId=$item['contact_id'];$version=null;
                try{$out=$this->transaction(function()use($job,$item){$raw=audienceJson($item['payload_json']);
                    if($job['job_type']==='import')return $this->importItem($job,$item,$raw);
                    if($job['job_type']==='export')return ['state'=>'exported','person_id'=>$item['contact_id'],'version'=>$raw['version'],'result'=>$this->exportPerson($raw['person'],$job['params'])];
                    $person=$this->person((int)$item['contact_id'],true);if((int)$person['version']!==(int)$raw['version'])throw new InvalidArgumentException('Connection changed since selection. Select again to use the latest record.');
                    $this->mutatePerson((int)$person['id'],(int)$person['version'],$job['params']['action'],$job['params']);return ['state'=>'updated','person_id'=>$person['id'],'version'=>(int)$person['version']+1];
                });$state=$out['state'];$personId=$out['person_id']??$personId;$version=$out['version']??null;$result=$out['result']??null;
                }catch(InvalidArgumentException $exception){$state='error';$error=mb_substr($exception->getMessage(),0,500);}
                $this->write('UPDATE marketing_job_items SET state=?,contact_id=?,applied_version=?,result_json=?,error_message=? WHERE id=?',[$state,$personId,$version,$result===null?null:json_encode($result,JSON_THROW_ON_ERROR),$error,$item['id']]);
            }
            $stats=$this->one("SELECT COUNT(*) total,SUM(state<>'pending') processed,SUM(state='added') added,SUM(state IN ('updated','exported')) updated,SUM(state IN ('skipped','undone')) skipped,SUM(state='error') errors FROM marketing_job_items WHERE job_id=?",[$id]);
            $state=(int)$stats['processed']===(int)$stats['total']?'complete':'processing';
            $this->write('UPDATE marketing_jobs SET state=?,processed=?,added=?,updated=?,skipped=?,errors=?,error_message=\'\' WHERE id=?',[$state,(int)$stats['processed'],(int)$stats['added'],(int)$stats['updated'],(int)$stats['skipped'],(int)$stats['errors'],$id]);return $this->job($id);
        });
    }
    private function importItem(array $job,array $item,array $raw):array
    {
        $params=$job['params'];if(count($raw)!==count($params['headers']))throw new InvalidArgumentException('Column count differs from the header row.');
        $mapped=[];foreach($params['mapping']as$column=>$field)if($field!=='skip')$mapped[$field]=$raw[(int)$column]??'';
        $email=strtolower(trim($mapped['email']??''));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid email address.');
        $existing=$this->one('SELECT id FROM marketing_contacts WHERE audience_id=? AND email=? AND deleted_at IS NULL FOR UPDATE',[$this->audienceId,$email]);$old=$existing?$this->person((int)$existing['id'],true):null;
        if($old&&!$params['update_existing']&&$params['status']!=='unsubscribed')return ['state'=>'skipped','person_id'=>$old['id'],'version'=>$old['version']];
        if($old&&$this->one("SELECT id FROM marketing_job_items WHERE job_id=? AND contact_id=? AND state IN ('added','updated') LIMIT 1",[$job['id'],$old['id']]))return ['state'=>'skipped','person_id'=>$old['id'],'version'=>$old['version']];
        $input=$old??['email'=>$email,'first_name'=>'','last_name'=>'','email_status'=>$params['status'],'custom'=>[],'tag_ids'=>[],'group_ids'=>[],'language'=>'','timezone'=>''];
        foreach(['email','first_name','last_name','language','timezone']as$key)if(isset($mapped[$key]))$input[$key]=$mapped[$key];
        foreach($mapped as$key=>$value)if(str_starts_with($key,'custom:'))$input['custom'][substr($key,7)]=$value;
        // Import never changes an existing opt-out/cleaned/pending/non-subscribed record into a subscriber.
        if($old)$input['email_status']=$params['status']==='unsubscribed'&&$old['email_status']!=='cleaned'?'unsubscribed':$old['email_status'];
        $input['consent_note']=$params['consent_note'];$input['tag_ids']=array_values(array_unique([...($input['tag_ids']??[]),...$params['tag_ids']]));$input['group_ids']=array_values(array_unique([...($input['group_ids']??[]),...$params['group_ids']]));
        $input['tag_names']=audienceParseListCell($mapped['tags']??'');
        if(!empty($mapped['groups'])){$options=array_flip($this->catalog()['groups']['options']);foreach(audienceParseListCell($mapped['groups'])as$name){if(!isset($options[$name]))throw new InvalidArgumentException('Unknown group: '.$name);$input['group_ids'][]=(int)$options[$name];}}
        $id=$this->savePerson($input,(int)($old['id']??0),(int)($old['version']??0),'Import');$this->event($id,'imported',['job_id'=>$job['id'],'row'=>$item['source_row']]);
        return ['state'=>$old?'updated':'added','person_id'=>$id,'version'=>(int)($old['version']??0)+1];
    }
    public function exportPerson(array $person,array $params):array
    {
        $result=['Email Address'=>$person['email'],'First Name'=>$person['first_name'],'Last Name'=>$person['last_name'],'Status'=>$person['email_status'],'Tags'=>audienceListCell(array_intersect_key($params['tag_names'],array_flip($person['tag_ids']))),'Groups'=>audienceListCell(array_intersect_key($params['group_names'],array_flip($person['group_ids']))),'Language'=>$person['language'],'Time zone'=>$person['timezone'],'Source'=>$person['source'],'Archived'=>$person['is_archived']?'Yes':'No','Date Added'=>$person['created_at'],'Last Changed'=>$person['updated_at']];
        foreach($params['fields']as$field){$v=$person['custom'][$field['id']]??'';$result[$field['label'].' ('.$field['merge_tag'].')']=is_array($v)?audienceListCell($v):$v;}return $result;
    }
    public function cancelJob(int $id):void
    {
        $this->transaction(function()use($id){$job=$this->job($id,true);if(!in_array($job['state'],['draft','queued','processing','failed'],true))throw new InvalidArgumentException('This operation has already finished.');$this->write("UPDATE marketing_jobs SET state='cancelled' WHERE id=?",[$id]);});
    }
    public function undoImport(int $id):array
    {
        return $this->transaction(function()use($id){$job=$this->job($id,true);if($job['job_type']!=='import'||!in_array($job['state'],['complete','cancelled'],true))throw new InvalidArgumentException('Finish or cancel this import before undoing it.');$undone=0;$changed=0;
            foreach($this->all("SELECT * FROM marketing_job_items WHERE job_id=? AND state='added' ORDER BY id",[$id])as$item){try{$this->mutatePerson((int)$item['contact_id'],(int)$item['applied_version'],'archive');$this->write("UPDATE marketing_job_items SET state='undone' WHERE id=?",[$item['id']]);$undone++;}catch(InvalidArgumentException $e){$changed++;}}
            $this->write("UPDATE marketing_jobs SET state='undone' WHERE id=?",[$id]);return ['undone'=>$undone,'changed'=>$changed];
        });
    }
}
