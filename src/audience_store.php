<?php
declare(strict_types=1);
require_once __DIR__ . '/audience_helpers.php';
require_once __DIR__ . '/audience_rules.php';
require_once __DIR__ . '/audience_jobs.php';
require_once __DIR__ . '/audience_campaigns.php';
require_once __DIR__ . '/audience_campaign_advanced.php';
require_once __DIR__ . '/audience_design.php';
require_once __DIR__ . '/audience_design_store.php';
require_once __DIR__ . '/audience_content.php';
require_once __DIR__ . '/audience_delivery_setup.php';
require_once __DIR__ . '/audience_dispatch.php';
require_once __DIR__ . '/audience_delivery_runtime.php';
require_once __DIR__ . '/audience_ses.php';
require_once __DIR__ . '/audience_search.php';
require_once __DIR__ . '/audience_collection.php';
require_once __DIR__ . '/audience_flow_store.php';
require_once __DIR__ . '/audience_inbox_store.php';
require_once __DIR__ . '/audience_analytics.php';
require_once __DIR__ . '/audience_mailchimp_history.php';

final class AudienceStore
{
    use AudienceJobs;
    use AudienceCampaigns;
    use AudienceCampaignAdvanced;
    use AudienceDesigns;
    use AudienceDesignExtras;
    use AudienceContent;
    use AudienceDeliverySetup;
    use AudienceDispatch;
    use AudienceDeliveryRuntime;
    use AudienceSesSetup;
    use AudienceCollection;
    use AudienceFlows;
    use AudienceInbox;
    use AudienceAnalytics;
    use AudienceMailchimpHistory;
    private int $transactionDepth = 0;
    public function __construct(public readonly mysqli $db, public readonly int $audienceId, public readonly int $actorId, public readonly string $actorLabel)
    {
        if (!$this->one('SELECT id FROM marketing_audiences WHERE id=?', [$audienceId])) throw new InvalidArgumentException('This audience is unavailable.');
    }
    public function all(string $sql, array $params = []): array { return $this->db->execute_query($sql, $params)->fetch_all(MYSQLI_ASSOC); }
    public function one(string $sql, array $params = []): ?array { return $this->db->execute_query($sql, $params)->fetch_assoc(); }
    public function write(string $sql, array $params = []): void { $this->db->execute_query($sql, $params); }
    public function transaction(callable $operation): mixed
    {
        $depth = $this->transactionDepth++;
        if ($depth === 0) $this->db->begin_transaction(); else $this->db->query('SAVEPOINT audience_' . $depth);
        try {
            $result = $operation();
            if ($depth === 0) $this->db->commit(); else $this->db->query('RELEASE SAVEPOINT audience_' . $depth);
            return $result;
        } catch (Throwable $error) {
            if ($depth === 0) $this->db->rollback(); else $this->db->query('ROLLBACK TO SAVEPOINT audience_' . $depth);
            if ($error instanceof mysqli_sql_exception && $error->getCode() === 1062) throw new InvalidArgumentException('That email address or name already exists in this audience. Open the existing record to update it.');
            throw $error;
        } finally { --$this->transactionDepth; }
    }
    public function event(int $id, string $type, array $details = []): void
    {
        $this->write('INSERT INTO marketing_contact_events (contact_id,actor_user_id,actor_label,event_type,details_json) VALUES (?,?,?,?,?)', [$id,$this->actorId,$this->actorLabel,$type,json_encode($details,JSON_THROW_ON_ERROR)]);
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
    public function fields(): array { return $this->all('SELECT * FROM marketing_fields WHERE audience_id=? ORDER BY position,id',[$this->audienceId]); }
    public function tags(): array
    {
        return $this->all('SELECT t.*,COUNT(c.id) AS contact_count FROM marketing_tags t LEFT JOIN marketing_contact_tags ct ON ct.tag_id=t.id LEFT JOIN marketing_contacts c ON c.id=ct.contact_id AND c.audience_id=t.audience_id AND c.deleted_at IS NULL AND c.is_archived=0 WHERE t.audience_id=? GROUP BY t.id ORDER BY t.name',[$this->audienceId]);
    }
    public function groups(): array
    {
        $groups = $this->all('SELECT * FROM marketing_group_categories WHERE audience_id=? ORDER BY name,id',[$this->audienceId]);
        foreach ($groups as &$group) $group['options'] = $this->all('SELECT o.*,COUNT(c.id) AS contact_count FROM marketing_group_options o LEFT JOIN marketing_contact_groups cg ON cg.option_id=o.id LEFT JOIN marketing_contacts c ON c.id=cg.contact_id AND c.audience_id=? AND c.deleted_at IS NULL AND c.is_archived=0 WHERE o.category_id=? GROUP BY o.id ORDER BY o.position,o.id',[$this->audienceId,$group['id']]);
        return $groups;
    }
    public function catalog(): array { return audienceRuleCatalog($this->fields(),$this->tags(),$this->groups()); }
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
            if ($this->one('SELECT email_hash FROM marketing_deleted_emails WHERE audience_id=? AND email_hash=?',[$this->audienceId,hash('sha256',$data['email'])])) throw new InvalidArgumentException('This address was permanently deleted and cannot be added again.');
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
            if (array_diff($tagIds,array_map('intval',array_column($this->tags(),'id')))) throw new InvalidArgumentException('A selected tag belongs to another audience or was removed.');
            $availableGroups=[];
            foreach ($this->groups() as $group) {
                $optionIds=array_map('intval',array_column($group['options'],'id')); array_push($availableGroups,...$optionIds);
                if (in_array($group['display_type'],['radio','dropdown'],true) && count(array_intersect($optionIds,$groupIds))>1) throw new InvalidArgumentException('Choose one option for '.$group['name'].'.');
            }
            if (array_diff($groupIds,$availableGroups)) throw new InvalidArgumentException('A selected group is unavailable in this audience.');
            $values=[$data['email'],$data['first_name'],$data['last_name'],$data['email_status'],json_encode((object)$custom,JSON_THROW_ON_ERROR),$language,$timezone];
            if ($id) $this->write('UPDATE marketing_contacts SET email=?,first_name=?,last_name=?,email_status=?,custom_json=?,language=?,timezone=?,version=version+1 WHERE id=? AND audience_id=?',[...$values,$id,$this->audienceId]);
            else { $this->write('INSERT INTO marketing_contacts (email,first_name,last_name,email_status,custom_json,language,timezone,audience_id,source,notes) VALUES (?,?,?,?,?,?,?,?,?,\'\')',[...$values,$this->audienceId,$origin]); $id=(int)$this->db->insert_id; }
            $this->write('DELETE FROM marketing_contact_tags WHERE contact_id=?',[$id]);
            foreach ($tagIds as $tag) $this->write('INSERT INTO marketing_contact_tags (contact_id,tag_id) VALUES (?,?)',[$id,$tag]);
            $this->write('DELETE FROM marketing_contact_groups WHERE contact_id=?',[$id]);
            foreach ($groupIds as $group) $this->write('INSERT INTO marketing_contact_groups (contact_id,option_id) VALUES (?,?)',[$id,$group]);
            $changed=[];foreach(['first_name','last_name','language','timezone']as$key)if(!$previous||($previous[$key]??'')!==($key==='language'?$language:($key==='timezone'?$timezone:$data[$key])))$changed[]=$key;foreach($custom as$key=>$value)if(!$previous||($previous['custom'][$key]??'')!==$value)$changed[]=(string)$key;
            $this->event($id,$previous?'contact_updated':'contact_added',['email'=>$data['email'],'status'=>$data['email_status'],'consent_note'=>$data['consent_note'],'changed_fields'=>$changed]);
            if ($previous && $previous['email_status']!==$data['email_status']) $this->event($id,'subscription_changed',['from'=>$previous['email_status'],'to'=>$data['email_status'],'consent_note'=>$data['consent_note']]);
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
                if ($this->one('SELECT email_hash FROM marketing_deleted_emails WHERE audience_id=? AND email_hash=?',[$this->audienceId,hash('sha256',$data['email'])])) throw new InvalidArgumentException('This address was permanently deleted and cannot be added again.');
                $this->write('UPDATE marketing_contacts SET email=?,email_status=?,version=version+1 WHERE id=? AND audience_id=?',[$data['email'],$status,$id,$this->audienceId]);
                $detail+=['email'=>$data['email'],'status'=>$status,'consent_note'=>$data['consent_note']];
                if ($person['email_status']!==$status) $this->event($id,'subscription_changed',['from'=>$person['email_status'],'to'=>$status,'consent_note'=>$data['consent_note']]);
            } elseif ($section==='groups') {
                $ids=audienceIds([...(array)($input['group_ids']??[]),...array_filter((array)($input['group_pick']??[]),fn($v)=>$v!=='')]);
                $available=[];
                foreach ($this->groups() as $group) {
                    $choices=array_map('intval',array_column($group['options'],'id'));array_push($available,...$choices);
                    if (in_array($group['display_type'],['radio','dropdown'],true)&&count(array_intersect($choices,$ids))>1) throw new InvalidArgumentException('Choose one option for '.$group['name'].'.');
                }
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
    public function ensureTag(string $name): int
    {
        $name=audienceText(['name'=>$name],'name'); if ($name==='') throw new InvalidArgumentException('Enter a tag name.');
        $this->write('INSERT INTO marketing_tags (audience_id,name,created_at) VALUES (?,?,CURRENT_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',[$this->audienceId,$name]); return (int)$this->db->insert_id;
    }
    public function saveTag(string $name, int $id, int $version): int
    {
        return $this->transaction(function () use($name,$id,$version) {
            $name=audienceText(['name'=>$name],'name');if($name==='')throw new InvalidArgumentException('Enter a tag name.');
            if (!$id) { $this->write('INSERT INTO marketing_tags (audience_id,name,created_at) VALUES (?,?,CURRENT_TIMESTAMP(6))',[$this->audienceId,$name]); return (int)$this->db->insert_id; }
            $this->write('UPDATE marketing_tags SET name=?,version=version+1 WHERE id=? AND audience_id=? AND version=?',[$name,$id,$this->audienceId,$version]);
            if ($this->db->affected_rows!==1)throw new InvalidArgumentException('This tag changed. Reload before editing.');return $id;
        });
    }
    public function deleteTag(int $id,int $version): void
    {
        $this->transaction(function()use($id,$version){
            $tag=$this->one('SELECT * FROM marketing_tags WHERE id=? AND audience_id=? AND version=? FOR UPDATE',[$id,$this->audienceId,$version]);if(!$tag)throw new InvalidArgumentException('This tag changed or is unavailable.');
            $people=$this->all('SELECT c.id FROM marketing_contacts c JOIN marketing_contact_tags ct ON ct.contact_id=c.id WHERE ct.tag_id=? AND c.audience_id=? AND c.deleted_at IS NULL',[$id,$this->audienceId]);
            foreach($people as $person){$this->write('UPDATE marketing_contacts SET version=version+1 WHERE id=?',[$person['id']]);$this->event((int)$person['id'],'tags_changed',['removed_tag'=>$tag['name']]);}
            $this->write('DELETE FROM marketing_tags WHERE id=? AND audience_id=?',[$id,$this->audienceId]);
        });
    }
    public function deleteSelectedDefinitions(string $kind, array $ids, array $versions): int
    {
        if (!in_array($kind,['tags','segments'],true)) throw new InvalidArgumentException('Choose tags or segments.');
        $ids=audienceIds($ids);
        if (!$ids||count($ids)>100) throw new InvalidArgumentException('Select between 1 and 100 items on this page.');
        sort($ids,SORT_NUMERIC);
        return $this->transaction(function()use($kind,$ids,$versions){
            $table=$kind==='tags'?'marketing_tags':'marketing_segments';
            // Validate and lock the entire selection before modifying any membership.
            foreach($ids as$id){
                $version=filter_var($versions[$id]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if(!$version||!$this->one("SELECT id FROM $table WHERE id=? AND audience_id=? AND version=? FOR UPDATE",[$id,$this->audienceId,$version]))throw new InvalidArgumentException('A selected item changed or is unavailable. Reload before deleting.');
            }
            foreach($ids as$id){
                if($kind==='tags')$this->deleteTag($id,(int)$versions[$id]);
                else $this->write('DELETE FROM marketing_segments WHERE id=? AND audience_id=?',[$id,$this->audienceId]);
            }
            return count($ids);
        });
    }
    public function saveGroup(array $input,int $id,int $version): int
    {
        return $this->transaction(function()use($input,$id,$version){
            $name=audienceText($input,'name');$type=audienceText($input,'display_type');
            if($name===''||!in_array($type,['checkboxes','radio','dropdown','hidden'],true))throw new InvalidArgumentException('Enter a group category name and display type.');
            $options=$input['options']??[];if(!is_array($options)||!$options||count($options)>100)throw new InvalidArgumentException('Add between 1 and 100 group choices.');
            if($id){$old=$this->one('SELECT * FROM marketing_group_categories WHERE id=? AND audience_id=? AND version=? FOR UPDATE',[$id,$this->audienceId,$version]);if(!$old)throw new InvalidArgumentException('This group changed. Reload before editing.');
                if(in_array($type,['radio','dropdown'],true)&&$this->one('SELECT cg.contact_id FROM marketing_contact_groups cg JOIN marketing_group_options o ON o.id=cg.option_id WHERE o.category_id=? GROUP BY cg.contact_id HAVING COUNT(*)>1 LIMIT 1',[$id]))throw new InvalidArgumentException('Some connections have multiple choices. Keep checkboxes or remove their extra choices first.');
                $this->write('UPDATE marketing_group_categories SET name=?,display_type=?,version=version+1 WHERE id=?',[$name,$type,$id]);
            }else{$this->write('INSERT INTO marketing_group_categories (audience_id,name,display_type) VALUES (?,?,?)',[$this->audienceId,$name,$type]);$id=(int)$this->db->insert_id;}
            $oldOptions=$this->all('SELECT id FROM marketing_group_options WHERE category_id=?',[$id]);$oldIds=array_map('intval',array_column($oldOptions,'id'));$keep=[];$position=0;
            foreach($options as $option){if(!is_array($option))throw new InvalidArgumentException('Invalid group option.');$optionName=audienceText($option,'name');if($optionName==='')continue;$oid=(int)($option['id']??0);
                if($oid){if(!in_array($oid,$oldIds,true))throw new InvalidArgumentException('Invalid group option.');$this->write('UPDATE marketing_group_options SET name=?,position=? WHERE id=? AND category_id=?',[$optionName,$position++,$oid,$id]);}
                else{$this->write('INSERT INTO marketing_group_options (category_id,name,position) VALUES (?,?,?)',[$id,$optionName,$position++]);$oid=(int)$this->db->insert_id;}$keep[]=$oid;
            }
            if(!$keep)throw new InvalidArgumentException('Add at least one group choice.');
            foreach(array_diff($oldIds,$keep)as$removed){$this->recordGroupRemoval((int)$id,[(int)$removed]);$this->write('DELETE FROM marketing_group_options WHERE id=? AND category_id=?',[$removed,$id]);}
            return $id;
        });
    }
    public function deleteGroup(int $id,int $version): void
    {
        $this->transaction(function()use($id,$version){
            if(!$this->one('SELECT id FROM marketing_group_categories WHERE id=? AND audience_id=? AND version=? FOR UPDATE',[$id,$this->audienceId,$version]))throw new InvalidArgumentException('This group changed or is unavailable.');
            $this->recordGroupRemoval($id);
            $this->write('DELETE FROM marketing_group_categories WHERE id=? AND audience_id=?',[$id,$this->audienceId]);
        });
    }
    private function recordGroupRemoval(int $categoryId,array $options=[]): void
    {
        $sql='SELECT DISTINCT c.id FROM marketing_contacts c JOIN marketing_contact_groups cg ON cg.contact_id=c.id JOIN marketing_group_options o ON o.id=cg.option_id WHERE c.audience_id=? AND c.deleted_at IS NULL AND o.category_id=?';$values=[$this->audienceId,$categoryId];
        if($options){$sql.=' AND o.id IN ('.implode(',',array_fill(0,count($options),'?')).')';array_push($values,...$options);}
        foreach($this->all($sql,$values)as$person){$this->write('UPDATE marketing_contacts SET version=version+1 WHERE id=?',[$person['id']]);$this->event((int)$person['id'],'groups_changed',['removed_category'=>$categoryId,'removed_options'=>$options]);}
    }
    public function saveField(array $input,int $id,int $version): int
    {
        return $this->transaction(function()use($input,$id,$version){
            $label=audienceText($input,'label');$tag=strtoupper(audienceText($input,'merge_tag',30));$type=audienceText($input,'field_type');
            if($label===''||!preg_match('/^[A-Z][A-Z0-9_]{0,29}$/D',$tag)||in_array($tag,['EMAIL','FNAME','LNAME','UNSUB','UPDATE_PROFILE'],true)||!isset(AUDIENCE_FIELD_TYPES[$type]))throw new InvalidArgumentException('Enter a field label, unique merge tag and valid field type.');
            $options=array_values(array_filter(array_map('trim',explode("\n",audienceText($input,'options_text',10000)))));
            if(count($options)>100||count($options)!==count(array_unique($options)))throw new InvalidArgumentException('Use up to 100 distinct choices.');
            if(in_array($type,['dropdown','radio','checkboxes'],true)&&!$options)throw new InvalidArgumentException('Add at least one choice.');
            $field=['label'=>$label,'field_type'=>$type,'required'=>0,'options_json'=>$options];$default=audienceText($input,'default_value',2000);audienceFieldValue($field,$default);
            if($id){$old=$this->one('SELECT * FROM marketing_fields WHERE id=? AND audience_id=? AND version=? FOR UPDATE',[$id,$this->audienceId,$version]);if(!$old)throw new InvalidArgumentException('This field changed. Reload before editing.');if($old['field_type']!==$type)throw new InvalidArgumentException('A field type cannot change after creation. Add a new field instead.');}
            if($id&&in_array($type,['dropdown','radio','checkboxes'],true)){
                $path='$."'.$id.'"';
                foreach($this->all('SELECT JSON_EXTRACT(custom_json,?) value FROM marketing_contacts WHERE audience_id=? AND deleted_at IS NULL AND JSON_CONTAINS_PATH(custom_json,\'one\',?)',[$path,$this->audienceId,$path])as$row){$value=json_decode($row['value'],true);try{audienceFieldValue($field,$value);}catch(InvalidArgumentException $e){throw new InvalidArgumentException('An existing connection uses a choice you removed. Update their values before removing that choice.');}}
            }
            $values=[$label,$tag,$type,(int)!empty($input['required']),(int)!empty($input['is_public']),$default,json_encode($options,JSON_THROW_ON_ERROR),(int)($input['position']??0)];
            if($id)$this->write('UPDATE marketing_fields SET label=?,merge_tag=?,field_type=?,required=?,is_public=?,default_value=?,options_json=?,position=?,version=version+1 WHERE id=? AND audience_id=?',[...$values,$id,$this->audienceId]);
            else{$this->write('INSERT INTO marketing_fields (label,merge_tag,field_type,required,is_public,default_value,options_json,position,audience_id) VALUES (?,?,?,?,?,?,?,?,?)',[...$values,$this->audienceId]);$id=(int)$this->db->insert_id;}return $id;
        });
    }
    public function deleteField(int $id,int $version): void
    {
        $this->transaction(function()use($id,$version){
            $this->write('DELETE FROM marketing_fields WHERE id=? AND audience_id=? AND version=?',[$id,$this->audienceId,$version]);if($this->db->affected_rows!==1)throw new InvalidArgumentException('This field changed or is unavailable.');
            $this->write('UPDATE marketing_contacts SET custom_json=JSON_REMOVE(custom_json,?),version=version+1 WHERE audience_id=?',[ '$."'.$id.'"',$this->audienceId]);
        });
    }
    public function saveSegment(string $name,array $tree,int $id,int $version): int
    {
        return $this->transaction(function()use($name,$tree,$id,$version){
            $name=audienceText(['name'=>$name],'name');if($name==='')throw new InvalidArgumentException('Enter a segment name.');audienceCompileRules($tree,$this->catalog());$json=json_encode($tree,JSON_THROW_ON_ERROR);
            if($id){$this->write('UPDATE marketing_segments SET name=?,filters_json=?,version=version+1 WHERE id=? AND audience_id=? AND version=?',[$name,$json,$id,$this->audienceId,$version]);if($this->db->affected_rows!==1)throw new InvalidArgumentException('This segment changed. Reload before editing.');}
            else{$this->write('INSERT INTO marketing_segments (audience_id,name,filters_json) VALUES (?,?,?)',[$this->audienceId,$name,$json]);$id=(int)$this->db->insert_id;}return $id;
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
                if($this->collectionAvailable()&&$this->one('SELECT id FROM marketing_collection_responses WHERE audience_id=? AND contact_id=? LIMIT 1',[$this->audienceId,$id]))$this->write('UPDATE marketing_collection_responses SET contact_id=NULL,payload_ciphertext=? WHERE audience_id=? AND contact_id=?',[\Dnr\Security\ApplicationKey::seal('{"redacted":true}'),$this->audienceId,$id]);
                $this->write("UPDATE marketing_job_items i JOIN marketing_jobs j ON j.id=i.job_id SET i.payload_json=JSON_OBJECT(),i.result_json=NULL,i.error_message='Connection data removed',i.state='skipped' WHERE j.audience_id=? AND (i.contact_id=? OR LOCATE(?,LOWER(i.payload_json))>0 OR LOCATE(?,LOWER(COALESCE(i.result_json,'')))>0)",[$this->audienceId,$id,$person['email'],$person['email']]);
                $this->write("UPDATE marketing_contacts SET email=?,first_name='',last_name='',source='',notes='',custom_json=JSON_OBJECT(),language='',timezone='',email_status='unsubscribed',is_archived=1,deleted_at=UTC_TIMESTAMP(6),version=version+1 WHERE id=?",['deleted-'.$id.'@invalid.example',$id]);$this->event($id,'person_deleted');return;
            }
            if(in_array($action,['archive','restore','unsubscribe'],true)){
                [$column,$value]=match($action){'archive'=>['is_archived',1],'restore'=>['is_archived',0],default=>['email_status',$person['email_status']==='cleaned'?'cleaned':'unsubscribed']};
                $this->write("UPDATE marketing_contacts SET $column=?,version=version+1 WHERE id=?",[$value,$id]);$details=['action'=>$action];if($action==='unsubscribe')$details+=['from'=>$person['email_status'],'to'=>$value];$this->event($id,match($action){'archive'=>'contact_archived','restore'=>'contact_restored',default=>'subscription_changed'},$details);return;
            }
            if(in_array($action,['tag','untag'],true)){
                $tag=(int)($params['tag_id']??0);if(!$this->one('SELECT id FROM marketing_tags WHERE id=? AND audience_id=?',[$tag,$this->audienceId]))throw new InvalidArgumentException('Choose an available tag.');
                if($action==='tag')$this->write('INSERT IGNORE INTO marketing_contact_tags (contact_id,tag_id) VALUES (?,?)',[$id,$tag]);else $this->write('DELETE FROM marketing_contact_tags WHERE contact_id=? AND tag_id=?',[$id,$tag]);$this->event($id,'tags_changed',['action'=>$action,'tag_id'=>$tag,'added'=>$action==='tag'&&!in_array($tag,$person['tag_ids'],true)?[$tag]:[],'removed'=>$action==='untag'&&in_array($tag,$person['tag_ids'],true)?[$tag]:[]]);
            }elseif(in_array($action,['group','ungroup'],true)){
                $option=(int)($params['option_id']??0);$group=$this->one('SELECT o.category_id,g.display_type FROM marketing_group_options o JOIN marketing_group_categories g ON g.id=o.category_id WHERE o.id=? AND g.audience_id=?',[$option,$this->audienceId]);if(!$group)throw new InvalidArgumentException('Choose an available group.');
                if($action==='group'){if(in_array($group['display_type'],['radio','dropdown'],true))$this->write('DELETE cg FROM marketing_contact_groups cg JOIN marketing_group_options o ON o.id=cg.option_id WHERE cg.contact_id=? AND o.category_id=?',[$id,$group['category_id']]);$this->write('INSERT IGNORE INTO marketing_contact_groups (contact_id,option_id) VALUES (?,?)',[$id,$option]);}else $this->write('DELETE FROM marketing_contact_groups WHERE contact_id=? AND option_id=?',[$id,$option]);$this->event($id,'groups_changed',['action'=>$action,'option_id'=>$option,'added'=>array_values(array_diff($this->person($id)['group_ids'],$person['group_ids'])),'removed'=>array_values(array_diff($person['group_ids'],$this->person($id)['group_ids']))]);
            }else throw new InvalidArgumentException('Choose a supported connections action.');
            $this->write('UPDATE marketing_contacts SET version=version+1 WHERE id=?',[$id]);
        });
    }
}
