<?php
declare(strict_types=1);
require_once __DIR__.'/../src/audience_store.php';
restore_exception_handler();

$checks=0;
$expect=static function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);$checks++;};
$reminder="You signed up for Example & Community updates.\nThank you for joining us.";
$settings=['from_name'=>'Example Community','mailing_address'=>'123 Example Street','permission_reminder'=>"  $reminder  "];
$identity=audienceBrandIdentity($settings,'Example audience');
$expect(audiencePermissionReminder($identity)===$reminder,'Saved reminder is trimmed and belongs to the audience identity.');
$expect(audiencePermissionReminder(audienceBrandIdentity([]))==='','Unconfigured audiences omit the reminder.');
$expect(audiencePermissionReminder(audienceBrandIdentity(['permission_reminder'=>'   ']))==='','Whitespace-only reminders are omitted.');
$expect(audiencePermissionReminder(audienceBrandIdentity($settings+['brand'=>['permission_reminder'=>'Wrong source']]))===$reminder,'Brand Kit does not override the audience permission reminder.');
$design=audienceDesign(['schema'=>1,'rows'=>[['columns'=>[[['type'=>'text','html'=>'<p>Hello *|FNAME|*</p>']]]]]]);
$context=$identity+['FNAME'=>'Jamie','EMAIL'=>'jamie@example.test','UNSUB'=>'https://example.test/unsubscribe','UPDATE_PROFILE'=>'https://example.test/preferences'];
$html=audienceDesignHtml($design,$context);
$expect(substr_count($html,'You signed up for Example &amp; Community updates.')===1,'Visual HTML includes the reminder exactly once.');
$expect(strpos($html,'You signed up')<strpos($html,'Example Community<br>'),'The reminder appears inside the footer before the identity.');
$text=audienceDesignPlainText($design,$context);
$expect(substr_count($text,$reminder)===1,'Generated plain text includes the reminder once with its line break.');
$expect(audiencePermissionReminderHtml($html,$identity)===$html,'Repeated rendering does not duplicate the reminder.');
$expect(audiencePermissionReminderHtml($html,[])===$html,'Missing settings leave existing HTML unchanged.');
$authored=$design;$authored['footer']['text']=$reminder;
$expect(substr_count(audienceDesignHtml($authored,$context),'You signed up')===1,'An authored footer with the same reminder is not repeated.');
$unsafe=$identity;$unsafe['__permission_reminder']='<script>alert("no")</script> & more';
$escaped=audienceDesignHtml($design,$unsafe);
$expect(!str_contains($escaped,'<script>')&&str_contains($escaped,'&lt;script&gt;alert(&quot;no&quot;)&lt;/script&gt; &amp; more'),'Reminder text cannot inject HTML.');

$conditional=$design;$conditional['rows'][0]['columns'][0][0]['html']='<p>*|IF:FNAME|*Hello *|FNAME|**|ELSE:|*Hello friend*|END:IF|*</p>';
$preview=audienceDesignPreviewHtml($conditional,'fixtureNonce1234',null,false,$identity);
$expect(str_contains($preview,'You signed up')&&str_contains($preview,'*|IF:FNAME|*')&&str_contains($preview,'*|FNAME|*'),'Identity-only previews include the reminder without resolving recipient conditions.');
$expect(str_contains(audienceDesignPlainText($design,null,$identity),$reminder),'Identity-only plain-text previews include the saved reminder.');
$customText=$design;$customText['plainText']='Custom text for *|FNAME|*';
$expect(audienceDesignPlainText($customText,$context)==="Custom text for Jamie\n\n$reminder",'An authored plain-text counterpart retains its text and gains the reminder.');
$customText['plainText'].="\n\n$reminder";
$expect(substr_count(audienceDesignPlainText($customText,$context),$reminder)===1,'An authored plain-text reminder is not duplicated.');

$coded=$design;$coded['mode']='code';
$coded['code']='<html><head></head><body><p>Custom email</p><footer>*|LIST:ADDRESS|* <a href="*|UNSUB|*">Unsubscribe</a> <a href="*|UPDATE_PROFILE|*">Preferences</a></footer></body></html>';
$codedHtml=audienceDesignHtml($coded,$context);
$expect(substr_count($codedHtml,'You signed up')===1&&strpos($codedHtml,'You signed up')<strpos($codedHtml,'</body>'),'Custom HTML with its own footer includes the reminder inside the document.');
$coded['code']='<html><head></head><body><p>Custom email without a footer</p></body></html>';
$expect(substr_count(audienceDesignHtml($coded,$context),'You signed up')===1,'Custom HTML without a footer gains the native footer and one reminder.');

$snapshot=['base'=>'https://public.example/a/community','type'=>'regular','identity'=>$identity,'draft'=>['subject'=>'Hello *|FNAME|*','from_name'=>'Example team','from_email'=>'sender@example.test','design'=>$design,'text'=>'Plain campaign body']];
$recipient=['FNAME'=>'Jamie','EMAIL'=>'jamie@example.test'];
foreach(['regular','plain-text']as$type)foreach([false,true]as$test){
    $snapshot['type']=$type;$snapshot['test']=$test;
    $message=audienceDispatchMessage($snapshot,$recipient,str_repeat('a',64),'<fixture@example.test>');
    $expect(substr_count($message['text'],$reminder)===1,"$type ".($test?'test':'campaign').' includes one plain-text reminder.');
    $expect($type==='plain-text'?$message['html']===null:substr_count($message['html'],'You signed up')===1,"$type HTML selection and reminder are correct.");
    $expect(str_contains($message['unsubscribe'],$test?'test=1':'token='),'Subscription links retain test/campaign behavior.');
}
$snapshot['type']='regular';$snapshot['test']=false;
$message=audienceDispatchMessage($snapshot,$recipient+['__email_format'=>'text'],str_repeat('a',64),'<fixture@example.test>');
$expect($message['html']===null&&substr_count($message['text'],$reminder)===1,'Recipient plain-text preference retains the reminder.');
$changedIdentity=audienceBrandIdentity(array_replace($settings,['permission_reminder'=>'A changed reminder']));
$expect(audiencePermissionReminder($changedIdentity)!==audiencePermissionReminder($snapshot['identity']),'A later settings edit does not mutate an existing delivery snapshot.');
$oldSnapshot=$snapshot;unset($oldSnapshot['identity']['__permission_reminder']);
$message=audienceDispatchMessage($oldSnapshot,$recipient,str_repeat('a',64),'<fixture@example.test>');
$expect(!str_contains($message['html'],'You signed up')&&!str_contains($message['text'],'You signed up'),'Older snapshots remain deliverable without a reminder field.');
$snapshot['identity']=$changedIdentity;
$message=audienceDispatchMessage($snapshot,$recipient,str_repeat('a',64),'<fixture@example.test>');
$expect(str_contains($message['html'],'A changed reminder')&&!str_contains($message['html'],'You signed up'),'New snapshots use their own audience settings.');
echo "Audience permission reminder: $checks checks passed.\n";
