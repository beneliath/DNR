<?php
declare(strict_types=1);
trait AudienceDesignExtras
{
    public function designOwner(string $kind,int $id,bool $lock=false,bool $editable=false):array
    {
        if(!in_array($kind,['campaign','template'],true))throw new InvalidArgumentException('Unknown design type.');
        $row=$kind==='template'?$this->emailTemplate($id,$lock):$this->campaign($id,$lock);
        if($kind==='campaign'&&($row['campaign_type']!=='regular'||$editable&&$row['status']!=='draft'))throw new InvalidArgumentException('Only regular email drafts can be edited.');return $row;
    }
    public function validateDesignConditions(array $design):void
    {
        $fields=['FNAME','LNAME','EMAIL','SOURCE','EMAIL_STATUS'];foreach($this->fields()as$f)$fields[]=$f['merge_tag'];
        $tags=array_map(static fn($id)=>'tag:'.$id,array_column($this->tags(),'id'));$groups=[];foreach($this->groups()as$g)foreach($g['options']as$o)$groups[]='group:'.$o['id'];$allowed=array_merge($fields,$tags,$groups);
        foreach($design['rows']as$row){$conditions=[$row['condition']];foreach($row['columns']as$column)foreach($column as$b)$conditions[]=$b['condition'];foreach($conditions as$condition)foreach($condition['rules']as$rule)if(!in_array($rule['field'],$allowed,true))throw new InvalidArgumentException('A dynamic-content field, tag, or group is unavailable in this audience.');}
    }
    public function recordDesignRevision(string $kind,int $id,int $version,array $design,int $variant=0):void
    {
        if($variant>0)$design['_campaign_variant']=$variant;
        $this->write('INSERT IGNORE INTO marketing_design_revisions(audience_id,owner_type,owner_id,version,design_json,actor_label) VALUES (?,?,?,?,?,?)',[$this->audienceId,$kind,$id,$version,json_encode($design,JSON_THROW_ON_ERROR),$this->actorLabel]);
        // Retain the most recent 30 document versions per owner.
        $older=$this->all('SELECT id FROM marketing_design_revisions WHERE audience_id=? AND owner_type=? AND owner_id=? ORDER BY version DESC LIMIT 100 OFFSET 30',[$this->audienceId,$kind,$id]);
        foreach($older as$row)$this->write('DELETE FROM marketing_design_revisions WHERE audience_id=? AND id=?',[$this->audienceId,$row['id']]);
    }
    public function designRevisions(string $kind,int $id,int $variant=0):array
    {
        $row=$this->designOwner($kind,$id);if($kind==='campaign')$this->campaignDesign($row,$variant);return $this->all("SELECT id,version,actor_label,created_at FROM marketing_design_revisions WHERE audience_id=? AND owner_type=? AND owner_id=? AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(design_json,'$._campaign_variant')),'0')=? ORDER BY version DESC LIMIT 30",[$this->audienceId,$kind,$id,(string)$variant]);
    }
    public function restoreDesignRevision(string $kind,int $id,int $version,int $revisionId,int $variant=0):array
    {
        return $this->transaction(function()use($kind,$id,$version,$revisionId,$variant){$this->designOwner($kind,$id,true,true);$revision=$this->one('SELECT design_json FROM marketing_design_revisions WHERE audience_id=? AND owner_type=? AND owner_id=? AND id=?',[$this->audienceId,$kind,$id,$revisionId]);if(!$revision)throw new InvalidArgumentException('This revision is unavailable.');$raw=audienceJson($revision['design_json']);if((int)($raw['_campaign_variant']??0)!==$variant)throw new InvalidArgumentException('Choose a revision for this content variation.');$design=audienceDesign($raw);$next=$this->saveEmailDesign($kind,$id,$version,$design,$variant);return ['version'=>$next,'design'=>$design];});
    }
    public function designComments(string $kind,int $id):array
    {
        $this->designOwner($kind,$id);return $this->all('SELECT * FROM marketing_design_comments WHERE audience_id=? AND owner_type=? AND owner_id=? ORDER BY created_at,id LIMIT 500',[$this->audienceId,$kind,$id]);
    }
    public function addDesignComment(string $kind,int $id,array $input):void
    {
        $this->transaction(function()use($kind,$id,$input){$this->designOwner($kind,$id,true,true);$body=audienceText($input,'body',4000);if($body==='')throw new InvalidArgumentException('Enter a comment.');$parent=\Dnr\Http\RequestInput::positiveInt($input,'parent_id')??0;$key=audienceText($input,'block_key',80);
            if($parent&&!$this->one('SELECT id FROM marketing_design_comments WHERE audience_id=? AND owner_type=? AND owner_id=? AND id=? AND parent_id IS NULL',[$this->audienceId,$kind,$id,$parent]))throw new InvalidArgumentException('This comment thread is unavailable.');
            if($key!==''&&!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,79}$/D',$key))throw new InvalidArgumentException('Invalid block reference.');
            $this->write('INSERT INTO marketing_design_comments(audience_id,owner_type,owner_id,parent_id,block_key,body,actor_user_id,actor_label) VALUES (?,?,?,?,?,?,?,?)',[$this->audienceId,$kind,$id,$parent?:null,$key,$body,$this->actorId,$this->actorLabel]);
        });
    }
    public function resolveDesignComment(string $kind,int $id,int $commentId,int $version,bool $resolved):void
    {
        $this->transaction(function()use($kind,$id,$commentId,$version,$resolved){$this->designOwner($kind,$id,true,true);$this->write('UPDATE marketing_design_comments SET resolved=?,version=version+1 WHERE audience_id=? AND owner_type=? AND owner_id=? AND id=? AND version=? AND parent_id IS NULL',[(int)$resolved,$this->audienceId,$kind,$id,$commentId,$version]);if($this->db->affected_rows!==1)throw new InvalidArgumentException('This comment changed. Reload the comments.');});
    }
    public function designLibrary(string $type,string $search='',int $page=1):array
    {
        if(!in_array($type,['image','section'],true))throw new InvalidArgumentException('Unknown library type.');$search=audienceDesignString($search,100);$page=max(1,$page);
        $total=(int)$this->one('SELECT COUNT(*) n FROM marketing_design_library WHERE audience_id=? AND item_type=? AND deleted_at IS NULL AND LOCATE(?,name)>0',[$this->audienceId,$type,$search])['n'];
        return ['items'=>$this->all('SELECT id,name,item_type,version,updated_at,actor_label FROM marketing_design_library WHERE audience_id=? AND item_type=? AND deleted_at IS NULL AND LOCATE(?,name)>0 ORDER BY updated_at DESC,id DESC LIMIT 20 OFFSET ?',[$this->audienceId,$type,$search,($page-1)*20]),'total'=>$total,'page'=>$page];
    }
    public function designLibraryItem(int $id,bool $lock=false):array
    {
        $row=$this->one('SELECT * FROM marketing_design_library WHERE audience_id=? AND id=? AND deleted_at IS NULL'.($lock?' FOR UPDATE':''),[$this->audienceId,$id]);if(!$row)throw new InvalidArgumentException('This library item is unavailable.');$row['data']=audienceJson($row['data_json']);return $row;
    }
    public function saveDesignLibrary(string $type,string $name,array $data):int
    {
        $name=trim(audienceDesignString($name,100));if($name==='')throw new InvalidArgumentException('Enter a library item name.');
        if($type==='image'){$image=audienceDesignImage($data['image']??'',1000000);if($image==='')throw new InvalidArgumentException('Upload an image before saving it.');$url=audienceDesignString($data['url']??'',2048);if($url!==''&&!audienceDesignUrl($url))throw new InvalidArgumentException('Enter a valid image link.');$bytes=base64_decode(substr($image,strpos($image,',')+1),true);$info=getimagesizefromstring($bytes);$data=['image'=>$image,'alt'=>audienceDesignString($data['alt']??'',300),'url'=>$url,'bytes'=>strlen($bytes),'width'=>$info[0],'height'=>$info[1]];}
        elseif($type==='section'){$design=audienceDesign($data);$this->validateDesignConditions($design);if(count($design['rows'])!==1)throw new InvalidArgumentException('Save one section at a time.');$data=$design;}
        else throw new InvalidArgumentException('Unknown library type.');
        $this->write('INSERT INTO marketing_design_library(audience_id,item_type,name,data_json,actor_label) VALUES (?,?,?,?,?)',[$this->audienceId,$type,$name,json_encode($data,JSON_THROW_ON_ERROR),$this->actorLabel]);return (int)$this->db->insert_id;
    }
    public function operateDesignLibrary(int $id,int $version,string $operation,string $name=''):void
    {
        $this->transaction(function()use($id,$version,$operation,$name){$row=$this->designLibraryItem($id,true);if((int)$row['version']!==$version)throw new InvalidArgumentException('This library item changed. Reload before continuing.');
            if($operation==='rename'){$name=trim(audienceDesignString($name,100));if($name==='')throw new InvalidArgumentException('Enter a name.');$this->write('UPDATE marketing_design_library SET name=?,version=version+1 WHERE audience_id=? AND id=?',[$name,$this->audienceId,$id]);}
            elseif($operation==='delete')$this->write('UPDATE marketing_design_library SET deleted_at=UTC_TIMESTAMP(6),version=version+1 WHERE audience_id=? AND id=?',[$this->audienceId,$id]);else throw new InvalidArgumentException('Unknown library action.');
        });
    }
    public function designRecipientContext(int $personId,array $audience):array
    {
        $settings=audienceJson($audience['settings_json']);$context=audienceBrandIdentity($settings,(string)$audience['name']);
        if(!$personId)return $context;$person=$this->person($personId);
        $context+=['FNAME'=>$person['first_name'],'LNAME'=>$person['last_name'],'EMAIL'=>$person['email'],'SOURCE'=>$person['source'],'EMAIL_STATUS'=>$person['email_status']];
        foreach($this->fields()as$f){$value=$person['custom'][$f['id']]??'';if($value===''||$value===[])$value=$f['default_value'];$context[$f['merge_tag']]=is_array($value)?implode(', ',$value):(string)$value;}
        foreach($this->tags()as$t)$context['tag:'.$t['id']]=in_array((int)$t['id'],$person['tag_ids'],true)?'1':'';
        foreach($this->groups()as$g)foreach($g['options']as$o)$context['group:'.$o['id']]=in_array((int)$o['id'],$person['group_ids'],true)?'1':'';
        return $context;
    }
}
