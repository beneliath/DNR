<?php
/** Fictional, disconnected reporting evidence for disposable tests and explicit localhost seeding. */
function audienceAnalyticsReportFixture(AudienceStore $s,array $people,array $campaigns):array
{
    $aid=$s->audienceId;
    // Enrich existing fictional observations without changing their counts or bot flags.
    foreach($s->all("SELECT e.id,e.event_type,e.event_at,e.source,e.details_json,r.contact_id,d.campaign_id FROM marketing_message_events e JOIN marketing_campaign_recipients r ON r.id=e.recipient_id JOIN marketing_campaign_deliveries d ON d.id=r.delivery_id WHERE e.audience_id=? AND e.event_type IN ('open','click') AND JSON_UNQUOTE(JSON_EXTRACT(e.details_json,'$.sample'))='true' AND JSON_EXTRACT(e.details_json,'$.device') IS NULL",[$aid])as$r){
        $n=array_search((int)$r['contact_id'],$people,true);$country=['IL','US','GB','Unknown'][($n===false?0:$n)%4];$device=['Mobile','Desktop','Tablet'][($n===false?0:$n)%3];
        $details=['country'=>$country,'device'=>$device,'client'=>$device==='Mobile'?'Safari':'Firefox'];
        if($r['event_type']==='click')$details+=['link_hash'=>hash('sha256','https://example.test/sample/'.$r['campaign_id']),'link_label'=>'example.test/sample/'.$r['campaign_id']];
        $details+=audienceJson($r['details_json']);$key=hash('sha256','stage7-local-evidence-'.$r['id']);
        $s->write('INSERT IGNORE INTO marketing_message_events(audience_id,recipient_id,event_type,event_key,source,event_at,details_json) SELECT audience_id,recipient_id,event_type,?,source,event_at,? FROM marketing_message_events WHERE audience_id=? AND id=?',[$key,json_encode($details),$aid,$r['id']]);
    }
    foreach($people as$i=>$pid){
        $at=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('-'.(2+$i*4).' days')->setTime(12,0)->format('Y-m-d H:i:s');
        $s->write("UPDATE marketing_contacts SET created_at=? WHERE audience_id=? AND id=? AND source='Local sample data' AND is_archived=1 AND email_status='non-subscribed'",[$at,$aid,$pid]);
        if(!$s->one("SELECT id FROM marketing_contact_events WHERE contact_id=? AND JSON_UNQUOTE(JSON_EXTRACT(details_json,'$.sample_dataset'))='stage7-growth'",[$pid])){
            $s->write("INSERT INTO marketing_contact_events(contact_id,actor_user_id,actor_label,actor_type,event_type,details_json,created_at) VALUES (?,?,'Local sample fixture','system','subscription_changed',?,?)",[$pid,$s->actorId,json_encode(['from'=>'non-subscribed','to'=>$i%4===0?'unsubscribed':'subscribed','sample_dataset'=>'stage7-growth']),$at]);
        }
    }
    foreach($s->all("SELECT d.id,d.campaign_id,d.snapshot_ciphertext FROM marketing_campaign_deliveries d JOIN marketing_campaigns c ON c.id=d.campaign_id AND c.audience_id=d.audience_id WHERE d.audience_id=? AND JSON_UNQUOTE(JSON_EXTRACT(c.draft_json,'$.sample_dataset'))='stage7-local'",[$aid])as$delivery){
        $snapshot=audienceJson(Dnr\Security\ApplicationKey::open($delivery['snapshot_ciphertext']));
        if(($snapshot['sample_dataset']??'')!=='stage7-local')continue;
        $link='https://example.test/sample/'.$delivery['campaign_id'];
        if(!str_contains($snapshot['draft']['text']??'',$link)){$snapshot['draft']['text'].="\n\nExplore the sample update: ".$link;$s->write('UPDATE marketing_campaign_deliveries SET snapshot_ciphertext=? WHERE audience_id=? AND id=?',[Dnr\Security\ApplicationKey::seal(json_encode($snapshot)),$aid,$delivery['id']]);}
    }
    $resources=[];$flows=[];
    foreach(['A community signup form'=>'hosted','Gathering information'=>'landing','Community survey'=>'survey']as$name=>$kind){
        $label='Sample · '.$name;$r=$s->one("SELECT id FROM marketing_collection_resources WHERE audience_id=? AND name=? AND JSON_UNQUOTE(JSON_EXTRACT(draft_json,'$.sample_dataset'))='stage7-local'",[$aid,$label]);
        if(!$r){$doc=audienceCollectionDefaults($kind);$doc['sample_dataset']='stage7-local';$s->write("INSERT INTO marketing_collection_resources(audience_id,kind,name,public_key,draft_json,state) VALUES (?,?,?,?,?,'draft')",[$aid,$kind,$label,bin2hex(random_bytes(32)),json_encode($doc)]);$rid=(int)$s->db->insert_id;$s->write('INSERT INTO marketing_collection_revisions(audience_id,resource_id,document_json) VALUES (?,?,?)',[$aid,$rid,json_encode($doc)]);$revision=(int)$s->db->insert_id;
            foreach(['-5 days'=>6,'-35 days'=>4,'-95 days'=>8]as$offset=>$count){$at=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify($offset)->setTime(12,0)->format('Y-m-d H:i:s');foreach(array_slice($people,0,$count)as$i=>$pid){$hash=hash('sha256',$label.$offset.$i);$s->write('INSERT IGNORE INTO marketing_collection_visits(resource_id,day,visitor_hash) VALUES (?,?,?)',[$rid,substr($at,0,10),$hash]);if($i%2===0)$s->write('INSERT INTO marketing_collection_responses(audience_id,resource_id,revision_id,receipt_hash,visitor_hash,contact_id,payload_ciphertext,created_at) VALUES (?,?,?,?,?,?,?,?)',[$aid,$rid,$revision,hash('sha256','response-'.$hash),$hash,$pid,Dnr\Security\ApplicationKey::seal(json_encode(['sample'=>true,'answers'=>[]])),$at]);if($i===0&&$kind!=='survey')$s->write("INSERT INTO marketing_contact_events(contact_id,actor_user_id,actor_label,actor_type,event_type,details_json,created_at) VALUES (?,?,'Local sample fixture','system','collection-converted',?,?)",[$pid,$s->actorId,json_encode(['resource_id'=>$rid,'sample'=>true]),$at]);}}
        }else$rid=(int)$r['id'];$resources[]=$rid;
    }
    foreach(['Welcome journey','Community follow-up']as$index=>$name){$label='Sample · '.$name;$r=$s->one("SELECT id FROM marketing_flows WHERE audience_id=? AND name=? AND JSON_UNQUOTE(JSON_EXTRACT(draft_json,'$.sample_dataset'))='stage7-local'",[$aid,$label]);
        if(!$r){$doc=audienceFlowDefault();$doc['sample_dataset']='stage7-local';$doc['start']='finish';$doc['nodes']=[['id'=>'finish','type'=>'exit','label'=>'Sample completion','next'=>'']];$s->write("INSERT INTO marketing_flows(audience_id,name,state,draft_json,cursor_json,paused_nodes_json) VALUES (?,?,'paused',?,JSON_OBJECT(),JSON_ARRAY())",[$aid,$label,json_encode($doc)]);$fid=(int)$s->db->insert_id;$s->write('INSERT INTO marketing_flow_revisions(audience_id,flow_id,document_json,actor_label) VALUES (?,?,?,?)',[$aid,$fid,json_encode($doc),'Local sample fixture']);$rev=(int)$s->db->insert_id;
            foreach(['-4 days'=>8,'-34 days'=>6,'-94 days'=>10]as$offset=>$count){$at=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify($offset)->setTime(12,0)->format('Y-m-d H:i:s');foreach(array_slice($people,0,$count)as$i=>$pid){$state=$i%5===0?'failed':($i%4===0?'exited':'completed');$s->write("INSERT INTO marketing_flow_runs(audience_id,flow_id,revision_id,contact_id,entry_key,node_key,state,due_at,started_at,ended_at) VALUES (?,?,?,?,?,'finish',?,?,?,?)",[$aid,$fid,$rev,$pid,'sample-'.$offset.'-'.$i,$state,$at,$at,$at]);}}
        }else$fid=(int)$r['id'];$flows[]=$fid;
    }
    return ['sample_forms'=>$resources,'sample_flows'=>$flows];
}
