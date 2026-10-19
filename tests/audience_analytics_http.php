<?php
require_once __DIR__.'/audience_analytics_fixtures.php';
foreach(['audience.php?view=analytics&section=history','audience.php?view=home&origin=mailchimp','audience.php?view=analytics&origin=mailchimp&download=csv','audience.php?view=assistant&mode=analytics&origin=mailchimp']as$removedPath){$removed=$admin($removedPath);audienceHttpExpect($removed['status']===400&&!str_contains($removed['body'],'Mailchimp history'),'Retired history routes do not expose imported data or exports');}
$nativePage=$admin('audience.php?view=analytics');audienceHttpExpect($nativePage['status']===200&&!str_contains($nativePage['body'],'Mailchimp history'),'Native analytics navigation omits the removed history feature');
$startChecks=$checks;
$conn->query("INSERT INTO marketing_audiences(name,settings_json) VALUES ('Analytics isolation fixture',JSON_OBJECT())");$analyticsAid=(int)$conn->insert_id;
$as=new AudienceStore($conn,$analyticsAid,1,'Analytics fixture');
$samples=audienceAnalyticsFixture($as,'Sample');$window=audienceAnalyticsWindow([]);
$result=$as->analyticsOverview($window);
audienceHttpExpect($result['totals']['accepted']===36,'Analytics counts accepted messages once');
audienceHttpExpect($result['totals']['delivered']===33&&$result['totals']['delivery_rate']===91.67,'Delivery rate requires provider receipts and keeps the accepted denominator');
audienceHttpExpect($result['totals']['opens']===18&&$result['totals']['clicks']===3,'Known bot activity is excluded by default');
$raw=$as->analyticsOverview(array_replace($window,['bots'=>'all']));
audienceHttpExpect($raw['totals']['opens']===21&&$raw['totals']['clicks']===6,'Unfiltered metrics include the exact detected bot observations');
audienceHttpExpect($result['totals']['detected_bot']===3&&$result['includes_samples'],'Sample and bot provenance are explicit');
$one=$as->analyticsOverview(array_replace($window,['messages_mode'=>'selected','messages'=>[$samples['campaigns'][0]]]));
audienceHttpExpect($one['totals']['accepted']===12,'Message selection scopes both model and report cohorts');
audienceHttpExpect($as->analyticsOverview(array_replace($window,['messages_mode'=>'selected','messages'=>[]]))['totals']['accepted']===0,'Deselecting every message is an empty selection rather than all messages');
audienceHttpExpect($as->analyticsOverview(array_replace($window,['channel'=>'automation']))['totals']['accepted']===0,'Automation type filter excludes regular delivery records');
audienceHttpExpect($result['previous']['accepted']===30&&$result['previous']['open_rate']===40.0,'Previous period uses its own accepted cohort and observations');
audienceHttpExpect($result['totals']['accepted']>$result['previous']['accepted']&&$result['totals']['click_rate']<$result['previous']['click_rate'],'Sample comparisons exercise both up and down trends');
audienceHttpExpect($result['previous']['unsubscribed']===1&&$result['totals']['unsubscribed']===0,'Unsubscribe attribution stays in the correct message cohort');
$empty=$as->analyticsOverview(audienceAnalyticsWindow(['start'=>'2020-01-01','end'=>'2020-01-30']));
audienceHttpExpect($empty['totals']['accepted']===0&&$empty['totals']['open_rate']===null,'Empty comparisons remain unknown rather than a fabricated percentage');
audienceHttpExpect(array_sum(array_column($result['daily'],'accepted'))===36,'Trend and headline reconcile');
audienceHttpExpect(array_sum(array_column(array_column($result['campaigns'],'metrics'),'accepted'))===36,'Campaign rows reconcile with the same cohort');
$r=$as->one('SELECT r.id FROM marketing_campaign_recipients r WHERE r.audience_id=? ORDER BY id LIMIT 1',[$analyticsAid]);
$as->write("INSERT INTO marketing_message_events(audience_id,recipient_id,event_type,event_key,source,event_at,details_json) VALUES (?,?,'open',?,'tracking',UTC_TIMESTAMP(),JSON_OBJECT('bot',false))",[$analyticsAid,$r['id'],bin2hex(random_bytes(32))]);
$new=$as->analyticsOverview($window);audienceHttpExpect($new['totals']['accepted']===36&&$new['totals']['opens']===19,'Repeated event fan-out never inflates sends or recipient engagement');
$as->write("INSERT INTO marketing_message_events(audience_id,recipient_id,event_type,event_key,source,event_at,details_json) VALUES (?,?,'open',?,'tracking',UTC_TIMESTAMP(),JSON_OBJECT('bot',false))",[$analyticsAid,$r['id'],bin2hex(random_bytes(32))]);
audienceHttpExpect($as->analyticsOverview($window)['totals']['opens']===19,'A second human observation of the same message remains unique');
foreach([['start'=>'2026-02-30'],['start'=>'2026-10-17','end'=>'2026-10-16'],['start'=>'2000-01-01'],['end'=>'2099-01-01'],['bots'=>'invented']]as$bad){$failed=false;try{audienceAnalyticsWindow($bad);}catch(InvalidArgumentException){$failed=true;}audienceHttpExpect($failed,'Invalid reporting window is rejected');}
audienceHttpExpect(!array_intersect($samples['campaigns'],array_column($campaignStore->analyticsOverview($window)['campaigns'],'id')),'Analytics fixture never crosses audience boundary');
foreach(['home','analytics']as$v){$response=$editor('audience.php?view='.$v);audienceHttpExpect($response['status']===200&&str_contains($response['body'],$v==='home'?'Email snapshot':'Email engagement')&&!preg_match('/(?:Warning|Fatal error):/',$response['body']),'Native report route renders '.$v);
    preg_match('/<script type="application\/json" id="audience-config">(.*?)<\/script>/s',$response['body'],$configMatch);$config=json_decode($configMatch[1]??'',true);
    audienceHttpExpect(is_array($config)&&is_array($config['catalog']??null)&&($config['audience']??null)===1&&str_ends_with(trim($response['body']),'</html>'),'Report renders a complete shared shell and valid client configuration '.$v);
}
$csv=$editor('audience.php?view=analytics&download=csv');audienceHttpExpect($csv['status']===200&&str_contains($csv['body'],'UTC day')&&count(str_getcsv(explode("\n",trim($csv['body']))[0],',','"',''))===5,'Daily CSV exposes the same native metric schema');
audienceHttpExpect($reviewer('audience.php?view=analytics&download=csv')['status']===403,'Reviewer cannot download analytics');
audienceHttpExpect($editor('audience.php?view=analytics&bots=invalid')['status']===400,'Invalid report filters return a clear client error');
audienceHttpExpect($editor('audience.php?view=analytics&metric=open_rate&group=week&delivery_metric=delivered&delivery_group=month')['status']===200,'Independent metric and chart interval controls render together');
audienceHttpExpect($editor('audience.php?view=analytics&messages_mode=selected&messages[]='.$samples['campaigns'][0])['status']===400,'Cross-audience message selection is rejected by the HTTP route');
foreach(['audience','conversions']as$section)audienceHttpExpect($editor('audience.php?view=analytics&section='.$section)['status']===200,'Analytics section renders '.$section);
audienceHttpExpect(!str_contains($editor('audience.php?view=campaigns&create=1')['body'],'A/B test'),'Email creation offers no A/B controls');
$blocked=false;try{$d=audienceCollectionDefaults('popup');$d['variants']=[['title'=>'Alternative']];audienceCollectionDocument($d,'popup',$campaignStore->fields(),$campaignStore->groups(),$campaignStore->tags());}catch(InvalidArgumentException $e){$blocked=str_contains($e->getMessage(),'A/B testing is not available');}
audienceHttpExpect($blocked,'Collection variants cannot reintroduce A/B testing outside emails');
audienceHttpExpect($editor('audience.php?view=analytics',['csrf_token'=>$token])['status']===400,'Unsupported report actions cannot mutate on POST');
require __DIR__.'/audience_analytics_reports_http.php';
require __DIR__.'/audience_engagement_http.php';
echo 'Analytics HTTP/storage qualification passed ('.($checks-$startChecks)." checks).\n";
