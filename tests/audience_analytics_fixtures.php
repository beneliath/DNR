<?php
declare(strict_types=1);
require_once __DIR__.'/audience_analytics_reports_fixtures.php';

/** Explicit local/disposable fixture; never run by a migration or application request. */
function audienceAnalyticsFixture(AudienceStore $store, string $prefix='Sample'): array
{
    $db=$store->db;$aid=$store->audienceId;$campaigns=[];$people=[];
    return $store->transaction(function()use($db,$store,$aid,$prefix,&$campaigns,&$people):array{
        for($person=1;$person<=12;$person++){
            $email='stage7-sample-'.$person.'@example.test';
            $old=$store->one('SELECT id FROM marketing_contacts WHERE audience_id=? AND email=?',[$aid,$email]);
            if(!$old){$store->write("INSERT INTO marketing_contacts(audience_id,email,first_name,last_name,email_status,source,notes,is_archived) VALUES (?,?,?,'Sample','non-subscribed','Local sample data','Fictional archived fixture; never opted in and never sent email.',1)",[$aid,$email,'Person '.$person]);$old=['id'=>(int)$db->insert_id];}
            $people[]=(int)$old['id'];
        }
        foreach(['current','previous','previous90'] as$period) foreach(['Community newsletter','An invitation to connect','A note of thanks'] as$i=>$name){
            $name=$prefix.' · '.$name.($period==='previous'?' · Earlier period':($period==='previous90'?' · Earlier 90-day period':''));
            $existing=$store->one("SELECT id FROM marketing_campaigns WHERE audience_id=? AND name=? AND JSON_UNQUOTE(JSON_EXTRACT(draft_json,'$.sample_dataset'))='stage7-local'",[$aid,$name]);
            if($existing){$campaigns[]=(int)$existing['id'];continue;}
            $at=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('-'.(3+$i*8+($period==='previous'?30:($period==='previous90'?90:0))).' days')->setTime(14,0)->format('Y-m-d H:i:s');
            $recipientCount=$period!=='current'?10:12;
            $draft=['sample_dataset'=>'stage7-local','subject'=>$name,'from_name'=>'Local sample','from_email'=>'sample@example.test','text'=>'Fictional sample content for local viewing.','target'=>'all'];
            $store->write("INSERT INTO marketing_campaigns(audience_id,name,campaign_type,status,draft_json,actor_label,created_at,updated_at,sent_at) VALUES (?,?,'plain-text','sent',?,'Local sample fixture',?,?,?)",[$aid,$name,json_encode($draft,JSON_THROW_ON_ERROR),$at,$at,$at]);$cid=(int)$db->insert_id;$campaigns[]=$cid;
            $snapshot=['draft'=>$draft,'type'=>'plain-text','base'=>'https://example.test','sample_dataset'=>'stage7-local'];
            $store->write("INSERT INTO marketing_campaign_deliveries(audience_id,campaign_id,confirmed_version,state,due_at,timezone,snapshot_ciphertext,asset_key,recipient_count,actor_id,actor_label,created_at,completed_at) VALUES (?,?,1,'completed',?,'UTC',?,?,?,?, 'Local sample fixture',?,?)",[$aid,$cid,$at,Dnr\Security\ApplicationKey::seal(json_encode($snapshot,JSON_THROW_ON_ERROR)),bin2hex(random_bytes(32)),$recipientCount,$store->actorId,$at,$at]);$did=(int)$db->insert_id;
            foreach(array_slice($people,0,$recipientCount) as$j=>$person){
                $email='stage7-sample-'.($j+1).'@example.test';
                $store->write("INSERT INTO marketing_campaign_recipients(audience_id,delivery_id,contact_id,email_hash,context_ciphertext,token_hash,message_id,state,next_attempt_at,accepted_at,phase) VALUES (?,?,?,?,?,?,?,'accepted',?,?,'main')",[$aid,$did,$person,audienceDispatchEmailHash($email),Dnr\Security\ApplicationKey::seal(json_encode(['email'=>$email,'FNAME'=>'Person '.($j+1)],JSON_THROW_ON_ERROR)),bin2hex(random_bytes(32)),'<sample-'.$did.'-'.$j.'@example.test>',$at,$at]);$rid=(int)$db->insert_id;
                $events=['send'];if($period!=='current'||$j<11)$events[]='delivered';else$events[]='hard-bounce';if($j<($period!=='current'?4:8-$i))$events[]='open';if($j<($period!=='current'?2:3-$i))$events[]='click';
                foreach($events as$type){$details=['sample'=>true,'bot'=>$period==='current'&&in_array($type,['open','click'],true)&&$j===0];if(in_array($type,['open','click'],true)){$details+=['country'=>['IL','US','GB','Unknown'][$j%4],'device'=>['Mobile','Desktop','Tablet'][$j%3],'client'=>'Firefox'];if($type==='click')$details+=['link_hash'=>hash('sha256','https://example.test/sample/'.$cid),'link_label'=>'example.test/sample/'.$cid];}$store->write('INSERT INTO marketing_message_events(audience_id,recipient_id,event_type,event_key,source,event_at,details_json) VALUES (?,?,?,?,?,?,?)',[$aid,$rid,$type,bin2hex(random_bytes(32)),in_array($type,['open','click'],true)?'tracking':'provider',$at,json_encode($details,JSON_THROW_ON_ERROR)]);}
                if($period==='previous'&&$i===0&&$j===9)$store->write("INSERT INTO marketing_contact_events(contact_id,actor_user_id,actor_label,actor_type,event_type,details_json,created_at) VALUES (?,?,'Local sample fixture','system','status',?,?)",[$person,$store->actorId,json_encode(['to'=>'unsubscribed','recipient_id'=>$rid,'sample'=>true],JSON_THROW_ON_ERROR),$at]);
            }
        }
        return ['campaigns'=>$campaigns,'archived_fictional_contacts'=>$people]+audienceAnalyticsReportFixture($store,$people,$campaigns);
    });
}
