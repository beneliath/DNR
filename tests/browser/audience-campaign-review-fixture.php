<?php
declare(strict_types=1);
require __DIR__.'/../../src/functions.php';
require __DIR__.'/../../src/audience_store.php';
function audienceInputs(array $values): void {
    foreach($values as $key=>$value)echo '<input type="hidden" name="'.htmlspecialchars($key,ENT_QUOTES,'UTF-8').'" value="'.htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8').'">';
}
$_SESSION=['user_id'=>11,'username'=>'qa-editor','role'=>'editor','_csrf_token'=>'fixture-token'];
$h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$url=static fn(array $params=[])=>'audience.php?'.http_build_query($params);
$id=6;$canManage=true;$campaignRecovery=[];
$text="Hi Doug,\n\n".str_repeat('A readable plain-text sentence with preserved spaces. ',8)."\n\nhttps://example.test/".str_repeat('long-path-',90)."\n\n<script>text only</script>\nMessageMonkey QA";
$campaign=['name'=>'QA plain-text review','status'=>'draft','draft'=>['subject'=>'QA plain-text review','preview_text'=>'Synthetic review fixture','text'=>$text]];
$campaignComments=[['actor_label'=>'QA editor','created_at'=>'2026-10-23 20:00:00','body'=>'Comments remain beside the email on desktop and below it on mobile.','resolved'=>0]];
$csrf=csrfInput();
echo '<!doctype html><html lang="en">';
renderPageHead('QA campaign review',['styles'=>['assets/css/pages/audience.min.css']]);
echo '<body class="audience-app"><main style="padding:24px;box-sizing:border-box">';
require __DIR__.'/../../src/templates/audience/campaign-review.php';
echo '</main></body></html>';
