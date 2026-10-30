<?php

declare(strict_types=1);

trait AudienceDefinitions
{
    private ?bool $definitionArchiveAvailable = null;

    public function definitionArchiveAvailable(): bool
    {
        return $this->definitionArchiveAvailable ??= (int)$this->one("SELECT COUNT(*) n FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name IN ('marketing_tags','marketing_segments','marketing_group_categories') AND column_name='archived_at'")['n']===3;
    }

    private function archiveAwareRows(string $currentSql,string $legacySql,array $params): array
    {
        if($this->definitionArchiveAvailable===false)return $this->all($legacySql,$params);
        try{$rows=$this->all($currentSql,$params);$this->definitionArchiveAvailable=true;return $rows;}
        catch(mysqli_sql_exception $error){
            if((int)$error->getCode()!==1054)throw $error;
            $this->definitionArchiveAvailable=false;
            return $this->all($legacySql,$params);
        }
    }

    public function fields(): array { return $this->all('SELECT * FROM marketing_fields WHERE audience_id=? ORDER BY position,id',[$this->audienceId]); }

    public function tags(bool $includeArchived = false): array
    {
        $base=' FROM marketing_tags t LEFT JOIN marketing_contact_tags ct ON ct.tag_id=t.id LEFT JOIN marketing_contacts c ON c.id=ct.contact_id AND c.audience_id=t.audience_id AND c.deleted_at IS NULL AND c.is_archived=0 WHERE t.audience_id=?';
        return $this->archiveAwareRows('SELECT t.*,t.archived_at AS archive_state,COUNT(c.id) AS contact_count'.$base.($includeArchived?'':' AND t.archived_at IS NULL').' GROUP BY t.id ORDER BY t.name','SELECT t.*,NULL AS archived_at,COUNT(c.id) AS contact_count'.$base.' GROUP BY t.id ORDER BY t.name',[$this->audienceId]);
    }

    public function groups(bool $includeArchived = false): array
    {
        $groups = $this->archiveAwareRows('SELECT *,archived_at AS archive_state FROM marketing_group_categories WHERE audience_id=?'.($includeArchived?'':' AND archived_at IS NULL').' ORDER BY name,id','SELECT *,NULL AS archived_at FROM marketing_group_categories WHERE audience_id=? ORDER BY name,id',[$this->audienceId]);
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
        return audienceRuleCatalog($this->fields(),$this->tags(true),$this->groups(true),$campaigns,$links);
    }

    public function ensureTag(string $name): int
    {
        $name=audienceText(['name'=>$name],'name'); if ($name==='') throw new InvalidArgumentException('Enter a tag name.');
        $this->write('INSERT INTO marketing_tags (audience_id,name,created_at) VALUES (?,?,CURRENT_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',[$this->audienceId,$name]);
        $id=(int)$this->db->insert_id;
        if($this->definitionArchiveAvailable()&&$this->one('SELECT archived_at FROM marketing_tags WHERE id=? AND audience_id=? AND archived_at IS NOT NULL',[$id,$this->audienceId]))throw new InvalidArgumentException('This tag is archived. Restore it before assigning it.');
        return $id;
    }

    public function saveTag(string $name, int $id, int $version): int
    {
        return $this->transaction(function () use($name,$id,$version) {
            $name=audienceText(['name'=>$name],'name');if($name==='')throw new InvalidArgumentException('Enter a tag name.');
            if (!$id) { $this->write('INSERT INTO marketing_tags (audience_id,name,created_at) VALUES (?,?,CURRENT_TIMESTAMP(6))',[$this->audienceId,$name]); return (int)$this->db->insert_id; }
            $this->write('UPDATE marketing_tags SET name=?,version=version+1 WHERE id=? AND audience_id=? AND version=?'.($this->definitionArchiveAvailable()?' AND archived_at IS NULL':''),[$name,$id,$this->audienceId,$version]);
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

    /** Archive changes availability, never stored memberships or saved rule IDs. */
    public function setDefinitionArchived(string $kind, int $id, int $version, bool $archive): void
    {
        if(!$this->definitionArchiveAvailable())throw new InvalidArgumentException('Definition archiving is unavailable until the database migration is applied.');
        $table=match($kind){'tag'=>'marketing_tags','segment'=>'marketing_segments','group'=>'marketing_group_categories',default=>throw new InvalidArgumentException('Choose a tag, segment, or group.')};
        $this->transaction(function()use($kind,$table,$id,$version,$archive):void{
            $row=$this->one("SELECT id,name,archived_at FROM $table WHERE id=? AND audience_id=? AND version=? FOR UPDATE",[$id,$this->audienceId,$version]);
            if(!$row)throw new InvalidArgumentException('This definition changed or is unavailable. Reload before trying again.');
            if($archive!==($row['archived_at']===null))throw new InvalidArgumentException('This definition changed. Reload before trying again.');
            if($archive&&$kind!=='segment'){
                $dependencies=$this->definitionWriteDependencies($kind,$id);
                if($dependencies)throw new InvalidArgumentException('Archive blocked. Update these active items first: '.implode(', ',array_slice($dependencies,0,5)).(count($dependencies)>5?' and '.(count($dependencies)-5).' more':'').'.');
            }
            $this->write("UPDATE $table SET archived_at=".($archive?'UTC_TIMESTAMP(6)':'NULL').',version=version+1 WHERE id=? AND audience_id=? AND version=?',[$id,$this->audienceId,$version]);
            if($this->db->affected_rows!==1)throw new InvalidArgumentException('This definition changed. Reload before trying again.');
        });
    }

    private function definitionWriteDependencies(string $kind,int $id): array
    {
        $dependencies=[];
        $forms=$this->all("SELECT r.name,r.kind,v.document_json FROM marketing_collection_resources r JOIN marketing_collection_revisions v ON v.id=r.published_revision AND v.resource_id=r.id AND v.audience_id=r.audience_id WHERE r.audience_id=? AND r.state='published' AND r.deleted_at IS NULL",[$this->audienceId]);
        foreach($forms as$form){
            $doc=audienceJson($form['document_json']);$uses=false;
            if($kind==='group')$uses=in_array($id,array_map('intval',(array)($doc['group_ids']??[])),true);
            else{
                $uses=in_array($id,array_map('intval',(array)($doc['tag_ids']??[])),true);
                foreach($doc['questions']??[]as$question)foreach($question['answer_tags']??[]as$tags)if(in_array($id,array_map('intval',(array)$tags),true))$uses=true;
            }
            if($uses)$dependencies[]=$form['kind'].' form “'.$form['name'].'”';
        }
        $flows=$this->all("SELECT f.name,v.document_json FROM marketing_flows f JOIN marketing_flow_revisions v ON v.id=f.published_revision AND v.flow_id=f.id AND v.audience_id=f.audience_id WHERE f.audience_id=? AND f.state='running' AND f.deleted_at IS NULL",[$this->audienceId]);
        if($kind==='tag')foreach($flows as$flow){$doc=audienceJson($flow['document_json']);foreach($doc['nodes']??[]as$node)if(($node['type']??'')==='tag'&&empty($node['remove'])&&(int)($node['tag_id']??0)===$id){$dependencies[]='automation “'.$flow['name'].'”';break;} }
        if($kind==='group'){
            $options=array_map('intval',array_column($this->all('SELECT id FROM marketing_group_options WHERE category_id=?',[$id]),'id'));
            foreach($flows as$flow){$doc=audienceJson($flow['document_json']);foreach($doc['nodes']??[]as$node)if(($node['type']??'')==='group'&&empty($node['remove'])&&in_array((int)($node['option_id']??0),$options,true)){$dependencies[]='automation “'.$flow['name'].'”';break;}}
        }
        foreach($this->all("SELECT name,job_type,params_json FROM marketing_jobs WHERE audience_id=? AND state IN ('queued','processing')",[$this->audienceId])as$job){
            $params=audienceJson($job['params_json']);$uses=$kind==='tag'
                ?((($params['action']??'')==='tag'&&(int)($params['tag_id']??0)===$id)||in_array($id,array_map('intval',(array)($params['tag_ids']??[])),true))
                :((($params['action']??'')==='group'&&in_array((int)($params['option_id']??0),$options??[],true))||count(array_intersect($options??[],array_map('intval',(array)($params['group_ids']??[]))))>0);
            if($uses)$dependencies[]='queued job “'.$job['name'].'”';
        }
        return array_values(array_unique($dependencies));
    }

    public function saveGroup(array $input,int $id,int $version): int
    {
        return $this->transaction(function()use($input,$id,$version){
            $name=audienceText($input,'name');$type=audienceText($input,'display_type');
            if($name===''||!in_array($type,['checkboxes','radio','dropdown','hidden'],true))throw new InvalidArgumentException('Enter a group category name and display type.');
            $options=$input['options']??[];if(!is_array($options)||!$options||count($options)>100)throw new InvalidArgumentException('Add between 1 and 100 group choices.');
            if($id){$old=$this->one('SELECT * FROM marketing_group_categories WHERE id=? AND audience_id=? AND version=?'.($this->definitionArchiveAvailable()?' AND archived_at IS NULL':'').' FOR UPDATE',[$id,$this->audienceId,$version]);if(!$old)throw new InvalidArgumentException('This group changed. Reload before editing.');
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
        $catalog=$this->catalog();$tags=$this->tags(true);$expressions=[];$params=[];$ids=[];
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
            if($this->definitionArchiveAvailable()){
                $previous=$id?$this->one('SELECT filters_json FROM marketing_segments WHERE id=? AND audience_id=?',[$id,$this->audienceId]):null;
                $oldRefs=$previous?$this->archivedRuleReferences(audienceJson($previous['filters_json'])):[];
                if(array_diff($this->archivedRuleReferences($tree),$oldRefs))throw new InvalidArgumentException('Archived tags or groups cannot be added to a saved segment. Restore them first.');
            }
            if($id){$this->write('UPDATE marketing_segments SET name=?,filters_json=?,version=version+1 WHERE id=? AND audience_id=? AND version=?'.($this->definitionArchiveAvailable()?' AND archived_at IS NULL':''),[$name,$json,$id,$this->audienceId,$version]);if($this->db->affected_rows!==1)throw new InvalidArgumentException('This segment changed. Reload before editing.');}
            else{$this->write('INSERT INTO marketing_segments (audience_id,name,filters_json) VALUES (?,?,?)',[$this->audienceId,$name,$json]);$id=(int)$this->db->insert_id;}return $id;
        });
    }

    private function archivedRuleReferences(array $tree): array
    {
        $archivedTags=array_fill_keys(array_map('intval',array_column(array_filter($this->tags(true),static fn($tag)=>!empty($tag['archived_at'])),'id')),true);
        $archivedOptions=[];foreach($this->groups(true)as$group)if(!empty($group['archived_at']))foreach($group['options']as$option)$archivedOptions[(int)$option['id']]=true;
        $found=[];$walk=function(array $node)use(&$walk,&$found,$archivedTags,$archivedOptions):void{
            if(isset($node['rules'])){foreach($node['rules']as$rule)if(is_array($rule))$walk($rule);return;}
            $field=$node['field']??'';if($field!=='tags'&&$field!=='groups')return;
            if(in_array($node['operator']??'',['empty','not_empty'],true))return;
            $archived=$field==='tags'?$archivedTags:$archivedOptions;
            foreach(audienceIds($node['value']??[])as$id)if(isset($archived[$id]))$found[]=$field.':'.$id;
        };$walk($tree);return array_values(array_unique($found));
    }
}
