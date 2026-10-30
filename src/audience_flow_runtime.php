<?php
declare(strict_types=1);
require_once __DIR__.'/audience_flow_store.php';
require_once __DIR__.'/audience_store.php';

/** Read only native, audience-bound events. Cursors and enrollments commit together. */
function audienceFlowEvents(AudienceStore $store,array $cursor,int $limit=100,?int $contact=null): array
{
    $aid=$store->audienceId;$limit=max(1,min(200,$limit));$events=[];
    $extra=$contact!==null?' AND c.id=?':'';$person=$contact!==null?[$contact]:[];
    foreach($store->all('SELECT e.id,e.contact_id,e.event_type,e.details_json FROM marketing_contact_events e JOIN marketing_contacts c ON c.id=e.contact_id WHERE c.audience_id=? AND e.id>?'.$extra.' ORDER BY e.id LIMIT '.$limit,[$aid,(int)($cursor['contact']??0),...$person])as$r)$events[]=['source'=>'contact','id'=>(int)$r['id'],'contact'=>(int)$r['contact_id'],'type'=>$r['event_type'],'details'=>audienceJson($r['details_json'])];
    foreach($store->all('SELECT e.id,r.contact_id,e.event_type,e.details_json,d.campaign_id FROM marketing_message_events e JOIN marketing_campaign_recipients r ON r.id=e.recipient_id AND r.audience_id=e.audience_id JOIN marketing_campaign_deliveries d ON d.id=r.delivery_id AND d.audience_id=r.audience_id JOIN marketing_contacts c ON c.id=r.contact_id AND c.audience_id=r.audience_id WHERE e.audience_id=? AND e.id>?'.$extra.' ORDER BY e.id LIMIT '.$limit,[$aid,(int)($cursor['message']??0),...$person])as$r)$events[]=['source'=>'message','id'=>(int)$r['id'],'contact'=>(int)$r['contact_id'],'type'=>$r['event_type'],'details'=>audienceJson($r['details_json'])+['campaign_id'=>(int)$r['campaign_id']]];
    foreach($store->all("SELECT r.id,r.contact_id,r.resource_id FROM marketing_collection_responses r JOIN marketing_contacts c ON c.id=r.contact_id AND c.audience_id=r.audience_id JOIN marketing_collection_resources f ON f.id=r.resource_id AND f.audience_id=r.audience_id WHERE r.audience_id=? AND r.id>? AND f.kind='survey'".$extra.' ORDER BY r.id LIMIT '.$limit,[$aid,(int)($cursor['survey']??0),...$person])as$r)$events[]=['source'=>'survey','id'=>(int)$r['id'],'contact'=>(int)$r['contact_id'],'type'=>'survey','details'=>['resource_id'=>(int)$r['resource_id']]];
    return $events;
}

function audienceFlowEnroll(AudienceStore $store,array $flow,array $d,int $person,string $key,int $now): ?int
{
    $p=$store->one('SELECT id,email_status,is_archived,deleted_at FROM marketing_contacts WHERE audience_id=? AND id=? FOR UPDATE',[$store->audienceId,$person]);
    if(!$p||$p['deleted_at']||$p['is_archived']||!$store->flowMatches($person,$d['entry']))return null;
    $prior=$store->one('SELECT id,state,started_at FROM marketing_flow_runs WHERE audience_id=? AND flow_id=? AND contact_id=? ORDER BY id DESC LIMIT 1',[$store->audienceId,$flow['id'],$person]);
    if($prior&&(!$d['settings']['repeat']||$prior['state']==='active'||$prior['state']==='failed'||strtotime($prior['started_at'].' UTC')+$d['settings']['cooldown']*86400>$now))return null;
    if($store->one('SELECT id FROM marketing_flow_runs WHERE flow_id=? AND contact_id=? AND entry_key=?',[$flow['id'],$person,$key]))return null;
    // Prevent cascaded flows from creating unbounded activity in one day.
    if((int)$store->one('SELECT COUNT(*) n FROM marketing_flow_runs WHERE audience_id=? AND contact_id=? AND started_at>=?',[$store->audienceId,$person,gmdate('Y-m-d H:i:s',$now-86400)])['n']>=100)return null;
    $store->write('INSERT INTO marketing_flow_runs(audience_id,flow_id,revision_id,contact_id,entry_key,node_key,due_at) VALUES (?,?,?,?,?,?,?)',[$store->audienceId,$flow['id'],$flow['published_revision'],$person,$key,$d['start'],gmdate('Y-m-d H:i:s',$now)]);$id=(int)$store->db->insert_id;
    $store->event($person,'flow_started',['flow_id'=>(int)$flow['id'],'run_id'=>$id]);return $id;
}

function audienceFlowDateEntries(AudienceStore $store,array $flow,array $d,array &$cursor,int $now): void
{
    $people=$store->all('SELECT id,created_at,custom_json,timezone FROM marketing_contacts WHERE audience_id=? AND id>? AND deleted_at IS NULL AND is_archived=0 ORDER BY id LIMIT 100',[$store->audienceId,(int)($cursor['date']??0)]);
    foreach($people as$p){
        $cursor['date']=(int)$p['id'];$s=$d['settings'];$tz=$s['recipient_timezone']&&in_array($p['timezone'],DateTimeZone::listIdentifiers(),true)?$p['timezone']:$s['timezone'];$zone=new DateTimeZone($tz);$local=(new DateTimeImmutable('@'.$now))->setTimezone($zone);$t=$d['trigger'];
        if($local->format('H:i')<$t['time'])continue;
        $value=$t['type']==='anniversary'?substr($p['created_at'],0,10):(audienceJson($p['custom_json'])[$t['field']]??'');if(!is_string($value)||$value==='')continue;
        if($t['type']==='date'){
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value))continue;$dates=[$value];
        }else{
            $md=preg_match('/^(?:\d{4}-)?(\d{2}-\d{2})$/D',$value,$match)?$match[1]:'';if($md==='')continue;$year=(int)$local->format('Y');$dates=[];foreach([$year-1,$year,$year+1]as$y){$date=$y.'-'.$md;if($md==='02-29'&&!checkdate(2,29,$y))$date=$y.'-02-28';$dates[]=$date;}
        }
        foreach($dates as$date){$target=(new DateTimeImmutable($date.' '.$t['time'],$zone))->modify(($t['offset']>=0?'+':'').$t['offset'].' days');if($target->format('Y-m-d')===$local->format('Y-m-d'))audienceFlowEnroll($store,$flow,$d,(int)$p['id'],'date:'.$target->format('Y-m-d'),$now);}
    }
    if(count($people)<100)$cursor['date']=0;
}

function audienceFlowQueueEmail(AudienceStore $store,array $run,array $node,array $d,array $person,int $now): int
{
    $snapshot=$d['email_snapshots'][$node['id']]??null;if(!$snapshot)throw new InvalidArgumentException('This email has no activated snapshot.');
    $snapshot['asset_key']=bin2hex(random_bytes(32));$snapshot['tracking_key']=bin2hex(random_bytes(32));
    $context=['EMAIL'=>$person['email'],'FNAME'=>$person['first_name'],'LNAME'=>$person['last_name'],'SOURCE'=>$person['source'],'EMAIL_STATUS'=>$person['email_status']];
    foreach($store->fields()as$f){$value=$person['custom'][$f['id']]??$f['default_value'];if($value===''||$value===[])$value=$f['default_value'];$context[$f['merge_tag']]=is_array($value)?implode(', ',$value):(string)$value;}
    foreach($store->tags(true)as$t)$context['tag:'.$t['id']]=in_array((int)$t['id'],$person['tag_ids'],true)?'1':'';
    foreach($store->groups(true)as$g)foreach($g['options']as$o)$context['group:'.$o['id']]=in_array((int)$o['id'],$person['group_ids'],true)?'1':'';
    $token=bin2hex(random_bytes(32));$mid='<flow-'.bin2hex(random_bytes(24)).'@'.parse_url($snapshot['base'],PHP_URL_HOST).'>';
    audienceDispatchMessage($snapshot,$context,$token,$mid);
    $store->write("INSERT INTO marketing_campaign_deliveries(audience_id,campaign_id,confirmed_version,state,due_at,timezone,snapshot_ciphertext,asset_key,recipient_count,actor_id,actor_label,flow_run_id,flow_node) VALUES (?,?,?,'sending',?,?,?,?,1,0,'Automation flow',?,?)",[$store->audienceId,$snapshot['campaign_id'],$snapshot['campaign_version'],gmdate('Y-m-d H:i:s',$now),$d['settings']['timezone'],\Dnr\Security\ApplicationKey::seal(json_encode($snapshot,JSON_THROW_ON_ERROR)),$snapshot['asset_key'],$run['id'],$node['id']]);$delivery=(int)$store->db->insert_id;
    $store->write('INSERT INTO marketing_campaign_recipients(audience_id,delivery_id,contact_id,email_hash,context_ciphertext,token_hash,message_id,next_attempt_at) VALUES (?,?,?,?,?,?,?,?)',[$store->audienceId,$delivery,$person['id'],audienceDispatchEmailHash($person['email']),\Dnr\Security\ApplicationKey::seal(json_encode(['context'=>$context,'token'=>$token],JSON_THROW_ON_ERROR)),hash('sha256',$token),$mid,gmdate('Y-m-d H:i:s',$now)]);
    return $delivery;
}

function audienceFlowFinish(AudienceStore $store,array $run,string $state,string $reason,int $now): void
{
    $store->write('UPDATE marketing_flow_runs SET state=?,ended_at=?,error_message=? WHERE audience_id=? AND id=?',[$state,gmdate('Y-m-d H:i:s',$now),$reason,$store->audienceId,$run['id']]);
    $store->event((int)$run['contact_id'],$state==='completed'?'flow_completed':'flow_exited',['flow_id'=>(int)$run['flow_id'],'run_id'=>(int)$run['id'],'reason'=>$reason]);
    if($state==='exited'){
        $store->write("UPDATE marketing_campaign_deliveries SET state='cancelled',version=version+1 WHERE audience_id=? AND flow_run_id=? AND state IN ('scheduled','sending','paused')",[$store->audienceId,$run['id']]);
        $store->write("UPDATE marketing_campaign_recipients p JOIN marketing_campaign_deliveries d ON d.id=p.delivery_id SET p.state='cancelled' WHERE d.audience_id=? AND d.flow_run_id=? AND p.state='queued'",[$store->audienceId,$run['id']]);
    }
}

function audienceFlowAdvance(AudienceStore $store,array $flow,array $run,array $d,int $now): void
{
    $p=$store->one('SELECT * FROM marketing_contacts WHERE audience_id=? AND id=? FOR UPDATE',[$store->audienceId,$run['contact_id']]);
    if(!$p||$p['deleted_at']||$p['is_archived']||strtotime($run['started_at'].' UTC')+$d['settings']['max_days']*86400<$now||(!empty($d['exit']['rules'])&&$store->flowMatches((int)$p['id'],$d['exit']))){audienceFlowFinish($store,$run,'exited','Exit conditions met',$now);return;}
    $map=array_column($d['nodes'],null,'id');$node=$map[$run['node_key']]??null;
    if(!$node){audienceFlowFinish($store,$run,'completed','',$now);return;}
    if(in_array($node['id'],$flow['paused_nodes'],true))return;
    $person=$store->person((int)$p['id']);$step=$store->one('SELECT * FROM marketing_flow_steps WHERE audience_id=? AND run_id=? AND node_key=? FOR UPDATE',[$store->audienceId,$run['id'],$node['id']]);$result=$step?audienceJson($step['result_json']):[];
    if($step&&in_array($step['state'],['completed','skipped'],true))throw new RuntimeException('A recorded step cannot run twice.');
    $next=$node['next'];$state='completed';$waiting=false;
    switch($node['type']){
        case 'delay':
            if(!$step){$result=['until'=>$now+$node['seconds']];$waiting=true;}elseif((int)$result['until']>$now)$waiting=true;
            break;
        case 'until':
            if(!$step){$settings=$d['settings'];$settings['after']=$node['time'];$settings['before']='23:59';if($settings['after']==='23:59')$settings['before']='23:59';$result=['until'=>audienceFlowWindow($settings,$person['timezone'],$now)];$waiting=$result['until']>$now;}
            elseif((int)$result['until']>$now)$waiting=true;
            break;
        case 'wait':
            if(!$step){
                $cursor=$store->flowCursor();
                // A fast recipient may click before the worker advances from
                // the email receipt into its wait step. Retain that activity
                // for an immediately following wait on the same email.
                if(in_array($node['trigger']['type'],['open','click'],true))foreach($d['nodes']as$previous){
                    if($previous['type']!=='email'||$previous['next']!==$node['id']||((int)($node['trigger']['id']??0)!==0&&(int)$node['trigger']['id']!==$previous['campaign_id']))continue;
                    $prior=$store->one("SELECT result_json FROM marketing_flow_steps WHERE audience_id=? AND run_id=? AND node_key=? AND state='completed'",[$store->audienceId,$run['id'],$previous['id']]);
                    $priorResult=$prior?audienceJson($prior['result_json']):[];
                    if(isset($priorResult['event_cursor']))$cursor=$priorResult['event_cursor'];
                }
                $result=['cursor'=>$cursor,'until'=>$now+$node['timeout']];$waiting=true;
            }
            else{
                $found=false;$cursor=$result['cursor'];foreach(audienceFlowEvents($store,$cursor,100,(int)$p['id'])as$event){$cursor[$event['source']]=$event['id'];if(audienceFlowEventMatches($node['trigger'],$event))$found=true;}
                $result['cursor']=$cursor;if($found){$next=$node['yes'];$result['path']='yes';}elseif($now>=(int)$result['until']){$next=$node['no'];$result['path']='no';}else $waiting=true;
            }break;
        case 'condition':$result=['path'=>$store->flowMatches((int)$p['id'],$node['rules'])?'yes':'no'];$next=$node[$result['path']];break;
        case 'split':$bucket=hexdec(substr(hash('sha256',$run['id'].':'.$run['revision_id'].':'.$node['id']),0,8))%100;$result=['bucket'=>$bucket,'path'=>$bucket<$node['percent']?'yes':'no'];$next=$node[$result['path']];break;
        case 'tag':case 'group':
            $kind=$node['type'];$key=$kind==='tag'?'tag_id':'option_id';$ids=$kind==='tag'?$person['tag_ids']:$person['group_ids'];$present=in_array((int)$node[$key],$ids,true);
            if($present!==!$node['remove'])$store->mutatePerson((int)$p['id'],(int)$p['version'],$node['remove']?'un'.$kind:$kind,[$key=>$node[$key]]);
            $result=['changed'=>$present!==!$node['remove']];break;
        case 'update':
            $field=$node['field'];$value=$node['value'];
            if(in_array($field,['first_name','last_name','language','timezone'],true)){
                $changed=$person[$field]!==$value;if($changed)$store->write('UPDATE marketing_contacts SET '.$field.'=?,version=version+1 WHERE audience_id=? AND id=?',[$value,$store->audienceId,$p['id']]);
            }else{
                $definition=$store->one('SELECT * FROM marketing_fields WHERE audience_id=? AND id=?',[$store->audienceId,(int)$field]);if(!$definition)throw new InvalidArgumentException('The updated field is unavailable.');$custom=$person['custom'];$typed=audienceFieldValue($definition,$value);$changed=($custom[$field]??'')!==$typed;
                if($changed){$custom[$field]=$typed;$store->write('UPDATE marketing_contacts SET custom_json=?,version=version+1 WHERE audience_id=? AND id=?',[json_encode((object)$custom,JSON_THROW_ON_ERROR),$store->audienceId,$p['id']]);}
            }
            if($changed)$store->event((int)$p['id'],'contact_updated',['changed_fields'=>[$field],'flow_id'=>(int)$flow['id']]);$result=['field'=>$field,'changed'=>$changed];break;
        case 'unsubscribe':case 'archive':$store->mutatePerson((int)$p['id'],(int)$p['version'],$node['type']);$result=['changed'=>true];break;
        case 'exit':audienceFlowFinish($store,$run,'exited','Exit step',$now);$result=['exit'=>true];break;
        case 'email':
            $optout=$store->one('SELECT email_hash FROM marketing_email_optouts WHERE audience_id=? AND email_hash=?',[$store->audienceId,audienceDispatchEmailHash($p['email'])])
                || audienceDeletedEmailSuppressed($store->db,$store->audienceId,(int)$p['id'],audienceDispatchEmailHash($p['email']));
            if($p['email_status']!=='subscribed'||$optout){$state='skipped';$result=['reason'=>'No longer subscribed'];break;}
            if(!$step){$due=audienceFlowWindow($d['settings'],$person['timezone'],$now);if($due>$now){$store->write('UPDATE marketing_flow_runs SET due_at=? WHERE id=?',[gmdate('Y-m-d H:i:s',$due),$run['id']]);return;}$result=['delivery_id'=>audienceFlowQueueEmail($store,$run,$node,$d,$person,$now),'event_cursor'=>$store->flowCursor()];$waiting=true;}
            else{
                $receipt=$store->one('SELECT state,error_message FROM marketing_campaign_recipients WHERE audience_id=? AND delivery_id=?',[$store->audienceId,$result['delivery_id']]);
                if(!$receipt)throw new RuntimeException('The email receipt is unavailable.');
                if(in_array($receipt['state'],['queued','processing'],true))$waiting=true;
                elseif($receipt['state']==='accepted'){$result['receipt']='accepted';$store->write("UPDATE marketing_campaign_deliveries SET state='completed',completed_at=UTC_TIMESTAMP(),version=version+1 WHERE audience_id=? AND id=? AND flow_run_id=?",[$store->audienceId,$result['delivery_id'],$run['id']]);}
                elseif(in_array($receipt['state'],['suppressed','cancelled'],true)){$state='skipped';$result['receipt']=$receipt['state'];$store->write("UPDATE marketing_campaign_deliveries SET state='completed',completed_at=UTC_TIMESTAMP() WHERE audience_id=? AND id=?",[$store->audienceId,$result['delivery_id']]);}
                else{$state='failed';$result['receipt']=$receipt['state'];$store->write("UPDATE marketing_flow_runs SET state='failed',error_message='Email delivery needs review; an uncertain receipt must never be replayed.' WHERE id=?",[$run['id']]);}
            }break;
    }
    if($waiting)$state='waiting';$previous=$step?audienceJson($step['result_json']):[];$history=$previous['history']??[];if(!$history||$history[count($history)-1]['state']!==$state)$history[]=['state'=>$state,'at'=>gmdate(DATE_ATOM,$now)];$result['history']=$history;$encoded=json_encode($result,JSON_THROW_ON_ERROR);
    $store->write('INSERT INTO marketing_flow_steps(audience_id,run_id,node_key,state,result_json,completed_at) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE state=VALUES(state),result_json=VALUES(result_json),completed_at=VALUES(completed_at)',[$store->audienceId,$run['id'],$node['id'],$state,$encoded,$waiting?null:gmdate('Y-m-d H:i:s',$now)]);
    if($node['type']==='archive'){audienceFlowFinish($store,$run,'exited','Connection archived',$now);return;}
    if($node['type']==='exit'||$state==='failed')return;
    if($waiting){$due=in_array($node['type'],['wait','email'],true)?$now+30:(int)$result['until'];$store->write('UPDATE marketing_flow_runs SET due_at=? WHERE id=?',[gmdate('Y-m-d H:i:s',$due),$run['id']]);return;}
    $store->write('UPDATE marketing_flow_runs SET node_key=?,due_at=?,error_message=\'\' WHERE id=?',[$next,gmdate('Y-m-d H:i:s',$now),$run['id']]);if($next==='')audienceFlowFinish($store,$run,'completed','',$now);
}

/** One bounded pass; real sending remains exclusively in the SES dispatch worker. */
function processAudienceFlows(mysqli $db,int $limit=25,?int $now=null): int
{
    if(!audienceFlowsInstalled($db)||function_exists('accountSecurityAccessAllowed')&&!accountSecurityAccessAllowed())return 0;
    $now??=time();$store=new AudienceStore($db,1,0,'Automation flow');$count=0;
    $flows=$store->all("SELECT id FROM marketing_flows WHERE audience_id=1 AND state='running' AND deleted_at IS NULL ORDER BY id LIMIT 100");
    foreach($flows as$record)$store->flowLock((int)$record['id'],function()use($store,$record,$now,$limit,&$count):void{
        $flow=$store->flow((int)$record['id']);if($flow['state']!=='running')return;
        $row=$store->one('SELECT document_json FROM marketing_flow_revisions WHERE audience_id=1 AND flow_id=? AND id=?',[$flow['id'],$flow['published_revision']]);if(!$row)return;$d=audienceJson($row['document_json']);
        $store->transaction(function()use($store,$flow,$d,$now):void{
            $cursor=audienceJson($flow['cursor_json']);
            $triggers=[$d['trigger'],...($d['additional_triggers']??[])];
            foreach($triggers as$index=>$trigger)if(in_array($trigger['type'],['date','birthday','anniversary'],true)){
                $dateKey=$index?'date_'.$index:'date';$dateCursor=['date'=>$cursor[$dateKey]??0];
                audienceFlowDateEntries($store,$flow,array_replace($d,['trigger'=>$trigger]),$dateCursor,$now);$cursor[$dateKey]=$dateCursor['date'];
            }
            foreach(audienceFlowEvents($store,$cursor)as$event){
                foreach($triggers as$trigger)if(audienceFlowEventMatches($trigger,$event)){audienceFlowEnroll($store,$flow,$d,$event['contact'],$event['source'].':'.$event['id'],$now);break;}
                $cursor[$event['source']]=$event['id'];
            }
            $store->write('UPDATE marketing_flows SET cursor_json=? WHERE audience_id=1 AND id=?',[json_encode($cursor,JSON_THROW_ON_ERROR),$flow['id']]);
        });
        for($i=0;$i<max(1,min(100,$limit));$i++){
            $run=$store->one("SELECT * FROM marketing_flow_runs WHERE audience_id=1 AND flow_id=? AND state='active' AND due_at<=? AND (node_key='' OR NOT JSON_CONTAINS(?,JSON_QUOTE(node_key))) ORDER BY due_at,id LIMIT 1",[$flow['id'],gmdate('Y-m-d H:i:s',$now),json_encode($flow['paused_nodes'],JSON_THROW_ON_ERROR)]);if(!$run)break;
            try{$store->transaction(function()use($store,$flow,$run,$now):void{$current=$store->one('SELECT * FROM marketing_flow_runs WHERE audience_id=1 AND id=? FOR UPDATE',[$run['id']]);if($current['state']!=='active')return;$revision=$store->one('SELECT document_json FROM marketing_flow_revisions WHERE audience_id=1 AND flow_id=? AND id=?',[$flow['id'],$current['revision_id']]);if(!$revision)throw new RuntimeException('The execution revision is unavailable.');audienceFlowAdvance($store,$flow,$current,audienceJson($revision['document_json']),$now);});}
            catch(Throwable){
                $store->transaction(function()use($store,$run,$now):void{
                    $store->write("UPDATE marketing_flow_runs SET state='failed',error_message='This step could not complete. Review its configuration before retrying.' WHERE audience_id=1 AND id=?",[$run['id']]);
                    $prior=$store->one('SELECT result_json FROM marketing_flow_steps WHERE audience_id=1 AND run_id=? AND node_key=?',[$run['id'],$run['node_key']]);$result=$prior?audienceJson($prior['result_json']):[];$result['history'][]=['state'=>'failed','at'=>gmdate(DATE_ATOM,$now)];$result['reason']='Step could not complete';
                    $store->write("INSERT INTO marketing_flow_steps(audience_id,run_id,node_key,state,result_json,completed_at) VALUES (1,?,?,'failed',?,?) ON DUPLICATE KEY UPDATE state='failed',result_json=VALUES(result_json),completed_at=VALUES(completed_at)",[$run['id'],$run['node_key'],json_encode($result,JSON_THROW_ON_ERROR),gmdate('Y-m-d H:i:s',$now)]);
                });
            }
            $count++;
        }
    });return $count;
}

/** Called while the same flow lock used by pause/remove is held, immediately before dispatch. */
function audienceFlowDeliveryAllowed(mysqli $db,array $delivery): bool
{
    if(empty($delivery['flow_run_id']))return true;
    $store=new AudienceStore($db,1,0,'Automation flow');$run=$store->one('SELECT r.*,f.state flow_state,f.deleted_at,f.paused_nodes_json FROM marketing_flow_runs r JOIN marketing_flows f ON f.id=r.flow_id AND f.audience_id=r.audience_id WHERE r.audience_id=1 AND r.id=?',[$delivery['flow_run_id']]);
    if(!$run||$run['state']!=='active'||$run['flow_state']!=='running'||$run['deleted_at']||$run['node_key']!==$delivery['flow_node']||in_array($delivery['flow_node'],audienceJson($run['paused_nodes_json']),true))return false;
    $revision=$store->one('SELECT document_json FROM marketing_flow_revisions WHERE audience_id=1 AND flow_id=? AND id=?',[$run['flow_id'],$run['revision_id']]);if(!$revision)return false;$d=audienceJson($revision['document_json']);
    if(strtotime($run['started_at'].' UTC')+$d['settings']['max_days']*86400<time()||(!empty($d['exit']['rules'])&&$store->flowMatches((int)$run['contact_id'],$d['exit']))){audienceFlowFinish($store,$run,'exited','Exit conditions met',time());return false;}
    return true;
}
