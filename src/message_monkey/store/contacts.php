<?php

declare(strict_types=1);

trait AudienceContacts
{
    public function event(int $id, string $type, array $details = []): void
    {
        $this->write('INSERT INTO marketing_contact_events (contact_id,actor_user_id,actor_label,event_type,details_json) VALUES (?,?,?,?,?)', [$id,$this->actorId,$this->actorLabel,$type,json_encode($details,JSON_THROW_ON_ERROR)]);
    }

    public function permissionEvent(int $personId):?array
    {
        $types=implode("','",AUDIENCE_PERMISSION_EVENT_TYPES);
        return $this->one("SELECT e.id,e.event_type,e.details_json,e.created_at FROM marketing_contact_events e
            JOIN marketing_contacts c ON c.id=e.contact_id WHERE c.audience_id=? AND c.id=? AND c.deleted_at IS NULL
            AND e.event_type IN ('$types') ORDER BY e.id DESC LIMIT 1",[$this->audienceId,$personId]);
    }

    /** First signup and confirmation, plus the latest self-service changes. */
    public function personInteractionHistory(int $personId): array
    {
        $this->person($personId);
        $rows=$this->all("SELECT event_type,details_json,created_at FROM marketing_contact_events WHERE contact_id=? AND event_type IN ('signup-permission','subscription-confirmed','preferences','status','survey-response') AND JSON_CONTAINS_PATH(details_json,'one','$.interaction_location.ip_address') ORDER BY id",[$personId]);
        $history=[];
        foreach($rows as $row){
            $details=audienceJson($row['details_json']);
            if(!is_array($details['interaction_location']??null))continue;
            $key=match($row['event_type']){
                'signup-permission'=>'signup',
                'subscription-confirmed'=>'confirmed',
                'preferences'=>'preferences',
                'survey-response'=>'survey',
                'status'=>($details['to']??'')==='unsubscribed'?'unsubscribe':'',
                default=>''
            };
            if($key==='')continue;
            if(in_array($key,['signup','confirmed'],true)&&isset($history[$key]))continue;
            $history[$key]=['at'=>$row['created_at'],'location'=>$details['interaction_location']];
        }
        return $history;
    }

    public function person(int $id, bool $lock = false): array
    {
        $person = $this->one('SELECT * FROM marketing_contacts WHERE audience_id=? AND id=? AND deleted_at IS NULL' . ($lock ? ' FOR UPDATE' : ''), [$this->audienceId,$id]);
        if (!$person) throw new InvalidArgumentException('This connection is unavailable in this audience.');
        $person['custom'] = audienceJson($person['custom_json']);
        $person['tag_ids'] = array_map('intval',array_column($this->all('SELECT ct.tag_id FROM marketing_contact_tags ct JOIN marketing_tags t ON t.id=ct.tag_id WHERE ct.contact_id=? AND t.audience_id=?',[$id,$this->audienceId]),'tag_id'));
        $person['group_ids'] = array_map('intval',array_column($this->all('SELECT cg.option_id FROM marketing_contact_groups cg JOIN marketing_group_options o ON o.id=cg.option_id JOIN marketing_group_categories g ON g.id=o.category_id WHERE cg.contact_id=? AND g.audience_id=?',[$id,$this->audienceId]),'option_id'));
        return $person;
    }

    /** Load membership labels for one contact page without per-contact queries. */
    public function contactListRelations(array $ids): array
    {
        $ids = audienceIds($ids);
        $tags = $groups = array_fill_keys($ids, []);
        if (!$ids) return compact('tags', 'groups');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        foreach ($this->all('SELECT ct.contact_id,t.id,t.name FROM marketing_contact_tags ct JOIN marketing_tags t ON t.id=ct.tag_id JOIN marketing_contacts c ON c.id=ct.contact_id AND c.audience_id=t.audience_id WHERE t.audience_id=? AND ct.contact_id IN ('.$placeholders.') ORDER BY t.name,t.id',[$this->audienceId,...$ids]) as $row) {
            $id = $row['contact_id']; unset($row['contact_id']);
            $tags[$id][] = $row;
        }
        foreach ($this->all('SELECT cg.contact_id,o.name,g.id category_id,g.name category FROM marketing_contact_groups cg JOIN marketing_group_options o ON o.id=cg.option_id JOIN marketing_group_categories g ON g.id=o.category_id JOIN marketing_contacts c ON c.id=cg.contact_id AND c.audience_id=g.audience_id WHERE g.audience_id=? AND cg.contact_id IN ('.$placeholders.') ORDER BY g.name,o.position,o.id',[$this->audienceId,...$ids]) as $row) {
            $id = $row['contact_id']; unset($row['contact_id']);
            $groups[$id][] = $row;
        }
        return compact('tags', 'groups');
    }

    public function where(array $filters, array $rules = ['match'=>'all','rules'=>[]]): array
    {
        $sql = '1=1'; $params = [];
        if (($filters['archived'] ?? '0') !== 'all') { $sql .= ' AND c.is_archived=?'; $params[] = (int)($filters['archived'] ?? 0); }
        if (($filters['q'] ?? '') !== '') { $sql .= " AND (LOCATE(?,c.email)>0 OR LOCATE(?,CONCAT(c.first_name,' ',c.last_name))>0)"; array_push($params,$filters['q'],$filters['q']); }
        foreach(['status','source']as$key)if(isset($filters[$key])&&!is_array($filters[$key]))$filters[$key]=$filters[$key]===''?[]:[$filters[$key]];
        [$advanced,$values] = audienceCompileRules(audienceActiveRules($filters,$rules,false),$this->catalog());
        return ['c.audience_id=? AND c.deleted_at IS NULL AND (' . $sql . ') AND (' . $advanced . ')',[$this->audienceId,...$params,...$values]];
    }

    public function selectedWhere(array $input, array $filters, array $rules): array
    {
        [$sql,$params] = $this->where($filters,$rules);
        if (($input['selection_scope'] ?? '') !== 'all') {
            $ids = audienceIds($input['selected'] ?? []);
            if (!$ids) throw new InvalidArgumentException('Select at least one connection.');
            $sql .= ' AND c.id IN (' . implode(',',array_fill(0,count($ids),'?')) . ')'; array_push($params,...$ids);
        }
        return [$sql,$params];
    }

    public function savePerson(array $input, int $id = 0, int $version = 0, string $origin = 'Manual entry'): int
    {
        return $this->transaction(function () use ($input,$id,$version,$origin) {
            $previous = $id ? $this->person($id,true) : null;
            if ($previous && (int)$previous['version'] !== $version) throw new InvalidArgumentException('This connection changed. Reload before saving.');
            $data = audienceContactInput(array_replace($input,['tags'=>'','notes'=>'']));
            audienceRequireConsent($data,$previous);
            if ($this->one('SELECT email_hash FROM marketing_deleted_emails WHERE audience_id=? AND email_hash=?',[$this->audienceId,hash('sha256',$data['email'])])
                && ($origin==='Import'||!$previous||audienceDeletedEmailSuppressed($this->db,$this->audienceId,(int)$previous['id'],hash('sha256',$data['email'])))) throw new InvalidArgumentException('This address was permanently deleted. It can only resubscribe through a signup form and email confirmation.');
            $customInput = $input['custom'] ?? [];
            if (!is_array($customInput)) throw new InvalidArgumentException('Invalid audience fields.');
            $custom = [];
            foreach ($this->fields() as $field) $custom[(string)$field['id']] = audienceFieldValue($field,$customInput[$field['id']] ?? $previous['custom'][$field['id']] ?? $field['default_value']);
            $language = audienceText($input,'language',12); $timezone = audienceText($input,'timezone',80);
            if ($language !== '' && !preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D',$language)) throw new InvalidArgumentException('Use a language code such as en or en-US.');
            if ($timezone !== '' && !in_array($timezone,DateTimeZone::listIdentifiers(),true)) throw new InvalidArgumentException('Choose a valid time zone.');
            $tagIds = audienceIds($input['tag_ids'] ?? []); $groupIds = audienceIds([...(array)($input['group_ids'] ?? []),...array_filter((array)($input['group_pick'] ?? []),fn($value) => $value !== '')]);
            foreach (array_filter(array_map('trim',explode("\n",str_replace("\r",'',audienceText($input,'new_tags',5000))))) as $name) $tagIds[] = $this->ensureTag($name);
            // Legacy and imported tags use an explicit array so names can contain commas.
            if (isset($input['tag_names']) && !is_array($input['tag_names'])) throw new InvalidArgumentException('Invalid tag names.');
            foreach (($input['tag_names'] ?? []) as $name) { if (!is_string($name)) throw new InvalidArgumentException('Invalid tag name.'); $tagIds[] = $this->ensureTag($name); }
            $tagIds = array_values(array_unique($tagIds));
            $availableTags=array_map('intval',array_column($this->tags(),'id'));
            if($previous)$availableTags=[...$availableTags,...$previous['tag_ids']];
            if (array_diff($tagIds,$availableTags)) throw new InvalidArgumentException('A selected tag is unavailable for new assignments.');
            $availableGroups=[];
            foreach ($this->groups(true) as $group) {
                $optionIds=array_map('intval',array_column($group['options'],'id')); array_push($availableGroups,...$optionIds);
                if (in_array($group['display_type'],['radio','dropdown'],true) && count(array_intersect($optionIds,$groupIds))>1) throw new InvalidArgumentException('Choose one option for '.$group['name'].'.');
            }
            $activeGroupIds=[];foreach($this->groups()as$group)array_push($activeGroupIds,...array_map('intval',array_column($group['options'],'id')));
            $availableGroups=$previous?[...$activeGroupIds,...$previous['group_ids']]:$activeGroupIds;
            if (array_diff($groupIds,$availableGroups)) throw new InvalidArgumentException('A selected group is unavailable in this audience.');
            $values=[$data['email'],$data['first_name'],$data['last_name'],$data['email_status'],json_encode((object)$custom,JSON_THROW_ON_ERROR),$language,$timezone];
            if ($id) $this->write('UPDATE marketing_contacts SET email=?,first_name=?,last_name=?,email_status=?,custom_json=?,language=?,timezone=?,version=version+1 WHERE id=? AND audience_id=?',[...$values,$id,$this->audienceId]);
            else { $this->write('INSERT INTO marketing_contacts (email,first_name,last_name,email_status,custom_json,language,timezone,audience_id,source,notes) VALUES (?,?,?,?,?,?,?,?,?,\'\')',[...$values,$this->audienceId,$origin]); $id=(int)$this->db->insert_id; }
            $this->write('DELETE FROM marketing_contact_tags WHERE contact_id=?',[$id]);
            foreach ($tagIds as $tag) $this->write('INSERT INTO marketing_contact_tags (contact_id,tag_id) VALUES (?,?)',[$id,$tag]);
            $this->write('DELETE FROM marketing_contact_groups WHERE contact_id=?',[$id]);
            foreach ($groupIds as $group) $this->write('INSERT INTO marketing_contact_groups (contact_id,option_id) VALUES (?,?)',[$id,$group]);
            $changed=[];foreach(['first_name','last_name','language','timezone']as$key)if(!$previous||($previous[$key]??'')!==($key==='language'?$language:($key==='timezone'?$timezone:$data[$key])))$changed[]=$key;foreach($custom as$key=>$value)if(!$previous||($previous['custom'][$key]??'')!==$value)$changed[]=(string)$key;
            $this->event($id,$previous?'contact_updated':'contact_added',['email'=>$data['email'],'status'=>$data['email_status'],'consent_note'=>$data['consent_note'],'changed_fields'=>$changed,'origin'=>$origin]);
            if ($previous && $previous['email_status']!==$data['email_status']) $this->event($id,'subscription_changed',['from'=>$previous['email_status'],'to'=>$data['email_status'],'consent_note'=>$data['consent_note'],'origin'=>$origin]);
            if ($tagIds !== ($previous['tag_ids']??[])) $this->event($id,'tags_changed',['tag_ids'=>$tagIds,'added'=>array_values(array_diff($tagIds,$previous['tag_ids']??[])),'removed'=>array_values(array_diff($previous['tag_ids']??[],$tagIds))]);
            if ($groupIds !== ($previous['group_ids']??[])) $this->event($id,'groups_changed',['group_ids'=>$groupIds,'added'=>array_values(array_diff($groupIds,$previous['group_ids']??[])),'removed'=>array_values(array_diff($previous['group_ids']??[],$groupIds))]);
            return $id;
        });
    }

    /** Profile editors update only their own section, under the same optimistic lock. */
    public function saveProfileSection(int $id, int $version, string $section, array $input): void
    {
        $this->transaction(function () use ($id,$version,$section,$input) {
            $person=$this->person($id,true);
            if ((int)$person['version']!==$version) throw new InvalidArgumentException('This connection changed. Reload before saving.');
            $event='contact_updated'; $detail=['section'=>$section];
            if ($section==='details') {
                $custom=$person['custom']; $submitted=$input['custom']??[];
                if (!is_array($submitted)) throw new InvalidArgumentException('Invalid audience fields.');
                foreach ($this->fields() as $field) if (array_key_exists($field['id'],$submitted)) $custom[(string)$field['id']]=audienceFieldValue($field,$submitted[$field['id']]);
                $this->write('UPDATE marketing_contacts SET first_name=?,last_name=?,custom_json=?,version=version+1 WHERE id=? AND audience_id=?',[audienceText($input,'first_name'),audienceText($input,'last_name'),json_encode((object)$custom,JSON_THROW_ON_ERROR),$id,$this->audienceId]);
            } elseif ($section==='marketing') {
                $status=!empty($input['email_consent'])?'subscribed':($person['email_status']==='subscribed'?'unsubscribed':$person['email_status']);
                $data=audienceContactInput(['email'=>audienceText($input,'email',254),'email_status'=>$status,'consent_note'=>audienceText($input,'consent_note',1000)]);
                audienceRequireConsent($data,$person);
                if ($this->one('SELECT email_hash FROM marketing_deleted_emails WHERE audience_id=? AND email_hash=?',[$this->audienceId,hash('sha256',$data['email'])])
                    && audienceDeletedEmailSuppressed($this->db,$this->audienceId,$id,hash('sha256',$data['email']))) throw new InvalidArgumentException('This address was permanently deleted. It can only resubscribe through a signup form and email confirmation.');
                $this->write('UPDATE marketing_contacts SET email=?,email_status=?,version=version+1 WHERE id=? AND audience_id=?',[$data['email'],$status,$id,$this->audienceId]);
                $detail+=['email'=>$data['email'],'status'=>$status,'consent_note'=>$data['consent_note']];
                if ($person['email_status']!==$status) $this->event($id,'subscription_changed',['from'=>$person['email_status'],'to'=>$status,'consent_note'=>$data['consent_note']]);
            } elseif ($section==='groups') {
                $ids=audienceIds([...(array)($input['group_ids']??[]),...array_filter((array)($input['group_pick']??[]),fn($v)=>$v!=='')]);
                $available=[];
                foreach ($this->groups(true) as $group) {
                    $choices=array_map('intval',array_column($group['options'],'id'));array_push($available,...$choices);
                    if (in_array($group['display_type'],['radio','dropdown'],true)&&count(array_intersect($choices,$ids))>1) throw new InvalidArgumentException('Choose one option for '.$group['name'].'.');
                }
                $available=[];foreach($this->groups()as$group)array_push($available,...array_map('intval',array_column($group['options'],'id')));
                $available=[...$available,...$person['group_ids']];
                if (array_diff($ids,$available)) throw new InvalidArgumentException('A selected group is unavailable in this audience.');
                $this->write('DELETE FROM marketing_contact_groups WHERE contact_id=?',[$id]);
                foreach ($ids as $option) $this->write('INSERT INTO marketing_contact_groups(contact_id,option_id) VALUES (?,?)',[$id,$option]);
                $this->write('UPDATE marketing_contacts SET version=version+1 WHERE id=? AND audience_id=?',[$id,$this->audienceId]);
                $event='groups_changed';$detail+=['group_ids'=>$ids,'added'=>array_values(array_diff($ids,$person['group_ids'])),'removed'=>array_values(array_diff($person['group_ids'],$ids))];
            } elseif ($section==='settings') {
                $language=audienceText($input,'language',12);$timezone=audienceText($input,'timezone',80);
                if ($language!==''&&!preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D',$language)) throw new InvalidArgumentException('Use a language code such as en or en-US.');
                if ($timezone!==''&&!in_array($timezone,DateTimeZone::listIdentifiers(),true)) throw new InvalidArgumentException('Choose a valid time zone.');
                $this->write('UPDATE marketing_contacts SET language=?,timezone=?,version=version+1 WHERE id=? AND audience_id=?',[$language,$timezone,$id,$this->audienceId]);
            } else throw new InvalidArgumentException('Unknown profile section.');
            if($event==='contact_updated'){$fresh=$this->person($id);$changed=[];foreach(['first_name','last_name','language','timezone']as$key)if($person[$key]!==$fresh[$key])$changed[]=$key;foreach($fresh['custom']as$key=>$value)if(($person['custom'][$key]??'')!==$value)$changed[]=(string)$key;$detail['changed_fields']=$changed;}
            $this->event($id,$event,$detail);
        });
    }

    public function saveNote(int $personId,string $body,int $id,int $version): void
    {
        $this->transaction(function()use($personId,$body,$id,$version){$this->person($personId,true);$body=audienceText(['body'=>$body],'body',10000);if($body==='')throw new InvalidArgumentException('Enter a note.');
            if($id){$this->write('UPDATE marketing_notes SET body=?,version=version+1 WHERE id=? AND contact_id=? AND version=?',[$body,$id,$personId,$version]);if($this->db->affected_rows!==1)throw new InvalidArgumentException('This note changed. Reload before editing.');}
            else $this->write('INSERT INTO marketing_notes (contact_id,actor_user_id,actor_label,body) VALUES (?,?,?,?)',[$personId,$this->actorId,$this->actorLabel,$body]);
            $this->write('UPDATE marketing_contacts SET version=version+1 WHERE id=?',[$personId]);$this->event($personId,$id?'note_updated':'note_added');});
    }

    public function mutatePerson(int $id,int $version,string $action,array $params=[]): void
    {
        $this->transaction(function()use($id,$version,$action,$params){$person=$this->person($id,true);if((int)$person['version']!==$version)throw new InvalidArgumentException('This connection changed since selection. Reload before retrying.');
            if($action==='delete'){
                $this->write('INSERT IGNORE INTO marketing_deleted_emails (audience_id,email_hash) VALUES (?,?)',[$this->audienceId,hash('sha256',$person['email'])]);
                $this->write('DELETE FROM marketing_contact_tags WHERE contact_id=?',[$id]);$this->write('DELETE FROM marketing_contact_groups WHERE contact_id=?',[$id]);$this->write('DELETE FROM marketing_notes WHERE contact_id=?',[$id]);
                $this->write("UPDATE marketing_contact_events SET details_json=JSON_OBJECT('redacted',true) WHERE contact_id=?",[$id]);
                $this->write("UPDATE marketing_message_events e JOIN marketing_campaign_recipients r ON r.id=e.recipient_id AND r.audience_id=e.audience_id SET e.details_json=JSON_REMOVE(e.details_json,'$.interaction_location') WHERE r.audience_id=? AND r.contact_id=? AND JSON_CONTAINS_PATH(e.details_json,'one','$.interaction_location')",[$this->audienceId,$id]);
                if($this->collectionAvailable()&&$this->one('SELECT id FROM marketing_collection_responses WHERE audience_id=? AND contact_id=? LIMIT 1',[$this->audienceId,$id]))$this->write('UPDATE marketing_collection_responses SET contact_id=NULL,payload_ciphertext=? WHERE audience_id=? AND contact_id=?',[\Dnr\Security\ApplicationKey::seal('{"redacted":true}'),$this->audienceId,$id]);
                $this->redactPersonJobs($id,$person['email']);
                $this->write("UPDATE marketing_contacts SET email=?,first_name='',last_name='',source='',notes='',custom_json=JSON_OBJECT(),language='',timezone='',email_status='unsubscribed',is_archived=1,deleted_at=UTC_TIMESTAMP(6),version=version+1 WHERE id=?",['deleted-'.$id.'@invalid.example',$id]);$this->event($id,'person_deleted');return;
            }
            if(in_array($action,['archive','restore','unsubscribe'],true)){
                [$column,$value]=match($action){'archive'=>['is_archived',1],'restore'=>['is_archived',0],default=>['email_status',$person['email_status']==='cleaned'?'cleaned':'unsubscribed']};
                $this->write("UPDATE marketing_contacts SET $column=?,version=version+1 WHERE id=?",[$value,$id]);$details=['action'=>$action];if($action==='unsubscribe')$details+=['from'=>$person['email_status'],'to'=>$value];$this->event($id,match($action){'archive'=>'contact_archived','restore'=>'contact_restored',default=>'subscription_changed'},$details);return;
            }
            if(in_array($action,['tag','untag'],true)){
                $tag=(int)($params['tag_id']??0);if(!$this->one('SELECT id FROM marketing_tags WHERE id=? AND audience_id=?'.($action==='tag'&&$this->definitionArchiveAvailable()?' AND archived_at IS NULL':''),[$tag,$this->audienceId]))throw new InvalidArgumentException('Choose an available tag.');
                if($action==='tag')$this->write('INSERT IGNORE INTO marketing_contact_tags (contact_id,tag_id) VALUES (?,?)',[$id,$tag]);else $this->write('DELETE FROM marketing_contact_tags WHERE contact_id=? AND tag_id=?',[$id,$tag]);$this->event($id,'tags_changed',['action'=>$action,'tag_id'=>$tag,'added'=>$action==='tag'&&!in_array($tag,$person['tag_ids'],true)?[$tag]:[],'removed'=>$action==='untag'&&in_array($tag,$person['tag_ids'],true)?[$tag]:[]]);
            }elseif(in_array($action,['group','ungroup'],true)){
                $option=(int)($params['option_id']??0);$group=$this->one('SELECT o.category_id,g.display_type FROM marketing_group_options o JOIN marketing_group_categories g ON g.id=o.category_id WHERE o.id=? AND g.audience_id=?'.($action==='group'&&$this->definitionArchiveAvailable()?' AND g.archived_at IS NULL':''),[$option,$this->audienceId]);if(!$group)throw new InvalidArgumentException('Choose an available group.');
                if($action==='group'){if(in_array($group['display_type'],['radio','dropdown'],true))$this->write('DELETE cg FROM marketing_contact_groups cg JOIN marketing_group_options o ON o.id=cg.option_id WHERE cg.contact_id=? AND o.category_id=?',[$id,$group['category_id']]);$this->write('INSERT IGNORE INTO marketing_contact_groups (contact_id,option_id) VALUES (?,?)',[$id,$option]);}else $this->write('DELETE FROM marketing_contact_groups WHERE contact_id=? AND option_id=?',[$id,$option]);$this->event($id,'groups_changed',['action'=>$action,'option_id'=>$option,'added'=>array_values(array_diff($this->person($id)['group_ids'],$person['group_ids'])),'removed'=>array_values(array_diff($person['group_ids'],$this->person($id)['group_ids']))]);
            }else throw new InvalidArgumentException('Choose a supported connections action.');
            $this->write('UPDATE marketing_contacts SET version=version+1 WHERE id=?',[$id]);
        });
    }
}
