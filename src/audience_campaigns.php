<?php
declare(strict_types=1);

/** Native campaign management. This surface cannot enqueue or send mail. */
trait AudienceCampaigns
{
    public function campaign(int $id, bool $lock=false, bool $includeArchived=false): array
    {
        $row=$this->one("SELECT * FROM marketing_campaigns WHERE audience_id=? AND id=? AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(draft_json,'$._permanently_deleted')),'0') NOT IN ('1','true')".($includeArchived?'':' AND deleted_at IS NULL').($lock?' FOR UPDATE':''),[$this->audienceId,$id]);
        if(!$row)throw new InvalidArgumentException('This campaign is unavailable.');
        $row['draft']=audienceJson($row['draft_json']);return $row;
    }
    public function campaignFolders(): array
    {
        return $this->all('SELECT f.*,COUNT(c.id) campaign_count FROM marketing_campaign_folders f LEFT JOIN marketing_campaigns c ON c.folder_id=f.id AND c.audience_id=f.audience_id AND c.deleted_at IS NULL WHERE f.audience_id=? GROUP BY f.id ORDER BY f.name,f.id',[$this->audienceId]);
    }
    private function campaignFolder(int $id): void
    {
        if($id&&!$this->one('SELECT id FROM marketing_campaign_folders WHERE id=? AND audience_id=? FOR UPDATE',[$id,$this->audienceId]))throw new InvalidArgumentException('This folder is unavailable.');
    }
    public function saveCampaignFolder(array $input,int $id,int $version): int
    {
        $name=audienceText($input,'name',100);if($name==='')throw new InvalidArgumentException('Enter a folder name.');
        return $this->transaction(function()use($name,$id,$version){
            if($id){$this->write('UPDATE marketing_campaign_folders SET name=?,version=version+1 WHERE id=? AND audience_id=? AND version=?',[$name,$id,$this->audienceId,$version]);if($this->db->affected_rows!==1)throw new InvalidArgumentException('This folder changed. Reload before saving.');}
            else{$this->write('INSERT INTO marketing_campaign_folders(audience_id,name) VALUES (?,?)',[$this->audienceId,$name]);$id=(int)$this->db->insert_id;}
            return $id;
        });
    }
    public function deleteCampaignFolder(int $id,int $version): void
    {
        $this->transaction(function()use($id,$version){
            $folder=$this->one('SELECT * FROM marketing_campaign_folders WHERE id=? AND audience_id=? FOR UPDATE',[$id,$this->audienceId]);
            if(!$folder||(int)$folder['version']!==$version)throw new InvalidArgumentException('This folder changed. Reload before deleting.');
            $this->write('UPDATE marketing_campaigns SET folder_id=NULL,version=version+1 WHERE audience_id=? AND folder_id=?',[$this->audienceId,$id]);
            $this->write('DELETE FROM marketing_campaign_folders WHERE id=? AND audience_id=?',[$id,$this->audienceId]);
        });
    }
    public function createCampaign(array $input): int
    {
        $name=audienceText($input,'name',100);if($name==='')throw new InvalidArgumentException('Enter an internal email name.');
        $type=audienceText($input,'campaign_type');if(!in_array($type,['regular','plain-text'],true))throw new InvalidArgumentException('Choose a supported email type.');
        $target=audienceText($input,'target',40);
        $filteredRules=null;if(($input['filtered_rules']??'')!==''){try{$filteredRules=json_decode(audienceText($input,'filtered_rules',50000),true,48,JSON_THROW_ON_ERROR);}catch(JsonException){throw new InvalidArgumentException('The audience filters are invalid.');}if(!is_array($filteredRules))throw new InvalidArgumentException('The audience filters are invalid.');audienceCompileRules($filteredRules,$this->catalog());}
        return $this->transaction(function()use($name,$type,$target,$filteredRules){
            $settings=audienceJson($this->one('SELECT settings_json FROM marketing_audiences WHERE id=?',[$this->audienceId])['settings_json']);
            $draft=array_intersect_key($settings,array_flip(['from_name','from_email']));if(!empty($settings['default_subject']))$draft['subject']=$settings['default_subject'];
            $draft['tracking']=audienceCampaignDefaultTracking();
            if($type==='ab')$draft['experiment']=['kind'=>$type,'variables'=>[]];if($target!==''){$draft+=['target'=>$target,'exclude_tag'=>0,'recipients_saved'=>true];$this->campaignTarget($draft);}
            if($filteredRules!==null){$draft['recipient_rules']=$filteredRules;$draft['recipients_saved']=true;$this->campaignTarget($draft);}
            $this->write('INSERT INTO marketing_campaigns(audience_id,name,campaign_type,draft_json,actor_label) VALUES (?,?,?,?,?)',[$this->audienceId,$name,$type==='ab'?'regular':$type,json_encode((object)$draft,JSON_THROW_ON_ERROR),$this->actorLabel]);
            return (int)$this->db->insert_id;
        });
    }
    public function campaignTarget(array $draft): array
    {
        return $this->campaignRecipients($draft);
    }
    public function saveCampaignSection(int $id,int $version,string $section,array $input): void
    {
        $this->transaction(function()use($id,$version,$section,$input){
            $row=$this->campaign($id,true);if((int)$row['version']!==$version)throw new InvalidArgumentException('This campaign changed. Reload before saving. Your entries are kept below.');
            if($row['status']!=='draft')throw new InvalidArgumentException('Only drafts can be edited. Replicate this campaign to start a new draft.');
            $draft=$row['draft'];
            switch($section){
                case 'to':$draft=$this->campaignAdvancedSection($row,$section,$input);break;
                case 'from':$draft['from_name']=audienceText($input,'from_name',100);$draft['from_email']=audienceText($input,'from_email',254);if($draft['from_name']===''||!filter_var($draft['from_email'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a sender name and valid email address.');break;
                case 'subject':$draft['subject']=audienceText($input,'subject',200);$draft['preview_text']=audienceText($input,'preview_text',200);if($draft['subject']==='')throw new InvalidArgumentException('Enter a subject line.');break;
                case 'content':if($row['campaign_type']!=='plain-text')throw new InvalidArgumentException('The visual email designer is the next Stage 2 step.');$draft['text']=audienceText($input,'text',100000);if($draft['text']==='')throw new InvalidArgumentException('Enter email content.');break;
                default:$draft=$this->campaignAdvancedSection($row,$section,$input);break;
            }
            $this->write('UPDATE marketing_campaigns SET draft_json=?,actor_label=?,version=version+1 WHERE id=? AND audience_id=?',[json_encode($draft,JSON_THROW_ON_ERROR),$this->actorLabel,$id,$this->audienceId]);
        });
    }
    /** Atomic version checks prevent partial bulk writes and stale selection changes. */
    public function campaignOperation(string $operation,array $ids,array $versions,array $input=[]): ?int
    {
        if(!in_array($operation,['rename','replicate','move','archive','restore','delete'],true))throw new InvalidArgumentException('Unknown campaign operation.');
        $ids=audienceIds($ids);sort($ids);if(!$ids||count($ids)>5000)throw new InvalidArgumentException('Select between 1 and 5,000 campaigns.');
        if(in_array($operation,['rename','replicate'],true)&&count($ids)!==1)throw new InvalidArgumentException('Select one campaign.');
        $locks=[];
        try{
        // Serialize lifecycle changes with the mail worker, including its network send.
        if(in_array($operation,['archive','restore','delete'],true)&&$this->campaignDispatchAvailable())foreach($ids as$id)$locks[]=audienceDispatchLock($this->db,$this->audienceId,$id);
        return $this->transaction(function()use($operation,$ids,$versions,$input){
            $folder=(int)audienceText($input,'folder_id',12);if($operation==='move')$this->campaignFolder($folder);
            $rows=[];foreach($ids as$id){
                $row=$this->campaign($id,true,in_array($operation,['restore','delete'],true));
                if((int)$row['version']!==(int)($versions[$id]??0))throw new InvalidArgumentException('A selected campaign changed. Reload before continuing.');
                if(in_array($operation,['restore','delete'],true)&&!$row['deleted_at'])throw new InvalidArgumentException('Only archived campaigns can be restored or permanently deleted.');
                if(in_array($operation,['archive','delete'],true)){
                    if(in_array($row['status'],['scheduled','sending'],true))throw new InvalidArgumentException('Pause delivery before archiving this campaign.');
                    if($this->campaignDispatchAvailable()&&$this->one("SELECT d.id FROM marketing_campaign_deliveries d WHERE d.audience_id=? AND d.campaign_id=? AND (d.state IN ('scheduled','sending') OR EXISTS(SELECT 1 FROM marketing_campaign_recipients r WHERE r.delivery_id=d.id AND r.audience_id=d.audience_id AND r.state='processing')) LIMIT 1",[$this->audienceId,$id]))throw new InvalidArgumentException('Pause delivery and wait for processing messages to finish before archiving or deleting this campaign.');
                }
                $rows[]=$row;
            }
            foreach($rows as$row){$id=(int)$row['id'];
                if($operation==='rename'){$name=audienceText($input,'name',100);if($name==='')throw new InvalidArgumentException('Enter a campaign name.');$this->write('UPDATE marketing_campaigns SET name=?,actor_label=?,version=version+1 WHERE id=? AND audience_id=?',[$name,$this->actorLabel,$id,$this->audienceId]);}
                elseif($operation==='replicate'){$copy=$row['draft'];$copy['tracking']??=audienceCampaignDefaultTracking();unset($copy['delivery'],$copy['winner'],$copy['public_key'],$copy['experiment'],$copy['design_variants']);$this->write("INSERT INTO marketing_campaigns(audience_id,name,campaign_type,folder_id,draft_json,actor_label) VALUES (?,?,?,?,?,?)",[$this->audienceId,mb_substr($row['name'],0,89).' (copy 01)',$row['campaign_type'],$row['folder_id'],json_encode((object)$copy,JSON_THROW_ON_ERROR),$this->actorLabel]);return (int)$this->db->insert_id;}
                elseif($operation==='move')$this->write('UPDATE marketing_campaigns SET folder_id=?,actor_label=?,version=version+1 WHERE id=? AND audience_id=?',[$folder?:null,$this->actorLabel,$id,$this->audienceId]);
                elseif($operation==='archive')$this->write('UPDATE marketing_campaigns SET deleted_at=UTC_TIMESTAMP(6),actor_label=?,version=version+1 WHERE id=? AND audience_id=?',[$this->actorLabel,$id,$this->audienceId]);
                elseif($operation==='restore')$this->write('UPDATE marketing_campaigns SET deleted_at=NULL,actor_label=?,version=version+1 WHERE id=? AND audience_id=?',[$this->actorLabel,$id,$this->audienceId]);
                else{
                    // Keep immutable delivery evidence and recipient tokens for old unsubscribe
                    // links. Erase the editable campaign definition and make restoration impossible.
                    if($this->campaignDispatchAvailable()){
                        $this->write("UPDATE marketing_campaign_recipients r JOIN marketing_campaign_deliveries d ON d.id=r.delivery_id AND d.audience_id=r.audience_id SET r.state='cancelled',r.error_message='Campaign permanently deleted.' WHERE d.audience_id=? AND d.campaign_id=? AND r.state='queued'",[$this->audienceId,$id]);
                        $this->write("UPDATE marketing_campaign_deliveries SET state='cancelled',version=version+1 WHERE audience_id=? AND campaign_id=? AND state='paused'",[$this->audienceId,$id]);
                    }
                    $this->write("UPDATE marketing_campaign_publications SET enabled=0,archive_listed=0,title='Deleted campaign',description='',image_data='',html='',version=version+1 WHERE audience_id=? AND campaign_id=?",[$this->audienceId,$id]);
                    $this->write('UPDATE marketing_campaign_touchpoints SET campaign_id=NULL,version=version+1 WHERE audience_id=? AND campaign_id=?',[$this->audienceId,$id]);
                    $this->write("UPDATE marketing_campaigns SET name='Deleted campaign',folder_id=NULL,draft_json=JSON_OBJECT('_permanently_deleted',true),actor_label=?,version=version+1 WHERE id=? AND audience_id=?",[$this->actorLabel,$id,$this->audienceId]);
                }
            }return null;
        });
        }finally{foreach(array_reverse($locks)as$lock)$this->db->execute_query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
}

function audienceCampaignFilters(array $input): array
{
    $filters=[];foreach(['search','type','status','folder','date_field','start','end','sort','direction','layout','month','date_preset','holidays','trash']as$key)$filters[$key]=audienceText($input,$key,$key==='search'?100:40);
    foreach(['type'=>['','regular','plain-text'],'status'=>['','draft','ongoing','scheduled','completed'],'date_field'=>['updated_at','created_at','sent_at'],'sort'=>['updated_at','created_at','sent_at','name'],'direction'=>['desc','asc'],'layout'=>['list','calendar']]as$key=>$allowed)if(!in_array($filters[$key],$allowed,true))$filters[$key]=$allowed[0];
    if(!array_key_exists($filters['date_preset'],audienceCampaignDatePresets()))$filters['date_preset']='';
    $filters['holidays']=$filters['holidays']==='1'?'1':'0';
    $filters['trash']=$filters['trash']==='1'?'1':'0';
    $filters['per_page']=(string)audiencePageSize($input);
    if($filters['date_preset']!==''&&$filters['date_preset']!=='custom')[$filters['start'],$filters['end']]=audienceCampaignDateRange($filters['date_preset']);
    if(!preg_match('/^(?:|unfiled|[1-9][0-9]*)$/D',$filters['folder']))$filters['folder']='';
    foreach(['start','end']as$key)if($filters[$key]!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$filters[$key])||date('Y-m-d',strtotime($filters[$key])?:0)!==$filters[$key]))throw new InvalidArgumentException('Enter a valid date range.');
    if(in_array($filters['date_preset'],['','custom'],true)&&$filters['start']!==''&&$filters['end']!==''&&$filters['start']>$filters['end'])throw new InvalidArgumentException('Start date must come before end date.');
    if(!preg_match('/^(20[0-9]{2})-(0[1-9]|1[0-2])$/D',$filters['month']))$filters['month']=userDisplayInstant(time())->format('Y-m');
    return $filters;
}
function audienceCampaignWhere(int $audienceId,array $filters): array
{
    $archived=($filters['trash']??'0')==='1';
    $sql='c.audience_id=? AND c.deleted_at IS '.($archived?'NOT NULL':'NULL');$params=[$audienceId];
    // Keep large design JSON out of the sorted row set. A direct JSON predicate
    // makes MySQL carry entire archived designs through the list's filesort.
    if($archived){$sql.=" AND c.id NOT IN (SELECT erased.id FROM marketing_campaigns erased WHERE erased.audience_id=? AND erased.deleted_at IS NOT NULL AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(erased.draft_json,'$._permanently_deleted')),'0') IN ('1','true'))";$params[]=$audienceId;}
    if($filters['search']!==''){$sql.=' AND LOCATE(?,c.name)>0';$params[]=$filters['search'];}
    if($filters['type']!==''){$sql.=" AND c.id IN (SELECT typed.id FROM marketing_campaigns typed WHERE typed.audience_id=? AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(typed.draft_json,'$.experiment.kind')),typed.campaign_type)=?)";$params[]=$audienceId;$params[]=$filters['type'];}
    $statuses=['draft'=>['draft'],'ongoing'=>['sending','paused'],'scheduled'=>['scheduled'],'completed'=>['sent']];
    if(isset($statuses[$filters['status']])){$values=$statuses[$filters['status']];$sql.=' AND c.status IN ('.implode(',',array_fill(0,count($values),'?')).')';array_push($params,...$values);}
    if($filters['folder']==='unfiled')$sql.=' AND c.folder_id IS NULL';elseif($filters['folder']!==''){$sql.=' AND c.folder_id=?';$params[]=(int)$filters['folder'];}
    foreach(['start'=>'>=','end'=>'<=']as$key=>$operator)if($filters[$key]!==''){$sql.=' AND c.'.$filters['date_field'].' '.$operator.' ?';$bound=new DateTimeImmutable($filters[$key].($key==='start'?' 00:00:00':' 23:59:59.999999'),new DateTimeZone('America/Chicago'));$params[]=$bound->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');}
    return [$sql,$params];
}
