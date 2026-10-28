<?php
declare(strict_types=1);
require_once __DIR__.'/../src/functions.php';
require_once __DIR__.'/../src/audience_store.php';
require_once __DIR__.'/../src/audience_collection_render.php';
$checks=0;
$expect=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);++$checks;};
$reject=static function(callable $call)use($expect):void{try{$call();}catch(InvalidArgumentException){$expect(true,'Invalid phone rejected');return;}throw new RuntimeException('Expected rejection');};
$phone=['id'=>12,'label'=>'Phone Number','field_type'=>'phone','required'=>false,'is_public'=>true,'options_json'=>'[]'];
$expect(audienceCollectionFieldValue($phone,['country_code'=>'+1','number'=>'312-555-0199'])==='+13125550199','US numbers use MOED normalization');
$expect(audienceCollectionFieldValue($phone,['country_code'=>'+44','number'=>'020 7946 0958'])==='+442079460958','International numbers use the selected country code');
$expect(audienceCollectionFieldValue($phone,['country_code'=>'+1','number'=>''])==='','An empty optional phone stays empty');
$expect(audienceCollectionFieldValue($phone,'Legacy phone with extension')==='Legacy phone with extension','Legacy scalar records are preserved');
foreach([['country_code'=>'+1','number'=>'123'],['country_code'=>'invalid','number'=>'3125550199'],['country_code'=>'+1','number'=>'+442079460958'],['country_code'=>['+1'],'number'=>'3125550199'],['country_code'=>'+1','number'=>['3125550199']]]as$value)$reject(fn()=>audienceCollectionFieldValue($phone,$value));
$required=array_replace($phone,['required'=>true]);
$reject(fn()=>audienceCollectionFieldValue($required,['country_code'=>'+1','number'=>'']));
foreach(['hosted','landing','popup','embedded','preferences']as$kind){
    $doc=audienceCollectionDefaults($kind);$doc['fields']=['email','custom:12'];$doc['required']=['email','custom:12'];
    $resource=['kind'=>$kind,'document'=>$doc];
    $expect(audienceCollectionHasPhone($resource,[$phone]),'Selected public phone fields load the shared behavior');
    $preview=audienceCollectionHtml($resource,[$phone],[],'','',[],'','',null,true);
    $expect(str_contains($preview,'data-phone-country-picker')&&str_contains($preview,'name="custom[12][number]"')&&str_contains($preview,'data-phone-number required'),'Preview uses the MOED phone tool and required national input');
    $expect(!str_contains($preview,'<script'),'Preview has no executable frame script');
    $public=audienceCollectionHtml($resource,[$phone],[],'','',['custom'=>[12=>'+442079460958']]);
    $expect(str_contains($public,'value="+44" data-phone-country-code')&&str_contains($public,'value="020 7946 0958"'),'Existing canonical numbers prefill both components');
    $expect(str_contains($public,'assets/js/phone-input.min.js')&&str_contains($public,'nonce="'),'Public phone behavior loads as a nonce-authorized packaged asset');
    $retry=audienceCollectionHtml($resource,[$phone],[],'','',['custom'=>[12=>['country_code'=>'+44','number'=>'invalid']]],'Invalid phone');
    $expect(str_contains($retry,'value="+44" data-phone-country-code')&&str_contains($retry,'value="invalid"'),'Validation errors retain the selected country and entered number');
    $resource['document']['fields']=['email'];
    $expect(!audienceCollectionHasPhone($resource,[$phone]),'Unselected phone fields do not load extra behavior');
}
$resource=['kind'=>'embedded','document'=>$doc];
foreach(['disable_javascript','remove_css']as$option){
    $resource['document']=audienceCollectionDefaults('embedded');$resource['document']['fields']=['email','custom:12'];$resource['document']['embedded'][$option]=true;
    $html=audienceCollectionHtml($resource,[$phone],[],'','');
    $expect(str_contains($html,'<select name="custom[12][country_code]"')&&str_contains($html,'data-phone-inputs="native"'),'JavaScript-free and unstyled embeds use a native country picker');
    $expect(!str_contains($html,'assets/js/phone-input.min.js'),'Native fallback omits the phone script');
}
$survey=audienceCollectionDefaults('survey');$survey['questions']=[['id'=>'contact','type'=>'contact','label'=>'Contact','required'=>false,'options'=>[]]];
$expect(audienceCollectionHasPhone(['kind'=>'survey','document'=>$survey],[]),'Survey contact questions use the same phone tool');
$answers=audienceCollectionAnswers($survey,['answers'=>['contact'=>['phone'=>['country_code'=>'+44','number'=>'020 7946 0958']]]]);
$expect($answers['contact']['phone']==='+442079460958','Survey answers normalize the component value too');
echo "Collection phone: $checks checks passed.\n";
