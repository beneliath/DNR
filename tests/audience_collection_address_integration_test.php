<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Collection address integration requires a disposable database.\n");
$source=getenv('DNR_TEST_SOURCE_DIR')?:__DIR__.'/../src';
require_once $source.'/bootstrap.php';
require_once $source.'/audience_store.php';
require_once $source.'/audience_collection_render.php';
$db=applicationDatabaseConnection();$store=new AudienceStore($db,1,0,'Address qualification');$suffix=bin2hex(random_bytes(5));$checks=0;
$expect=static function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);++$checks;};
$field=$store->saveField(['label'=>'Mailing address '.$suffix,'merge_tag'=>'ADDR_'.strtoupper($suffix),'field_type'=>'address','is_public'=>'1'],0,0);
$makeForm=static function(bool $required)use($store,$suffix,$field):array{
    $id=$store->saveCollection(['kind'=>'hosted','name'=>'Address signup '.$suffix.' '.($required?'required':'optional')]);
    $document=$store->collectionResource($id)['draft'];$document['opt_in']='single';$document['fields']=['email','custom:'.$field];
    $document['required']=$required?['email','custom:'.$field]:['email'];$document['tag_ids']=$document['group_ids']=[];
    $store->saveCollection(['name'=>'Address signup '.$suffix,'document'=>$document],$id,1);$store->collectionOperation($id,2,'publish');
    return $store->collectionPublished($store->collectionResource($id)['public_key']);
};
$submit=static function(array $resource,string $email,array $address)use($store,$suffix,$field):array{
    $proof=$store->collectionChallenge($resource,bin2hex(random_bytes(32)));
    $data=json_decode(\Dnr\Security\ApplicationKey::open($proof),true,12,JSON_THROW_ON_ERROR);$data['issued']-=5;
    return $store->submitCollection($resource,['proof'=>\Dnr\Security\ApplicationKey::seal(json_encode($data,JSON_THROW_ON_ERROR)),
        'email'=>$email,'permission'=>'1','custom'=>[$field=>$address]],'address-'.$suffix,'https://newsletter.example.test');
};
$required=$makeForm(true);$optional=$makeForm(false);
$email='address-'.$suffix.'@example.test';
$address=['address1'=>'PO Box 1966','address2'=>'','city'=>'Brentwood','region'=>'Tennessee','postal_code'=>'37024-1966','country'=>'US'];
$result=$submit($required,$email,$address);
$person=$store->one('SELECT * FROM marketing_contacts WHERE audience_id=1 AND email=?',[$email]);$id=(int)$person['id'];
$custom=audienceJson($person['custom_json']);$expected="PO Box 1966\n\nBrentwood\nTN\n37024-1966\nUS";
$expect($result['confirmed']===false&&$person['email_status']==='subscribed','Single opt-in semantics stay intact');
$expect($custom[$field]===$expected,'The real signup transaction stores all address parts in their connection slots');
$expect(audienceAddressParts($store->person($id)['custom'][$field])===array_replace($address,['region'=>'TN']),'The connection editor reads the exact saved components');
$submit($required,$email,array_replace($address,['city'=>'Must not overwrite']));
$expect($store->person($id)['custom'][$field]===$expected,'Public repeat signup cannot overwrite an existing connection address');
$preferences=array_replace($address,['address1'=>'100 King Street','city'=>'Toronto','region'=>'Ontario','postal_code'=>'M5V 1J2','country'=>'CA']);
$person=$store->person($id);$store->savePublicPreferences($id,(int)$person['version'],['custom'=>[$field=>$preferences]],['fields'=>['custom:'.$field],'group_ids'=>[]]);
$expect($store->person($id)['custom'][$field]==="100 King Street\n\nToronto\nON\nM5V 1J2\nCA",'Public preference edits use the same structured address contract');
$blank='blank-address-'.$suffix.'@example.test';$submit($optional,$blank,['country'=>'US']);
$blankPerson=$store->one('SELECT custom_json FROM marketing_contacts WHERE audience_id=1 AND email=?',[$blank]);
$expect(audienceJson($blankPerson['custom_json'])[$field]==='','A blank optional address is saved as blank despite the country default');
$invalid='invalid-address-'.$suffix.'@example.test';
try{$submit($required,$invalid,['country'=>'US']);throw new LogicException('Missing required street accepted');}catch(InvalidArgumentException){}
$expect(!$store->one('SELECT id FROM marketing_contacts WHERE audience_id=1 AND email=?',[$invalid]),'Validation failure cannot leave a partial connection');
$public=audienceCollectionHtml($required,$store->fields(),$store->groups(),'/subscribe');
$expect(str_contains($public,'name="custom['.$field.'][address1]"')&&str_contains($public,'name="custom['.$field.'][postal_code]"'),'Published form fields match the real stored custom field');
echo "Collection address integration: $checks checks passed; no real email sent.\n";
