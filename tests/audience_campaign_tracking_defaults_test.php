<?php
declare(strict_types=1);
require_once __DIR__.'/../src/audience_store.php';

// Exercise the real creation/save methods and message renderer without a database or mail transport.
$store=new class {
    use AudienceCampaigns, AudienceCampaignAdvanced;
    public int $audienceId=1;
    public string $actorLabel='Tracking defaults fixture';
    public object $db;
    public array $rows=[];
    public function __construct(){$this->db=(object)['insert_id'=>0,'affected_rows'=>0];}
    public function transaction(callable $operation):mixed{return $operation();}
    public function one(string $sql,array $params=[]):?array{
        if(str_starts_with($sql,'SELECT settings_json FROM marketing_audiences'))return ['settings_json'=>json_encode(['from_name'=>'Fixture sender','from_email'=>'sender@example.test'])];
        if(str_starts_with($sql,'SELECT * FROM marketing_campaigns'))return $this->rows[$params[1]]??null;
        throw new RuntimeException('Unexpected fixture read');
    }
    public function write(string $sql,array $params=[]):void{
        if(str_starts_with($sql,'INSERT INTO marketing_campaigns')){
            $id=++$this->db->insert_id;
            $this->rows[$id]=['id'=>$id,'name'=>$params[1],'campaign_type'=>$params[2],'draft_json'=>$params[count($params)===6?4:3],'folder_id'=>count($params)===6?$params[3]:null,'status'=>'draft','version'=>1];return;
        }
        if(str_starts_with($sql,'UPDATE marketing_campaigns SET draft_json=')){
            $this->rows[$params[2]]['draft_json']=$params[0];$this->rows[$params[2]]['version']++;$this->db->affected_rows=1;return;
        }
        throw new RuntimeException('Unexpected fixture write');
    }
};
$checks=0;
$expect=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$enabled=['opens'=>true,'clicks'=>true,'plain_clicks'=>true,'inbox'=>true,'video'=>true];
$design=audienceDesign(['schema'=>1,'rows'=>[['columns'=>[[['type'=>'text','html'=>'<p>Read <a href="https://example.test/news">our news</a>.</p>']]]]]]);
$message=static function(array $row)use($design):array{
    $draft=$row['draft']+['subject'=>'Tracking fixture','text'=>'Read https://example.test/news','design'=>$design];
    return audienceDispatchMessage(['base'=>'https://public.example/a/community','tracking_key'=>str_repeat('a',64),'type'=>$row['campaign_type'],'draft'=>$draft,'identity'=>['LIST:COMPANY'=>'Fixture organization','LIST:ADDRESS'=>'123 Fixture Street']],['FNAME'=>'Fixture','EMAIL'=>'recipient@example.test'],str_repeat('b',64),'<fixture@example.test>');
};
foreach(['regular','plain-text']as$type){
    $id=$store->createCampaign(['name'=>'New '.$type,'campaign_type'=>$type]);$row=$store->campaign($id);
    $expect($row['draft']['tracking']===$enabled,'All five settings are stored enabled for new '.$type.' campaigns');
    $rendered=$message($row);
    $expect(str_contains($rendered['text'],'audience_track.php?token='),'New '.$type.' email contains signed plain-text click tracking');
    if($type==='regular'){
        $expect(str_contains($rendered['html'],'open=1'),'New HTML email contains an open-tracking pixel');
        $expect((bool)preg_match('/href="[^"]*audience_track\.php\?token=/',$rendered['html']),'New HTML email links use click tracking');
    }else $expect($rendered['html']===null,'Plain-text email remains plain text');
    $store->saveCampaignSection($id,1,'tracking',[]);
    $row=$store->campaign($id);$expect($row['draft']['tracking']===array_fill_keys(array_keys($enabled),false),'Explicit opt-out persists for '.$type);
    $store->saveCampaignSection($id,2,'subject',['subject'=>'Edited fixture']);
    $row=$store->campaign($id);$rendered=$message($row);
    $expect(!str_contains($rendered['text'].($rendered['html']??''),'audience_track.php?'),'Saving another section does not re-enable tracking for '.$type);
}
$optedOut=$id;
$legacy=$store->createCampaign(['name'=>'Legacy fixture','campaign_type'=>'regular']);$draft=$store->campaign($legacy)['draft'];unset($draft['tracking']);$store->rows[$legacy]['draft_json']=json_encode($draft);
$store->saveCampaignSection($legacy,1,'subject',['subject'=>'Legacy subject edit']);
$expect(!isset($store->campaign($legacy)['draft']['tracking']),'Existing campaigns without tracking settings are not retroactively opted in');
$copy=$store->campaignOperation('replicate',[$legacy],[$legacy=>2],[]);
$expect($store->campaign($copy)['draft']['tracking']===$enabled,'New copies of legacy campaigns receive the tracking defaults');
$expect(!isset($store->campaign($legacy)['draft']['tracking']),'Copying a legacy campaign does not change the original');
$copy=$store->campaignOperation('replicate',[$optedOut],[$optedOut=>3],[]);
$expect($store->campaign($copy)['draft']['tracking']===array_fill_keys(array_keys($enabled),false),'Copies preserve explicitly saved opt-outs');
echo "Campaign tracking defaults: $checks checks passed.\n";
