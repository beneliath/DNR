<?php
declare(strict_types=1);
require_once __DIR__.'/audience_inbox.php';
require_once __DIR__.'/audience_inbox_compose.php';

trait AudienceInbox
{
    use AudienceInboxCompose;
    public function inboxAvailable():bool{return audienceInboxInstalled($this->db);}
    public function inboxSettings():array
    {
        $s=$this->one('SELECT * FROM marketing_inbox_settings WHERE audience_id=?',[$this->audienceId]);
        if(!$s)throw new InvalidArgumentException('Inbox requires the qualified database update.');
        $s['document']=audienceJson($s['contact_document']);return $s;
    }
    public function saveInboxSettings(int $version,array $input):void
    {
        $this->transaction(function()use($version,$input){
            $s=$this->one('SELECT * FROM marketing_inbox_settings WHERE audience_id=? FOR UPDATE',[$this->audienceId]);
            if(!$s||(int)$s['version']!==$version)throw new InvalidArgumentException('Inbox settings changed. Reload before saving.');
            $document=[];foreach(['title'=>150,'intro'=>2000,'button'=>60,'success'=>500]as$key=>$limit){$document[$key]=audienceText($input,$key,$limit);if($document[$key]==='')throw new InvalidArgumentException('Complete the contact form text.');}
            $gateway=($input['gateway_confirmed']??'')==='1';
            if($gateway){$address=(string)(getenv('DNR_INBOUND_ADDRESS')?:'');audienceInboxAddress($address,currentAccountKey(),1,accountMailSigningKey());}
            $contact=($input['contact_enabled']??'')==='1';$token=$s['contact_token'];if($contact&&$token==='')$token=bin2hex(random_bytes(32));
            $this->write('UPDATE marketing_inbox_settings SET campaign_replies=?,survey_replies=?,gateway_confirmed=?,contact_enabled=?,contact_token=?,contact_document=?,version=version+1 WHERE audience_id=?',[(int)(($input['campaign_replies']??'')==='1'),(int)(($input['survey_replies']??'')==='1'),(int)$gateway,(int)$contact,$token,json_encode($document,JSON_THROW_ON_ERROR),$this->audienceId]);
            if($gateway){$this->inboxReplyRoute('email');$this->inboxReplyRoute('direct');}
        });
    }
    /** Prepare an account-bound receiving address without connecting sources or sending mail. */
    public function prepareInboxRoutingTest():string
    {
        $gateway=(string)(getenv('DNR_INBOUND_ADDRESS')?:'');
        audienceInboxAddress($gateway,currentAccountKey(),1,accountMailSigningKey());
        $this->write("INSERT IGNORE INTO marketing_inbox_routes(audience_id,source,reference_id) VALUES (?,'direct',0)",[$this->audienceId]);
        $route=$this->one("SELECT id,enabled FROM marketing_inbox_routes WHERE audience_id=? AND source='direct' AND reference_id=0",[$this->audienceId]);
        if(!$route||!$route['enabled'])throw new InvalidArgumentException('The direct-message route is disconnected.');
        return audienceInboxAddress($gateway,currentAccountKey(),(int)$route['id'],accountMailSigningKey());
    }
    public function inboxRoutingStatus():array
    {
        $status=['configured'=>false,'address'=>'','latest'=>null,'verified_at'=>null];
        $gateway=(string)(getenv('DNR_INBOUND_ADDRESS')?:'');
        try{audienceInboxAddress($gateway,currentAccountKey(),1,accountMailSigningKey());}catch(InvalidArgumentException){return $status;}
        $status['configured']=true;
        $route=$this->one("SELECT id FROM marketing_inbox_routes WHERE audience_id=? AND source='direct' AND reference_id=0 AND enabled=1",[$this->audienceId]);
        if(!$route)return $status;
        $status['address']=audienceInboxAddress($gateway,currentAccountKey(),(int)$route['id'],accountMailSigningKey());
        if(audienceInboxRouteReceiptsInstalled($this->db)){
            $status['latest']=$this->one('SELECT sender_verified,authentication_method,received_at FROM marketing_inbox_route_receipts WHERE audience_id=? AND route_id=? ORDER BY received_at DESC,inbound_id DESC LIMIT 1',[$this->audienceId,$route['id']]);
            $status['verified_at']=$this->one('SELECT MAX(received_at) at FROM marketing_inbox_route_receipts WHERE audience_id=? AND route_id=? AND sender_verified=1',[$this->audienceId,$route['id']])['at']??null;
        }
        return $status;
    }
    public function inboxCampaignReplyTo(array $draft):string
    {
        if(!$this->inboxAvailable())return '';
        $settings=$this->inboxSettings();
        return $settings['gateway_confirmed']&&($settings['campaign_replies']||!empty($draft['tracking']['inbox']))?$this->inboxReplyRoute('email'):'';
    }
    public function inboxSurveyResponse(array $resource,array $answers,?array $contact,int $response):void
    {
        if(!$this->inboxAvailable()||!$this->inboxSettings()['survey_replies'])return;
        $email=$contact['email']??'';$name=$contact?trim($contact['first_name'].' '.$contact['last_name']):'';$lines=[];$hasText=false;
        foreach($resource['document']['questions']as$q){$answer=$answers[$q['id']]??'';
            if($q['type']==='email'&&is_string($answer)&&$answer!=='')$email=$answer;
            if($q['type']==='contact'&&is_array($answer)&&!empty($answer['email'])){$email=$answer['email'];$name=trim(($answer['first_name']??'').' '.($answer['last_name']??''));}
            if($q['type']==='text'&&is_string($answer)&&trim($answer)!=='')$hasText=true;
            if($q['type']!=='content')$lines[]=$q['label'].': '.(is_array($answer)?implode(', ',array_map('strval',$answer)):$answer);
        }
        if(!$hasText||!filter_var($email,FILTER_VALIDATE_EMAIL))return;
        // Only a signed recipient link authenticates an identity; typed email is a claim.
        $verified=$contact&&strtolower($contact['email'])===strtolower($email);
        $this->inboxReceive('survey',(int)$resource['id'],$email,$name,$resource['name'],implode("\n\n",$lines),hash('sha256','survey-response:'.$this->audienceId.':'.$response),(bool)$verified,[],0,[],true);
    }
    public function inboxReplyRoute(string $source,int $reference=0):string
    {
        if(!in_array($source,['email','direct','thread'],true)||$reference<0)throw new InvalidArgumentException('Invalid Inbox reply source.');
        if(empty($this->inboxSettings()['gateway_confirmed']))throw new InvalidArgumentException('An administrator must confirm Inbox reply routing in Sources.');
        $this->write('INSERT IGNORE INTO marketing_inbox_routes(audience_id,source,reference_id) VALUES (?,?,?)',[$this->audienceId,$source,$reference]);
        $route=$this->one('SELECT id,enabled FROM marketing_inbox_routes WHERE audience_id=? AND source=? AND reference_id=?',[$this->audienceId,$source,$reference]);
        if(!$route||!$route['enabled'])throw new InvalidArgumentException('This reply source is disconnected.');
        return audienceInboxAddress((string)(getenv('DNR_INBOUND_ADDRESS')?:''),currentAccountKey(),(int)$route['id'],accountMailSigningKey());
    }
    public function inboxLabels():array
    {
        return $this->all("SELECT l.*,COUNT(t.id) message_count FROM marketing_inbox_labels l LEFT JOIN marketing_inbox_thread_labels tl ON tl.audience_id=l.audience_id AND tl.label_id=l.id LEFT JOIN marketing_inbox_threads t ON t.id=tl.thread_id AND t.audience_id=tl.audience_id AND t.status<>'trash' AND t.last_message_id>0 WHERE l.audience_id=? AND l.deleted_at IS NULL GROUP BY l.id ORDER BY l.name,l.id",[$this->audienceId]);
    }
    public function inboxTemplates():array{return $this->all('SELECT * FROM marketing_inbox_templates WHERE audience_id=? AND deleted_at IS NULL ORDER BY name,id',[$this->audienceId]);}
    public function saveInboxNamed(string $kind,array $input,int $id=0,int $version=0):int
    {
        if(!in_array($kind,['label','template'],true))throw new InvalidArgumentException('Invalid Inbox item.');
        $name=audienceText($input,'name',100);if($name==='')throw new InvalidArgumentException('Enter a name.');
        $subject=$kind==='template'?audienceInboxSubject(audienceText($input,'subject',250)):'';
        $html=$kind==='template'?audienceDesignRichText(audienceText($input,'body_html',30000)):'';
        if($kind==='template'&&audienceInboxPlain($html)==='')throw new InvalidArgumentException('Enter the message template body.');
        $table=$kind==='label'?'marketing_inbox_labels':'marketing_inbox_templates';
        return $this->transaction(function()use($table,$name,$subject,$html,$id,$version,$kind){
            if($id){$row=$this->one("SELECT id,version FROM $table WHERE audience_id=? AND id=? AND deleted_at IS NULL FOR UPDATE",[$this->audienceId,$id]);if(!$row||(int)$row['version']!==$version)throw new InvalidArgumentException('This item changed. Reload before saving.');
                $this->write("UPDATE $table SET name=?,version=version+1".($kind==='template'?',subject=?,body_html=?':'').' WHERE audience_id=? AND id=?',$kind==='template'?[$name,$subject,$html,$this->audienceId,$id]:[$name,$this->audienceId,$id]);return $id;}
            $old=$this->one("SELECT id FROM $table WHERE audience_id=? AND name=? AND deleted_at IS NOT NULL FOR UPDATE",[$this->audienceId,$name]);
            if($old){$this->write("UPDATE $table SET deleted_at=NULL,version=version+1".($kind==='template'?',subject=?,body_html=?':'').' WHERE audience_id=? AND id=?',$kind==='template'?[$subject,$html,$this->audienceId,$old['id']]:[$this->audienceId,$old['id']]);return (int)$old['id'];}
            if($kind==='template')$this->write("INSERT INTO $table(audience_id,name,subject,body_html) VALUES (?,?,?,?)",[$this->audienceId,$name,$subject,$html]);else $this->write("INSERT INTO $table(audience_id,name) VALUES (?,?)",[$this->audienceId,$name]);return (int)$this->db->insert_id;
        });
    }
    public function removeInboxNamed(string $kind,int $id,int $version):void
    {
        $table=match($kind){'label'=>'marketing_inbox_labels','template'=>'marketing_inbox_templates',default=>throw new InvalidArgumentException('Invalid Inbox item.')};
        $this->write("UPDATE $table SET deleted_at=UTC_TIMESTAMP(),version=version+1 WHERE audience_id=? AND id=? AND version=? AND deleted_at IS NULL",[$this->audienceId,$id,$version]);if($this->db->affected_rows!==1)throw new InvalidArgumentException('This item changed. Reload before removing it.');
    }
    public function inboxList(array $input):array
    {
        $status=in_array($input['status']??'todo',['todo','done','trash','all'],true)?($input['status']??'todo'):'todo';
        $source=in_array($input['source']??'',['email','contact','survey','direct'],true)?$input['source']:'';
        $q=audienceText($input,'q',100);$label=max(0,(int)($input['label']??0));$unread=($input['unread']??'')==='1';
        $where='t.audience_id=? AND t.last_message_id>0';$p=[$this->audienceId];
        if($status!=='all'){$where.=' AND t.status=?';$p[]=$status;}
        if($source!==''){$where.=' AND t.source=?';$p[]=$source;}
        if($label){$where.=' AND EXISTS(SELECT 1 FROM marketing_inbox_thread_labels tl JOIN marketing_inbox_labels l ON l.audience_id=tl.audience_id AND l.id=tl.label_id AND l.deleted_at IS NULL WHERE tl.audience_id=t.audience_id AND tl.thread_id=t.id AND tl.label_id=?)';$p[]=$label;}
        if($q!==''){$where.=' AND (LOCATE(?,t.subject)>0 OR LOCATE(?,t.sender_email)>0 OR LOCATE(?,t.sender_name)>0 OR EXISTS(SELECT 1 FROM marketing_inbox_messages sm WHERE sm.audience_id=t.audience_id AND sm.thread_id=t.id AND LOCATE(?,sm.body_text)>0))';array_push($p,$q,$q,$q,$q);}
        $read='COALESCE((SELECT last_read_id FROM marketing_inbox_read_state rs WHERE rs.audience_id=t.audience_id AND rs.thread_id=t.id AND rs.user_id='.(int)$this->actorId.'),0)';
        if($unread)$where.=" AND t.last_message_id>$read";
        $total=(int)$this->one("SELECT COUNT(*) n FROM marketing_inbox_threads t WHERE $where",$p)['n'];$page=max(1,min(max(1,(int)ceil($total/25)),(int)($input['page']??1)));
        $items=$this->all("SELECT t.*,t.last_message_id>$read unread,LEFT(m.body_text,180) preview,m.direction FROM marketing_inbox_threads t JOIN marketing_inbox_messages m ON m.id=t.last_message_id AND m.audience_id=t.audience_id WHERE $where ORDER BY t.last_activity_at DESC,t.id DESC LIMIT 25 OFFSET ?",[...$p,($page-1)*25]);
        $counts=['todo'=>0,'done'=>0,'trash'=>0,'all'=>0];foreach($this->all('SELECT status,COUNT(*) n FROM marketing_inbox_threads WHERE audience_id=? AND last_message_id>0 GROUP BY status',[$this->audienceId])as$r){$counts[$r['status']]=(int)$r['n'];$counts['all']+=(int)$r['n'];}
        $sources=['email'=>0,'contact'=>0,'survey'=>0,'direct'=>0];foreach($this->all("SELECT source,COUNT(*) n FROM marketing_inbox_threads WHERE audience_id=? AND last_message_id>0 AND status<>'trash' GROUP BY source",[$this->audienceId])as$r)$sources[$r['source']]=(int)$r['n'];
        return compact('items','total','page','status','source','q','label','unread','counts','sources');
    }
    public function inboxThread(int $id,bool $lock=false):array
    {
        $t=$this->one('SELECT * FROM marketing_inbox_threads WHERE audience_id=? AND id=?'.($lock?' FOR UPDATE':''),[$this->audienceId,$id]);
        if(!$t)throw new InvalidArgumentException('This conversation is unavailable in this audience.');
        $t['contact']=$t['contact_id']?$this->one('SELECT id,email,first_name,last_name,email_status,is_archived FROM marketing_contacts WHERE audience_id=? AND id=? AND deleted_at IS NULL AND LOWER(email)=LOWER(?)',[$this->audienceId,$t['contact_id'],$t['sender_email']]):null;
        $t['labels']=array_map('intval',array_column($this->all('SELECT label_id FROM marketing_inbox_thread_labels WHERE audience_id=? AND thread_id=?',[$this->audienceId,$id]),'label_id'));
        return $t;
    }
    public function inboxMessages(int $thread,int $before=0):array
    {
        $this->inboxThread($thread);
        $messages=$this->all('SELECT m.*,s.state delivery_state,s.error_message delivery_error,s.provider_message_id FROM marketing_inbox_messages m LEFT JOIN marketing_service_mail s ON s.id=m.service_mail_id AND s.audience_id=m.audience_id WHERE m.audience_id=? AND m.thread_id=?'.($before?' AND m.id<?':'').' ORDER BY m.id DESC LIMIT 100',$before?[$this->audienceId,$thread,$before]:[$this->audienceId,$thread]);
        foreach($messages as&$m){$m['delivery_events']=$m['service_mail_id']?$this->all('SELECT event_type,event_at FROM marketing_inbox_delivery_events WHERE audience_id=? AND service_mail_id=? ORDER BY id',[$this->audienceId,$m['service_mail_id']]):[];$m['attachments']=$this->all('SELECT id,filename,mime_type,byte_size FROM marketing_inbox_attachments WHERE audience_id=? AND message_id=? ORDER BY id',[$this->audienceId,$m['id']]);}unset($m);
        return array_reverse($messages);
    }
    public function inboxMarkRead(int $id,bool $read):void
    {
        $t=$this->inboxThread($id);
        $this->write('INSERT INTO marketing_inbox_read_state(audience_id,thread_id,user_id,last_read_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE last_read_id=VALUES(last_read_id)',[$this->audienceId,$id,$this->actorId,$read?(int)$t['last_message_id']:0]);
        if($read)$this->write('UPDATE marketing_inbox_notifications SET read_at=UTC_TIMESTAMP() WHERE audience_id=? AND user_id=? AND thread_id=? AND message_id<=? AND read_at IS NULL',[$this->audienceId,$this->actorId,$id,$t['last_message_id']]);
    }
    public function inboxThreadOperation(int $id,int $version,string $operation,array $input=[]):void
    {
        $lock='mm-inbox-'.$this->audienceId.'-'.$id;
        if((int)$this->one('SELECT GET_LOCK(?,0) acquired',[$lock])['acquired']!==1)throw new InvalidArgumentException('A message is being sent. Wait for its receipt before changing this conversation.');
        try{$this->transaction(function()use($id,$version,$operation,$input){
            $t=$this->inboxThread($id,true);if((int)$t['version']!==$version)throw new InvalidArgumentException('This conversation changed. Reload before continuing.');
            if(in_array($operation,['todo','done','trash','restore'],true)){
                if($operation==='trash')$this->write("UPDATE marketing_service_mail s JOIN marketing_inbox_messages m ON m.service_mail_id=s.id AND m.audience_id=s.audience_id SET s.state='cancelled',s.error_message='The conversation was moved to Trash.' WHERE m.audience_id=? AND m.thread_id=? AND (s.state='queued' OR (s.state='processing' AND s.delivery_started_at IS NULL))",[$this->audienceId,$id]);
                $status=$operation==='restore'?$t['previous_status']:$operation;
                $this->write('UPDATE marketing_inbox_threads SET previous_status=?,status=?,version=version+1 WHERE audience_id=? AND id=?',[$t['status']==='trash'?$t['previous_status']:$t['status'],$status,$this->audienceId,$id]);
            }elseif($operation==='labels'){
                $labels=audienceIds($input['labels']??[]);if(count($labels)>100)throw new InvalidArgumentException('Choose up to 100 labels.');
                foreach($labels as$label)if(!$this->one('SELECT id FROM marketing_inbox_labels WHERE audience_id=? AND id=? AND deleted_at IS NULL',[$this->audienceId,$label]))throw new InvalidArgumentException('Choose an existing label.');
                $this->write('DELETE FROM marketing_inbox_thread_labels WHERE audience_id=? AND thread_id=?',[$this->audienceId,$id]);
                foreach($labels as$label)$this->write('INSERT INTO marketing_inbox_thread_labels(audience_id,thread_id,label_id) VALUES (?,?,?)',[$this->audienceId,$id,$label]);
                $this->write('UPDATE marketing_inbox_threads SET version=version+1 WHERE audience_id=? AND id=?',[$this->audienceId,$id]);
            }else throw new InvalidArgumentException('Choose a supported conversation action.');
        });}finally{$this->one('SELECT RELEASE_LOCK(?) released',[$lock]);}
    }
    private function inboxNotify(int $thread,int $message,string $kind):void
    {
        $this->write("INSERT IGNORE INTO marketing_inbox_notifications(audience_id,user_id,thread_id,message_id,kind) SELECT ?,id,?,?,? FROM users WHERE role IN ('admin','editor') AND account_status='active' AND id<>?",[$this->audienceId,$thread,$message,$kind,$this->actorId]);
    }
    public function inboxComment(int $id,string $html,int $parent=0):int
    {
        $html=audienceDesignRichText($html);$text=audienceInboxPlain($html);if($text==='')throw new InvalidArgumentException('Enter an internal comment.');
        return $this->transaction(function()use($id,$html,$text,$parent){
            $t=$this->inboxThread($id,true);
            if($parent&&!$this->one("SELECT id FROM marketing_inbox_messages WHERE audience_id=? AND thread_id=? AND id=? AND direction='comment'",[$this->audienceId,$id,$parent]))throw new InvalidArgumentException('Choose an internal comment from this conversation.');
            $this->write("INSERT INTO marketing_inbox_messages(audience_id,thread_id,direction,source_key,references_json,sender_name,actor_id,body_text,body_html,parent_id) VALUES (?,?,'comment',?,JSON_ARRAY(),?,?,?,?,?)",[$this->audienceId,$id,bin2hex(random_bytes(32)),$this->actorLabel,$this->actorId,$text,$html,$parent?:null]);$message=(int)$this->db->insert_id;
            $this->write('UPDATE marketing_inbox_threads SET last_message_id=?,last_activity_at=UTC_TIMESTAMP(6),version=version+1 WHERE audience_id=? AND id=?',[$message,$this->audienceId,$id]);$this->inboxNotify($id,$message,'comment');return $message;
        });
    }
    /** The caller may join a collection/inbound transaction, retaining exactly-once effects. */
    public function inboxReceive(string $source,int $reference,string $email,string $name,string $subject,string $text,string $key,bool $verified,array $references=[],int $thread=0,array $attachments=[],bool $inTransaction=false,string $rfcMessageId=''):int
    {
        if(!in_array($source,['email','contact','survey','direct'],true))throw new InvalidArgumentException('Invalid message source.');
        $email=audienceInboxEmail($email);$name=mb_substr(trim($name),0,100);$subject=audienceInboxSubject(mb_substr(preg_replace('/[\x00-\x1f\x7f]/',' ',$subject)??'',0,250))?:'(No subject)';
        if(strlen($text)>1_000_000)throw new InvalidArgumentException('The message exceeds the retained text limit.');
        if(!preg_match('/\A[a-f0-9]{64}\z/D',$key))throw new InvalidArgumentException('Invalid message receipt.');
        if(count($references)>20)throw new InvalidArgumentException('The message has too many references.');
        foreach([...$references,...($rfcMessageId!==''?[$rfcMessageId]:[])]as$ref)if(!is_string($ref)||!preg_match('/\A<[A-Za-z0-9.!#$%&\x27*+\/=?^_`{|}~@-]{1,500}>\z/D',$ref))throw new InvalidArgumentException('Invalid message reference.');
        $perform=function()use($source,$reference,$email,$name,$subject,$text,$key,$verified,$references,$thread,$attachments,$rfcMessageId):int{
            $old=$this->one('SELECT thread_id FROM marketing_inbox_messages WHERE audience_id=? AND source_key=?',[$this->audienceId,$key]);if($old)return (int)$old['thread_id'];
            $contact=$verified?$this->one('SELECT id FROM marketing_contacts WHERE audience_id=? AND LOWER(email)=? AND deleted_at IS NULL AND is_archived=0',[$this->audienceId,$email]):null;
            $target=$thread;
            if($target){$t=$this->inboxThread($target,true);if(strtolower($t['sender_email'])!==$email)throw new InvalidArgumentException('The sender does not match this conversation.');}
            if(!$target&&$references){$matches=$this->all('SELECT DISTINCT t.id FROM marketing_inbox_threads t JOIN marketing_inbox_messages m ON m.thread_id=t.id AND m.audience_id=t.audience_id WHERE t.audience_id=? AND LOWER(t.sender_email)=? AND (JSON_OVERLAPS(m.references_json,CAST(? AS JSON)) OR m.rfc_message_id IN ('.implode(',',array_fill(0,count($references),'?')).')) LIMIT 2',[$this->audienceId,$email,json_encode($references,JSON_THROW_ON_ERROR),...$references]);if(count($matches)===1)$target=(int)$matches[0]['id'];}
            if(!$target){$this->write('INSERT INTO marketing_inbox_threads(audience_id,contact_id,sender_email,sender_name,subject,source,source_ref,sender_verified) VALUES (?,?,?,?,?,?,?,?)',[$this->audienceId,$contact['id']??null,$email,$name,$subject,$source,$reference,(int)$verified]);$target=(int)$this->db->insert_id;}
            $messageId=$rfcMessageId;
            $this->write("INSERT INTO marketing_inbox_messages(audience_id,thread_id,direction,source_key,rfc_message_id,references_json,sender_email,sender_name,body_text,body_html) VALUES (?,?,'inbound',?,?,?,?,?,?,'')",[$this->audienceId,$target,$key,$messageId,json_encode($references,JSON_THROW_ON_ERROR),$email,$name,$text]);$message=(int)$this->db->insert_id;
            $total=0;foreach($attachments as$a){$total+=strlen($a['data']);if($total>AUDIENCE_INBOX_ATTACHMENTS_BYTES||count($attachments)>10)throw new InvalidArgumentException('Attachments exceed the Inbox message limit.');$this->write('INSERT INTO marketing_inbox_attachments(audience_id,message_id,filename,mime_type,byte_size,sha256,content) VALUES (?,?,?,?,?,?,?)',[$this->audienceId,$message,$a['filename'],$a['content_type'],strlen($a['data']),hash('sha256',$a['data']),$a['data']]);}
            $this->write("UPDATE marketing_inbox_threads SET last_message_id=?,last_inbound_id=?,last_activity_at=UTC_TIMESTAMP(6),status='todo',sender_verified=GREATEST(sender_verified,?),version=version+1 WHERE audience_id=? AND id=?",[$message,$message,(int)$verified,$this->audienceId,$target]);
            if($verified)$this->write('INSERT INTO marketing_inbox_preferences(audience_id,email_hash,last_inbound_at) VALUES (?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE last_inbound_at=UTC_TIMESTAMP(6)',[$this->audienceId,hash('sha256',$email)]);
            $this->inboxNotify($target,$message,'inbound');return $target;
        };
        return $inTransaction?$perform():$this->transaction($perform);
    }
    public function inboxNotificationSettings():array{return $this->one('SELECT * FROM marketing_inbox_user_settings WHERE audience_id=? AND user_id=?',[$this->audienceId,$this->actorId])??['browser_alerts'=>0,'email_alerts'=>0,'version'=>0];}
    public function saveInboxNotificationSettings(int $version,array $input):void
    {
        $this->transaction(function()use($version,$input){$old=$this->one('SELECT version FROM marketing_inbox_user_settings WHERE audience_id=? AND user_id=? FOR UPDATE',[$this->audienceId,$this->actorId]);if((int)($old['version']??0)!==$version)throw new InvalidArgumentException('Notification settings changed. Reload before saving.');$this->write('INSERT INTO marketing_inbox_user_settings(audience_id,user_id,browser_alerts,email_alerts) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE browser_alerts=VALUES(browser_alerts),updated_at=IF(email_alerts<>VALUES(email_alerts),CURRENT_TIMESTAMP(6),updated_at),email_alerts=VALUES(email_alerts),version=version+1',[$this->audienceId,$this->actorId,(int)(($input['browser_alerts']??'')==='1'),(int)(($input['email_alerts']??'')==='1')]);});
    }
    public function inboxNotifications(int $after=0):array
    {
        return $this->all('SELECT n.id,n.thread_id,n.kind,n.created_at FROM marketing_inbox_notifications n JOIN marketing_inbox_threads t ON t.audience_id=n.audience_id AND t.id=n.thread_id AND t.status<>\'trash\' WHERE n.audience_id=? AND n.user_id=? AND n.id>? AND n.read_at IS NULL ORDER BY n.id DESC LIMIT 25',[$this->audienceId,$this->actorId,$after]);
    }
    public function inboxPresence(int $thread,bool $typing):array
    {
        $this->inboxThread($thread);
        $this->write('INSERT INTO marketing_inbox_presence(audience_id,user_id,thread_id,is_typing,seen_at) VALUES (?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE thread_id=VALUES(thread_id),is_typing=VALUES(is_typing),seen_at=UTC_TIMESTAMP()',[$this->audienceId,$this->actorId,$thread,(int)$typing]);
        return $this->all("SELECT p.user_id,p.is_typing,COALESCE(NULLIF(CONCAT_WS(' ',u.first_name,u.last_name),''),u.username) name FROM marketing_inbox_presence p JOIN users u ON u.id=p.user_id AND u.role IN ('admin','editor') AND u.account_status='active' WHERE p.audience_id=? AND p.thread_id=? AND p.user_id<>? AND p.seen_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 45 SECOND)",[$this->audienceId,$thread,$this->actorId]);
    }
}
