<?php
declare(strict_types=1);
require_once __DIR__.'/audience_design.php';

const AUDIENCE_TEMPLATE_PACKAGE_FORMAT='moed-message-monkey-email-template';
const AUDIENCE_TEMPLATE_PACKAGE_MAX_BYTES=AUDIENCE_DESIGN_MAX_BYTES+65536;

function audienceTemplatePackageJson(array $value):string
{
    return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}

/** Portable snapshots contain fonts/images, never credentials or recipient records. */
function audienceTemplatePackageExport(string $name,array $design,string $account,string $version):string
{
    $payload=['exported_at'=>gmdate('Y-m-d\TH:i:s\Z'),'application_version'=>$version,'source_account'=>$account,'template'=>['name'=>audienceDesignString(trim($name),100),'design'=>audienceDesign($design)]];
    $file=audienceTemplatePackageJson(['format'=>AUDIENCE_TEMPLATE_PACKAGE_FORMAT,'format_version'=>1,'payload'=>$payload,'sha256'=>hash('sha256',audienceTemplatePackageJson($payload))]);
    if(strlen($file)>AUDIENCE_TEMPLATE_PACKAGE_MAX_BYTES)throw new InvalidArgumentException('This template exceeds the export size limit.');
    return $file;
}

/** Validate the native export format and integrity; a checksum does not prove authorship. */
function audienceTemplatePackageImport(string $file,string $account):array
{
    $invalid='Choose a valid .mmtemplate file exported from MessageMonkey.';
    if($file===''||strlen($file)>AUDIENCE_TEMPLATE_PACKAGE_MAX_BYTES)throw new InvalidArgumentException($invalid);
    try{$package=json_decode($file,true,64,JSON_THROW_ON_ERROR);}catch(JsonException){throw new InvalidArgumentException($invalid);}
    if(!is_array($package)||array_diff(array_keys($package),['format','format_version','payload','sha256'])||($package['format']??'')!==AUDIENCE_TEMPLATE_PACKAGE_FORMAT||($package['format_version']??null)!==1||!is_array($package['payload']??null)||!is_string($package['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$package['sha256']))throw new InvalidArgumentException($invalid);
    $payload=$package['payload'];
    if(!hash_equals($package['sha256'],hash('sha256',audienceTemplatePackageJson($payload))))throw new InvalidArgumentException('The template export is incomplete or has changed. Export it again from MessageMonkey.');
    // Match applicationVersion(), including local development and qualified build versions.
    if(array_diff(array_keys($payload),['exported_at','application_version','source_account','template'])||!is_string($payload['exported_at']??null)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$payload['exported_at'])||!is_string($payload['application_version']??null)||!preg_match('/\A(?:dev|[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?)\z/',$payload['application_version'])||!is_string($payload['source_account']??null)||!preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D',$payload['source_account'])||!is_array($payload['template']??null))throw new InvalidArgumentException($invalid);
    $template=$payload['template'];
    if(array_diff(array_keys($template),['name','design'])||!is_string($template['name']??null)||trim($template['name'])===''||!is_array($template['design']??null))throw new InvalidArgumentException($invalid);
    $name=audienceDesignString(trim($template['name']),100);$design=audienceDesign($template['design']);
    if($payload['source_account']!==$account){
        // Numeric audience references can identify unrelated tags/groups on another account.
        foreach($design['rows']as$row){$conditions=[$row['condition']];foreach($row['columns']as$column)foreach($column as$block)$conditions[]=$block['condition'];foreach($conditions as$condition)foreach($condition['rules']as$rule)if(!in_array($rule['field'],['FNAME','LNAME','EMAIL','SOURCE','EMAIL_STATUS'],true))throw new InvalidArgumentException('Remove audience-specific dynamic conditions before exporting this template to another account.');}
    }
    return ['name'=>$name,'design'=>$design];
}

function audienceTemplatePackageUpload(array $upload,string $account):array
{
    if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($upload['name']??null)||strtolower(pathinfo($upload['name'],PATHINFO_EXTENSION))!=='mmtemplate'||!is_string($upload['tmp_name']??null)||!is_uploaded_file($upload['tmp_name']))throw new InvalidArgumentException('Choose a .mmtemplate file exported from MessageMonkey.');
    $size=filesize($upload['tmp_name']);if($size===false||$size>AUDIENCE_TEMPLATE_PACKAGE_MAX_BYTES)throw new InvalidArgumentException('Choose a template export up to 10 MB.');
    $file=file_get_contents($upload['tmp_name'],false,null,0,AUDIENCE_TEMPLATE_PACKAGE_MAX_BYTES+1);if($file===false)throw new InvalidArgumentException('Unable to read this template export.');
    return audienceTemplatePackageImport($file,$account);
}
