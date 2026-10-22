<?php
declare(strict_types=1);
require_once (getenv('DNR_TEST_SOURCE_DIR')?:__DIR__.'/../src').'/audience_store.php';
$checks=0;$expect=static function(bool $condition,string $message)use(&$checks):void{if(!$condition)throw new RuntimeException($message);$checks++;};
$tags=[['id'=>7,'name'=>'News'],['id'=>9,'name'=>'Community']];$catalog=audienceRuleCatalog([],$tags,[]);
$advanced=['match'=>'any','rules'=>[['field'=>'language','operator'=>'is','value'=>'en'],['field'=>'timezone','operator'=>'is','value'=>'Asia/Jerusalem']]];
$f=audienceListFilters(['q'=>'Toolbar Alpha','status'=>['subscribed','unsubscribed'],'tags'=>[7,9],'source'=>['type:api','Legacy source']]);$active=audienceActiveRules($f,$advanced);
[$sql,$params]=audienceCompileRules($active,$catalog);
$expect($active['match']==='all'&&count($active['rules'])===5,'Different quick filter types and advanced rules combine with AND');
$expect($active['rules'][0]['match']==='any'&&count($active['rules'][0]['rules'])===2,'Multiple statuses combine with OR');
$expect($active['rules'][1]['match']==='any'&&$active['rules'][1]['rules'][0]['field']==='signup_source','Source buckets and original source details retain OR matching');
$expect($active['rules'][2]===['field'=>'tags','operator'=>'any','value'=>[7,9]],'Multiple tags retain any-tag matching');
$expect($active['rules'][3]['rules'][1]['field']==='full_name'&&str_contains($sql,"CONCAT(c.first_name,' ',c.last_name)"),'Full-name search survives conversion into saved rules');
$expect($active['rules'][4]===$advanced,'Advanced OR groups keep their original logic');
$expect(in_array('api',$params,true)&&in_array('Legacy source',$params,true)&&!str_contains($sql,'Legacy source'),'Source values stay bound parameters');
$expect(audienceActiveRules(audienceListFilters([]),$advanced)===$advanced,'Opening an existing segment without quick filters preserves its tree');
$expect(count(audienceActiveRules($f,$advanced,false)['rules'])===4,'Store SQL can keep its existing search clause without adding search twice');
try{audienceCompileRules(['field'=>'signup_source','operator'=>'is','value'=>"bad' OR 1=1"],$catalog);throw new RuntimeException('Invalid source bucket accepted');}catch(InvalidArgumentException){$checks++;}
echo "Audience filter composition checks passed: $checks\n";
