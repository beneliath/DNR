<?php
declare(strict_types=1);
require_once __DIR__.'/audience_flows.php';

trait AudienceFlows
{
    public function flowAvailable(): bool { return audienceFlowsInstalled($this->db); }
    public function flow(int $id,bool $lock=false,bool $removed=false): array
    {
        $r=$this->one('SELECT * FROM marketing_flows WHERE audience_id=? AND id=?'.($removed?'':' AND deleted_at IS NULL').($lock?' FOR UPDATE':''),[$this->audienceId,$id]);
        if(!$r)throw new InvalidArgumentException('This flow is unavailable in this audience.');
        $r['document']=audienceJson($r['draft_json']);$r['paused_nodes']=audienceJson($r['paused_nodes_json']);return $r;
    }
    public function flowLock(int $id,callable $operation): mixed
    {
        $key='mm-flow-'.$this->audienceId.'-'.$id;
        if((int)$this->one('SELECT GET_LOCK(?,10) acquired',[$key])['acquired']!==1)throw new InvalidArgumentException('This flow is busy. Try again.');
        try{return $operation();}finally{$this->one('SELECT RELEASE_LOCK(?)',[$key]);}
    }
    public function flowValidate(array $d,bool $activate=false): array
    {
        $catalog=$this->catalog();foreach(['entry','exit']as$key)audienceCompileRules($d[$key],$catalog);
        $errors=[];$trigger=$d['trigger'];$tagIds=array_map('intval',array_column($this->tags(),'id'));$groupIds=[];foreach($this->groups()as$g)foreach($g['options']as$o)$groupIds[]=(int)$o['id'];
        $validateTrigger=function(array $t)use(&$errors,$tagIds,$groupIds):void{
            $id=(int)($t['id']??0);
            if(in_array($t['type'],['tag','untag'],true)&&!in_array($id,$tagIds,true))$errors[]='Choose an available trigger tag.';
            if(in_array($t['type'],['group','ungroup'],true)&&!in_array($id,$groupIds,true))$errors[]='Choose an available trigger group.';
            if($t['type']==='survey'&&$id&&!$this->one("SELECT id FROM marketing_collection_resources WHERE audience_id=? AND id=? AND kind='survey' AND deleted_at IS NULL",[$this->audienceId,$id]))$errors[]='Choose an available survey.';
            if($t['type']==='flow'&&(!$id||!$this->one('SELECT id FROM marketing_flows WHERE audience_id=? AND id=? AND deleted_at IS NULL',[$this->audienceId,$id])))$errors[]='Choose an available source flow.';
            if(in_array($t['type'],['open','click'],true)&&$id&&!$this->one('SELECT id FROM marketing_campaigns WHERE audience_id=? AND id=? AND deleted_at IS NULL',[$this->audienceId,$id]))$errors[]='Choose an available source email.';
            if(in_array($t['type'],['date','birthday'],true)){
                $field=$this->one('SELECT field_type FROM marketing_fields WHERE audience_id=? AND id=?',[$this->audienceId,(int)($t['field']??0)]);
                if(!$field||!in_array($field['field_type'],['date','birthday'],true))$errors[]='Choose a date or birthday audience field.';
            }
            if($t['type']==='profile'&&($t['field']??'')!==''&&!in_array($t['field'],['first_name','last_name','language','timezone',...array_map(static fn($f)=>(string)$f['id'],$this->fields())],true))$errors[]='Choose an available audience field.';
        };
        $validateTrigger($trigger);
        if(!$d['start']||!$d['nodes'])$errors[]='Add a first step.';
        $reachable=[];$map=array_column($d['nodes'],null,'id');$walk=function(string $id)use(&$walk,&$reachable,$map):void{if($id===''||isset($reachable[$id]))return;$reachable[$id]=true;$n=$map[$id];foreach(in_array($n['type'],['condition','split','wait'],true)?['yes','no']:['next']as$edge)$walk($n[$edge]);};$walk($d['start']);
        foreach($d['nodes']as$n){
            if(!isset($reachable[$n['id']]))$errors[]=$n['label'].': connect this step to the flow.';
            if(in_array($n['type'],['condition'],true))audienceCompileRules($n['rules'],$catalog);
            if($n['type']==='condition'&&empty($n['rules']['rules']))$errors[]=$n['label'].': add at least one condition.';
            if($n['type']==='tag'&&!in_array($n['tag_id'],$tagIds,true))$errors[]=$n['label'].': choose an available tag.';
            if($n['type']==='group'&&!in_array($n['option_id'],$groupIds,true))$errors[]=$n['label'].': choose an available group.';
            if($n['type']==='split'&&($n['percent']<1||$n['percent']>99))$errors[]=$n['label'].': use a split from 1% to 99%.';
            if($n['type']==='until'&&!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$n['time']))$errors[]=$n['label'].': choose a time.';
            if($n['type']==='wait'){if(!isset($n['trigger']))$errors[]=$n['label'].': choose a trigger.';else $validateTrigger($n['trigger']);if($n['timeout']<60)$errors[]=$n['label'].': choose a timeout of at least one minute.';}
            if($n['type']==='update'){
                $field=$this->one('SELECT field_type FROM marketing_fields WHERE audience_id=? AND id=?',[$this->audienceId,(int)$n['field']]);
                if(!in_array($n['field'],['first_name','last_name','language','timezone'],true)&&!$field)$errors[]=$n['label'].': choose an editable audience field.';
                if($field){$definition=$this->one('SELECT * FROM marketing_fields WHERE audience_id=? AND id=?',[$this->audienceId,(int)$n['field']]);try{audienceFieldValue($definition,$n['value']);}catch(InvalidArgumentException $e){$errors[]=$n['label'].': '.$e->getMessage();}}
                if($n['field']==='timezone'&&$n['value']!==''&&!in_array($n['value'],DateTimeZone::listIdentifiers(),true))$errors[]='Choose a valid updated time zone.';
                if($n['field']==='language'&&$n['value']!==''&&!preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D',$n['value']))$errors[]='Choose a valid updated language code.';
            }
            if($n['type']==='email'){
                try{$campaign=$this->campaign($n['campaign_id']);if(!in_array($campaign['campaign_type'],['regular','plain-text'],true)||!empty($campaign['draft']['experiment']))throw new InvalidArgumentException('Choose a regular or plain-text email.');$draft=$campaign['draft'];if(empty($draft['subject'])||empty($draft['from_name'])||empty($draft['from_email'])||empty($draft[$campaign['campaign_type']==='regular'?'design':'text']))throw new InvalidArgumentException('Complete the sender, subject and email content.');if($activate){$audience=$this->one('SELECT * FROM marketing_audiences WHERE id=?',[$this->audienceId]);$ready=$this->sendingReadiness($draft['from_email'],audienceJson($audience['settings_json']));if(!$ready['ready'])throw new InvalidArgumentException(implode(' ',$ready['reasons']));audienceDispatchBase(accountPrimaryPublicUrl());}}
                catch(InvalidArgumentException $e){$errors[]=$n['label'].': '.$e->getMessage();}
            }
        }
        return array_values(array_unique($errors));
    }
    public function saveFlow(string $name,array $raw,int $id=0,int $version=0): int
    {
        $name=trim(mb_substr($name,0,100));if($name==='')throw new InvalidArgumentException('Enter a flow name.');$d=audienceFlowDocument($raw);$this->flowValidate($d);
        $operation=function()use($name,$d,$id,$version):int{return $this->transaction(function()use($name,$d,$id,$version):int{
            if($id){$row=$this->flow($id,true);if((int)$row['version']!==$version)throw new InvalidArgumentException('This flow changed. Reload before saving. Your draft is still available.');if($row['state']==='running')throw new InvalidArgumentException('Pause this flow before editing.');$this->write('UPDATE marketing_flows SET name=?,draft_json=?,version=version+1 WHERE id=? AND audience_id=?',[$name,json_encode($d,JSON_THROW_ON_ERROR),$id,$this->audienceId]);return $id;}
            $this->write('INSERT INTO marketing_flows(audience_id,name,draft_json,cursor_json,paused_nodes_json) VALUES (?,?,?,JSON_OBJECT(),JSON_ARRAY())',[$this->audienceId,$name,json_encode($d,JSON_THROW_ON_ERROR)]);return (int)$this->db->insert_id;
        });};return $id?$this->flowLock($id,$operation):$operation();
    }
    public function flowOperation(int $id,int $version,string $op,string $node=''): int
    {
        return $this->flowLock($id,function()use($id,$version,$op,$node):int{return $this->transaction(function()use($id,$version,$op,$node):int{
            $row=$this->flow($id,true,$op==='restore');if((int)$row['version']!==$version)throw new InvalidArgumentException('This flow changed. Reload before trying again.');
            if($op==='replicate')return $this->saveFlow(mb_substr($row['name'].' (copy)',0,100),$row['document']);
            if($op==='activate'){
                if(function_exists('isRestrictedDemoSession')&&isRestrictedDemoSession()||function_exists('accountSecurityAccessAllowed')&&!accountSecurityAccessAllowed())throw new InvalidArgumentException('Starting a flow requires an unrestricted editor or administrator.');
                if($row['state']==='running')throw new InvalidArgumentException('This flow is already active.');
                if(($row['document']['trigger']['type']??'')==='flow'&&(int)($row['document']['trigger']['id']??0)===$id)throw new InvalidArgumentException('A flow cannot trigger itself.');
                $d=audienceFlowDocument($row['document']);$errors=$this->flowValidate($d,true);if($errors)throw new InvalidArgumentException(implode(' ',$errors));
                // Snapshot designs and sender binding once. Later draft changes cannot change an enrolled person's path.
                $d['email_snapshots']=[];$audience=$this->one('SELECT * FROM marketing_audiences WHERE id=?',[$this->audienceId]);
                foreach($d['nodes']as$n)if($n['type']==='email'){
                    $campaign=$this->campaign($n['campaign_id']);$draft=$campaign['draft'];$draft['send_time']=['mode'=>'now'];$draft['resend']=['enabled'=>false];unset($draft['experiment']);
                    $links=[];foreach($this->all("SELECT public_key,kind FROM marketing_collection_resources WHERE audience_id=? AND state='published' AND deleted_at IS NULL AND kind IN ('survey','preferences')",[$this->audienceId])as$r)$links[$r['public_key']]=$r['kind'];
                    $d['email_snapshots'][$n['id']]=['draft'=>$draft,'type'=>$campaign['campaign_type'],'identity'=>$this->designRecipientContext(0,$audience),'ses'=>audienceSesBinding($this->sesSettings()),'base'=>audienceDispatchBase(accountPrimaryPublicUrl()),'collection_links'=>$links,'campaign_id'=>$n['campaign_id'],'campaign_version'=>(int)$campaign['version']];
                }
                $encoded=json_encode($d,JSON_THROW_ON_ERROR);$old=$row['published_revision']?$this->one('SELECT document_json FROM marketing_flow_revisions WHERE audience_id=? AND flow_id=? AND id=?',[$this->audienceId,$id,$row['published_revision']]):null;
                if(!$old||audienceJson($old['document_json'])!=$d){$this->write('INSERT INTO marketing_flow_revisions(audience_id,flow_id,document_json,actor_label) VALUES (?,?,?,?)',[$this->audienceId,$id,$encoded,$this->actorLabel]);$revision=(int)$this->db->insert_id;}else $revision=(int)$row['published_revision'];
                $cursor=audienceJson($row['cursor_json']);if(!$row['published_revision'])$cursor=$this->flowCursor();
                $this->write("UPDATE marketing_flows SET state='running',published_revision=?,cursor_json=?,version=version+1 WHERE id=? AND audience_id=?",[$revision,json_encode($cursor,JSON_THROW_ON_ERROR),$id,$this->audienceId]);
            }elseif($op==='pause')$this->write("UPDATE marketing_flows SET state='paused',version=version+1 WHERE audience_id=? AND id=?",[$this->audienceId,$id]);
            elseif(in_array($op,['pause_node','resume_node'],true)){
                if(!in_array($node,array_column($row['document']['nodes'],'id'),true))throw new InvalidArgumentException('Choose an available step.');$nodes=$row['paused_nodes'];$nodes=$op==='pause_node'?array_values(array_unique([...$nodes,$node])):array_values(array_diff($nodes,[$node]));$this->write('UPDATE marketing_flows SET paused_nodes_json=?,version=version+1 WHERE audience_id=? AND id=?',[json_encode($nodes,JSON_THROW_ON_ERROR),$this->audienceId,$id]);
            }elseif($op==='remove'){
                $this->write("UPDATE marketing_flows SET state='paused',deleted_at=UTC_TIMESTAMP(),version=version+1 WHERE audience_id=? AND id=?",[$this->audienceId,$id]);
                $this->write("UPDATE marketing_flow_runs SET state='exited',ended_at=UTC_TIMESTAMP(),error_message='Flow removed' WHERE audience_id=? AND flow_id=? AND state IN ('active','failed')",[$this->audienceId,$id]);
                $this->write("UPDATE marketing_campaign_deliveries d JOIN marketing_flow_runs r ON r.id=d.flow_run_id SET d.state='cancelled',d.version=d.version+1 WHERE r.audience_id=? AND r.flow_id=? AND d.state IN ('scheduled','sending','paused')",[$this->audienceId,$id]);
                $this->write("UPDATE marketing_campaign_recipients p JOIN marketing_campaign_deliveries d ON d.id=p.delivery_id JOIN marketing_flow_runs r ON r.id=d.flow_run_id SET p.state='cancelled' WHERE r.audience_id=? AND r.flow_id=? AND p.state='queued'",[$this->audienceId,$id]);
            }elseif($op==='restore')$this->write("UPDATE marketing_flows SET state='draft',deleted_at=NULL,published_revision=NULL,cursor_json=JSON_OBJECT(),version=version+1 WHERE audience_id=? AND id=?",[$this->audienceId,$id]);
            else throw new InvalidArgumentException('Choose a supported flow action.');
            return $id;
        });});
    }
    public function flowCursor(): array
    {
        return ['contact'=>(int)$this->one('SELECT COALESCE(MAX(e.id),0) n FROM marketing_contact_events e JOIN marketing_contacts c ON c.id=e.contact_id WHERE c.audience_id=?',[$this->audienceId])['n'],
            'message'=>(int)$this->one('SELECT COALESCE(MAX(id),0) n FROM marketing_message_events WHERE audience_id=?',[$this->audienceId])['n'],
            'survey'=>(int)$this->one('SELECT COALESCE(MAX(id),0) n FROM marketing_collection_responses WHERE audience_id=?',[$this->audienceId])['n'],'date'=>0];
    }
    public function flowMatches(int $person,array $rules): bool
    {
        [$where,$params]=$this->where(audienceListFilters(['archived'=>'all']),$rules);
        return (bool)$this->one('SELECT c.id FROM marketing_contacts c WHERE '.$where.' AND c.id=?',[...$params,$person]);
    }
    public function flowReport(int $id,array $input=[]): array
    {
        $this->flow($id);$node=audienceText($input,'node',40);$state=audienceText($input,'state',20);$page=max(1,(int)($input['page']??1));
        $where='r.audience_id=? AND r.flow_id=?';$params=[$this->audienceId,$id];
        if(in_array($state,['active','completed','exited','failed'],true)){$where.=' AND r.state=?';$params[]=$state;}
        if($node!==''){$where.=' AND EXISTS(SELECT 1 FROM marketing_flow_steps s WHERE s.run_id=r.id AND s.node_key=?)';$params[]=$node;}
        $total=(int)$this->one('SELECT COUNT(*) n FROM marketing_flow_runs r WHERE '.$where,$params)['n'];$page=min($page,max(1,(int)ceil($total/25)));
        $runs=$this->all('SELECT r.*,c.email,c.first_name,c.last_name,c.deleted_at FROM marketing_flow_runs r JOIN marketing_contacts c ON c.id=r.contact_id WHERE '.$where.' ORDER BY r.id DESC LIMIT 25 OFFSET ?',[...$params,($page-1)*25]);
        foreach($runs as&$run)$run['steps']=$this->all('SELECT node_key,state,result_json,created_at,completed_at FROM marketing_flow_steps WHERE audience_id=? AND run_id=? ORDER BY id',[$this->audienceId,$run['id']]);unset($run);
        return ['totals'=>$this->all('SELECT state,COUNT(*) n FROM marketing_flow_runs WHERE audience_id=? AND flow_id=? GROUP BY state',[$this->audienceId,$id]),
            'nodes'=>$this->all('SELECT s.node_key,s.state,COUNT(*) n FROM marketing_flow_steps s JOIN marketing_flow_runs r ON r.id=s.run_id WHERE r.audience_id=? AND r.flow_id=? GROUP BY s.node_key,s.state',[$this->audienceId,$id]),'runs'=>$runs,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/25))];
    }
}
