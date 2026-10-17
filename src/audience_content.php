<?php
declare(strict_types=1);

function audienceContentUpload(array $file):string
{
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??''))throw new InvalidArgumentException('Choose a valid uploaded image.');
    $bytes=file_get_contents($file['tmp_name']);if($bytes===false||strlen($bytes)>700000)throw new InvalidArgumentException('Choose an image up to 700 KB.');
    $info=@getimagesizefromstring($bytes);$mime=$info['mime']??'';if(!in_array($mime,['image/png','image/jpeg','image/webp'],true))throw new InvalidArgumentException('Use a PNG, JPEG or WebP image.');
    return audienceDesignImage('data:'.$mime.';base64,'.base64_encode($bytes));
}

trait AudienceContent
{
    public function contentSettings():array
    {
        $row=$this->one('SELECT settings_json,version FROM marketing_audiences WHERE id=?',[$this->audienceId]);return ['settings'=>audienceJson($row['settings_json']),'version'=>(int)$row['version']];
    }
    public function contentList(array $input):array
    {
        $search=audienceText($input,'search',100);$folder=audienceText($input,'folder',100);$type=audienceText($input,'type');$trash=($input['trash']??'')==='1';$sort=in_array($input['sort']??'',['name','updated_at','created_at','size'],true)?$input['sort']:'updated_at';$direction=($input['direction']??'desc')==='asc'?'asc':'desc';$pageSize=in_array((int)($input['per_page']??25),[10,25,50,100],true)?(int)($input['per_page']??25):25;
        $where='audience_id=? AND deleted_at IS '.($trash?'NOT ':'').'NULL AND LOCATE(?,name)>0';$params=[$this->audienceId,$search];
        if(in_array($type,['image','section'],true)){$where.=' AND item_type=?';$params[]=$type;}
        if($folder!==''){$where.=" AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(data_json,'$.folder')),'')=?";$params[]=$folder==='unfiled'?'':$folder;}
        $total=(int)$this->one("SELECT COUNT(*) n FROM marketing_design_library WHERE $where",$params)['n'];$page=max(1,min(max(1,(int)ceil($total/$pageSize)),(int)($input['page']??1)));$order=$sort==='size'?"COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(data_json,'$.bytes')) AS UNSIGNED),0)":$sort;
        // Sort the bounded ID page before fetching large embedded image documents.
        if($sort==='size'){
            // MySQL includes the JSON argument in filesort even when only IDs are selected.
            $sizes=$this->all("SELECT id,$order byte_size FROM marketing_design_library WHERE $where",$params);
            usort($sizes,static fn($a,$b)=>($direction==='asc'?1:-1)*([(int)$a['byte_size'],(int)$a['id']]<=>[(int)$b['byte_size'],(int)$b['id']]));
            $ids=array_column(array_slice($sizes,($page-1)*$pageSize,$pageSize),'id');
        }else $ids=array_column($this->all("SELECT id FROM marketing_design_library WHERE $where ORDER BY $order $direction,id $direction LIMIT $pageSize OFFSET ?",[...$params,($page-1)*$pageSize]),'id');
        $byId=[];if($ids)foreach($this->all('SELECT * FROM marketing_design_library WHERE audience_id=? AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')',[$this->audienceId,...$ids])as$item){$item['data']=audienceJson($item['data_json']);$byId[$item['id']]=$item;}
        $items=[];foreach($ids as$id)if(isset($byId[$id]))$items[]=$byId[$id];
        return ['items'=>$items,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$pageSize)),'per_page'=>$pageSize,'search'=>$search,'folder'=>$folder,'type'=>$type,'sort'=>$sort,'direction'=>$direction,'trash'=>$trash,'layout'=>($input['layout']??'')==='list'?'list':'grid'];
    }
    public function saveContentItem(array $input,int $id=0,int $version=0):int
    {
        $name=audienceText($input,'name',100);if($name==='')throw new InvalidArgumentException('Enter a file name.');
        return $this->transaction(function()use($input,$id,$version,$name){
            $old=$id?$this->designLibraryItem($id,true):null;if($old&&((int)$old['version']!==$version||$old['item_type']!=='image'))throw new InvalidArgumentException('This file changed. Reload before saving.');
            $data=$old['data']??[];$image=audienceDesignImage($input['image']??($data['image']??''));if($image==='')throw new InvalidArgumentException('Upload an image before saving.');
            $url=audienceText($input,'link',2048);if($url!==''&&!audienceDesignUrl($url))throw new InvalidArgumentException('Enter a valid image link.');
            $published=($input['published']??'')==='1';if($published&&($input['rights_confirmed']??'')!=='1')throw new InvalidArgumentException('Confirm that you may publish this image.');
            $folder=audienceText($input,'folder',100);if($folder!==''&&!in_array($folder,$this->contentSettings()['settings']['content_folders']??[],true))throw new InvalidArgumentException('Choose an existing folder or Unfiled.');
            $bytes=base64_decode(substr($image,strpos($image,',')+1),true);$info=getimagesizefromstring($bytes);
            $data=array_replace($data,['image'=>$image,'alt'=>audienceText($input,'alt',300),'url'=>$url,'folder'=>$folder,'bytes'=>strlen($bytes),'width'=>$info[0],'height'=>$info[1],'published'=>$published,'public_key'=>$data['public_key']??bin2hex(random_bytes(32)),'rights_confirmed'=>$published]);
            if($old)$this->write('UPDATE marketing_design_library SET name=?,data_json=?,actor_label=?,version=version+1 WHERE audience_id=? AND id=?',[$name,json_encode($data,JSON_THROW_ON_ERROR),$this->actorLabel,$this->audienceId,$id]);
            else{$this->write("INSERT INTO marketing_design_library(audience_id,item_type,name,data_json,actor_label) VALUES (?,'image',?,?,?)",[$this->audienceId,$name,json_encode($data,JSON_THROW_ON_ERROR),$this->actorLabel]);$id=(int)$this->db->insert_id;}return $id;
        });
    }
    public function contentOperation(string $operation,array $ids,array $versions,string $folder=''):void
    {
        $ids=audienceIds($ids);sort($ids);if(!$ids||count($ids)>5000||!in_array($operation,['move','delete','restore'],true))throw new InvalidArgumentException('Choose files and a supported action.');$folder=audienceText(['folder'=>$folder],'folder',100);
        $this->transaction(function()use($ids,$versions,$folder,$operation){if($operation==='move'&&$folder!==''&&!in_array($folder,$this->contentSettings()['settings']['content_folders']??[],true))throw new InvalidArgumentException('Choose an existing folder or Unfiled.');$items=[];foreach($ids as$id){$item=$this->one('SELECT * FROM marketing_design_library WHERE audience_id=? AND id=? FOR UPDATE',[$this->audienceId,$id]);if(!$item||(int)$item['version']!==(int)($versions[$id]??0)||($operation==='restore')!==($item['deleted_at']!==null))throw new InvalidArgumentException('A selected file changed. Reload before continuing.');$items[]=$item;}
            foreach($items as$item){$data=audienceJson($item['data_json']);if($operation==='move')$data['folder']=$folder;else $data['published']=false;$this->write('UPDATE marketing_design_library SET data_json=?,deleted_at='.($operation==='delete'?'UTC_TIMESTAMP(6)':'NULL').',version=version+1 WHERE audience_id=? AND id=?',[json_encode($data,JSON_THROW_ON_ERROR),$this->audienceId,$item['id']]);}
        });
    }
    public function contentFolder(string $operation,string $name,string $newName,int $version):void
    {
        $name=audienceText(['name'=>$name],'name',100);$newName=audienceText(['name'=>$newName],'name',100);if($name===''||!in_array($operation,['create','rename','delete'],true)||($operation==='rename'&&$newName===''))throw new InvalidArgumentException('Choose a folder and valid name.');
        $this->transaction(function()use($operation,$name,$newName,$version){$row=$this->one('SELECT * FROM marketing_audiences WHERE id=? FOR UPDATE',[$this->audienceId]);if((int)$row['version']!==$version)throw new InvalidArgumentException('Content settings changed. Reload before continuing.');$settings=audienceJson($row['settings_json']);$folders=$settings['content_folders']??[];
            if(strtolower($operation==='rename'?$newName:$name)==='unfiled')throw new InvalidArgumentException('Unfiled is reserved for files without a folder.');
            if($operation==='create'){if(in_array($name,$folders,true)||count($folders)>=100)throw new InvalidArgumentException('Choose a unique folder name; up to 100 folders are supported.');$folders[]=$name;}else{if(!in_array($name,$folders,true))throw new InvalidArgumentException('This folder is unavailable.');if($operation==='rename'&&in_array($newName,$folders,true))throw new InvalidArgumentException('That folder already exists.');$folders=array_values(array_filter($folders,static fn($f)=>$f!==$name));if($operation==='rename')$folders[]=$newName;
                foreach($this->all("SELECT id,data_json FROM marketing_design_library WHERE audience_id=? AND JSON_UNQUOTE(JSON_EXTRACT(data_json,'$.folder'))=? FOR UPDATE",[$this->audienceId,$name])as$item){$data=audienceJson($item['data_json']);$data['folder']=$operation==='rename'?$newName:'';$this->write('UPDATE marketing_design_library SET data_json=?,version=version+1 WHERE audience_id=? AND id=?',[json_encode($data,JSON_THROW_ON_ERROR),$this->audienceId,$item['id']]);}
            }sort($folders);$settings['content_folders']=$folders;$this->write('UPDATE marketing_audiences SET settings_json=?,version=version+1 WHERE id=?',[json_encode($settings,JSON_THROW_ON_ERROR),$this->audienceId]);
        });
    }
    public function contentUsage(array $item):int
    {
        if($item['item_type']!=='image')return 0;$image=$item['data']['image'];$count=0;foreach(['marketing_campaigns'=>'draft_json','marketing_email_templates'=>'design_json']as$table=>$column)$count+=(int)$this->one("SELECT COUNT(*) n FROM $table WHERE audience_id=? AND deleted_at IS NULL AND JSON_SEARCH($column,'one',?) IS NOT NULL",[$this->audienceId,$image])['n'];return $count;
    }
    public function saveBrand(array $input,int $version):void
    {
        $brandKeys=['background','body','text','link','button','buttonText','font','buttonRadius','fontSize'];$styles=[];foreach($brandKeys as$key)if(isset($input[$key]))$styles[$key]=$input[$key];$styles=array_intersect_key(audienceDesign(['schema'=>1,'styles'=>$styles,'rows'=>[]])['styles'],array_flip($brandKeys));
        $font=audienceDesignWebFont($input['web_font']??'');if($font!==''&&($input['font_license']??'')!=='1')throw new InvalidArgumentException('Confirm your license permits embedding the uploaded font.');$styles['webFont']=$font;
        $logos=[];foreach(['logo','secondary_logo']as$key){$library=(int)audienceText($input,$key.'_library',12);$logos[$key]=$library?$this->designLibraryItem($library)['data']['image']??'':audienceDesignImage($input[$key]??'');$logos[$key]=audienceDesignImage($logos[$key]);if($library&&$logos[$key]==='')throw new InvalidArgumentException('Choose an image from your library.');}
        $this->transaction(function()use($styles,$logos,$version){$row=$this->one('SELECT * FROM marketing_audiences WHERE id=? FOR UPDATE',[$this->audienceId]);if((int)$row['version']!==$version)throw new InvalidArgumentException('Brand settings changed. Reload before saving.');$settings=audienceJson($row['settings_json']);$settings['brand']=['styles'=>$styles]+$logos;$this->write('UPDATE marketing_audiences SET settings_json=?,version=version+1 WHERE id=?',[json_encode($settings,JSON_THROW_ON_ERROR),$this->audienceId]);});
    }
    public function brandedDesign(array $design,array $brand,bool $logo=false):array
    {
        $design['styles']=array_replace($design['styles'],$brand['styles']??[]);
        if($logo&&!empty($brand['logo'])){if($design['mode']==='code')throw new InvalidArgumentException('Insert the logo in the HTML editor for a custom-code template.');$key=bin2hex(random_bytes(8));$row=['id'=>'brand-row-'.$key,'name'=>'Brand logo','columns'=>[[['id'=>'brand-image-'.$key,'type'=>'image','image'=>$brand['logo'],'alt'=>'Logo','align'=>'center','padding'=>24]]]];if(($design['rows'][0]['name']??'')==='Brand logo')$design['rows'][0]=$row;else array_unshift($design['rows'],$row);}
        if($design['mode']==='code'){$font=$design['styles']['webFont']??'';$style=($font!==''?'@font-face{font-family:MOEDBrandFont;src:url('.$font.');font-display:swap}':'').'body{background:'.$design['styles']['background'].';color:'.$design['styles']['text'].';font-family:'.($font!==''?'MOEDBrandFont,':'').$design['styles']['font'].'}a{color:'.$design['styles']['link'].'}';$design['code']=preg_replace('~<style data-moed-brand>.*?</style>~s','',$design['code']);$tag='<style data-moed-brand>'.$style.'</style>';$design['code']=stripos($design['code'],'</head>')!==false?str_ireplace('</head>',$tag.'</head>',$design['code']):$tag.$design['code'];}
        return audienceDesign($design);
    }
    public function applyBrand(int $templateId,int $version,bool $logo):void
    {
        $this->transaction(function()use($templateId,$version,$logo){$template=$this->emailTemplate($templateId,true);$brand=$this->contentSettings()['settings']['brand']??[];$this->saveEmailDesign('template',$templateId,$version,$this->brandedDesign($template['design'],$brand,$logo));});
    }
}
