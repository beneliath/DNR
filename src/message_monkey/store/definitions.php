<?php

declare(strict_types=1);

trait AudienceDefinitions
{
    public function fields(): array { return $this->all('SELECT * FROM marketing_fields WHERE audience_id=? ORDER BY position,id',[$this->audienceId]); }

    public function tags(): array
    {
        return $this->all('SELECT t.*,COUNT(c.id) AS contact_count FROM marketing_tags t LEFT JOIN marketing_contact_tags ct ON ct.tag_id=t.id LEFT JOIN marketing_contacts c ON c.id=ct.contact_id AND c.audience_id=t.audience_id AND c.deleted_at IS NULL AND c.is_archived=0 WHERE t.audience_id=? GROUP BY t.id ORDER BY t.name',[$this->audienceId]);
    }

    public function groups(): array
    {
        $groups = $this->all('SELECT * FROM marketing_group_categories WHERE audience_id=? ORDER BY name,id',[$this->audienceId]);
        $options = [];
        if ($groups) {
            foreach ($this->all('SELECT o.*,COUNT(c.id) AS contact_count FROM marketing_group_options o JOIN marketing_group_categories g ON g.id=o.category_id LEFT JOIN marketing_contact_groups cg ON cg.option_id=o.id LEFT JOIN marketing_contacts c ON c.id=cg.contact_id AND c.audience_id=g.audience_id AND c.deleted_at IS NULL AND c.is_archived=0 WHERE g.audience_id=? GROUP BY o.id ORDER BY o.position,o.id',[$this->audienceId]) as $option) {
                $options[$option['category_id']][] = $option;
            }
        }
        foreach ($groups as &$group) $group['options'] = $options[$group['id']] ?? [];
        unset($group);
        return $groups;
    }

    public function catalog(): array
    {
        $campaigns=array_column($this->all('SELECT id,name FROM marketing_campaigns WHERE audience_id=? AND deleted_at IS NULL ORDER BY name,id',[$this->audienceId]),'name','id');
        $links=[];
        if($this->deliveryRuntimeAvailable())foreach($this->all("SELECT JSON_UNQUOTE(JSON_EXTRACT(details_json,'$.link_hash')) hash,MAX(JSON_UNQUOTE(JSON_EXTRACT(details_json,'$.link_label'))) label FROM marketing_message_events WHERE audience_id=? AND event_type='click' GROUP BY hash",[$this->audienceId])as$link)if(preg_match('/^[a-f0-9]{64}$/D',(string)$link['hash']))$links[$link['hash']]=$link['label']?:'Tracked link '.substr($link['hash'],0,8);
        return audienceRuleCatalog($this->fields(),$this->tags(),$this->groups(),$campaigns,$links);
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

    /** Count only the visible saved segments using the same active-connection scope as Connections. */
    public function segmentConnectionCounts(array $segments): array
    {
        if(count($segments)>100)throw new InvalidArgumentException('Count up to 100 visible segments at a time.');
        if(!$segments)return [];
        $catalog=$this->catalog();$tags=$this->tags();$expressions=[];$params=[];$ids=[];
        foreach($segments as$segment){
            $id=(int)($segment['id']??0);
            if($id<1||(isset($segment['audience_id'])&&(int)$segment['audience_id']!==$this->audienceId))throw new InvalidArgumentException('This segment is unavailable.');
            [$condition,$values]=audienceCompileRules(audienceLegacyRules(audienceJson($segment['filters_json']),$tags),$catalog);
            $expressions[]='COALESCE(SUM(CASE WHEN ('.$condition.') THEN 1 ELSE 0 END),0) AS count_'.$id;
            array_push($params,...$values);$ids[]=$id;
        }
        $row=$this->one('SELECT '.implode(',',$expressions).' FROM marketing_contacts c WHERE c.audience_id=? AND c.deleted_at IS NULL AND c.is_archived=0',[...$params,$this->audienceId]);
        $counts=[];foreach($ids as$id)$counts[$id]=(int)($row['count_'.$id]??0);
        return $counts;
    }

    public function saveSegment(string $name,array $tree,int $id,int $version): int
    {
        return $this->transaction(function()use($name,$tree,$id,$version){
            $name=audienceText(['name'=>$name],'name');if($name==='')throw new InvalidArgumentException('Enter a segment name.');audienceCompileRules($tree,$this->catalog());$json=json_encode($tree,JSON_THROW_ON_ERROR);
            if($id){$this->write('UPDATE marketing_segments SET name=?,filters_json=?,version=version+1 WHERE id=? AND audience_id=? AND version=?',[$name,$json,$id,$this->audienceId,$version]);if($this->db->affected_rows!==1)throw new InvalidArgumentException('This segment changed. Reload before editing.');}
            else{$this->write('INSERT INTO marketing_segments (audience_id,name,filters_json) VALUES (?,?,?)',[$this->audienceId,$name,$json]);$id=(int)$this->db->insert_id;}return $id;
        });
    }
}
