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
// Image-heavy designs use hosted bytes before the shared 10 MB delivery limit.
$snapshot['draft']['subject']='Image regression';$snapshot['asset_key']=str_repeat('b',64);
$gif='data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jF9sAAAAASUVORK5CYII=');
$padding='tEXt'.'Comment'."\0".str_repeat('x',1000000-strlen($png)-20);
$chunk=pack('N',strlen($padding)-4).$padding.pack('N',crc32($padding));
$photo='data:image/png;base64,'.base64_encode(substr($png,0,-12).$chunk.substr($png,-12));
$rows=[];for($i=0;$i<5;$i++)$rows[]=['columns'=>[[['type'=>'image','image'=>$photo,'alt'=>'Large image']]]];
$rows[]=['columns'=>[[['type'=>'image','image'=>$gif,'alt'=>'Animation']]]];
$svg=audienceSvgImage('data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 80"><path d="M0 0h40v80H0z" fill="#3261b5"/></svg>'));
$rows[]=['columns'=>[[['type'=>'image','image'=>$svg,'alt'=>'Vector logo']]]];
$snapshot['type']='regular';$snapshot['draft']['design']=audienceDesign(['schema'=>1,'rows'=>$rows]);
$expect(strlen(json_encode($snapshot['draft']['design']))>6000000,'Fixture exceeds the former send limit');
$imageMessage=audienceDispatchMessage($snapshot,$context,str_repeat('a',64),'<images@example.test>');
$expect(!str_contains($imageMessage['html'],'data:image/')&&str_contains($imageMessage['html'],hash('sha256',$gif)),'PNG and GIF use immutable hosted images');
$expect(str_contains($imageMessage['html'],hash('sha256',$svg)),'SVG logos use their vector snapshot in hosted email assets');
$expect(strlen($imageMessage['html'])<10000,'Hosted images do not consume the message body budget');
$expect(audienceDispatchRenderer($snapshot)($context,str_repeat('a',64),'<images@example.test>')===$imageMessage,'Prepared image-heavy message matches the worker render byte for byte');
// A production-sized uploaded font must not inflate delivered HTML or alter the draft.
$ttfBytes="\x00\x01\x00\x00".str_repeat("\0",415000);
foreach(['ttf'=>$ttfBytes,'woff'=>'wOFF'.str_repeat("\0",4).pack('N',64).str_repeat("\0",52),'woff2'=>'wOF2'.str_repeat("\0",4).pack('N',64).str_repeat("\0",52)]as$format=>$fontBytes){
    $font='data:font/'.$format.';base64,'.base64_encode($fontBytes);
    foreach(['visual','code']as$mode){
        $fontSnapshot=$snapshot;
        $fontSnapshot['draft']['design']=audienceDesign(['schema'=>1,'mode'=>$mode,'styles'=>['font'=>'Georgia','webFont'=>$font],'code'=>'<html><head></head><body><p>Keep this newsletter and <a href="https://example.test/news">its link</a>.</p></body></html>','rows'=>[['columns'=>[[['type'=>'text','text'=>'Keep this newsletter and its link.']]]]]]);
        $saved=$fontSnapshot;
        $fontMessage=audienceDispatchMessage($fontSnapshot,$context,str_repeat('a',64),'<font@example.test>');
        $fontUrl='https://public.example/a/community/audience_email_asset.php?key='.str_repeat('b',64).'&font='.hash('sha256',$font);
        $expect(str_contains($fontMessage['html'],'src:url('.$fontUrl.')')&&!str_contains($fontMessage['html'],'data:font/'),$format.' font is hosted with a CSS-safe URL in '.$mode.' email');
        $expect(str_contains($fontMessage['html'],'MOEDBrandFont,Georgia')&&str_contains($fontMessage['text'],'Keep this newsletter'),'Custom font, fallback and newsletter content remain in '.$mode.' email');
        $expect($fontSnapshot===$saved&&str_contains(audienceDesignHtml($fontSnapshot['draft']['design']),$font),'Saved '.$mode.' design and its embedded preview font are preserved');
        $expect(!audienceDispatchMessageSize($fontMessage)['warning'],'Hosted '.$format.' font does not cause a false clipping warning');
        $expect(audienceDispatchRenderer($fontSnapshot)($context,str_repeat('a',64),'<font@example.test>')===$fontMessage,'Prepared '.$format.' '.$mode.' message matches the worker render');
    }
}
// Reusing the immutable design must never reuse a recipient's conditional content,
// share links, tracking, custom text or personalization.
$personalized=$fontSnapshot;
$personalized['tracking_key']=str_repeat('d',64);
$personalized['identity']['__permission_reminder']='You requested these updates.';
$personalized['draft']['subject']='Hello *|FNAME|*';
$personalized['draft']['tracking']=['opens'=>true,'clicks'=>true,'plain_clicks'=>true];
$personalized['draft']['design']=audienceDesign(['schema'=>1,'styles'=>['webFont'=>$font],'rows'=>[
    ['condition'=>['rules'=>[['field'=>'tag:1','operator'=>'is','value'=>'1']]],'columns'=>[[
        ['type'=>'heading','text'=>'Members only','includeToc'=>true],
        ['type'=>'text','html'=>'<p>*|IF:FNAME|*Hello *|FNAME|**|ELSE:|*Hello friend*|END:IF|*</p>']
    ]]],
    ['columns'=>[[
        ['type'=>'toc'],['type'=>'share','sharePlatforms'=>['facebook','email']],
        ['type'=>'image-group','images'=>[['image'=>$gif,'alt'=>'Animation','url'=>'https://example.test/read']]],
        ['type'=>'text','text'=>'Special member copy','condition'=>['rules'=>[['field'=>'group:2','operator'=>'not_empty','value'=>'']]]],
        ['type'=>'text','html'=>'<p>Inline <img src="'.$gif.'"> photograph.</p>'],
        ['type'=>'code','code'=>'<p><a href="https://example.test/article">Article for *|FNAME|*</a></p>']
    ]]]
]]);
$otherDesign=$personalized['draft']['design'];$otherDesign['plainText']='Custom alternative for *|FNAME|*: *|ARCHIVE|*';
$personalized['variants']=[
    ['subject'=>'Hello *|FNAME|*','from_name'=>'Fixture team','design'=>null],
    ['subject'=>'Alternate *|FNAME|*','from_name'=>'Other team','design'=>$otherDesign]
];
$prepared=audienceDispatchRenderer($personalized);$savedPersonalized=$personalized;
foreach([
    ['EMAIL'=>'one@example.test','FNAME'=>'A & <B>','tag:1'=>'1','group:2'=>'1'],
    ['EMAIL'=>'two@example.test','FNAME'=>'','tag:1'=>'','group:2'=>''],
    ['EMAIL'=>'three@example.test','FNAME'=>'Third','tag:1'=>'1','group:2'=>'','__email_format'=>'text']
]as$i=>$person)foreach(['0','1','0']as$variant){
    $token=str_pad((string)$i,64,'a');$mid='<personalized-'.$i.'@example.test>';
    $actual=$prepared($person,$token,$mid,$variant);
    $expect($actual===audienceDispatchMessage($personalized,$person,$token,$mid,$variant),'Prepared variants preserve per-recipient content, headers, links, formats and tracking');
    if($variant==='0')$expect(str_contains($actual['text'],'Members only')===($person['tag:1']==='1'),'Conditional headings and table of contents remain recipient specific');
}
$expect($personalized===$savedPersonalized,'Preparation does not rewrite the saved design or variants');
$reject(fn()=>$prepared(['EMAIL'=>'bad@example.test','FNAME'=>"Bad\nName"],str_repeat('a',64),'<bad@example.test>'));
$reject(fn()=>$prepared(['EMAIL'=>'bad@example.test','FNAME'=>'*|UNKNOWN|*'],str_repeat('a',64),'<bad@example.test>'));
$expect(!audienceDispatchMessageSize(['html'=>str_repeat('a',AUDIENCE_DISPATCH_HTML_WARNING_BYTES-1),'text'=>'Body'])['warning'],'HTML below the warning boundary stays clear');
$atBoundary=audienceDispatchMessageSize(['html'=>str_repeat('a',AUDIENCE_DISPATCH_HTML_WARNING_BYTES),'text'=>'Body']);
$expect($atBoundary['warning']&&$atBoundary['html_bytes']===100000&&$atBoundary['text_bytes']===4,'Warning starts before the approximate Gmail clipping threshold');
$expect(!audienceDispatchMessageSize(['html'=>null,'text'=>str_repeat('a',200000)])['warning'],'Long plain-text messages do not get an HTML clipping warning');
$plainPreference=audienceDispatchMessage($snapshot,$context+['__email_format'=>'text'],str_repeat('a',64),'<plain-preference@example.test>');
$expect($plainPreference['html']===null&&str_contains($plainPreference['text'],'Unsubscribe:'),'Signup plain-text preference excludes HTML while retaining native subscription links');
$snapshot['type']='plain-text';$snapshot['draft']['text']=str_repeat('a',6500000);
$expect(strlen(audienceDispatchMessage($snapshot,$context,str_repeat('a',64),'<large@example.test>')['text'])>6000000,'Plain-text email above the former limit renders');
$snapshot['draft']['text']=str_repeat('a',AUDIENCE_DESIGN_MAX_BYTES);
$reject(fn()=>audienceDispatchMessage($snapshot,$context,str_repeat('a',64),'<oversized@example.test>'));
$mime=smtpMessageData('sender@example.test','Community','jamie@example.test','Subject','Body','',null,null,'<fixture@example.test>',[],['List-Unsubscribe'=>'<https://public.example/unsub>','List-Unsubscribe-Post'=>'List-Unsubscribe=One-Click'],'Jamie');
$expect(str_contains($mime,'List-Unsubscribe-Post: List-Unsubscribe=One-Click')&&str_contains($mime,'To: =?UTF-8?B?SmFtaWU=?= <jamie@example.test>'),'List headers and encoded To name');
$reject(fn()=>smtpMessageData('sender@example.test','Community','jamie@example.test','Subject','Body','',null,null,null,[],['Bcc'=>'victim@example.test']));
$reject(fn()=>smtpMessageData('sender@example.test','Community','jamie@example.test','Subject','Body','',null,null,null,[],['List-Unsubscribe'=>"<https://public.example>\r\nBcc: victim@example.test"]));
echo "Campaign dispatch validation: $checks checks passed.\n";
