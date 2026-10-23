<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Pagination HTTP checks require a disposable deployment.\n");
ob_start();$source=getenv('DNR_TEST_SOURCE_DIR')?:__DIR__.'/../src';require_once $source.'/bootstrap.php';require_once $source.'/audience_store.php';require_once __DIR__.'/integration_auth_helpers.php';
$db=applicationDatabaseConnection();$checks=0;$suffix=bin2hex(random_bytes(4));$prefix='Pager '.$suffix;
function paginationHttpExpect(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function paginationCsrf(array $response):string{preg_match('/name="csrf_token"[^>]*value="([^"]+)"/',$response['body'],$m);if(empty($m[1]))throw new RuntimeException('Missing login CSRF');return html_entity_decode($m[1],ENT_QUOTES);}
$cookie=tempnam(sys_get_temp_dir(),'pagination-');$username='pagination-'.$suffix;$password=bin2hex(random_bytes(16));
$db->execute_query('INSERT INTO users(username,password,role) VALUES (?,?,?)',[$username,password_hash($password,PASSWORD_DEFAULT),'editor']);$actor=(int)$db->insert_id;
$client=static function(string $path,?array $post=null)use($cookie):array{$curl=curl_init('http://127.0.0.1/'.$path);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>45]);if($post!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,http_build_query($post));$raw=curl_exec($curl);if($raw===false)throw new RuntimeException(curl_error($curl));$size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);return ['status'=>curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>substr($raw,$size),'headers'=>substr($raw,0,$size)];};
try{
 $login=$client('login.php');$login=$client('login.php',['csrf_token'=>paginationCsrf($login),'username'=>$username,'password'=>$password]);$login=finishIntegrationTestEnrollment($client,$login);paginationHttpExpect($login['status']===302,'Editor fixture login');
 $store=new AudienceStore($db,1,$actor,'Pagination fixture');$people=[];$flowIds=[];$formIds=[];$threads=[];
 $doc=audienceFlowDefault();$doc['trigger']=['type'=>'manual'];$doc['nodes']=[['id'=>'finish','type'=>'exit']];$doc['start']='finish';
 for($i=1;$i<=70;$i++){
  $name=$prefix.' '.str_pad((string)$i,3,'0',STR_PAD_LEFT);
  $people[]=$store->savePerson(['email'=>'pagination-'.$suffix.'-'.$i.'@example.test','first_name'=>$name,'email_status'=>'non-subscribed']);
  $store->createCampaign(['name'=>$name,'campaign_type'=>'plain-text']);$flowIds[]=$store->saveFlow($name,$doc);$formIds[]=$store->saveCollection(['name'=>$name,'kind'=>'survey']);$store->saveTag($name,0,0);$store->saveSegment($name,['match'=>'all','rules'=>[]],0,0);
  $store->write("INSERT INTO marketing_design_library(audience_id,item_type,name,data_json,actor_label) VALUES (1,'image',?,JSON_OBJECT('url','https://example.test/picture.png'),'Pagination fixture')",[$name]);
  $store->write("INSERT INTO marketing_campaign_plans(audience_id,name,data_json,actor_label) VALUES (1,?,JSON_OBJECT('goal','Grow audience','start','2026-10-01','end','2026-12-01'),'Pagination fixture')",[$name]);
  $store->write("INSERT INTO marketing_jobs(audience_id,job_type,name,state,actor_user_id,actor_label,params_json) VALUES (1,'export',?,'complete',?,'Pagination fixture',JSON_OBJECT())",[$name,$actor]);
  $threads[]=$store->inboxReceive('direct',0,'pagination-'.$suffix.'-'.$i.'@example.test',$name,$name,'Pagination message',hash('sha256',$name),true);
 }
 $form=$formIds[0];$flow=$flowIds[0];$person=$people[0];$thread=$threads[0];
 $store->write('INSERT INTO marketing_collection_revisions(audience_id,resource_id,document_json) SELECT audience_id,id,draft_json FROM marketing_collection_resources WHERE id=?',[$form]);$revision=(int)$db->insert_id;
 $store->write('INSERT INTO marketing_flow_revisions(audience_id,flow_id,document_json,actor_label) SELECT audience_id,id,draft_json,? FROM marketing_flows WHERE id=?',['Pagination fixture',$flow]);$flowRevision=(int)$db->insert_id;
 for($i=1;$i<=70;$i++){
  $store->write('INSERT INTO marketing_collection_responses(audience_id,resource_id,revision_id,receipt_hash,visitor_hash,payload_ciphertext) VALUES (1,?,?,?,?,?)',[$form,$revision,hash('sha256','receipt-'.$i),hash('sha256','visitor-'.$i),\Dnr\Security\ApplicationKey::seal(json_encode(['email'=>'record'.$i.'@example.test']))]);
  $store->write("INSERT INTO marketing_flow_runs(audience_id,flow_id,revision_id,contact_id,entry_key,node_key,state,due_at) VALUES (1,?,?,?,?,'finish','completed',UTC_TIMESTAMP())",[$flow,$flowRevision,$people[$i-1],'pagination-'.$i]);
  $store->event($person,'pagination_fixture',['row'=>$i]);
  if($i>1)$store->inboxReceive('direct',0,'pagination-'.$suffix.'-1@example.test','Pagination fixture','Conversation '.$prefix,'Message '.$i,hash('sha256','reply-'.$suffix.'-'.$i),true,[],$thread);
 }
 $cases=[
  ['Connections',['q'=>$prefix],'Connection pages','page','per_page','//*[@data-person-select]'],
  ['Campaigns',['view'=>'campaigns','search'=>$prefix],'Campaign pages','page','per_page','//*[@data-campaign-action="edit"]'],
  ['Automations',['view'=>'automations','q'=>$prefix],'Flow pages','page','per_page','//li[contains(@class,"flow-overview-row")]'],
  ['Surveys',['view'=>'surveys','q'=>$prefix],'Form pages','page','per_page','//table[@data-mm-paginated-table]/tbody/tr'],
  ['Tags',['view'=>'tags','list_search'=>$prefix],'Tags pages','list_page','list_size','//*[contains(concat(" ",normalize-space(@class)," ")," audience-definition-item ")]'],
  ['Segments',['view'=>'segments','list_search'=>$prefix],'Segment pages','segment_page','segment_size','//*[contains(concat(" ",normalize-space(@class)," ")," audience-definition-item ")]'],
  ['Plans',['view'=>'campaign-manager','planner_layout'=>'list','plan_search'=>$prefix],'Campaign plan pages','plan_page','plan_size','//table[@data-mm-paginated-table]/tbody/tr'],
  ['Files',['view'=>'content','search'=>$prefix],'Content pages','page','per_page',null],
  ['Responses',['view'=>'collection-report','id'=>$form],'Response pages','page','per_page','//table[@data-mm-paginated-table]/tbody/tr'],
  ['Runs',['view'=>'flow-report','id'=>$flow],'Execution pages','page','per_page','//*[contains(concat(" ",normalize-space(@class)," ")," flow-run ")]'],
  ['Contact activity',['view'=>'contact','id'=>$person,'activity_type'=>'pagination_fixture','activity_days'=>0],'Connection activity pages','activity_page','activity_size',null],
  ['Export history',['view'=>'jobs','kind'=>'export'],'Export history pages','history_page','history_size',null],
  ['Conversations',['view'=>'inbox','q'=>$prefix],'Conversation pages','page','per_page',null],
  ['Conversation messages',['view'=>'inbox','thread'=>$thread],'Conversation message pages','message_page','message_size',null],
 ];
 foreach($cases as[$name,$params,$label,$pageKey,$sizeKey,$rows])foreach([10,50]as$size){
  $response=$client('audience.php?'.http_build_query($params+[$sizeKey=>$size,$pageKey=>2]));paginationHttpExpect($response['status']===200&&!preg_match('/(?:Warning|Fatal error|Deprecated):/',$response['body']),$name.' renders page size '.$size.' without runtime errors');
  if(getenv('DNR_PAGINATION_CAPTURE')==='1'&&$size===50){$folder='/tmp/mm-pagination-review';if(!is_dir($folder))mkdir($folder,0700);file_put_contents($folder.'/'.strtolower(str_replace(' ','-',$name)).'.html',$response['body']);}
  $dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$response['body']);$xpath=new DOMXPath($dom);$nav=$xpath->query('//nav[@data-mm-pagination and @aria-label="'.$label.'"]')->item(0);paginationHttpExpect($nav!==null,$name.' uses the shared pager');
  $range=$xpath->query('.//*[@class="mm-pagination-range"]',$nav)->item(0)->textContent;paginationHttpExpect(trim($range)===($size===10?'11–20 of 70':'51–70 of 70'),$name.' reflects the actual requested slice: '.$range);
  if($rows!==null)paginationHttpExpect($xpath->query($rows)->length===($size===10?10:20),$name.' query returns the requested number of records');
  foreach($xpath->query('.//a',$nav)as$link){parse_str(parse_url($link->getAttribute('href'),PHP_URL_QUERY),$query);foreach($params as$key=>$value)paginationHttpExpect((string)($query[$key]??'')===(string)$value,$name.' retains '.$key);}
 }
 foreach([10,50]as$size){$a=$store->designLibrary('image',$prefix,1,$size);$b=$store->designLibrary('image',$prefix,2,$size);paginationHttpExpect(count($a['items'])===$size&&count($b['items'])===min($size,70-$size)&&!array_intersect(array_column($a['items'],'id'),array_column($b['items'],'id')),'Image/section library uses disjoint pages at size '.$size);}
 require_once __DIR__.'/audience_analytics_fixtures.php';audienceAnalyticsFixture($store,'Pagination analytics');
 $window=audienceAnalyticsWindow([]);$a=$store->analyticsActivity($window,'accepted',1,10);$b=$store->analyticsActivity($window,'accepted',2,10);
 paginationHttpExpect(count($a['rows'])===10&&count($b['rows'])===10&&!array_intersect(array_column($a['rows'],'id'),array_column($b['rows'],'id')),'Recipient activity has disjoint variable-size pages');
 $analytics=$client('audience.php?view=analytics&section=activity&per_page=10&page=2');paginationHttpExpect($analytics['status']===200&&str_contains($analytics['body'],'aria-label="Recipient activity pages"')&&!preg_match('/(?:Warning|Fatal error):/',$analytics['body']),'Recipient report renders the common pager');
 $last=$store->flowReport($flow,['page'=>999,'per_page'=>10]);paginationHttpExpect($last['page']===7&&count($last['runs'])===10,'Reports clamp stale page numbers');
 echo "MessageMonkey pagination HTTP: $checks checks passed.\n";
}finally{if(is_file($cookie))unlink($cookie);}
ob_end_flush();
