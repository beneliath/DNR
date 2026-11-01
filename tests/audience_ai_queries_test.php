<?php
declare(strict_types=1);
// This test double records read operations; production SQL is qualified separately against MySQL.
final class AudienceStore
{
    public int $audienceId=73;
    public array $reads=[];
    public array $reports=[];
    public array $overview=['includes_samples'=>false];
    public ?array $reportRows=null;
    public function fields():array{return [['id'=>5,'label'=>'Gift amount','field_type'=>'number'],['id'=>6,'label'=>'City','field_type'=>'text'],['id'=>9,'label'=>'Mailing address','field_type'=>'address'],['id'=>10,'label'=>'Telephone','field_type'=>'phone']];}
    public function one(string $sql,array $params=[]):?array{$this->reads[]=[$sql,$params];return ['n'=>2];}
    public function all(string $sql,array $params=[]):array{
        $this->reads[]=[$sql,$params];
        if(str_contains($sql,'grouped_values'))return [['label'=>'John','total'=>10,'ties'=>2,'recorded'=>29],['label'=>'James','total'=>10,'ties'=>2,'recorded'=>29],['label'=>'Michael','total'=>9,'ties'=>1,'recorded'=>29]];
        return [['id'=>1,'first_name'=>'John','last_name'=>'Smith','email'=>'john@example.test'],['id'=>2,'first_name'=>'John','last_name'=>'Smith','email'=>'john2@example.test']];
    }
    public function analyticsReportRows(array $window,array $definition):array{$this->reports[]=[$window,$definition];return $this->reportRows??[['label'=>'Native campaign','accepted'=>12,'click_rate'=>5,'previous'=>['accepted'=>10,'click_rate'=>4]]];}
    public function analyticsRecentCampaigns(array $window,int $limit):array{$this->reports[]=[$window,$limit];return [7,8,9];}
    public function analyticsOverview(array $window):array{return $this->overview;}
    public function analyticsAudienceHistory(array $window,string $source):array{return ['current'=>[['added'=>50],['added'=>50]],'previous'=>[['added'=>40],['added'=>40]]];}
    public function analyticsEvidence(array $window):array{return ['country'=>[['label'=>'US','messages'=>8]],'device'=>[['label'=>'Mobile','messages'=>6]],'links'=>[]];}
}
require_once __DIR__.'/../src/account_helpers.php';
require_once __DIR__.'/../src/audience_helpers.php';
require_once __DIR__.'/../src/audience_analytics.php';
require_once __DIR__.'/../src/audience_ai.php';
$checks=0;
$expect=static function(bool $condition,string $message)use(&$checks):void{if(!$condition)throw new RuntimeException($message);$checks++;};
$reject=static function(callable $action,string $message)use($expect):void{try{$action();}catch(Throwable){$expect(true,$message);return;}$expect(false,$message);};
$window=audienceAiReportWindow(['start'=>'2026-07-22','end'=>'2026-10-19']);$store=new AudienceStore();$tools=new AudienceAiQueries($store,$window);
$base=['tool'=>'frequency','field'=>'first_name','search'=>'','filters'=>[],'dataset'=>'email','dimension'=>'campaign','metrics'=>[],'limit'=>1];
$emptyFilter=$tools->validate(['queries'=>[array_replace($base,['filters'=>[['field'=>'first_name','operator'=>'eq','value'=>' ']]])]])[0]['filters'][0];
$expect($emptyFilter['operator']==='is_empty'&&$emptyFilter['value']==='','Equality to a blank value uses the same null-safe presence semantics as an explicit empty-field condition.');
$reject(static fn()=>$tools->validate(['queries'=>[array_replace($base,['filters'=>[['field'=>'first_name','operator'=>'contains','value'=>'']]])]]),'An empty search operand cannot silently match every record.');
$expect($tools->clarificationConditions([['field'=>'created_at','operator'=>'lte','value'=>'2022-12-31','value_quote'=>'a long time ago']],'Find people who joined a long time ago.')===null,'Vague durations cannot be turned into fabricated date cutoffs when dismissing a clarification.');
$expect($tools->clarificationConditions([['field'=>'created_at','operator'=>'lte','value'=>'2022-12-31','value_quote'=>'before 2023']],'Find long-standing contacts.')===null,'Assistant examples cannot be used as user-provided definitions.');
$grounded=$tools->clarificationConditions([['field'=>'email_status','operator'=>'eq','value'=>'unsubscribed','value_quote'=>'unsubscribed']],'Count unsubscribed contacts.');
$expect($grounded===[['field'=>'email_status','operator'=>'eq','value'=>'unsubscribed']],'A supplied status becomes a concrete filter with only validated query keys.');
$knownPresence=['field'=>'first_name','operator'=>'is_empty','value'=>''];
$resolvedConditions=$tools->clarificationConditions([['field'=>'email_status','operator'=>'eq','value'=>'unsubscribed','value_quote'=>'unsubscribed'],array_replace($knownPresence,['value'=>',','value_quote'=>','])],'Count unsubscribed contacts with no first name.','unsubscribed',['Count unsubscribed contacts with no first name.']);
$expect($resolvedConditions===$grounded,'Redundant unrelated presence conditions cannot invalidate a grounded answer to the actual clarification.');
$resolvedConditions=$tools->clarificationConditions([['field'=>'email_status','operator'=>'eq','value'=>'unsubscribed','value_quote'=>'unsubscribed'],['field'=>'last_name','operator'=>'eq','value'=>'*','value_quote'=>'*']],'Count unsubscribed contacts with a last name.','unsubscribed',['Count unsubscribed contacts with a last name.']);
$expect($resolvedConditions===$grounded,'An unrelated proposed change is discarded rather than applied or allowed to block an explicit criterion.');
$coreQuestion='Find core supporters who are subscribed.';
$expect($tools->clarificationConditions([['field'=>'email_status','operator'=>'eq','value'=>'subscribed','value_quote'=>'subscribed']],$coreQuestion,'core supporters',[$coreQuestion])===null,'Repeating an unrelated known condition cannot define an unresolved membership criterion.');
$expect($tools->clarificationConditions([['field'=>'email_status','operator'=>'eq','value'=>'subscribed','value_quote'=>'unsubscribed']],'Count unsubscribed contacts.')===null,'A substring inside a different status word is not evidence for that value.');
foreach(['first_name','last_name','custom:6']as$field){
    $result=$tools->execute(['queries'=>[array_replace($base,['field'=>$field])]]);
    $expect(count($result['facts'])===3&&$result['facts'][0]['value']===10&&$result['facts'][1]['value']===10,'One generic frequency query preserves ties for '.$field);
    $expect(!str_contains(json_encode($result['facts']),'Connections added'),'Frequency results exclude growth and campaign metrics.');
}
$expect(isset($tools->catalog()['custom:6'])&&!str_contains(json_encode($tools->catalog()),'sql'),'The planner receives field metadata without SQL.');
$nativePlan=$tools->nativeQuery(['function'=>['name'=>'recent_email_campaign_report','arguments'=>['count'=>1,'dimension'=>'campaign','metrics'=>['open_rate','click_rate'],'limit'=>100]]]);
$nativeSelection=$tools->validate($nativePlan)[0];
$expect($nativeSelection['recent_campaigns']===1&&$nativeSelection['limit']===10,'Native tools preserve a latest-email selection while bounding large requested result limits.');
$ellen=$tools->validate($tools->nativeQuery(['function'=>['name'=>'query_audience','arguments'=>['tool'=>'contacts','search'=>'Ellen']]]))[0];
$expect($ellen['field']===''&&$ellen['filters']===[]&&$ellen['limit']===100&&$ellen['search']==='Ellen','A name lookup accepts omitted optional arguments and returns a full contact page by default.');
$common=$tools->validate($tools->nativeQuery(['function'=>['name'=>'query_audience','arguments'=>['tool'=>'frequency','field'=>'first_name','limit'=>1]]]))[0];
$expect($common['search']===''&&$common['filters']===[],'An aggregate query does not require unused name-search arguments.');
$expect($tools->catalog()['custom:9']['type']==='address'&&isset($tools->catalog()['custom:10:area_code']),'The catalog exposes postal address types and reusable normalized phone components.');
$reject(static fn()=>$tools->nativeQuery(['function'=>['name'=>'query_audience','arguments'=>['tool'=>'count','filters'=>[['field'=>'email_status','operator'=>'eq','value'=>'confirmed']]]]]),'Invented status values are rejected instead of returning a misleading empty count.');
$filled=$tools->nativeQuery(['function'=>['name'=>'query_audience','arguments'=>['tool'=>'count','filters'=>[['field'=>'custom:9','operator'=>'is_not_empty']]]]]);
$filled=$tools->execute($filled);
$expect(str_contains($store->reads[array_key_last($store->reads)][0],'IS NOT NULL'),'Presence filters work for any supported catalog field without a dummy value.');
$tools->execute(['queries'=>[array_replace($base,['tool'=>'count','field'=>'custom:9'])]]);
$expect(str_contains($store->reads[array_key_last($store->reads)][0],'COUNT(NULLIF(')&&!str_contains($store->reads[array_key_last($store->reads)][0],'SUM(CASE'),'A field-presence count returns zero for no matching rows and excludes whitespace-only values.');
$payload=$tools->nativePayload('Look up a saved contact.');
$schemas=array_column(array_column($payload['tools'],'function'),null,'name');
$expect(isset($schemas['list_audience_contacts'],$schemas['count_audience_contacts'],$schemas['group_audience_values'])&&!isset($schemas['query_audience']),'Native functions distinguish rows, counts and grouped values without prompt-specific handlers.');
$expect($schemas['group_audience_values']['parameters']['required']===['field']&&!isset($schemas['count_audience_contacts']['parameters']['properties']['field']),'Grouping requires its field while record counts express conditions only through filters.');
foreach(['list_audience_contacts'=>'contacts','count_audience_contacts'=>'count','group_audience_values'=>'frequency']as$name=>$operation){
    $args=['filters'=>[['field'=>'email_status','operator'=>'eq','value'=>'unsubscribed'],['field'=>'custom:9','operator'=>'is_empty']]];
    if($operation==='frequency')$args['field']='source';
    $chosen=$tools->validate($tools->nativeQuery(['function'=>['name'=>$name,'arguments'=>$args]]))[0];
    $expect($chosen['tool']===$operation&&count($chosen['filters'])===2,'The '.$name.' primitive preserves all filters and fixes the result type.');
}
$reject(static fn()=>$tools->nativeQuery(['function'=>['name'=>'group_audience_values','arguments'=>[]]]),'A grouping request cannot silently become a count without a grouping field.');
$reject(static fn()=>$tools->nativeQuery(['function'=>['name'=>'count_audience_contacts','arguments'=>['field'=>'email_status']]]),'A record count cannot confuse its status field with a status condition.');

$followup=$tools->nativePayload('List those records.',['question'=>'A prior count','queries'=>[$common],'summary'=>'Saved audience count.']);
$followupContext=json_decode($followup['messages'][1]['content'],true);
$priorCall=$followupContext['previous']['data_requests'][0];
$planningContext=json_decode($tools->planningPayload('List those records.',audienceAiDialogueState(['question'=>'A prior count','queries'=>[$common],'summary'=>'Saved audience count.']))['messages'][1]['content'],true);
$expect($planningContext['conversation']['queries'][0]['tool']==='frequency'&&!isset($planningContext['conversation']['queries'][0]['dataset']),'Planning exposes only relevant prior query properties, without unrelated report defaults.');
$dialogue=$tools->planningPayload('The current answer to clarification.',audienceAiDialogueState(['question'=>'An older question','queries'=>[$common],'summary'=>'An earlier clarification.']))['messages'];
$expect(array_slice(array_column($dialogue,'role'),-3)===['user','assistant','user']&&$dialogue[array_key_last($dialogue)]['content']==='The current answer to clarification.','The current request follows explicit user and assistant history, so old clarifications cannot replace the latest turn.');

$expect(!isset($priorCall['arguments']['dataset'])&&$tools->nativeQuery(['function'=>$priorCall])['queries'][0]['field']==='first_name','Follow-ups receive valid native tool arguments without unrelated internal report defaults.');
$reject(static fn()=>$tools->nativeQuery(['function'=>['name'=>'email_campaign_report','arguments'=>['dimension'=>'day']]]),'A meaningful report range cannot silently default when the model omits the scope.');
$reject(static fn()=>$tools->nativeQuery(['function'=>['name'=>'execute_sql','arguments'=>['sql'=>'DELETE FROM users']]]),'The model cannot invoke arbitrary database operations.');
$reject(static fn()=>$tools->nativeQuery(['function'=>['name'=>'query_audience','arguments'=>['tool'=>'contacts','field'=>'','search'=>'John','filters'=>[],'limit'=>5,'account_id'=>2]]]),'Native calls cannot select another account.');
foreach([
    array_replace($base,['field'=>'first_name; DROP TABLE users']),array_replace($base,['account_id'=>1]),array_replace($base,['limit'=>1000]),
    array_replace($base,['tool'=>'delete']),array_replace($base,['tool'=>'sum','field'=>'last_name']),
    array_replace($base,['filters'=>[['field'=>'notes','operator'=>'contains','value'=>'secret']]]),
    array_replace($base,['filters'=>[['field'=>['email'],'operator'=>'eq','value'=>'secret']]]),
    array_replace($base,['metrics'=>[['accepted']]]),
    array_replace($base,['tool'=>'report','dataset'=>'audience','dimension'=>'campaign']),
]as$bad)$reject(static fn()=>$tools->validate(['queries'=>[$bad]]),'Unsafe or unsupported tool arguments are rejected.');
$lookup=array_replace($base,['tool'=>'contacts','field'=>'','search'=>'john%_smith','limit'=>5]);
$contacts=$tools->execute(['queries'=>[$lookup]]);
$expect(count($contacts['tables'][0]['rows'])===2,'Ambiguous contact lookups retain both saved matches.');
$expect($contacts['tables'][0]['total']===2&&$contacts['tables'][0]['shown']===2&&!$contacts['tables'][0]['truncated'],'Contact evidence distinguishes the full match count from the displayed subset.');
$page=audienceAiContactPage($tools,$contacts,'query_0_contacts',1);
$expect($page['id']==='query_0_contacts'&&str_ends_with($store->reads[array_key_last($store->reads)][0],'LIMIT 100 OFFSET 1'),'Pagination reuses the saved query with a bounded page size.');
$reject(static fn()=>audienceAiContactPage($tools,$contacts,'query_9_contacts',0),'Pagination cannot select a different, unavailable table.');
$reject(static fn()=>audienceAiContactPage($tools,$contacts,'query_0_contacts',-1),'Pagination rejects invalid offsets.');
$large=$contacts;$large['tables'][0]['rows']=array_fill(0,30,$contacts['tables'][0]['rows'][0]);$preview=audienceAiModelEvidence($large);
$expect(count($preview['tables'][0]['rows'])===10&&count($large['tables'][0]['rows'])===30,'The AI receives a bounded preview while all returned contact rows remain available to the interface.');
$expect(str_contains(implode(' ',$contacts['limitations']),'Multiple contacts match'),'Ambiguous lookups cannot silently choose an email.');
$direct=['summary'=>'John Smith has the saved address john@example.test.','observations'=>[['evidence_id'=>'query_0_contacts','text'=>'John Smith uses john@example.test.']],'recommendations'=>[]];
$raw=static fn($value)=>['done'=>true,'done_reason'=>'stop','message'=>['content'=>json_encode($value)]];
$expect(audienceAiReply($raw($direct),['mode'=>'analytics','operation'=>'analyze','evidence'=>$contacts])['summary']===$direct['summary'],'Contact lookups answer from saved records and cite the returned table.');
$withCount=$direct;$withCount['observations'][0]['text']='There are 2 matching audience records.';
$expect(audienceAiReply($raw($withCount),['mode'=>'analytics','operation'=>'analyze','evidence'=>$contacts])['observations'][0]['text']===$withCount['observations'][0]['text'],'A table citation can describe its verified full match count.');
$dated=$contacts;$dated['queries'][0]['filters']=[['field'=>'created_at','operator'=>'gte','value'=>'2026-10-01'],['field'=>'created_at','operator'=>'lte','value'=>'2026-10-07']];
$datedReply=$withCount;$datedReply['summary']='There are 2 matching contacts added from October 1 through October 7, 2026.';
$expect(audienceAiReply($raw($datedReply),['mode'=>'analytics','operation'=>'analyze','evidence'=>$dated])['summary']===$datedReply['summary'],'Dates in validated filters can be restated in normal date formats.');
$datedReply['summary']='There are 2 matching contacts added on October 19, 2026.';
$reject(static fn()=>audienceAiReply($raw($datedReply),['mode'=>'analytics','operation'=>'analyze','evidence'=>$dated]),'An unrelated date is not treated as a grounded statistic.');
$partial=$contacts;$partial['tables'][0]['total']=30;$partial['tables'][0]['shown']=2;$partial['facts'][0]['value']=30;
$partialReply=['summary'=>'Showing 2 of 30 matching contacts.','observations'=>[],'recommendations'=>[]];
$expect(audienceAiReply($raw($partialReply),['mode'=>'analytics','operation'=>'analyze','evidence'=>$partial])['summary']===$partialReply['summary'],'A summary may state the verified displayed count as well as the full table total.');
$partialReply['summary']='Showing 5 of 30 matching contacts.';
$reject(static fn()=>audienceAiReply($raw($partialReply),['mode'=>'analytics','operation'=>'analyze','evidence'=>$partial]),'A summary cannot invent the number of displayed rows.');
$withCount['observations'][0]['text']='There are 999 matching audience records.';
$reject(static fn()=>audienceAiReply($raw($withCount),['mode'=>'analytics','operation'=>'analyze','evidence'=>$contacts]),'A table citation cannot invent a different match count.');
$direct['summary']='See query_0_contacts for John Smith at john@example.test.';
$expect(audienceAiReply($raw($direct),['mode'=>'analytics','operation'=>'analyze','evidence'=>$contacts])['summary']===$direct['summary'],'An exact source identifier is not an invented numerical claim.');
[$sql,$params]=$store->reads[count($store->reads)-1];
$expect($params[0]===73&&!str_contains($sql,'john%_smith')&&in_array('%john!%!_smith%',$params,true),'Account scope and literal wildcard search use bound parameters.');
$countSource=$tools->execute(['queries'=>[array_replace($base,['tool'=>'count','field'=>'','filters'=>[['field'=>'last_name','operator'=>'eq','value'=>'Smith']]])]]);
$sourcePage=audienceAiContactPage($tools,$countSource,'query_0_result',0,25);
$expect($sourcePage['id']==='query_0_result'&&$sourcePage['total']===2
    &&in_array('Smith',$store->reads[array_key_last($store->reads)][1],true),'A saved scalar count can reopen its matching contacts using the same account-scoped filters.');
$reject(static fn()=>audienceAiContactPage($tools,$countSource,'query_9_result',0),'A count source cannot select a fact outside its saved evidence.');
$reject(static fn()=>audienceAiContactPage($tools,$contacts,'query_0_result',0),'A contact table cannot be misrepresented as a scalar count source.');
$report=$tools->execute(['queries'=>[array_replace($base,['tool'=>'report','field'=>'','metrics'=>['accepted'],'limit'=>5])]]);
$expect(count($report['facts'])===1&&str_contains($report['facts'][0]['label'],'Total sends'),'Report queries return only requested metrics.');
$expect(str_contains($report['scope_description'],'Email reports exclude test emails'),'Report scope visibly distinguishes campaign-recipient data from test activity.');
$expect(str_contains(implode(' ',$report['limitations']),'acceptance is not confirmed delivery'),'Email evidence distinguishes provider acceptance from receipt-confirmed delivery.');
$growthScope=$tools->execute(['queries'=>[['tool'=>'report','dataset'=>'audience','dimension'=>'day','metrics'=>['added'],'limit'=>5]]]);
$expect(!str_contains($growthScope['scope_description'],'test emails')&&!str_contains(implode(' ',$growthScope['limitations']),'Provider acceptance'),'Email-specific scope does not contaminate audience-growth reports.');
$recent=$tools->execute(['queries'=>[array_replace($base,['tool'=>'report','field'=>'','dimension'=>'links','recent_campaigns'=>3])]]);
$expect($recent['window']['messages_mode']==='selected'&&$recent['window']['messages']===[7,8,9],'Recent-campaign selection is passed to the native tracking query.');
$period=$tools->execute(['queries'=>[array_replace($base,['tool'=>'report','field'=>'','metrics'=>['accepted'],'last_days'=>30])]]);
$expect($period['window']['start']==='2026-09-20'&&$period['window']['end']==='2026-10-19','A relative report period is calculated from the selected end date.');
$reportPlan=['tool'=>'report','dataset'=>'email','dimension'=>'links','metrics'=>[],'recent_campaigns'=>3,'period'=>['kind'=>'selected'],'limit'=>5];
$selected=$tools->validate(['queries'=>[$reportPlan]]);
$expect($selected[0]['recent_campaigns']===3&&$selected[0]['last_days']===0&&$selected[0]['start']==='','A campaign count preserves the selected calendar period.');
$days=$tools->validate(['queries'=>[array_replace($reportPlan,['recent_campaigns'=>0,'period'=>['kind'=>'last_days','days'=>30]])]]);
$expect($days[0]['last_days']===30&&$days[0]['recent_campaigns']===0,'Calendar duration and campaign count are separate tool arguments.');
$expect(count($tools->validate(['queries'=>[$reportPlan,$reportPlan]]))===1,'Duplicate planned queries run and display only once.');
$reject(static fn()=>$tools->validate(['queries'=>[array_replace($reportPlan,['period'=>['kind'=>'selected','days'=>30]])]]),'Conflicting period arguments are rejected.');
$tracking=$tools->execute(['queries'=>[array_replace($base,['tool'=>'report','field'=>'','dimension'=>'country']),array_replace($base,['tool'=>'report','field'=>'','dimension'=>'device'])]]);
$expect(count($tracking['facts'])===2&&!str_contains(json_encode($tracking['facts']),'Total sends'),'Geographic and device results exclude unrelated campaign metrics.');
$store->overview+=['totals'=>['accepted'=>100],'previous'=>['accepted'=>80]];
$store->reportRows=array_map(static fn($day)=>['label'=>'Day '.$day,'accepted'=>5],range(1,20));
$totals=$tools->execute(['queries'=>[array_replace($base,['tool'=>'report','field'=>'','dimension'=>'day','metrics'=>['accepted'],'limit'=>3])]]);
$expect($totals['facts'][0]['value']===100&&$totals['facts'][0]['previous']===80&&$totals['facts'][0]['change']===25.0,'Selected-period totals and comparison include all report rows, not only displayed cards.');
$expect(count($totals['facts'])===4&&str_contains($totals['facts'][1]['label'],'Day 1 ')&&str_contains($totals['facts'][3]['label'],'Day 20 '),'Bounded time-group cards sample both ends of the requested range.');
$growth=$tools->execute(['queries'=>[array_replace($base,['tool'=>'report','field'=>'','dataset'=>'audience','dimension'=>'day','metrics'=>['added'],'limit'=>3])]]);
$expect($growth['facts'][0]['value']===100&&$growth['facts'][0]['previous']===80,'Audience growth uses complete native history totals.');
$store->overview=['includes_samples'=>false];$store->reportRows=null;
$empty=$tools->execute(['queries'=>[]]);$expect($empty['facts']===[]&&$empty['limitations']!==[],'Unsupported questions produce an explicit limitation, not unrelated metrics.');

putenv('DNR_AI_COACH_URL=http://ai-coach-tunnel:11437');
$request=['_audience_ai'=>1,'account'=>currentAccountKey(),'mode'=>'analytics','operation'=>'analyze','role'=>'admin','prompt'=>'A fresh population question','request_id'=>'12345678-1234-4234-a234-123456789012','analysis_scope'=>$window];
$wordQuestion='how many "r"s are in the word strawberry?';
$expect(audienceAiClearlyUnrelated($wordQuestion)&&!audienceAiClearlyUnrelated('How many contacts have an r in their email address?'),'Word puzzles are outside analytics without blocking saved-contact questions.');
$expect(audienceAiClearlyUnrelated("How many r's in strawberry?")&&!audienceAiClearlyUnrelated('How many characters are in the subject of my last campaign?'),'Short word-count phrasing is recognized without intercepting campaign content questions.');
$expect(audienceAiUnavailableCampaignRevenue('What was the revenue from those campaigns?')
    &&audienceAiUnavailableCampaignRevenue('How many purchases came from the last email?')
    &&!audienceAiUnavailableCampaignRevenue('List contacts whose email address contains sales')
    &&!audienceAiUnavailableCampaignRevenue("What was the Sales campaign's open rate?"),'Unsupported financial measures do not hide supported contact or campaign metrics.');
$readsBefore=count($store->reads);$reportsBefore=count($store->reports);
$wordReply=audienceAiGenerate(array_replace($request,['prompt'=>$wordQuestion]),microtime(true)+30,null,static fn()=>throw new RuntimeException('Word puzzles must not call the model.'),$tools);
$expect($wordReply['interpretation']==='information'&&$wordReply['telemetry']['model_calls']===0
    &&count($store->reads)===$readsBefore&&count($store->reports)===$reportsBefore,'Unrelated word counting gets an application-owned scope reply without data access.');
$revenueReply=audienceAiGenerate(array_replace($request,['prompt'=>'What was the revenue from those campaigns?']),microtime(true)+30,null,static fn()=>throw new RuntimeException('Unsupported revenue must not call the model.'),$tools);
$expect($revenueReply['interpretation']==='information'&&str_contains($revenueReply['summary'],'do not include revenue')
    &&$revenueReply['telemetry']['model_calls']===0&&count($store->reads)===$readsBefore&&count($store->reports)===$reportsBefore,'Unavailable campaign revenue gets a precise scope reply without data access.');
$topNameQuestion='what is the most common last name in the audience?';
$expect(audienceAiSimpleTopNameField($topNameQuestion,['turns'=>[]])==='last_name'
    &&audienceAiSimpleTopNameField('what is the last name with the largest total occurrences in the audience?',['turns'=>[]])==='last_name'
    &&audienceAiSimpleTopNameField('most common last name among subscribed contacts',['turns'=>[]])===null,'Unqualified top-name requests are exact grouped reads; filtered questions remain with the planner.');
$topName=audienceAiGenerate(array_replace($request,['prompt'=>$topNameQuestion]),microtime(true)+30,null,static fn()=>throw new RuntimeException('Unambiguous top-name questions must not call the model.'),$tools);
$expect($topName['conversation_state']['queries'][0]['tool']==='frequency'
    &&$topName['conversation_state']['queries'][0]['field']==='last_name'
    &&$topName['telemetry']['model_calls']===0&&str_contains($topName['summary'],'most common last name')
    &&count($store->reads)===$readsBefore+1,'Most common last name groups recorded names and answers from the top values.');
$clarified=['state'=>['version'=>1,'turns'=>[['question'=>$topNameQuestion,'response'=>'Which metric should be used: total occurrences or percentage share?']],'queries'=>[]]];
$topFollowup=audienceAiGenerate(array_replace($request,['prompt'=>'total occurrences','previous_context'=>$clarified]),microtime(true)+30,null,static fn()=>throw new RuntimeException('Clarification follow-up must not call the model.'),$tools);
$expect($topFollowup['conversation_state']['queries'][0]['tool']==='frequency'
    &&$topFollowup['conversation_state']['queries'][0]['field']==='last_name'
    &&count($store->reads)===$readsBefore+2,'Total occurrences after a top-name clarification retains grouping rather than counting every contact.');
$topRestated=audienceAiGenerate(array_replace($request,['prompt'=>'what is the last name with the largest total occurrences in the audience?','previous_context'=>audienceAiPreviousContext('total occurrences',$topFollowup)]),microtime(true)+30,null,static fn()=>throw new RuntimeException('Restated top-name question must not call the model.'),$tools);
$expect($topRestated['conversation_state']['queries'][0]['tool']==='frequency'
    &&$topRestated['conversation_state']['queries'][0]['field']==='last_name'
    &&count($store->reads)===$readsBefore+3,'Restating the ranking after a follow-up cannot turn it into an overall audience count.');
$addressCatalog=$tools->catalog();
$expect(isset($addressCatalog['custom:9:us_state'])&&$addressCatalog['custom:9:us_state']['type']==='region'
    &&audienceAiSimpleAddressStateCount('how many of the audience have addresses in Texas?',$addressCatalog)['code']==='TX'
    &&audienceAiSimpleAddressStateCount('how many connections have a California address?',$addressCatalog)['code']==='CA'
    &&audienceAiSimpleAddressStateCount('how many connections have an address?',$addressCatalog)===null,'Simple named-state address questions use the owned state filter without treating any address as a match.');
$texasQuery=['tool'=>'count','field'=>'','search'=>'','filters'=>[['field'=>'custom:9:us_state','operator'=>'eq','value'=>'Texas']],'limit'=>5];
$reject(static fn()=>$tools->validate(['queries'=>[array_replace($texasQuery,['field'=>'custom:9:us_state'])]]),'Address state cannot be used as an unscoped display or calculation field.');
$reject(static fn()=>$tools->validate(['queries'=>[array_replace($texasQuery,['filters'=>[['field'=>'custom:9:us_state','operator'=>'contains','value'=>'Texas']]])]]),'Address state requires an exact recognized state rather than a loose text match.');
$addressReads=count($store->reads);
$texasReply=audienceAiGenerate(array_replace($request,['prompt'=>'how many of the audience have addresses in Texas?']),microtime(true)+30,null,static fn()=>throw new RuntimeException('Simple state count must not call the model.'),$tools);
$addressSql=$store->reads[array_key_last($store->reads)];
$expect($texasReply['conversation_state']['queries'][0]['filters'][0]['field']==='custom:9:us_state'
    &&$texasReply['telemetry']['model_calls']===0&&str_contains($texasReply['summary'],'Texas')
    &&count($store->reads)===$addressReads+1&&str_contains($addressSql[0],'REGEXP_LIKE')
    &&in_array('TX',$addressSql[1],true)&&in_array('Texas',$addressSql[1],true),'Texas count uses a bound state and ZIP pattern plus the structured state component.');
$californiaReply=audienceAiGenerate(array_replace($request,['prompt'=>'how many connections have a California address?']),microtime(true)+30,null,static fn()=>throw new RuntimeException('California state count must not call the model.'),$tools);
$expect($californiaReply['conversation_state']['queries'][0]['filters'][0]['value']==='CA'
    &&str_contains($californiaReply['summary'],'California')&&count($store->reads)===$addressReads+2,'California address phrasing uses the same state-specific count.');
$expect(isset($addressCatalog['custom:9:country'])&&$addressCatalog['custom:9:country']['type']==='country'
    &&audienceAiSimpleAddressCountryCount('how many connections are in Germany?',$addressCatalog)['code']==='DE'
    &&audienceAiSimpleAddressCountryCount('how many connections are in Australia?',$addressCatalog)['code']==='AU'
    &&audienceAiSimpleAddressCountryCount('how many connections are in Florida?',$addressCatalog)===null,'Named countries use the saved postal country while US states retain their separate path.');
$germanyQuery=['tool'=>'count','field'=>'','search'=>'','filters'=>[['field'=>'custom:9:country','operator'=>'eq','value'=>'Germany']],'limit'=>5];
$reject(static fn()=>$tools->validate(['queries'=>[array_replace($germanyQuery,['field'=>'custom:9:country'])]]),'Address country cannot be used as an unscoped display field.');
$reject(static fn()=>$tools->validate(['queries'=>[array_replace($germanyQuery,['filters'=>[['field'=>'custom:9:country','operator'=>'contains','value'=>'Germany']]])]]),'Address country requires an exact recognized country.');
$reject(static fn()=>$tools->validate(['queries'=>[array_replace($germanyQuery,['filters'=>[['field'=>'custom:9:country','operator'=>'eq','value'=>'Atlantis']]])]]),'Unknown countries cannot silently return a zero count.');
$countryReads=count($store->reads);
$germanyReply=audienceAiGenerate(array_replace($request,['prompt'=>'how many connections are in Germany?']),microtime(true)+30,null,static fn()=>throw new RuntimeException('Simple country count must not call the model.'),$tools);
$countrySql=$store->reads[array_key_last($store->reads)];
$expect($germanyReply['conversation_state']['queries'][0]['filters'][0]['value']==='DE'
    &&$germanyReply['telemetry']['model_calls']===0&&str_contains($germanyReply['summary'],'Germany')
    &&count($store->reads)===$countryReads+1&&str_contains($countrySql[0],'REGEXP_LIKE')
    &&in_array('DE',$countrySql[1],true)&&in_array('Germany',$countrySql[1],true),'Germany count uses bound country values and an explicit country suffix for imported addresses.');
$australiaReply=audienceAiGenerate(array_replace($request,['prompt'=>'how many connections are in Australia?']),microtime(true)+30,null,static fn()=>throw new RuntimeException('Australia count must not call the model.'),$tools);
$expect($australiaReply['conversation_state']['queries'][0]['filters'][0]['value']==='AU'
    &&str_contains($australiaReply['summary'],'Australia')&&count($store->reads)===$countryReads+2,'Australia country phrasing uses the same verified count.');
$africaCodes=(new ReflectionClass(AudienceAiQueries::class))->getConstant('AFRICA_COUNTRY_CODES');
$expect(count($africaCodes)===60&&array_diff($africaCodes,array_keys(addressCountryChoices()))===[]
    &&in_array('ZA',$africaCodes,true)&&in_array('EG',$africaCodes,true)&&!in_array('DE',$africaCodes,true),'UN M49 Africa codes map to supported saved-address countries without including Germany.');
$expect(isset($addressCatalog['custom:9:continent'])&&$addressCatalog['custom:9:continent']['enum']===['Africa']
    &&audienceAiSimpleAddressContinentCount('how many connections are in Africa?',$addressCatalog)['field']==='custom:9:continent'
    &&audienceAiSimpleAddressCountryCount('how many connections are in Africa?',$addressCatalog)===null,'A continent request uses its explicit address-country grouping rather than a fabricated country.');
$africaQuery=['tool'=>'count','field'=>'','search'=>'','filters'=>[['field'=>'custom:9:continent','operator'=>'eq','value'=>'Africa']],'limit'=>5];
$reject(static fn()=>$tools->validate(['queries'=>[array_replace($africaQuery,['field'=>'custom:9:continent'])]]),'Continent is filter-only, not a display or calculation field.');
$reject(static fn()=>$tools->validate(['queries'=>[array_replace($africaQuery,['filters'=>[['field'=>'custom:9:continent','operator'=>'contains','value'=>'Africa']]])]]),'Continent requires an exact supported region.');
$reject(static fn()=>$tools->validate(['queries'=>[array_replace($africaQuery,['filters'=>[['field'=>'custom:9:continent','operator'=>'eq','value'=>'Atlantis']]])]]),'Unsupported regions cannot silently return zero.');
$africaReads=count($store->reads);
$africaReply=audienceAiGenerate(array_replace($request,['prompt'=>'how many connections are in Africa?']),microtime(true)+30,null,static fn()=>throw new RuntimeException('Africa count must not call the model.'),$tools);
$africaSql=$store->reads[array_key_last($store->reads)];
$expect($africaReply['conversation_state']['queries'][0]['filters'][0]['value']==='Africa'
    &&$africaReply['telemetry']['model_calls']===0&&str_contains($africaReply['summary'],'Africa')
    &&count($store->reads)===$africaReads+1&&str_contains($africaSql[0],'REGEXP_LIKE')
    &&str_contains($africaSql[1][1],'ZA')&&str_contains($africaSql[1][1],'Egypt')
    &&!str_contains($africaSql[1][1],'Germany'),'Africa count uses bound UN country grouping and excludes non-African countries.');
$africaPage=audienceAiContactPage($tools,$africaReply['evidence'],'query_0_result',0,25);
$expect($africaPage['id']==='query_0_result'&&$africaPage['total']===2,'Africa count source reopens its matching connections with the same validated filters.');
foreach(['greeting','current_date','help','unsupported']as$kind){
    $calls=0;$readsBefore=count($store->reads);$reportsBefore=count($store->reports);
    $transport=static function()use(&$calls,$raw,$kind):array{
        return ++$calls===1?['done'=>true,'done_reason'=>'stop','message'=>['content'=>'Unverified prose claiming 999 contacts and an invented date.']]:$raw(['kind'=>$kind]);
    };
    $answer=audienceAiGenerate($request+['display_timezone'=>'America/Chicago'],microtime(true)+30,null,$transport,$tools);
    $expect($answer['reason']===''&&$answer['interpretation']==='information'&&$answer['telemetry']['model_calls']===2,'Plain informational turns use a typed classification and application-owned response.');
    $expect(!str_contains($answer['summary'],'999')&&count($store->reads)===$readsBefore&&count($store->reports)===$reportsBefore,'Informational turns neither echo unverified model claims nor read account data.');
}
$date=audienceAiInformationReply('current_date',$window,'America/Chicago',new DateTimeImmutable('2026-10-30T02:53:00Z'));
$expect(str_contains($date['summary'],'Thursday, October 29, 2026 (America/Chicago)'),'Current date uses the server clock and user timezone, not the model date.');
$reject(static fn()=>audienceAiInformationReply('execute_sql',$window),'Unknown informational classifications fail closed.');
$calls=0;$readsBefore=count($store->reads);
$answer=audienceAiGenerate($request,microtime(true)+30,null,static function()use(&$calls,$raw):array{
    return ++$calls%2===1?['done'=>true,'done_reason'=>'stop','message'=>['content'=>'You have 999 contacts.']]:$raw(['kind'=>'data']);
},$tools);
$expect($calls===4&&$answer['reason']==='unavailable'&&count($store->reads)===$readsBefore,'A prose answer to a data question must replan within the bounded retry budget, never become verified data.');
$state=['version'=>1,'turns'=>[],'queries'=>[]];
$benchmarkState=['version'=>1,'turns'=>[['question'=>'How did my last email perform compared to similar emails and to benchmarks?','response'=>'Which benchmarks should I use?']],
    'queries'=>$tools->validate($tools->nativeQuery(['function'=>['name'=>'recent_email_campaign_report','arguments'=>['count'=>1,'dimension'=>'campaign','metrics'=>['open_rate']]]]))];
$editMetrics=['open_rate','click_rate','bounce_rate','complaint_rate','unsubscribed'];
$edited=$tools->selectedPlan(['done'=>true,'done_reason'=>'stop','message'=>['tool_calls'=>[['function'=>['name'=>'edit_report_query','arguments'=>['metrics'=>$editMetrics]]]]]],$benchmarkState);
$resolvedEdit=$tools->resolvePlan($edited,$benchmarkState);
$expect(count($resolvedEdit['queries'][0]['metrics'])===4&&$resolvedEdit['queries'][0]['recent_campaigns']===1,'Follow-up report drafts use the same bounded metrics as new reports without losing the latest campaign selection.');
$reject(static fn()=>$tools->selectedPlan(['done'=>true,'done_reason'=>'stop','message'=>['tool_calls'=>[['function'=>['name'=>'edit_report_query','arguments'=>['metrics'=>[...$editMetrics,'invented_metric']]]]]]],$benchmarkState),'Every metric is validated, including extra draft metrics beyond the displayed limit.');
$clarificationOnly=['context'=>'continuation','queries'=>[],'clarification'=>['issue'=>'missing_criterion','question'=>'Which benchmark?']];
$review=$tools->planReviewPayload('historical averages',$benchmarkState,$clarificationOnly);
$expect(isset($review['format']['properties']['report_metrics']['items']['enum'])&&in_array('open_rate',$review['format']['properties']['report_metrics']['items']['enum'],true),'Clarification-only follow-ups retain their report catalog during independent review.');
$calls=0;$reportsBefore=count($store->reports);
$answer=audienceAiGenerate(array_replace($request,['prompt'=>'historical averages','previous_context'=>['state'=>$benchmarkState]]),microtime(true)+30,null,static function($base,$payload)use(&$calls,$raw,$clarificationOnly):array{
    $calls++;
    if((isset($payload['tools'])||isset($payload['format']['properties']['calls'])))return $raw(['context'=>'continuation','requests'=>[],'clarification'=>$clarificationOnly['clarification']]);
    if(isset($payload['format']['properties']['context']))return $raw(['context'=>'continuation']);
    return $raw(['valid'=>true,'conditions'=>[],'result_type'=>'report','report_metrics'=>[],'unresolved'=>[['phrase'=>'historical_campaign_averages','question'=>'Which historical averages?','kind'=>'measurement','unavailable_capability'=>'historical_campaign_averages']],'correction'=>'']);
},$tools);
$expect($calls===3&&$answer['reason']===''&&$answer['interpretation']==='clarification'&&str_contains($answer['summary'],'do not support historical per-campaign averages'),'Unsupported historical averages produce a clear capability explanation instead of an invalid-response error.');
$expect(count($store->reports)===$reportsBefore&&$answer['conversation_state']['queries'][0]['recent_campaigns']===1,'Unsupported comparisons do not run substitute data queries or discard the original campaign selection.');
$structured=$tools->planningPayload('Show the last email metrics',$benchmarkState,'Return tools, not prose.',['context'=>'continuation'],true);
$expect(!isset($structured['tools'])&&isset($structured['format']['properties']['calls']['items']['anyOf']),'A native prose failure can retry with an enforced structured tool-call schema.');
$selected=$tools->selectedPlan($raw(['calls'=>[['name'=>'edit_report_query','arguments'=>['metrics'=>['click_rate']]]]]),$benchmarkState);
$expect($tools->resolvePlan($selected,$benchmarkState)['queries'][0]['recent_campaigns']===1,'Structured retries pass through the same context-preserving query validation as native tools.');
$reject(static fn()=>$tools->selectedPlan($raw(['calls'=>[['name'=>'execute_sql','arguments'=>['sql'=>'SELECT secret']]]]),$benchmarkState),'Structured retries cannot introduce new data tools.');
$reject(static fn()=>$tools->resolvePlan($tools->selectedPlan($raw(['calls'=>[['name'=>'edit_report_query','arguments'=>['account_id'=>2]]]]),$benchmarkState),$benchmarkState),'Structured retries cannot change account scope.');
$plan=static fn(array $changes,int $base=-1,array $remove=[],string $issue='none',string $question=''):array=>['assessment'=>'Resolve the requested conditions and output.','context'=>$base<0?'new_topic':'continuation','requests'=>[['base_query'=>$base,'changes'=>$changes,'remove_filters'=>$remove]],'clarification'=>['issue'=>$issue,'question'=>$question]];
$filters=[['field'=>'email_status','operator'=>'eq','value'=>'subscribed'],['field'=>'email_domain','operator'=>'eq','value'=>'example.test'],['field'=>'last_name','operator'=>'is_not_empty']];
$initial=$tools->resolvePlan($plan(['tool'=>'count','filters'=>$filters]),$state);
$state['queries']=$initial['queries'];
$follow=$tools->resolvePlan($plan(['tool'=>'contacts','limit'=>7],0),$state);
$expect($follow['queries'][0]['filters']===$state['queries'][0]['filters']&&$follow['queries'][0]['limit']===7,'Changing output to a list retains every population filter.');
$changed=$tools->resolvePlan($plan(['filters'=>[['field'=>'email_status','operator'=>'eq','value'=>'unsubscribed']]],0),$state);
$expect(count($changed['queries'][0]['filters'])===3&&array_column($changed['queries'][0]['filters'],'value','field')['email_domain']==='example.test','Replacing one filter cannot discard other conditions.');
$removed=$tools->resolvePlan($plan([],0,['last_name']),$state);
$expect(count($removed['queries'][0]['filters'])===2,'A user-requested filter removal is explicit and scoped to that field.');
$removed=$tools->resolvePlan($plan(['filters'=>$filters],0,['last_name']),$state);
$expect(count($removed['queries'][0]['filters'])===2,'An explicit removal wins over an unchanged filter echoed from the previous query.');
$replaced=$tools->resolvePlan($plan(['filters'=>[['field'=>'email_status','operator'=>'eq','value'=>'unsubscribed']]],0,['email_status']),$state);
$expect(array_column($replaced['queries'][0]['filters'],'value','field')['email_status']==='unsubscribed','Removing an old field condition and supplying a different value still replaces that condition.');
$reset=$tools->resolvePlan($plan(['tool'=>'count']),$state);
$expect($reset['queries'][0]['filters']===[],'A new topic resets population constraints rather than leaking old filters.');
$bad=$plan(['tool'=>'count']);$bad['context']='continuation';
$reject(static fn()=>$tools->resolvePlan($bad,$state),'An existing follow-up population cannot be silently rebuilt from scratch.');
$reject(static fn()=>$tools->resolvePlan($plan(['account_id'=>2],0),$state),'Query edits cannot change accounts.');
$reject(static fn()=>$tools->resolvePlan($plan(['tool'=>'count','filters'=>[['field'=>'notes','operator'=>'is_not_empty']]]),$state),'Unknown or private fields are rejected in the edit path.');
$reject(static fn()=>$tools->resolvePlan($plan([],99),$state),'Query edits cannot select a missing base.');
$dates=$tools->resolvePlan($plan(['tool'=>'count','filters'=>[['field'=>'created_at','operator'=>'gte','value'=>'2026-01-01'],['field'=>'created_at','operator'=>'lte','value'=>'2026-01-31']]]),$state);
$dates=$tools->resolvePlan($plan(['filters'=>[['field'=>'created_at','operator'=>'gte','value'=>'2026-02-01'],['field'=>'created_at','operator'=>'lte','value'=>'2026-02-28']]],0),array_replace($state,['queries'=>$dates['queries']]));
$expect(count($dates['queries'][0]['filters'])===2&&$dates['queries'][0]['filters'][1]['value']==='2026-02-28','Replacing a field preserves every boundary in its new condition group.');
$narrowed=$tools->resolvePlan($plan(['filters'=>[['field'=>'created_at','operator'=>'lte','value'=>'2026-02-21']]],0),array_replace($state,['queries'=>$dates['queries']]));
$expect($narrowed['queries'][0]['filters'][0]['value']==='2026-02-01'&&count($narrowed['queries'][0]['filters'])===2,'Changing one range boundary retains the unmentioned other boundary.');
$reportPlan=$plan(['tool'=>'report','dataset'=>'email','dimension'=>'campaign','metrics'=>['delivery_rate'],'scope'=>['kind'=>'recent_campaigns','count'=>4]]);
$reportState=array_replace($state,['queries'=>$tools->resolvePlan($reportPlan,$state)['queries']]);
$changedReport=$tools->resolvePlan($plan(['metrics'=>['total_clicks']],0),$reportState);
$expect($changedReport['queries'][0]['recent_campaigns']===4&&$changedReport['queries'][0]['metrics']===['total_clicks'],'A metric follow-up preserves the existing campaign and time selection.');
$changedReport=$tools->resolvePlan($plan(['scope'=>['kind'=>'last_days','days'=>21]],0),$reportState);
$expect($changedReport['queries'][0]['recent_campaigns']===0&&$changedReport['queries'][0]['last_days']===21,'An explicit new scope replaces the entire prior scope without conflicting defaults.');
$countFromList=$tools->resolvePlan($plan(['tool'=>'count','limit'=>100],0),array_replace($state,['queries'=>$follow['queries']]));
$expect($countFromList['queries'][0]['limit']===5&&$countFromList['queries'][0]['filters']===$follow['queries'][0]['filters'],'Changing a contact list to a scalar count ignores irrelevant display limits and keeps its population.');

$calls=0;$readCount=count($store->reads);
$partial=$plan(['tool'=>'count','filters'=>$filters],-1,[],'missing_criterion','Which date range defines recent?');
$request['prompt']='Count recent subscribed contacts at example.test with a recorded last name.';
$transport=static function($url,$payload,$deadline,$cancelled,&$metrics)use(&$calls,$partial,$raw,$filters):array{$calls++;if(isset($payload['format']['properties']['needed']))return $raw(['needed'=>true,'explanation'=>'No date range was supplied.']);return $raw((isset($payload['tools'])||isset($payload['format']['properties']['calls']))?$partial:['unresolved'=>[['phrase'=>'recent','question'=>'Which date range defines recent?']],'conditions'=>$filters,'result_type'=>'count','valid'=>false,'correction'=>'Define recent.']);};
$answer=audienceAiGenerate($request,microtime(true)+45,null,$transport,$tools);
$expect($answer['interpretation']==='clarification'&&count($store->reads)===$readCount&&count($answer['conversation_state']['queries'][0]['filters'])===3,'Clarifications retain known conditions without running partial queries.');
$next=$request+['previous_context'=>audienceAiPreviousContext($request['prompt'],$answer)];$next['prompt']='Use the first week of February and list seven.';
$transport=static function($url,$payload,$deadline,$cancelled,&$metrics)use($plan,$raw):array{if(isset($payload['format']['properties']['context']))return $raw(['context'=>'continuation','result_type'=>'contacts']);return $raw((isset($payload['tools'])||isset($payload['format']['properties']['calls']))?$plan(['tool'=>'contacts','limit'=>7],0):['conditions'=>[],'result_type'=>'multiple','valid'=>true,'correction'=>'']);};
$answer=audienceAiGenerate($next,microtime(true)+45,null,$transport,$tools);
$expect(count($answer['evidence']['queries'][0]['filters'])===3&&count($answer['conversation_state']['turns'])===2,'An answer to clarification preserves conditions and the original question through the owned response.');
$expect(str_contains($answer['summary'],'Showing 2 of 2')&&count($answer['evidence']['tables'][0]['rows'])===2,'Displayed-row claims use actual returned rows, never the requested limit.');
$expect(count($answer['observations'])===1&&$answer['observations'][0]['evidence_id']==='query_0_contacts','Contact results cite their actual table.');
$request['prompt']='How many contacts?';
$calls=0;
$transport=static function($url,$payload,$deadline,$cancelled,&$metrics)use(&$calls,$plan,$raw):array{$calls++;if($calls>2)throw new RuntimeException('No prose generation is allowed for a count.');return $raw((isset($payload['tools'])||isset($payload['format']['properties']['calls']))?$plan(['tool'=>'count']):['conditions'=>[],'result_type'=>'multiple','valid'=>true,'correction'=>'']);};
$answer=audienceAiGenerate($request,microtime(true)+45,null,$transport,$tools);
$expect($calls===2&&$answer['evidence']['tables']===[]&&!str_contains($answer['summary'],'Showing')&&$answer['observations'][0]['text']==='Count · audience records: 2.','Count answers cannot invent a displayed list or unsupported numeric prose.');

$calls=0;$readsBefore=count($store->reads);$feedback=false;
$transport=static function($url,$payload,$deadline,$cancelled,&$metrics)use(&$calls,&$feedback,$plan,$raw):array{
    $calls++;
    if(isset($payload['format']['properties']['valid']))return $raw(['conditions'=>[],'result_type'=>'multiple','valid'=>$calls>2,'correction'=>$calls>2?'':'Return contact rows, not a count.']);
    $context=json_decode($payload['messages'][1]['content'],true);if($calls>2)$feedback=str_contains($context['correction'],'contact rows');
    return $raw($plan(['tool'=>$calls===1?'count':'contacts','limit'=>7]));
};
$answer=audienceAiGenerate($request,microtime(true)+45,null,$transport,$tools);
$expect($feedback&&$calls===4&&count($store->reads)===$readsBefore+2&&count($answer['evidence']['tables'])===1,'An incorrect output plan is repaired before any data read, rather than retaining misleading count evidence.');
$calls=0;$readsBefore=count($store->reads);
$transport=static function($url,$payload,$deadline,$cancelled,&$metrics)use(&$calls,$plan,$raw):array{$calls++;return $raw((isset($payload['tools'])||isset($payload['format']['properties']['calls']))?$plan(['tool'=>'count']):['conditions'=>[],'result_type'=>'multiple','valid'=>false,'correction'=>'A required condition is unresolved.']);};
$answer=audienceAiGenerate($request,microtime(true)+45,null,$transport,$tools);
$expect($calls===4&&$answer['reason']==='unavailable'&&count($store->reads)===$readsBefore,'An unverified plan never executes and never claims the requested rows were retrieved.');
$calls=0;
$transport=static function($url,$payload,$deadline,$cancelled,&$metrics)use(&$calls,$plan,$raw):array{
    $calls++;
    if($calls===1)return $raw($plan(['tool'=>'frequency','field'=>'nonexistent']));
    return $raw((isset($payload['tools'])||isset($payload['format']['properties']['calls']))?$plan(['tool'=>'frequency','field'=>'first_name']):['conditions'=>[],'result_type'=>'multiple','valid'=>true,'correction'=>'']);
};
$answer=audienceAiGenerate($request,microtime(true)+45,null,$transport,$tools);
$expect($calls===3&&$answer['reason']===''&&count($answer['evidence']['facts'])===4,'Invalid fields receive a bounded correction and grouping retains all verified facts and ties.');
$store->overview=['includes_samples'=>false,'totals'=>['click_rate'=>13.64,'clicks_per_open'=>25],'previous'=>['click_rate'=>null,'clicks_per_open'=>null]];
$store->reportRows=[['label'=>'Newsletter','click_rate'=>18.18,'clicks_per_open'=>30],['label'=>'Invitation','click_rate'=>9.09,'clicks_per_open'=>20]];
$reportRequest=array_replace($request,['prompt'=>'Compare two campaigns.']);$calls=0;$reportsBefore=count($store->reports);$readsBefore=count($store->reads);
$transport=static function($url,$payload,$deadline,$cancelled,&$metrics)use(&$calls,$plan,$raw):array{
    $calls++;
    if((isset($payload['tools'])||isset($payload['format']['properties']['calls'])))return $raw($plan(['tool'=>'report','dataset'=>'email','dimension'=>'campaign','metrics'=>['click_rate'],'scope'=>['kind'=>'recent_campaigns','count'=>2]]));
    if(isset($payload['format']['properties']['valid']))return $raw(['conditions'=>[],'result_type'=>'multiple','valid'=>true,'correction'=>'']);
    return $raw(['finding_ids'=>['compare:query_0_0_click_rate:member'],'limitation_ids'=>[],'recommendations'=>[]]);
};
$answer=audienceAiGenerate($reportRequest,microtime(true)+45,null,$transport,$tools);
$expect($calls===3&&count($store->reports)===$reportsBefore+2&&count($store->reads)===$readsBefore,'A reviewed report plan runs only its selected reads and calculated finding selection.');
$expect($answer['interpretation']==='calculated'&&str_contains($answer['observations'][0]['text'],'higher than Invitation (9.09%) by 9.09 percentage points'),'Structured planning retains verified campaign comparisons end to end.');
$ratio=$tools->execute(['queries'=>[array_replace($base,['tool'=>'report','field'=>'','metrics'=>['clicks_per_open'],'limit'=>2])]]);
$expect(count(array_filter($ratio['facts'],static fn($f)=>$f['unit']==='%'))===3,'Percentage metrics without a rate suffix retain percentage units in all report facts.');
$catalog=audienceAiFindingCatalog($ratio);
$expect(str_contains($catalog['compare:query_0_0_clicks_per_open:member']['text'],'by 10 percentage points'),'Every supported percentage metric uses the same comparison calculation.');
$expect($ratio['facts'][0]['measurement']['level']==='total'&&$ratio['facts'][1]['measurement']['level']==='member','Period aggregates are identified separately from individual report groups.');

// Ambiguous language must not execute a guessed population, and partial state must be safe to resume.
$draft=$tools->clarificationPlan($initial,['queries'=>[]],'new_topic',[$filters[1]],'contacts','What qualifies someone for this group?');
$expect($draft['queries'][0]['filters']===[$filters[1]]&&$draft['queries'][0]['tool']==='contacts','An unresolved draft retains only independently verified conditions, excluding guessed filters.');
$draft=$tools->clarificationPlan(['queries'=>[]],['queries'=>[]],'new_topic',[],'contacts','Which criterion defines this group?');
$expect($draft['queries'][0]['limit']===100&&$draft['queries'][0]['filters']===[],'A clarification can preserve the intended output even when the planner supplied no data tool.');
$draft=$tools->clarificationPlan(['queries'=>[]],$reportState,'continuation',[],'report','Which supported measure should be used?');
$expect($draft['queries']===$reportState['queries'],'Unsupported report measures preserve the previously verified campaign selection while asking for clarification.');
$review=$tools->planReviewPayload('Compare these campaigns.',$reportState,['queries'=>$reportState['queries']]);
$expect($review['think']===false&&$review['options']['num_predict']<=700,'Report review follows the disabled reasoning setting and keeps a bounded output budget.');
putenv('DNR_AI_COACH_REASONING=1');
$thinkingReview=$tools->planReviewPayload('Compare these campaigns.',$reportState,['queries'=>$reportState['queries']]);
$expect($thinkingReview['think']===true,'Explicit reasoning opt-in still enables extended report review.');
putenv('DNR_AI_COACH_REASONING=0');
$growthQuery=['tool'=>'report','dataset'=>'audience','dimension'=>'day','metrics'=>['net'],'limit'=>5,'last_days'=>90];
$growthReview=$tools->planReviewPayload('Show audience growth over the last quarter.',audienceAiDialogueState([]),['queries'=>$tools->validate(['queries'=>[$growthQuery]])]);
$expect($growthReview['think']===false&&$growthReview['options']['num_predict']<=700,'Count-only report verification avoids extended reasoning while retaining independent checks.');
$expect(isset($growthReview['format']['properties']['valid'],$growthReview['format']['properties']['unresolved'],$growthReview['format']['properties']['report_metrics']),'Fast report review retains validity, clarification, and independent metric selection.');
$expect(!in_array('clarification',$review['format']['properties']['result_type']['enum'],true)&&$review['format']['properties']['conditions']['maxItems']===0,'Report validation cannot contradict its own ambiguity decision or invent contact filters.');
$reviewContext=json_decode($review['messages'][1]['content'],true);
$expect(!isset($reviewContext['proposed']['queries'][0]['metrics'])&&isset($reviewContext['conversation']['queries'][0]['metrics']),'Measurement intent is selected independently of unverified planner columns while retaining verified conversational scope.');
$scopeText=$reviewContext['report_capabilities']['email']['scope']??'';
$expect(str_contains($scopeText,'Test emails')&&str_contains($scopeText,'tracked separately')&&str_contains($scopeText,'confirmed delivery counts require delivery receipts'),'Independent metric review knows campaign evidence excludes separately tracked test sends and acceptance is not delivery.');
$planningContext=json_decode($tools->planningPayload('Explain campaign delivery evidence.',audienceAiDialogueState([]))['messages'][1]['content'],true);
$expect($scopeText!==''&&($planningContext['reports']['email']['scope']??null)===$scopeText,'Planning and independent review share the same native email scope instead of inventing test-email inclusion.');
$fixedQuery=array_replace($reportState['queries'][0],['dimension'=>'links','metrics'=>[]]);
$fixedReview=$tools->planReviewPayload('Compare the tracked links.', ['queries'=>[],'turns'=>[]], ['queries'=>[$fixedQuery]]);
$expect($fixedReview['format']['properties']['report_metrics']['maxItems']===0,'Fixed-observation report dimensions cannot acquire incompatible metric overrides during review.');

$payload=$tools->planningPayload('Change the condition.',$state,'',['context'=>'continuation']);
$names=array_column(array_column($payload['tools'],'function'),'name');
$expect(!in_array('count_audience_contacts',$names,true)&&in_array('edit_audience_query',$names,true),'Follow-ups edit their saved population instead of recreating it with dropped filters.');
$selected=$tools->selectedPlan(['done'=>true,'done_reason'=>'stop','message'=>['tool_calls'=>[['function'=>['name'=>'edit_audience_query','arguments'=>['operation'=>'contacts','limit'=>8]]]]]],$state);
$expect($selected['requests'][0]['base_query']===0&&$tools->resolvePlan($selected,$state)['queries'][0]['filters']===$state['queries'][0]['filters'],'A sole prior query needs no model-generated array index and retains all its filters.');
$payload=$tools->planningPayload('An independent question.',$state,'',['context'=>'new_topic']);
$expect(!in_array('edit_audience_query',array_column(array_column($payload['tools'],'function'),'name'),true),'New independent questions cannot accidentally edit the old population.');
$calls=0;$readsBefore=count($store->reads);$feedback=false;
$transport=static function($url,$payload,$deadline,$cancelled,&$metrics)use(&$calls,&$feedback,$plan,$raw,$filters):array{
    $calls++;
    if(isset($payload['format']['properties']['valid']))return $raw(['unresolved'=>[],'conditions'=>$filters,'result_type'=>'count','valid'=>true,'correction'=>'']);
    $context=json_decode($payload['messages'][1]['content'],true);if($calls>2)$feedback=str_contains($context['correction'],'Required condition');
    return $raw($plan(['tool'=>'count','filters'=>$calls===1?[]:$filters]));
};
$answer=audienceAiGenerate($request,microtime(true)+45,null,$transport,$tools);
$expect(!$feedback&&$calls===2&&count($store->reads)===$readsBefore+1&&count($answer['evidence']['queries'][0]['filters'])===3,'Typed required conditions are compiled into the approved query before reading any records, without another model round trip.');
$calls=0;$readsBefore=count($store->reads);$ambiguous=array_replace($request,['prompt'=>'Who are our priority members?']);
$transport=static function($url,$payload,$deadline,$cancelled,&$metrics)use(&$calls,$plan,$raw,$filters):array{
    $calls++;if(isset($payload['format']['properties']['needed']))return $raw(['needed'=>true,'explanation'=>'Membership has no definition.']);return $raw((isset($payload['tools'])||isset($payload['format']['properties']['calls']))?$plan(['tool'=>'contacts','filters'=>[$filters[0]]]):['unresolved'=>[['phrase'=>'priority members','question'=>'What criterion defines a priority member?']],'conditions'=>[],'result_type'=>'contacts','valid'=>false,'correction'=>'Do not guess membership.']);
};
$answer=audienceAiGenerate($ambiguous,microtime(true)+45,null,$transport,$tools);
$expect($calls===3&&count($store->reads)===$readsBefore&&$answer['interpretation']==='clarification'&&$answer['conversation_state']['queries'][0]['filters']===[],'Undefined membership prompts ask for a criterion and never execute the guessed subscription filter.');
$failed=$request+['previous_context'=>audienceAiPreviousContext($ambiguous['prompt'],$answer)];
$answer=audienceAiGenerate($failed,microtime(true)+45,null,static function(){throw new RuntimeException('Unavailable model.');},$tools);
$expect(count($answer['conversation_state']['turns'])===2&&$answer['conversation_state']['queries'][0]['tool']==='contacts','A failed model call preserves earlier conversation state for the next follow-up.');
$resolved=array_replace($request,['prompt'=>'Count contacts added on or before February 21, 2026.']);$readsBefore=count($store->reads);
$transport=static function($url,$payload,$deadline,$cancelled,&$metrics)use($plan,$raw):array{
    if((isset($payload['tools'])||isset($payload['format']['properties']['calls'])))return $raw(['context'=>'new_topic','requests'=>[],'clarification'=>['issue'=>'missing_criterion','question'=>'Should I use February 21, 2026 as the cutoff?']]);
    if(isset($payload['format']['properties']['needed']))return $raw(['needed'=>false,'conditions'=>[['field'=>'created_at','operator'=>'lte','value'=>'2026-02-21','value_quote'=>'February 21, 2026']],'explanation'=>'The user explicitly supplied this cutoff.']);
    return $raw(['unresolved'=>[['phrase'=>'on or before February 21, 2026','question'=>'Should I use February 21, 2026 as the cutoff?']],'conditions'=>[['field'=>'created_at','operator'=>'lte','value'=>'2026-02-21']],'result_type'=>'count','valid'=>true,'correction'=>'']);
};
$answer=audienceAiGenerate($resolved,microtime(true)+45,null,$transport,$tools);
$expect($answer['interpretation']==='data-only'&&count($store->reads)===$readsBefore+1&&$answer['evidence']['queries'][0]['filters'][0]['value']==='2026-02-21','Confirmation of an explicitly supplied criterion becomes a verified count instead of a repeated clarification.');
$calls=0;
$transport=static function($url,$payload,$deadline,$cancelled,&$metrics)use(&$calls,$plan,$raw,$filters):array{
    $calls++;return $raw((isset($payload['tools'])||isset($payload['format']['properties']['calls']))?$plan(['tool'=>'frequency','field'=>'source','filters'=>$filters,'limit'=>9]):['unresolved'=>[],'conditions'=>[],'result_type'=>'contacts','valid'=>true,'correction'=>'']);
};
$answer=audienceAiGenerate($request,microtime(true)+45,null,$transport,$tools);
$expect($calls===2&&$answer['evidence']['queries'][0]['tool']==='contacts'&&$answer['evidence']['queries'][0]['limit']===9&&count($answer['evidence']['queries'][0]['filters'])===3,'An approved contact-row intent changes the output without losing the selected row limit or population.');
$transport=static function($url,$payload,$deadline,$cancelled,&$metrics)use($plan,$raw,$filters):array{
    return $raw((isset($payload['tools'])||isset($payload['format']['properties']['calls']))?$plan(['tool'=>'count','filters'=>$filters,'limit'=>7]):['unresolved'=>[],'conditions'=>$filters,'result_type'=>'contacts','valid'=>true,'correction'=>'']);
};
$answer=audienceAiGenerate($request,microtime(true)+45,null,$transport,$tools);
$expect($answer['evidence']['queries'][0]['tool']==='contacts'&&$answer['evidence']['queries'][0]['limit']===7,'A scalar draft cannot erase the requested row count before the verified contact output is compiled.');

$transport=static function($url,$payload,$deadline,$cancelled,&$metrics)use($plan,$raw):array{
    if((isset($payload['tools'])||isset($payload['format']['properties']['calls'])))return $raw($plan(['tool'=>'report','dataset'=>'email','dimension'=>'campaign','metrics'=>['clicks'],'scope'=>['kind'=>'recent_campaigns','count'=>2]]));
    if(isset($payload['format']['properties']['valid']))return $raw(['unresolved'=>[],'conditions'=>[],'report_metrics'=>['click_rate'],'result_type'=>'report','valid'=>true,'correction'=>'']);
    return $raw(['finding_ids'=>['compare:query_0_0_click_rate:member'],'limitation_ids'=>[],'recommendations'=>[]]);
};
$answer=audienceAiGenerate($reportRequest,microtime(true)+45,null,$transport,$tools);
$expect($answer['evidence']['queries'][0]['metrics']===['click_rate']&&$answer['interpretation']==='calculated'&&$answer['evidence']['facts'][0]['unit']==='%','A verified percentage requirement replaces an incorrectly selected count before report calculation.');
$calls=0;
$transport=static function($url,$payload,$deadline,$cancelled,&$metrics)use(&$calls,$plan,$raw):array{
    $calls++;
    if((isset($payload['tools'])||isset($payload['format']['properties']['calls'])))return $raw($plan(['tool'=>'report','dataset'=>'email','dimension'=>'campaign','metrics'=>['clicks'],'scope'=>['kind'=>'recent_campaigns','count'=>2]]));
    if(isset($payload['format']['properties']['valid']))return $raw(['unresolved'=>[['phrase'=>'click percentage','question'=>'Should I use the percentage of delivered messages clicked?']],'conditions'=>[],'report_metrics'=>['click_rate'],'result_type'=>'report','valid'=>true,'correction'=>'']);
    if(isset($payload['format']['properties']['needed']))return $raw(['needed'=>false,'conditions'=>[],'report_metrics'=>['clicks'],'explanation'=>'The percentage is already specified.']);
    return $raw(['finding_ids'=>['compare:query_0_0_click_rate:member'],'limitation_ids'=>[],'recommendations'=>[]]);
};
$answer=audienceAiGenerate(array_replace($reportRequest,['prompt'=>'Compare click percentage for the latest two campaigns.']),microtime(true)+45,null,$transport,$tools);
$expect($calls===4&&$answer['evidence']['queries'][0]['metrics']===['click_rate'],'A secondary confirmation check cannot overwrite the independently verified measurement with an absolute count.');
$calls=0;$readsBefore=count($store->reads);
$transport=static function($url,$payload,$deadline,$cancelled,&$metrics)use(&$calls,$plan,$raw):array{
    $calls++;
    if((isset($payload['tools'])||isset($payload['format']['properties']['calls'])))return $raw($plan(['tool'=>'report','dataset'=>'email','dimension'=>'campaign','metrics'=>['clicks'],'scope'=>['kind'=>'recent_campaigns','count'=>2]]));
    return $raw(['unresolved'=>[['phrase'=>'profitability','question'=>'Profit data is unavailable. Which recorded performance measure should I compare?']],'conditions'=>[],'report_metrics'=>[],'result_type'=>'report','valid'=>false,'correction'=>'Unsupported measure.']);
};
$answer=audienceAiGenerate(array_replace($reportRequest,['prompt'=>'Compare profitability for the latest two campaigns.']),microtime(true)+45,null,$transport,$tools);
$expect($calls===2&&count($store->reads)===$readsBefore&&$answer['interpretation']==='clarification'&&$answer['conversation_state']['queries'][0]['recent_campaigns']===2&&$answer['conversation_state']['queries'][0]['metrics']===[],'An unavailable measure asks for clarification without substituting engagement metrics or losing the known campaign selection.');
foreach (['Show the last 2 campaigns'=>2,'Compare the latest four email campaigns'=>4,'Most recent ten campaigns'=>10] as $text=>$count) $expect(audienceAiExplicitCampaignCount($text)===$count,'Explicit cardinality uses campaign units.');
foreach (['Show the last 3 days','Compare the last 2 campaigns and last 4 campaigns','Not the last 3 campaigns',"Don't use the last 3 campaigns",'Avoid the latest four campaigns','The last 3 campaigns in the past 30 days','Latest 11 campaigns','The last 3 campaign days'] as $text) $expect(audienceAiExplicitCampaignCount($text)===null,'Ambiguous, unsupported or date-qualified selections are not silently rewritten.');
$transport=static function($url,$payload,$deadline,$cancelled,&$metrics)use($plan,$raw):array{
    if((isset($payload['tools'])||isset($payload['format']['properties']['calls'])))return $raw($plan(['tool'=>'report','dataset'=>'email','dimension'=>'links','metrics'=>[],'scope'=>['kind'=>'last_days','days'=>4],'limit'=>7]));
    return $raw(['unresolved'=>[],'conditions'=>[],'report_metrics'=>[],'result_type'=>'report','valid'=>true,'correction'=>'']);
};
$answer=audienceAiGenerate(array_replace($reportRequest,['prompt'=>'Show clicked links in the latest four campaigns.']),microtime(true)+45,null,$transport,$tools);
$expect($answer['evidence']['queries'][0]['recent_campaigns']===4&&$answer['evidence']['queries'][0]['last_days']===0,'A planner cannot silently replace an explicit campaign count with a day range.');
// Synthetic ambiguity: a campaign count is already explicit, despite a draft asking for confirmation.
foreach (['admin','editor','reviewer'] as $role) {
    $calls=0;
    $transport=static function($url,$payload,$deadline,$cancelled,&$metrics)use(&$calls,$plan,$raw):array{
        $calls++;
        if ($calls===1) return $raw(['context'=>'new_topic','requests'=>[],'clarification'=>['issue'=>'missing_criterion','question'=>'Campaigns or days?']]);
        if ($calls===2) return $raw(['unresolved'=>[['phrase'=>'latest four campaigns','question'=>'Do you mean four campaigns or four days?','kind'=>'scope']],'conditions'=>[],'report_metrics'=>[],'result_type'=>'report','valid'=>false,'correction'=>'Confirm scope.']);
        if (isset($payload['format']['properties']['needed'])) return $raw(['needed'=>false,'conditions'=>[],'explanation'=>'The user already specified a count of campaigns.']);
        if ((isset($payload['tools'])||isset($payload['format']['properties']['calls']))) return $raw($plan(['tool'=>'report','dataset'=>'email','dimension'=>'links','metrics'=>[],'scope'=>['kind'=>'recent_campaigns','count'=>4],'limit'=>7]));
        return $raw(['unresolved'=>[],'conditions'=>[],'report_metrics'=>[],'result_type'=>'report','valid'=>true,'correction'=>'']);
    };
    $answer=audienceAiGenerate(array_replace($reportRequest,['role'=>$role,'prompt'=>'Show clicked links in the latest four campaigns.']),microtime(true)+45,null,$transport,$tools);
    $query=$answer['evidence']['queries'][0]??[];
    $expect($calls===4&&($query['recent_campaigns']??0)===4&&($query['last_days']??-1)===0&&($query['dimension']??'')==='links'&&($query['limit']??0)===7,'An explicit campaign count is independently checked and replanned instead of re-asked for every role.');
}
$calls=0;$reportsBefore=count($store->reports);
$transport=static function($url,$payload,$deadline,$cancelled,&$metrics)use(&$calls,$plan,$raw):array{
    $calls++;
    if((isset($payload['tools'])||isset($payload['format']['properties']['calls'])))return $raw($plan(['tool'=>'report','dataset'=>'email','dimension'=>'links','metrics'=>[],'scope'=>['kind'=>'selected']]));
    if(isset($payload['format']['properties']['valid']))return $raw(['unresolved'=>[['phrase'=>'recent campaigns','question'=>'How many recent campaigns should I use?','kind'=>'scope']],'conditions'=>[],'report_metrics'=>[],'result_type'=>'report','valid'=>false,'correction'=>'Missing campaign count.']);
    return $raw(['needed'=>true,'conditions'=>[],'explanation'=>'No number of campaigns was supplied.']);
};
$answer=audienceAiGenerate(array_replace($reportRequest,['prompt'=>'Show links from recent campaigns.']),microtime(true)+45,null,$transport,$tools);
$expect($calls===3&&count($store->reports)===$reportsBefore&&$answer['interpretation']==='clarification','A genuinely missing campaign count still clarifies before reading reports.');
$expect($tools->missingReportGroups([['dataset'=>'email','dimension'=>'country'],['dataset'=>'email','dimension'=>'device']],[['tool'=>'report','dataset'=>'email','dimension'=>'country'],['tool'=>'report','dataset'=>'email','dimension'=>'device']])===[],'A complete multi-breakdown report needs no replanning.');
$expect($tools->missingReportGroups([['dataset'=>'email','dimension'=>'day']],[['tool'=>'report','dataset'=>'email','dimension'=>'campaign']])===[['dataset'=>'email','dimension'=>'day']],'A temporal breakdown cannot be replaced by campaign grouping.');
$reject(static fn()=>$tools->missingReportGroups([['dataset'=>'audience','dimension'=>'device']],[]),'Tracking groupings cannot be assigned to an unsupported dataset.');
$reject(static fn()=>$tools->missingReportGroups([['dataset'=>'email','dimension'=>'secret','account'=>'other']],[]),'Grouping requirements cannot add fields, accounts or unsupported dimensions.');
foreach(['admin','editor','reviewer'] as $groupRole){
    $plannerCalls=0;$reportsBefore=count($store->reports);
    $transport=static function($url,$payload,$deadline,$cancelled,&$metrics)use(&$plannerCalls,$reportsBefore,$store,$expect,$plan,$raw):array{
        if(isset($payload['tools'])||isset($payload['format']['properties']['calls'])){
            $plannerCalls++;
            $expect(count($store->reports)===$reportsBefore,'An incomplete report grouping is corrected before any report reads.');
            $draft=$plan(['tool'=>'report','dataset'=>'email','dimension'=>$plannerCalls===1?'campaign':'country','metrics'=>[],'scope'=>['kind'=>'last_days','days'=>30]]);
            if($plannerCalls>1)$draft['requests'][]=['base_query'=>-1,'changes'=>['tool'=>'report','dataset'=>'email','dimension'=>'device','metrics'=>[],'scope'=>['kind'=>'last_days','days'=>30]],'remove_filters'=>[]];
            return $raw($draft);
        }
        if(isset($payload['format']['properties']['valid']))return $raw(['unresolved'=>[],'conditions'=>[],'report_metrics'=>[],'report_groups'=>[['dataset'=>'email','dimension'=>'country'],['dataset'=>'email','dimension'=>'device']],'result_type'=>'report','valid'=>true,'correction'=>'']);
        return $raw(['finding_ids'=>[],'limitation_ids'=>[],'recommendations'=>[]]);
    };
    $answer=audienceAiGenerate(array_replace($reportRequest,['role'=>$groupRole,'prompt'=>'Break down email engagement by geography and device.']),microtime(true)+45,null,$transport,$tools);
    $expect($plannerCalls===1&&array_column($answer['evidence']['queries'],'dimension')===['country','device'],'Independent grouping requirements compile all requested breakdowns before reads for each analytics role.');
}
putenv('DNR_AI_COACH_URL');
echo "Audience AI read-only query tools passed ($checks checks).\n";
