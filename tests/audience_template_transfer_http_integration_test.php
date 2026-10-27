<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Disposable template transfer HTTP environment required.\n");
ob_start();
$source=getenv('DNR_TEST_SOURCE_DIR')?:__DIR__.'/../src';
require_once $source.'/bootstrap.php';require_once $source.'/audience_store.php';require_once $source.'/audience_template_transfer.php';require_once __DIR__.'/integration_auth_helpers.php';
$conn=applicationDatabaseConnection();$checks=0;$files=[];$clients=[];$suffix=bin2hex(random_bytes(5));
function transferExpect(bool $condition,string $message):void{global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
function transferCsrf(array $response):string{preg_match('/name="csrf_token"[^>]*value="([^"]+)"/',$response['body'],$match);if(empty($match[1]))preg_match('/"csrf":"([^"]+)"/',$response['body'],$match);if(empty($match[1]))throw new RuntimeException('Template transfer CSRF unavailable.');return html_entity_decode($match[1],ENT_QUOTES);}
try{
    foreach(['admin','editor','reviewer']as$role){
        $username='transfer-'.$role.'-'.$suffix;$password=bin2hex(random_bytes(16));
        $conn->execute_query('INSERT INTO users(username,password,role) VALUES (?,?,?)',[$username,password_hash($password,PASSWORD_DEFAULT),$role]);
        $cookie=tempnam('/tmp','transfer-cookie-');$files[]=$cookie;
        $client=static function(string $path,?array $post=null)use($cookie):array{
            $curl=curl_init('http://127.0.0.1/'.$path);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>30]);
            if($post!==null){$multipart=count(array_filter($post,static fn($value)=>$value instanceof CURLFile))>0;curl_setopt($curl,CURLOPT_POSTFIELDS,$multipart?$post:http_build_query($post));}
            $raw=curl_exec($curl);if($raw===false)throw new RuntimeException('Template transfer HTTP request failed.');
            $size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);$result=['status'=>curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'headers'=>substr($raw,0,$size),'body'=>substr($raw,$size)];unset($curl);return $result;
        };
        $login=$client('login.php');$login=$client('login.php',['csrf_token'=>transferCsrf($login),'username'=>$username,'password'=>$password]);$login=finishIntegrationTestEnrollment($client,$login);
        transferExpect($login['status']===302,'Fixture login '.$role);$clients[$role]=$client;
    }
    $path='audience.php?view=templates';$store=new AudienceStore($conn,1,1,'Isolated template transfer');
    $images=[];foreach([0x007c89,0xc10304]as$color){$image=imagecreatetruecolor(4,4);imagefill($image,0,0,$color);ob_start();imagepng($image);$images[]='data:image/png;base64,'.base64_encode(ob_get_clean());imagedestroy($image);}
    $design=audienceDesign(['schema'=>1,'rows'=>[['id'=>'transfer-row','columns'=>[[
        ['id'=>'transfer-heading','type'=>'text','text'=>'<h1>Portable template</h1>'],
        ['id'=>'transfer-image','type'=>'image','image'=>$images[0],'originalImage'=>$images[1],'alt'=>'Portable image'],
    ]]]]]);
    $id=$store->createEmailTemplate('Round trip '.$suffix,$design);
    $count=static fn()=>(int)$store->one('SELECT COUNT(*) AS n FROM marketing_email_templates WHERE audience_id=1')['n'];
    $upload=tempnam('/tmp','transfer-export-');$files[]=$upload;
    foreach(['admin','editor']as$role){
        $client=$clients[$role];$page=$client($path);
        transferExpect(str_contains($page['body'],'data-open-dialog="template-package-import"'),'Editors and administrators see import');
        $export=$client($path.'&template_export='.$id);
        transferExpect($export['status']===200&&str_contains($export['headers'],'filename="message-monkey-template-'.$id.'.mmtemplate"'),'Native export downloads the expected file');
        transferExpect(str_contains(strtolower($export['headers']),'cache-control: no-store'),'Export is not cached');
        $package=json_decode($export['body'],true,64,JSON_THROW_ON_ERROR);
        transferExpect($package['payload']['application_version']===applicationVersion(),'Actual application version is included, including dev');
        file_put_contents($upload,$export['body']);$before=$count();
        $response=$client($path,['csrf_token'=>transferCsrf($page),'action'=>'template_package_import','template_package'=>new CURLFile($upload,'application/octet-stream','message-monkey-template-'.$id.'.mmtemplate')]);
        transferExpect($response['status']===302&&$count()===$before+1,'The actual downloaded export imports through multipart upload');
        preg_match('/Location: [^\r\n]*view=designer[^\r\n]*id=(\d+)/i',$response['headers'],$location);
        transferExpect(!empty($location[1]),'Import opens the new editable template');
        $imported=$store->emailTemplate((int)$location[1]);
        // MySQL JSON may reorder object keys and return whole numbers as integers.
        transferExpect($imported['name']==='Round trip '.$suffix&&$imported['design']==$design,'Round trip preserves editable blocks and the original image');
    }
    $editor=$clients['editor'];$reviewer=$clients['reviewer'];$before=$count();$reviewPage=$reviewer($path);
    transferExpect(!str_contains($reviewPage['body'],'data-open-dialog="template-package-import"')&&!str_contains($reviewPage['body'],'template_export='),'Reviewers see no transfer controls');
    transferExpect($reviewer($path.'&template_export='.$id)['status']===403,'Reviewer direct export is forbidden');
    transferExpect($reviewer($path,['csrf_token'=>transferCsrf($reviewPage),'action'=>'template_package_import','template_package'=>new CURLFile($upload,'application/octet-stream','valid.mmtemplate')])['status']===403&&$count()===$before,'Reviewer direct import cannot create a template');
    transferExpect($editor($path,['csrf_token'=>'invalid','action'=>'template_package_import','template_package'=>new CURLFile($upload,'application/octet-stream','valid.mmtemplate')])['status']===400&&$count()===$before,'Import requires valid CSRF');
    foreach(['unrelated HTML'=>'<html><body>Not exported</body></html>','changed export'=>str_replace('Round trip','Altered name',file_get_contents($upload))]as$case=>$contents){
        file_put_contents($upload,$contents);$page=$editor($path);
        $response=$editor($path,['csrf_token'=>transferCsrf($page),'action'=>'template_package_import','template_package'=>new CURLFile($upload,'application/octet-stream','invalid.mmtemplate')]);
        transferExpect($response['status']===200&&str_contains($response['body'],'data-reopen-dialog')&&$count()===$before,'Reject '.$case.' without creating records');
    }
    file_put_contents($upload,$export['body']);$page=$editor($path);
    $response=$editor($path,['csrf_token'=>transferCsrf($page),'action'=>'template_package_import','template_package'=>new CURLFile($upload,'text/html','renamed.html')]);
    transferExpect($count()===$before&&str_contains($response['body'],'Choose a .mmtemplate file'),'Unrelated extensions are rejected');
    $conn->query("INSERT INTO marketing_audiences(name,settings_json) VALUES ('Foreign template audience',JSON_OBJECT())");
    $foreignStore=new AudienceStore($conn,(int)$conn->insert_id,1,'Foreign template transfer');$foreign=$foreignStore->createEmailTemplate('Foreign template',$design);
    transferExpect($editor($path.'&template_export='.$foreign)['status']===404,'Exports cannot cross audience boundaries');
    $store->emailTemplateOperation('archive',$id,1,[]);
    transferExpect($editor($path.'&template_export='.$id)['status']===200,'Archived templates can still be exported');
    echo "Template transfer: $checks HTTP round-trip and permission checks passed.\n";
}finally{foreach($files as$file)if(is_file($file))unlink($file);}
