<?php
declare(strict_types=1);
require_once __DIR__.'/../src/audience_store.php';
require_once __DIR__.'/../src/email_helpers.php';
$checks=0;$expect=static function(bool $ok,string $why)use(&$checks){if(!$ok)throw new RuntimeException($why);$checks++;};
$reject=static function(callable $call)use($expect){try{$call();}catch(InvalidArgumentException){$expect(true,'Rejected');return;}throw new RuntimeException('Expected invalid input rejection.');};
$now=strtotime('2026-10-16 12:00 UTC');
$expect(audienceDispatchDue(['mode'=>'now'],$now)==='2026-10-16 12:00:00','Immediate due time');
$expect(audienceDispatchDue(['mode'=>'now','delivery'=>'optimized'],$now)==='2026-10-16 12:00:00','Immediate sending ignores inactive scheduling preferences');
$expect(audienceDispatchDue(['mode'=>'schedule','timezone'=>'America/Chicago','local'=>'2026-10-20 09:00'],$now)==='2026-10-20 14:00:00','Chicago schedule resolves UTC');
foreach([
 ['mode'=>'triggered'],['mode'=>'schedule','delivery'=>'optimized'],
 ['mode'=>'schedule','timezone'=>'America/Chicago','local'=>'2026-03-08 02:30'],
 ['mode'=>'schedule','timezone'=>'America/Chicago','local'=>'2026-11-01 01:30'],
 ['mode'=>'schedule','timezone'=>'Bad/Zone','local'=>'2026-10-20 09:00'],
 ['mode'=>'schedule','timezone'=>'UTC','local'=>'2026-10-16 12:04'],
 ['mode'=>'schedule','timezone'=>'UTC','local'=>'2028-10-16 12:04']
]as$send)$reject(fn()=>audienceDispatchDue($send,$now));
foreach(['http://public.example','https://localhost','https://127.0.0.1','https://user:pass@public.example','https://public.example?a=1']as$url)$reject(fn()=>audienceDispatchBase($url));
$snapshot=['base'=>'https://public.example/a/community','type'=>'plain-text','identity'=>['LIST:COMPANY'=>'Community','LIST:ADDRESS'=>'123 Fixture Street'],'draft'=>['subject'=>'Hello *|FNAME|*','from_name'=>'Community team','from_email'=>'sender@community.example','text'=>'A message for *|EMAIL|*','personalize_to'=>true,'to_merge'=>'FNAME']];
$context=['FNAME'=>'Jamie','EMAIL'=>'jamie@example.test'];$message=audienceDispatchMessage($snapshot,$context,str_repeat('a',64),'<fixture@example.test>');
$expect($message['subject']==='Hello Jamie'&&$message['to_name']==='Jamie','Subject and To personalization');
$expect(str_contains($message['text'],'123 Fixture Street')&&str_contains($message['text'],'Unsubscribe: https://public.example/a/community/audience_subscription.php?token='),'Plain text always gets identity and subscription links');
$snapshot['draft']['subject']='*|UNKNOWN|*';$reject(fn()=>audienceDispatchMessage($snapshot,$context,str_repeat('a',64),'<fixture@example.test>'));
$snapshot['draft']['subject']="Hello\r\nBcc: other@example.test";$reject(fn()=>audienceDispatchMessage($snapshot,$context,str_repeat('a',64),'<fixture@example.test>'));
$mime=smtpMessageData('sender@example.test','Community','jamie@example.test','Subject','Body','',null,null,'<fixture@example.test>',[],['List-Unsubscribe'=>'<https://public.example/unsub>','List-Unsubscribe-Post'=>'List-Unsubscribe=One-Click'],'Jamie');
$expect(str_contains($mime,'List-Unsubscribe-Post: List-Unsubscribe=One-Click')&&str_contains($mime,'To: =?UTF-8?B?SmFtaWU=?= <jamie@example.test>'),'List headers and encoded To name');
$reject(fn()=>smtpMessageData('sender@example.test','Community','jamie@example.test','Subject','Body','',null,null,null,[],['Bcc'=>'victim@example.test']));
$reject(fn()=>smtpMessageData('sender@example.test','Community','jamie@example.test','Subject','Body','',null,null,null,[],['List-Unsubscribe'=>"<https://public.example>\r\nBcc: victim@example.test"]));
echo "Campaign dispatch validation: $checks checks passed.\n";
