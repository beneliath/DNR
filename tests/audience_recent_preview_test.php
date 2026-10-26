<?php
declare(strict_types=1);
require_once __DIR__.'/../src/audience_store.php';
restore_exception_handler();
$checks=0;
$expect=static function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);$checks++;};
$reject=static function(callable $call)use($expect):void{try{$call();}catch(InvalidArgumentException){$expect(true,'Rejected');return;}throw new RuntimeException('Expected preview rejection.');};
$design=audienceDesign(['schema'=>1,'rows'=>[['columns'=>[[['type'=>'text','html'=>'<p>Sent newsletter <a href="https://example.test">Read more</a></p>']]]]]]);
$campaign=['status'=>'sent','deleted_at'=>null,'draft'=>['design'=>$design]];
$identity=audienceBrandIdentity(['from_name'=>'Example community','mailing_address'=>'123 Example Street','permission_reminder'=>'You signed up for Example updates.']);
$before=$campaign;
$html=audienceCampaignRecentPreviewHtml($campaign,'fixtureNonce1234',$identity);
$expect(str_contains($html,'Sent newsletter')&&str_contains($html,'Example community'),'Sent design content and account identity appear in its preview.');
$expect(str_contains($html,'You signed up for Example updates.'),'The connected footer is present in the card preview.');
$expect(str_contains($html,'html,body{background:#fff!important}'),'The card canvas is white.');
$expect(!str_contains($html,' style="')&&str_contains($html,'<style nonce="fixtureNonce1234">'),'Preview styling uses the parent page nonce.');
$expect($campaign===$before,'Preview rendering leaves the saved campaign and its colors unchanged.');
foreach(['draft','scheduled','sending','paused','failed','cancelled']as$status){$unsent=$campaign;$unsent['status']=$status;$reject(fn()=>audienceCampaignRecentPreviewHtml($unsent,'fixtureNonce1234'));}
$archived=$campaign;$archived['deleted_at']='2026-10-25 12:00:00';$reject(fn()=>audienceCampaignRecentPreviewHtml($archived,'fixtureNonce1234'));
foreach(['','bad','"><script>alert(1)</script>']as$nonce)$reject(fn()=>audienceCampaignRecentPreviewHtml($campaign,$nonce));
$plain=['status'=>'sent','draft'=>['text'=>"Plain-text newsletter\n<script>literal text</script> ".str_repeat('long message ',5000)]];
$html=audienceCampaignRecentPreviewHtml($plain,'fixtureNonce1234',$identity);
$expect(str_contains($html,'Plain-text newsletter')&&str_contains($html,'&lt;script&gt;literal text&lt;/script&gt;'),'Plain-text campaigns have a safely escaped graphical preview.');
$expect(!str_contains($html,'<script>')&&strlen($html)<20000,'Large plain-text messages have a bounded, inactive thumbnail.');
echo "Recent sent email previews: $checks checks passed.\n";
