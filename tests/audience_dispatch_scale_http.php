<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Disposable suite required.\n");
// Exercise the real 30-second HTTP client and confirmation transaction. These
// fictional recipients are scheduled in the future; no worker or transport runs.
$scaleStart=$checks;$scaleCount=7233;
$conn->query("INSERT INTO marketing_tags(audience_id,name) VALUES (1,'Confirmation scale fixture')");
$scaleTag=(int)$conn->insert_id;$scaleId=0;
$scalePrefix='confirm-scale-'.bin2hex(random_bytes(4)).'-';
try{
    $conn->begin_transaction();
    for($offset=0;$offset<$scaleCount;$offset+=100){
        $params=[];$values=[];
        for($i=$offset;$i<min($offset+100,$scaleCount);$i++){
            $values[]="(1,?,?,'subscribed','','scale-fixture')";
            array_push($params,$scalePrefix.$i.'@example.invalid','Reader '.$i);
        }
        $conn->execute_query('INSERT INTO marketing_contacts(audience_id,email,first_name,email_status,notes,source) VALUES '.implode(',',$values),$params);
    }
    $conn->execute_query('INSERT INTO marketing_contact_tags(contact_id,tag_id) SELECT id,? FROM marketing_contacts WHERE audience_id=1 AND email LIKE ?',[$scaleTag,$scalePrefix.'%']);
    $conn->commit();
    $scalePeople=array_map('intval',array_column($campaignStore->all('SELECT contact_id FROM marketing_contact_tags WHERE tag_id=? ORDER BY contact_id',[$scaleTag]),'contact_id'));
    audienceHttpExpect(count($scalePeople)===$scaleCount,'Scale fixture contains exactly 7,233 fictional recipients');
    // Latest valid recipient events win; malformed/operator/unrelated events do not.
    foreach([[0,'recipient','signup-permission','html'],[0,'recipient','signup-permission','text'],[0,'recipient','signup-permission','invalid'],
             [1,'recipient','signup-permission','text'],[1,'recipient','signup-permission','html'],
             [2,'user','signup-permission','text'],[3,'recipient','other','text']]as[$index,$actor,$event,$format]){
        $conn->execute_query('INSERT INTO marketing_contact_events(contact_id,actor_user_id,actor_type,event_type,details_json) VALUES (?,NULL,?,?,?)',[$scalePeople[$index],$actor,$event,json_encode(['email_format'=>$format])]);
    }
    $scaleId=$makeDispatch('Large confirmation no-send fixture');
    $scaleRow=$campaignStore->campaign($scaleId);
    $campaignStore->saveCampaignSection($scaleId,(int)$scaleRow['version'],'to',['target'=>'tag:'.$scaleTag]);
    $scaleRow=$campaignStore->campaign($scaleId);$scaleDraft=$scaleRow['draft'];
    $scaleDraft['personalize_to']=true;$scaleDraft['to_merge']='FNAME';
    $scaleDraft['tracking']=['opens'=>true,'clicks'=>true,'plain_clicks'=>true];
    // Match a real newsletter's ~850 KB embedded font/image workload, not a tiny
    // plain-text benchmark. The image has a valid PNG text chunk.
    $scalePng=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jF9sAAAAASUVORK5CYII=');
    $padding='tEXt'."Comment\0".str_repeat('x',220000);
    $chunk=pack('N',strlen($padding)-4).$padding.pack('N',crc32($padding));
    $scaleImage='data:image/png;base64,'.base64_encode(substr($scalePng,0,-12).$chunk.substr($scalePng,-12));
    $scaleFont='data:font/ttf;base64,'.base64_encode("\x00\x01\x00\x00".str_repeat("\0",415000));
    $scaleDraft['design']=audienceDesign(['schema'=>1,'styles'=>['webFont'=>$scaleFont],'rows'=>[
        ['columns'=>[[['type'=>'image','image'=>$scaleImage,'alt'=>'Fixture photo']]]],
        ['columns'=>[[['type'=>'heading','text'=>'Hello *|FNAME|*']]]],
        ['columns'=>[[['type'=>'text','html'=>'<p>'.str_repeat('Newsletter content with a <a href="https://example.test/article">reader link</a>. ',70).'</p>']]]],
        ['columns'=>[[['type'=>'share','sharePlatforms'=>['facebook','email']]]]]
    ]]);
    $conn->execute_query("UPDATE marketing_campaigns SET campaign_type='regular',draft_json=? WHERE id=?",[json_encode($scaleDraft),$scaleId]);
    $scaleSaved=$campaignStore->campaign($scaleId)['draft_json'];
    audienceHttpExpect(strlen($scaleSaved)>850000,'Scale newsletter exercises production-sized embedded assets');
    // A failure after 72 successful insert batches must roll back every batch.
    $lastScalePerson=$scalePeople[$scaleCount-1];
    $conn->execute_query("UPDATE marketing_contacts SET first_name=?,version=version+1 WHERE id=?",["Invalid\nHeader",$lastScalePerson]);
    $scaleReview=$campaignStore->reviewCampaignDispatch($scaleId,$dispatchAudience,$dispatchBase);
    $queryCounts=static function()use($conn):array{
        return array_column($conn->query("SHOW SESSION STATUS WHERE Variable_name IN ('Com_select','Com_insert')")->fetch_all(MYSQLI_ASSOC),'Value','Variable_name');
    };
    $beforeCounts=$queryCounts();$rejected=false;
    try{$campaignStore->confirmCampaignDispatch($scaleId,(int)$scaleRow['version'],$scaleReview['proof'],$dispatchAudience,$dispatchBase);}
    catch(InvalidArgumentException $error){$rejected=str_contains($error->getMessage(),'invalid characters');}
    $afterCounts=$queryCounts();
    $selects=(int)$afterCounts['Com_select']-(int)$beforeCounts['Com_select'];
    $inserts=(int)$afterCounts['Com_insert']-(int)$beforeCounts['Com_insert'];
    audienceHttpExpect($rejected&&!$campaignStore->campaignDelivery($scaleId)&&$campaignStore->campaign($scaleId)['status']==='draft','Invalid final recipient rolls back the complete large-campaign confirmation');
    audienceHttpExpect($selects<100&&$inserts>=73&&$inserts<100,'Confirmation uses bounded batch queries instead of per-recipient reads and inserts');
    $conn->execute_query("UPDATE marketing_contacts SET first_name='Final reader',version=version+1 WHERE id=?",[$lastScalePerson]);
    $scalePath=$sendPath($scaleId);$scalePage=$editor($scalePath);
    $scaleConfirm=['csrf_token'=>$token,'action'=>'campaign_dispatch_confirm','id'=>$scaleId,'version'=>(int)$scaleRow['version'],'review_proof'=>$proofFrom($scalePage),'confirmed'=>'1'];
    $started=hrtime(true);$response=$editor($scalePath,$scaleConfirm);$seconds=(hrtime(true)-$started)/1e9;
    audienceHttpExpect($response['status']===302,'All 7,233 recipients confirm through the real HTTP endpoint within its 30-second timeout');
    $scaleDelivery=$campaignStore->campaignDelivery($scaleId);$scaleDeliveryId=(int)$scaleDelivery['id'];
    $totals=$campaignStore->one("SELECT COUNT(*) n,COUNT(DISTINCT contact_id) contacts,COUNT(DISTINCT token_hash) tokens,COUNT(DISTINCT message_id) messages,SUM(state='queued' AND attempts=0 AND delivery_started_at IS NULL AND next_attempt_at>UTC_TIMESTAMP()) unsent FROM marketing_campaign_recipients WHERE delivery_id=?",[$scaleDeliveryId]);
    audienceHttpExpect(count(array_filter($totals,static fn($n)=>(int)$n===$scaleCount))===5&&$scaleDelivery['state']==='scheduled','Every recipient has one unique, frozen, unsent receipt');
    $frozenIds=array_map('intval',array_column($campaignStore->all('SELECT contact_id FROM marketing_campaign_recipients WHERE delivery_id=? ORDER BY contact_id',[$scaleDeliveryId]),'contact_id'));
    audienceHttpExpect($frozenIds===$scalePeople,'The complete recipient set exactly matches the reviewed audience');
    foreach($campaignStore->all('SELECT contact_id,context_ciphertext FROM marketing_campaign_recipients WHERE delivery_id=? ORDER BY contact_id LIMIT 4',[$scaleDeliveryId])as$recipient){
        $payload=json_decode(\Dnr\Security\ApplicationKey::open($recipient['context_ciphertext']),true);
        audienceHttpExpect($payload['context']['__email_format']===$campaignStore->collectionEmailFormat((int)$recipient['contact_id']),'Batched preferences match the established latest-valid-event policy');
    }
    $scaleSnapshot=json_decode(\Dnr\Security\ApplicationKey::open($scaleDelivery['snapshot_ciphertext']),true);
    audienceHttpExpect($campaignStore->campaign($scaleId)['draft_json']===$scaleSaved&&$scaleSnapshot['draft']['design']===json_decode($scaleSaved,true)['design'],'Large confirmation preserves the complete saved newsletter and frozen design');
    audienceHttpExpect($editor($scalePath,$scaleConfirm)['status']===302&&(int)$campaignStore->one('SELECT COUNT(*) n FROM marketing_campaign_deliveries WHERE campaign_id=?',[$scaleId])['n']===1&&(int)$campaignStore->one('SELECT COUNT(*) n FROM marketing_campaign_recipients WHERE delivery_id=?',[$scaleDeliveryId])['n']===$scaleCount,'Retrying large confirmation cannot create duplicate deliveries or recipients');
    $campaignStore->campaignDeliveryOperation($scaleId,(int)$scaleDelivery['version'],'cancel');
    echo sprintf("Large-campaign no-send rehearsal: %d recipients confirmed over HTTP in %.3f seconds; %d SELECTs / %d INSERTs before late-error rollback; %d checks passed.\n",$scaleCount,$seconds,$selects,$inserts,$checks-$scaleStart);
}finally{
    $conn->rollback();
    if($scaleId){
        $cleanupDelivery=$campaignStore->campaignDelivery($scaleId);
        if($cleanupDelivery&&in_array($cleanupDelivery['state'],['scheduled','sending','paused'],true))$campaignStore->campaignDeliveryOperation($scaleId,(int)$cleanupDelivery['version'],'cancel');
        $conn->execute_query('UPDATE marketing_campaigns SET deleted_at=UTC_TIMESTAMP() WHERE id=?',[$scaleId]);
    }
    // Delivery receipts and consent evidence are deliberately append-only for
    // the application user. Retire fixtures; the harness destroys the database.
    $conn->execute_query('UPDATE marketing_contacts SET is_archived=1,deleted_at=UTC_TIMESTAMP() WHERE audience_id=1 AND email LIKE ?',[$scalePrefix.'%']);
}
