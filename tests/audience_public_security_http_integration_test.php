<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Public security tests skipped (disposable database required).\n");
require_once (getenv('DNR_TEST_SOURCE_DIR')?:__DIR__.'/../src').'/bootstrap.php';
require_once (getenv('DNR_TEST_SOURCE_DIR')?:__DIR__.'/../src').'/audience_store.php';
$db=applicationDatabaseConnection();$checks=0;
$expect=static function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);$checks++;};
$request=static function(string $path,array $headers=[],?array $post=null):array{
    $curl=curl_init('http://127.0.0.1/'.$path);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>$headers]);
    if($post!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($post));
    $body=curl_exec($curl);if($body===false)throw new RuntimeException('Public fixture request failed.');
    return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>$body];
};
$db->execute_query('INSERT INTO users(username,password,role) VALUES (?,?,?)',['public-security-'.bin2hex(random_bytes(6)),password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT),'admin']);
$store=new AudienceStore($db,1,(int)$db->insert_id,'Public security fixture');
$id=$store->saveCollection(['kind'=>'hosted','name'=>'Public security fixture']);
$draft=$store->collectionResource($id)['draft'];$draft['opt_in']='single';
$store->saveCollection(['name'=>'Public security fixture','document'=>$draft],$id,1);$store->collectionOperation($id,2,'publish');
$resource=$store->collectionResource($id);
$path='audience_collect_public.php?key='.$resource['public_key'].'&visitor=';
$count=static fn():int=>(int)$db->execute_query('SELECT COUNT(*) n FROM marketing_collection_visits WHERE resource_id=?',[$id])->fetch_assoc()['n'];
$bucket=hash('sha256',currentAccountKey().'|collection|visit:127.0.0.1');$hour=gmdate('Y-m-d H:00:00');
$previous=$db->execute_query('SELECT attempts FROM marketing_collection_limits WHERE audience_id=1 AND bucket_hash=? AND hour_start=?',[$bucket,$hour])->fetch_assoc();
try{
    $db->execute_query('INSERT INTO marketing_collection_limits(audience_id,bucket_hash,hour_start,attempts) VALUES (1,?,?,299) ON DUPLICATE KEY UPDATE attempts=299',[$bucket,$hour]);
    $first=$request($path.str_repeat('1',64));$expect($first['status']===200&&$count()===1,'Final admitted view remains usable and recorded');
    $repeat=$request($path.str_repeat('1',64));$expect($repeat['status']===200&&$count()===1,'Repeat visit remains usable without an extra row');
    foreach(['2','3','4']as$visitor){
        $response=$request($path.str_repeat($visitor,64));
        $expect($response['status']===200&&str_contains($response['body'],'name="proof"'),'Analytics cap must not prevent viewing or submitting the form');
        $expect($count()===1,'Rotating visitor identifiers cannot exceed the per-IP analytics budget');
    }
    $forged=$request($path.str_repeat('5',64),['X-Forwarded-For: 198.51.100.123','CF-Connecting-IP: 198.51.100.123']);
    $expect($forged['status']===200&&$count()===1,'Untrusted forwarding headers cannot reset the analytics budget');
    $other=$store->saveCollection(['kind'=>'hosted','name'=>'Second public security fixture']);$store->collectionOperation($other,1,'publish');
    $otherKey=$store->collectionResource($other)['public_key'];
    $otherView=$request('audience_collect_public.php?key='.$otherKey.'&visitor='.str_repeat('6',64));
    $expect($otherView['status']===200&&(int)$db->execute_query('SELECT COUNT(*) n FROM marketing_collection_visits WHERE resource_id=?',[$other])->fetch_assoc()['n']===0,'Switching forms does not reset the analytics budget');
    preg_match('/name="proof" value="([^"]+)"/',$forged['body'],$proofMatch);
    $proof=json_decode(\Dnr\Security\ApplicationKey::open(html_entity_decode($proofMatch[1]??'',ENT_QUOTES)),true,12,JSON_THROW_ON_ERROR);$proof['issued']-=5;
    $email='public-security-'.bin2hex(random_bytes(6)).'@example.test';
    $post=['proof'=>\Dnr\Security\ApplicationKey::seal(json_encode($proof,JSON_THROW_ON_ERROR)),'email'=>$email,'first_name'=>'Security','last_name'=>'Fixture','website'=>'','permission'=>'1'];
    $submitted=$request($path.str_repeat('5',64),[],$post);
    $expect($submitted['status']===200&&(int)$db->execute_query('SELECT COUNT(*) n FROM marketing_contacts WHERE audience_id=1 AND email=?',[$email])->fetch_assoc()['n']===1,'The analytics cap does not block a valid signup');
    $expect($request('audience_collect_public.php?key='.str_repeat('0',64))['status']===404,'Unknown forms remain unavailable');
}finally{
    if($previous)$db->execute_query('UPDATE marketing_collection_limits SET attempts=? WHERE audience_id=1 AND bucket_hash=? AND hour_start=?',[$previous['attempts'],$bucket,$hour]);
    else $db->execute_query('DELETE FROM marketing_collection_limits WHERE audience_id=1 AND bucket_hash=? AND hour_start=?',[$bucket,$hour]);
}
echo "Public form analytics admission and continued access: $checks checks passed.\n";
