<?php
declare(strict_types=1);
require_once __DIR__.'/audience_design.php';
require_once __DIR__.'/account_mail_helpers.php';

const AUDIENCE_INBOX_ATTACHMENT_BYTES=5*1024*1024;
const AUDIENCE_INBOX_ATTACHMENTS_BYTES=6*1024*1024;

function audienceInboxInstalled(mysqli $db):bool
{
    if(!(int)$db->query("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='marketing_inbox_settings'")->fetch_assoc()['n'])return false;
    return (int)($db->query('SELECT runtime_version FROM marketing_inbox_settings WHERE audience_id=1')->fetch_assoc()['runtime_version']??0)===1;
}
function audienceInboxEmail(string $value):string
{
    $value=strtolower(trim($value));
    if(strlen($value)>254||!filter_var($value,FILTER_VALIDATE_EMAIL)||preg_match('/[\x00-\x20\x7f]/',$value))throw new InvalidArgumentException('Enter one valid email address.');
    return $value;
}
function audienceInboxSubject(string $value):string
{
    if(mb_strlen($value)>250||preg_match('/[\x00-\x1f\x7f]/',$value))throw new InvalidArgumentException('Use a subject up to 250 characters without line breaks.');
    return trim($value);
}
function audienceInboxPlain(string $html):string
{
    return trim(html_entity_decode(strip_tags(preg_replace('/<\/(?:p|div|li|h[1-6])>|<br\s*\/?\s*>/i',"\n",$html)??$html),ENT_QUOTES|ENT_HTML5,'UTF-8'));
}
function audienceInboxReferences(string $headers):array
{
    $out=[];
    $headers=preg_replace('/\r?\n[ \t]+/',' ',$headers)??'';
    if(preg_match_all('/^(?:Message-ID|In-Reply-To|References):([^\r\n]{1,8000})/mi',$headers,$matches))foreach($matches[1]as$value){
        if(preg_match_all('/<[A-Za-z0-9.!#$%&\x27*+\/=?^_`{|}~@-]{1,500}>/',$value,$ids))foreach($ids[0]as$id)$out[$id]=true;
    }
    return array_slice(array_keys($out),-20);
}
function audienceInboxAutoReply(string $headers):bool
{
    $headers=preg_replace('/\r?\n[ \t]+/',' ',$headers)??'';
    return (bool)preg_match('/^(?:Auto-Submitted:\s*(?!no\b)\S+|X-Autoreply:\s*yes|X-Autorespond:\s*yes|Precedence:\s*(?:bulk|junk|list)\b)/mi',$headers);
}
/** File names/MIME/bytes are all validated; downloads never execute or render inline. */
function audienceInboxAttachment(string $name,string $bytes,bool $scan=true):array
{
    if($bytes===''||strlen($bytes)>AUDIENCE_INBOX_ATTACHMENT_BYTES)throw new InvalidArgumentException('Each attachment must be no larger than 5 MB.');
    $name=basename(str_replace('\\','/',$name));$name=preg_replace('/[\x00-\x1f\x7f]/','',$name)??'';
    if($name===''||mb_strlen($name)>180)throw new InvalidArgumentException('Choose an attachment with a file name up to 180 characters.');
    $types=['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif'];
    $extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));$mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    if(!isset($types[$extension])||$mime!==$types[$extension])throw new InvalidArgumentException('Use a PDF, JPG, PNG or GIF attachment with its matching file extension.');
    if($mime==='application/pdf'&&!str_starts_with($bytes,'%PDF-'))throw new InvalidArgumentException('This PDF is invalid.');
    if(str_starts_with($mime,'image/')&&(!($info=@getimagesizefromstring($bytes))||($info['mime']??'')!==$mime||($info[0]??0)*($info[1]??0)>40_000_000))throw new InvalidArgumentException('This image is invalid or exceeds 40 million pixels.');
    if($scan&&getenv('DNR_DOCUMENT_SCANNING')==='1'){
        require_once __DIR__.'/document_scanning_helpers.php';$stream=fopen('php://temp','w+b');
        try{fwrite($stream,$bytes);rewind($stream);if(!scanDocumentStream($stream,strlen($bytes)))throw new InvalidArgumentException('The attachment failed its security scan.');}
        finally{fclose($stream);}
    }
    return ['filename'=>$name,'content_type'=>$mime,'data'=>$bytes,'size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)];
}
function audienceInboxContext(array $person,array $audience):array
{
    return ['FNAME'=>(string)($person['first_name']??''),'LNAME'=>(string)($person['last_name']??''),'EMAIL'=>(string)($person['email']??''),'LIST:COMPANY'=>(string)($audience['name']??'')];
}
