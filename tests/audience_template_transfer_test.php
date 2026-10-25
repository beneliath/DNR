<?php
declare(strict_types=1);
require __DIR__.'/../src/audience_template_transfer.php';
require_once __DIR__.'/../src/application_runtime.php';
$checks=0;
$expect=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);++$checks;};
$reject=static function(callable $run,string $message)use($expect):void{$failed=false;try{$run();}catch(InvalidArgumentException){$failed=true;}$expect($failed,$message);};
$design=audienceDesignStarters()['newsletter']['design'];
$file=audienceTemplatePackageExport('Portable newsletter',$design,'source-account','2.7.7');
$package=json_decode($file,true,64,JSON_THROW_ON_ERROR);
$import=audienceTemplatePackageImport($file,'target-account');
$expect($import['name']==='Portable newsletter'&&$import['design']===$design,'Native exports preserve the complete editable design across accounts.');
$expect(audienceDesignHtml($import['design'])===audienceDesignHtml($design),'Imported output matches the exported template.');
foreach([applicationVersion([]),'2.7.8-rc.1','2.7.8+local.1']as$version){
    $localFile=audienceTemplatePackageExport('Local newsletter',$design,'source-account',$version);
    $expect(audienceTemplatePackageImport($localFile,'source-account')['design']===$design,'Exports from development and qualified builds import successfully.');
}
foreach(['<html><body>Not exported</body></html>','{}','[]','not JSON',substr($file,0,-10)]as$bad)$reject(fn()=>audienceTemplatePackageImport($bad,'target-account'),'Reject unrelated or truncated files.');
$change=static function(array $package,callable $fn,bool $rehash=false):string{$fn($package);if($rehash)$package['sha256']=hash('sha256',audienceTemplatePackageJson($package['payload']));return audienceTemplatePackageJson($package);};
foreach([
    static function(&$p){unset($p['format']);},
    static function(&$p){$p['format']='custom-template';},
    static function(&$p){$p['format_version']=2;},
    static function(&$p){$p['payload']['template']['name']='Changed';},
    static function(&$p){$p['extra']='Unrecognized';},
]as$changePackage)$reject(fn()=>audienceTemplatePackageImport($change($package,$changePackage),'target-account'),'Reject missing markers, unsupported formats and checksum mismatches.');
foreach([
    static function(&$p){unset($p['payload']['exported_at']);},
    static function(&$p){$p['payload']['source_account']='';},
    static function(&$p){$p['payload']['application_version']='unrecognized';},
    static function(&$p){$p['payload']['template']['design']['schema']=99;},
    static function(&$p){$p['payload']['template']['design']['rows'][0]['columns'][0][0]['image']='data:text/html;base64,YmFk';},
]as$changePackage)$reject(fn()=>audienceTemplatePackageImport($change($package,$changePackage,true),'target-account'),'Required export metadata and safe design validation remain mandatory.');
$reject(fn()=>audienceTemplatePackageImport(str_repeat('x',AUDIENCE_TEMPLATE_PACKAGE_MAX_BYTES+1),'target-account'),'Oversized files are rejected.');
$reject(fn()=>audienceTemplatePackageUpload(['error'=>UPLOAD_ERR_NO_FILE],'target-account'),'An actual uploaded export file is required.');
$conditional=$design;$conditional['rows'][0]['condition']=['match'=>'all','rules'=>[['field'=>'tag:12','operator'=>'is','value'=>'1']]];
$conditionFile=audienceTemplatePackageExport('Scoped design',$conditional,'source-account','2.7.7');
$expect(audienceTemplatePackageImport($conditionFile,'source-account')['design']===audienceDesign($conditional),'Same-account exports retain audience conditions.');
$reject(fn()=>audienceTemplatePackageImport($conditionFile,'target-account'),'Numeric audience conditions cannot silently bind to another account.');
echo "MessageMonkey template transfer: $checks checks passed.\n";
