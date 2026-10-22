<?php
declare(strict_types=1);

function audienceContentFileSize(array $item):string
{
    if(!in_array($item['item_type'],['image','file'],true))return '';
    $data=$item['data'];$bytes=(int)($data['bytes']??0);
    // Older library entries may predate size metadata. Read the encoded length
    // without allocating another decoded image for every card on the page.
    if($bytes<=0){
        $encoded=$data[$item['item_type']==='image'?'image':'document']??'';
        $offset=strpos($encoded,',');
        if($offset===false)return '';
        $bytes=intdiv((strlen($encoded)-$offset-1)*3,4)-(str_ends_with($encoded,'==')?2:(str_ends_with($encoded,'=')?1:0));
    }
    if($bytes<1000)return $bytes.' B';
    $scale=$bytes>=1000000?1000000:1000;$precision=$bytes>=1000000?2:1;
    return rtrim(rtrim(number_format($bytes/$scale,$precision,'.',''),'0'),'.').($scale===1000000?' MB':' KB');
}

function audienceContentUpload(array $file):string
{
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??''))throw new InvalidArgumentException('Choose a valid uploaded image.');
    $bytes=file_get_contents($file['tmp_name']);if($bytes===false||strlen($bytes)>1000000)throw new InvalidArgumentException('Choose an image up to 1 MB.');
    $info=@getimagesizefromstring($bytes);$mime=$info['mime']??'';if(!in_array($mime,['image/png','image/jpeg','image/webp','image/gif'],true))throw new InvalidArgumentException('Use a PNG, JPEG, WebP or GIF image.');
    return audienceDesignImage('data:'.$mime.';base64,'.base64_encode($bytes));
}

function audienceContentFileUpload(array $file):array
{
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??''))throw new InvalidArgumentException('Choose a valid uploaded file.');
    if(strtolower(pathinfo($file['name']??'',PATHINFO_EXTENSION))!=='pdf')return ['image'=>audienceContentUpload($file)];
    if(filesize($file['tmp_name'])>10000000)throw new InvalidArgumentException('Choose a PDF up to 10 MB.');
    $bytes=file_get_contents($file['tmp_name']);if($bytes===false)throw new InvalidArgumentException('The PDF could not be read.');
    return ['document'=>'data:application/pdf;base64,'.base64_encode($bytes)];
}

function audienceContentDocument(mixed $value):string
{
    if(!is_string($value)||strlen($value)>13400000||!str_starts_with($value,'data:application/pdf;base64,'))throw new InvalidArgumentException('Choose a PDF up to 10 MB.');
    $bytes=base64_decode(substr($value,28),true);
    if($bytes===false||strlen($bytes)>10000000||!str_starts_with($bytes,'%PDF-')||!str_contains(substr($bytes,-1024),'%%EOF')||(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes)!=='application/pdf')throw new InvalidArgumentException('Choose a valid PDF document.');
    return $value;
}

function audienceContentSendDocument(array $item):never
{
    $document=audienceContentDocument($item['data']['document']??'');
    header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="document.pdf"; filename*=UTF-8\'\''.rawurlencode($item['name'].(str_ends_with(strtolower($item['name']),'.pdf')?'':'.pdf')));
    header("Content-Security-Policy: sandbox; default-src 'none'");header('X-Content-Type-Options: nosniff');header('Cache-Control: no-store');
    echo base64_decode(substr($document,28),true);exit;
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
        if(in_array($type,['image','section','file'],true)){$where.=' AND item_type=?';$params[]=$type;}
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
        if(isset($input['document'])){
            $input['document']=audienceContentDocument($input['document']);
            require_once __DIR__.'/document_scanning_helpers.php';
            if(documentScanningEnabled()){
                $stream=fopen('php://temp','w+b');$bytes=base64_decode(substr($input['document'],28),true);fwrite($stream,$bytes);rewind($stream);
                try{if(!scanDocumentStream($stream,strlen($bytes)))throw new InvalidArgumentException('The document did not pass scanning.');}catch(RuntimeException $e){throw new InvalidArgumentException('The document could not be verified. Try again later.',0,$e);}finally{fclose($stream);}
            }
        }
        return $this->transaction(function()use($input,$id,$version,$name){
            $old=$id?$this->designLibraryItem($id,true):null;if($old&&((int)$old['version']!==$version||!in_array($old['item_type'],['image','file'],true)))throw new InvalidArgumentException('This file changed. Reload before saving.');
            if($old&&$old['item_type']==='file'&&trim((string)($input['image']??''))!=='')throw new InvalidArgumentException('Upload a replacement PDF, or create a new image file.');
            $data=$old['data']??[];$document=$input['document']??($data['document']??'');$type=$document!==''?'file':'image';
            if($old&&$old['item_type']!==$type)throw new InvalidArgumentException('Upload a replacement of the same file type, or create a new file.');
            $image=$type==='image'?audienceDesignImage($input['image']??($data['image']??''),1000000):'';if($type==='image'&&$image==='')throw new InvalidArgumentException('Upload an image or PDF before saving.');
            $url=audienceText($input,'link',2048);if($url!==''&&!audienceDesignUrl($url))throw new InvalidArgumentException('Enter a valid image link.');
            $folder=audienceText($input,'folder',100);if($folder!==''&&!in_array($folder,$this->contentSettings()['settings']['content_folders']??[],true))throw new InvalidArgumentException('Choose an existing folder or Unfiled.');
            $encoded=$type==='file'?$document:$image;$bytes=base64_decode(substr($encoded,strpos($encoded,',')+1),true);$info=$type==='image'?getimagesizefromstring($bytes):[0,0];
            $data=array_replace($data,['image'=>$image,'document'=>$document,'alt'=>audienceText($input,'alt',300),'url'=>$url,'folder'=>$folder,'bytes'=>strlen($bytes),'width'=>$info[0],'height'=>$info[1],'published'=>false,'rights_confirmed'=>false]);
            unset($data['public_key']);
            if($old)$this->write('UPDATE marketing_design_library SET name=?,data_json=?,actor_label=?,version=version+1 WHERE audience_id=? AND id=?',[$name,json_encode($data,JSON_THROW_ON_ERROR),$this->actorLabel,$this->audienceId,$id]);
            else{$this->write("INSERT INTO marketing_design_library(audience_id,item_type,name,data_json,actor_label) VALUES (?,?,?,?,?)",[$this->audienceId,$type,$name,json_encode($data,JSON_THROW_ON_ERROR),$this->actorLabel]);$id=(int)$this->db->insert_id;}return $id;
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
        $fontName=$font===''?'':(array_key_exists('web_font_name',$input)?audienceText($input,'web_font_name',255):null);
        $logos=[];foreach(['logo','secondary_logo']as$key)$logos[$key]=audienceDesignImage($input[$key]??'');
        $identity=[];if(array_key_exists('organization_name',$input))$identity['name']=audienceText($input,'organization_name',150);if(array_key_exists('mailing_address',$input))$identity['mailing_address']=audienceText($input,'mailing_address',1000);
        $this->transaction(function()use($styles,$logos,$identity,$fontName,$version){$row=$this->one('SELECT * FROM marketing_audiences WHERE id=? FOR UPDATE',[$this->audienceId]);if((int)$row['version']!==$version)throw new InvalidArgumentException('Brand settings changed. Reload before saving.');$settings=audienceJson($row['settings_json']);$previous=audienceBrandIdentity($settings,(string)$row['name']);$fontName??=($settings['brand']['styles']['webFont']??'')===$styles['webFont']?(string)($settings['brand']['web_font_name']??''):'';$settings['brand']=['styles'=>$styles,'web_font_name'=>$fontName]+$logos+$identity+['name'=>$previous['LIST:COMPANY'],'mailing_address'=>$previous['LIST:ADDRESS']];$this->write('UPDATE marketing_audiences SET settings_json=?,version=version+1 WHERE id=?',[json_encode($settings,JSON_THROW_ON_ERROR),$this->audienceId]);});
    }
    public function brandedDesign(array $design,array $brand,bool $logo=false):array
    {
        $design['styles']=array_replace($design['styles'],$brand['styles']??[]);
        $design['styles']['brandColors']=array_fill_keys(array_keys(AUDIENCE_DESIGN_BRAND_COLORS),true);$design['styles']['webFontDefault']=true;$design['styles']['webFontName']=audienceBrandFont($brand)['name'];
        if($logo&&!empty($brand['logo'])){if($design['mode']==='code')throw new InvalidArgumentException('Insert the logo in the HTML editor for a custom-code template.');$key=bin2hex(random_bytes(8));$row=['id'=>'brand-row-'.$key,'name'=>'Brand logo','columns'=>[[['id'=>'brand-image-'.$key,'type'=>'image','image'=>$brand['logo'],'alt'=>'Logo','align'=>'center','padding'=>24]]]];if(($design['rows'][0]['name']??'')==='Brand logo')$design['rows'][0]=$row;else array_unshift($design['rows'],$row);}
        if($design['mode']==='code'){$font=$design['styles']['webFont']??'';$style=($font!==''?'@font-face{font-family:MOEDBrandFont;src:url('.$font.');font-display:swap}':'').'body{background:'.$design['styles']['background'].';color:'.$design['styles']['text'].';font-family:'.($font!==''?'MOEDBrandFont,':'').$design['styles']['font'].'}a{color:'.$design['styles']['link'].'}';$design['code']=preg_replace('~<style data-moed-brand>.*?</style>~s','',$design['code']);$tag='<style data-moed-brand>'.$style.'</style>';$design['code']=stripos($design['code'],'</head>')!==false?str_ireplace('</head>',$tag.'</head>',$design['code']):$tag.$design['code'];}
        return audienceDesign($design);
    }
    public function applyBrand(int $templateId,int $version,bool $logo):void
    {
        $this->transaction(function()use($templateId,$version,$logo){$template=$this->emailTemplate($templateId,true);$brand=$this->contentSettings()['settings']['brand']??[];$this->saveEmailDesign('template',$templateId,$version,$this->brandedDesign($template['design'],$brand,$logo));});
    }
}
