<?php
declare(strict_types=1);

trait AudienceInboxCompose
{
    public function inboxDrafts(string $query=''):array
    {
        $query=audienceText(['q'=>$query],'q',100);
        $where="d.audience_id=? AND d.actor_id=? AND d.state='draft'";$parameters=[$this->audienceId,$this->actorId];
        if($query!==''){$where.=' AND (LOCATE(?,d.subject)>0 OR LOCATE(?,d.body_html)>0 OR LOCATE(?,c.email)>0 OR LOCATE(?,CONCAT_WS(\' \',c.first_name,c.last_name))>0 OR LOCATE(?,t.sender_email)>0 OR LOCATE(?,t.sender_name)>0)';array_push($parameters,$query,$query,$query,$query,$query,$query);}
        return $this->all("SELECT d.id,d.thread_id,d.subject,d.updated_at,LEFT(d.body_html,2000) preview_html,COALESCE(NULLIF(CONCAT_WS(' ',c.first_name,c.last_name),''),t.sender_name,'') recipient_name,COALESCE(c.email,t.sender_email,'') recipient_email FROM marketing_inbox_drafts d LEFT JOIN marketing_contacts c ON c.audience_id=d.audience_id AND c.id=d.contact_id AND c.deleted_at IS NULL LEFT JOIN marketing_inbox_threads t ON t.audience_id=d.audience_id AND t.id=d.thread_id WHERE $where ORDER BY d.updated_at DESC,d.id DESC",$parameters);
    }
    public function inboxRecipients(string $query):array
    {
        $query=trim($query);if(mb_strlen($query)>100)throw new InvalidArgumentException('Keep this search under 100 characters.');
        return $this->all("SELECT c.id,c.email,c.first_name,c.last_name FROM marketing_contacts c WHERE c.audience_id=? AND c.deleted_at IS NULL AND c.is_archived=0 AND c.email_status='subscribed' AND (LOCATE(?,c.email)>0 OR LOCATE(?,CONCAT_WS(' ',c.first_name,c.last_name))>0) AND NOT EXISTS(SELECT 1 FROM marketing_email_optouts o WHERE o.audience_id=c.audience_id AND o.email_hash=SHA2(LOWER(c.email),256)) ORDER BY c.email LIMIT 25",[$this->audienceId,$query,$query]);
    }
    public function inboxDraft(int $id,bool $lock=false):array
    {
        $d=$this->one('SELECT * FROM marketing_inbox_drafts WHERE audience_id=? AND actor_id=? AND id=?'.($lock?' FOR UPDATE':''),[$this->audienceId,$this->actorId,$id]);
        if(!$d)throw new InvalidArgumentException('This draft is unavailable for your account.');
        $d['attachments']=$this->all('SELECT id,filename,mime_type,byte_size FROM marketing_inbox_attachments WHERE audience_id=? AND draft_id=? ORDER BY id',[$this->audienceId,$id]);
        return $d;
    }
    public function saveInboxDraft(array $input,int $id=0,int $version=0):int
    {
        $subject=audienceInboxSubject(audienceText($input,'subject',250));$html=audienceDesignRichText(audienceText($input,'body_html',30000));
        $name=audienceText($input,'from_name',100);if(preg_match('/[\x00-\x1f\x7f]/',$name))throw new InvalidArgumentException('Use a From name without line breaks.');
        $thread=max(0,(int)($input['thread_id']??0));$person=max(0,(int)($input['contact_id']??0));
        return $this->transaction(function()use($input,$id,$version,$subject,$html,$name,$thread,$person){
            if($id){$d=$this->inboxDraft($id,true);if($d['state']!=='draft'||(int)$d['version']!==$version)throw new InvalidArgumentException('This draft changed. Reload before saving.');$this->write('UPDATE marketing_inbox_drafts SET subject=?,body_html=?,from_name=?,include_optout=?,version=version+1 WHERE audience_id=? AND actor_id=? AND id=?',[$subject,$html,$name,(int)(($input['include_optout']??'')==='1'),$this->audienceId,$this->actorId,$id]);return $id;}
            if($thread){$t=$this->inboxThread($thread,true);if($t['status']==='trash')throw new InvalidArgumentException('Restore this conversation before replying.');$person=(int)($t['contact']['id']??0);}
            elseif($person){$p=$this->person($person);if($p['is_archived'])throw new InvalidArgumentException('Choose an active connection.');}
            else throw new InvalidArgumentException('Choose a connection or conversation.');
            $this->write('INSERT INTO marketing_inbox_drafts(audience_id,actor_id,contact_id,thread_id,subject,from_name,body_html,include_optout) VALUES (?,?,?,?,?,?,?,?)',[$this->audienceId,$this->actorId,$person?:null,$thread?:null,$subject,$name,$html,(int)(($input['include_optout']??'1')==='1')]);return (int)$this->db->insert_id;
        });
    }
    public function inboxDraftAttachment(int $id,int $version,array $file,int $remove=0):void
    {
        $attachment=null;
        if(!$remove){if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??''))throw new InvalidArgumentException('Choose a valid uploaded attachment.');if((int)($file['size']??0)>AUDIENCE_INBOX_ATTACHMENT_BYTES)throw new InvalidArgumentException('Each attachment must be no larger than 5 MB.');$bytes=file_get_contents($file['tmp_name']);if($bytes===false)throw new InvalidArgumentException('The attachment could not be read.');$attachment=audienceInboxAttachment((string)($file['name']??''),$bytes);}
        $this->transaction(function()use($id,$version,$attachment,$remove){$d=$this->inboxDraft($id,true);if($d['state']!=='draft'||(int)$d['version']!==$version)throw new InvalidArgumentException('This draft changed. Reload before attaching a file.');
            if($remove)$this->write('DELETE FROM marketing_inbox_attachments WHERE audience_id=? AND draft_id=? AND id=? AND message_id IS NULL',[$this->audienceId,$id,$remove]);
            else{$size=(int)$this->one('SELECT COALESCE(SUM(byte_size),0) bytes FROM marketing_inbox_attachments WHERE audience_id=? AND draft_id=?',[$this->audienceId,$id])['bytes'];if(count($d['attachments'])>=10||$size+$attachment['size']>AUDIENCE_INBOX_ATTACHMENTS_BYTES)throw new InvalidArgumentException('Use up to 10 attachments, totaling no more than 6 MB.');$this->write('INSERT INTO marketing_inbox_attachments(audience_id,draft_id,filename,mime_type,byte_size,sha256,content) VALUES (?,?,?,?,?,?,?)',[$this->audienceId,$id,$attachment['filename'],$attachment['content_type'],$attachment['size'],$attachment['sha256'],$attachment['data']]);}
            $this->write('UPDATE marketing_inbox_drafts SET version=version+1 WHERE audience_id=? AND id=?',[$this->audienceId,$id]);
        });
    }
    public function discardInboxDraft(int $id,int $version):void
    {
        $this->write("UPDATE marketing_inbox_drafts SET state='discarded',version=version+1 WHERE audience_id=? AND actor_id=? AND id=? AND version=? AND state='draft'",[$this->audienceId,$this->actorId,$id,$version]);if($this->db->affected_rows!==1)throw new InvalidArgumentException('This draft changed. Reload before discarding.');
    }
    public function inboxRecipientEligibility(array $draft):array
    {
        $person=$draft['contact_id']?$this->one('SELECT * FROM marketing_contacts WHERE audience_id=? AND id=? AND deleted_at IS NULL',[$this->audienceId,$draft['contact_id']]):null;
        // Delivery only needs recipient identity and status, not UI labels or contact decoration.
        // Keep this path usable by the restricted mail worker without granting label access.
        $reasons=[];$thread=$draft['thread_id']?$this->one('SELECT sender_email,status FROM marketing_inbox_threads WHERE audience_id=? AND id=?',[$this->audienceId,(int)$draft['thread_id']]):null;
        if($draft['thread_id']&&!$thread)throw new InvalidArgumentException('This conversation is unavailable in this audience.');
        if(!$person)$reasons[]='Link this sender to a subscribed connection before sending.';
        elseif($person['is_archived']||$person['email_status']!=='subscribed')$reasons[]='This connection is archived, cleaned, or is not subscribed to email.';
        if($person){$hash=hash('sha256',strtolower($person['email']));
            if($this->one('SELECT email_hash FROM marketing_email_optouts WHERE audience_id=? AND email_hash=?',[$this->audienceId,$hash])||audienceDeletedEmailSuppressed($this->db,$this->audienceId,(int)$person['id'],$hash))$reasons[]='This address is suppressed from receiving email.';
            $preference=$this->one('SELECT optout_at,last_inbound_at FROM marketing_inbox_preferences WHERE audience_id=? AND email_hash=?',[$this->audienceId,$hash]);
            if($preference&&$preference['optout_at']&&(!$preference['last_inbound_at']||$preference['last_inbound_at']<=$preference['optout_at']))$reasons[]='This connection opted out of one-to-one Inbox messages.';
            if($thread&&strtolower($thread['sender_email'])!==strtolower($person['email']))$reasons[]='The connection email changed. Review the recipient before replying.';
        }
        if($thread&&$thread['status']==='trash')$reasons[]='Restore this conversation before sending.';
        return ['person'=>$person,'thread'=>$thread,'reasons'=>$reasons];
    }
    public function inboxSendReadiness():array
    {
        $s=$this->sesAvailable()?$this->sesSettings():[];$reasons=$s?audienceSesReasons($s,(string)($s['from_email']??'')):['Configure the account’s SES sender before sending.'];
        if(empty($this->inboxSettings()['gateway_confirmed']))$reasons[]='An administrator must confirm Inbox reply routing in Sources.';
        return ['ready'=>!$reasons,'reasons'=>$reasons];
    }
    /** Freeze a private draft exactly once; all actual sends remain in the SES queue. */
    public function queueInboxDraft(int $id,int $version,array $audience,string $base):array
    {
        return $this->transaction(function()use($id,$version,$audience,$base){
            $d=$this->inboxDraft($id,true);if($d['state']==='queued')return ['thread_id'=>(int)$d['thread_id'],'service_mail_id'=>(int)$d['service_mail_id']];
            if($d['state']!=='draft'||(int)$d['version']!==$version)throw new InvalidArgumentException('This draft changed. Reload before sending.');
            $eligible=$this->inboxRecipientEligibility($d);$reasons=[...$eligible['reasons'],...$this->inboxSendReadiness()['reasons']];if($reasons)throw new InvalidArgumentException(implode(' ',$reasons));$person=$eligible['person'];
            $context=audienceInboxContext($person,$audience);$subject=audienceInboxSubject(audienceDesignMerge($d['subject'],$context,false));$html=audienceDesignRichText(audienceDesignMerge($d['body_html'],$context));$text=audienceInboxPlain($html);
            if($subject===''||$text==='')throw new InvalidArgumentException('Complete the subject and message before sending.');
            if(preg_match('/\*\|[^|]{1,300}\|\*/',$subject.$html))throw new InvalidArgumentException('Replace unresolved personalization tags before sending.');
            $thread=(int)$d['thread_id'];
            if(!$thread){$this->write("INSERT INTO marketing_inbox_threads(audience_id,contact_id,sender_email,sender_name,subject,source) VALUES (?,?,?,?,?,'direct')",[$this->audienceId,$person['id'],$person['email'],trim($person['first_name'].' '.$person['last_name']),$subject]);$thread=(int)$this->db->insert_id;}
            $replyTo=$this->inboxReplyRoute('thread',$thread);$s=$this->sesSettings();$messageId='<inbox-'.bin2hex(random_bytes(24)).'@message-monkey.invalid>';
            $references=[];$reply='';$previous=$this->one("SELECT rfc_message_id,references_json FROM marketing_inbox_messages WHERE audience_id=? AND thread_id=? AND direction<>'comment' ORDER BY id DESC LIMIT 1",[$this->audienceId,$thread]);
            if($previous){$references=audienceJson($previous['references_json']);$reply=$previous['rfc_message_id'];if($reply!=='')$references[]=$reply;$references=array_slice(array_values(array_unique($references)),-20);while(strlen(implode(' ',$references))>900)array_shift($references);}
            $token='';$optout='';
            if($d['include_optout']){$token=bin2hex(random_bytes(32));$optout=rtrim(audienceDispatchBase($base),'/').'/audience_inbox_optout.php?token='.$token;$this->write('INSERT INTO marketing_inbox_optout_tokens(token_hash,audience_id,email_hash,expires_at) VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 YEAR))',[hash('sha256',$token),$this->audienceId,hash('sha256',strtolower($person['email']))]);$text.="\n\nStop receiving one-to-one messages: ".$optout;$html.='<p><small><a href="'.htmlspecialchars($optout,ENT_QUOTES,'UTF-8').'">Stop receiving one-to-one messages</a></small></p>';}
            $files=[];foreach($this->all('SELECT filename,mime_type,content FROM marketing_inbox_attachments WHERE audience_id=? AND draft_id=? ORDER BY id',[$this->audienceId,$id])as$a)$files[]=['filename'=>$a['filename'],'content_type'=>$a['mime_type'],'data_base64'=>base64_encode($a['content'])];
            $fromName=$d['from_name']?:preg_replace('/[\x00-\x1f\x7f]/',' ',$this->actorLabel);
            $message=['email'=>$person['email'],'to_name'=>trim($person['first_name'].' '.$person['last_name']),'from_email'=>$s['from_email'],'from_name'=>$fromName,'subject'=>$subject,'html'=>$html,'text'=>$text,'message_id'=>$messageId,'reply_to'=>$replyTo,'in_reply_to'=>$reply,'references'=>$references,'attachments'=>$files,'ses_binding'=>audienceSesBinding($s),'inbox_thread_id'=>$thread,'inbox_contact_id'=>(int)$person['id'],'inbox_actor_id'=>$this->actorId,'unsubscribe'=>$optout];
            $sealed=\Dnr\Security\ApplicationKey::seal(json_encode(['message'=>$message],JSON_THROW_ON_ERROR));
            $this->write("INSERT INTO marketing_service_mail(audience_id,contact_id,kind,actor_id,actor_label,operation_key,payload_ciphertext,message_id,next_attempt_at,expires_at) VALUES (?,?,'inbox',?,?,?,?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY))",[$this->audienceId,$person['id'],$this->actorId,$this->actorLabel,hash('sha256','inbox-draft:'.$this->audienceId.':'.$id),$sealed,$messageId]);$mail=(int)$this->db->insert_id;
            $this->write("INSERT INTO marketing_inbox_messages(audience_id,thread_id,direction,source_key,rfc_message_id,references_json,sender_email,sender_name,actor_id,body_text,body_html,service_mail_id) VALUES (?,?,'outbound',?,?,?,?,?,?,?,?,?)",[$this->audienceId,$thread,hash('sha256','outbound:'.$mail),$messageId,json_encode($references,JSON_THROW_ON_ERROR),$s['from_email'],$message['from_name'],$this->actorId,$text,$html,$mail]);$mid=(int)$this->db->insert_id;
            $this->write('UPDATE marketing_inbox_attachments SET message_id=? WHERE audience_id=? AND draft_id=?',[$mid,$this->audienceId,$id]);
            $this->write('UPDATE marketing_inbox_threads SET last_message_id=?,last_activity_at=UTC_TIMESTAMP(6),version=version+1 WHERE audience_id=? AND id=?',[$mid,$this->audienceId,$thread]);
            $this->write("UPDATE marketing_inbox_drafts SET state='queued',thread_id=?,service_mail_id=?,version=version+1 WHERE audience_id=? AND id=?",[$thread,$mail,$this->audienceId,$id]);
            return ['thread_id'=>$thread,'service_mail_id'=>$mail];
        });
    }
}
