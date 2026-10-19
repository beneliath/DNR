<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Disposable suite required.\n");
require_once '/var/www/html/bootstrap.php';
require_once '/var/www/html/inbound_ingestion_helpers.php';
require_once '/var/www/html/audience_inbox_runtime.php';
$db=applicationDatabaseConnection();
if(!str_starts_with($db->query('SELECT CURRENT_USER() u')->fetch_assoc()['u'],'dnrmailingest@'))throw new RuntimeException('Restricted ingestion identity required.');
$checks=0;
$expect=static function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);$checks++;};
$thread=$db->query("SELECT t.id,r.id route_id FROM marketing_inbox_threads t JOIN marketing_inbox_routes r ON r.audience_id=t.audience_id AND r.source='thread' AND r.reference_id=t.id WHERE t.audience_id=1 AND t.sender_email='inbox-person@example.test' AND t.contact_id IS NOT NULL ORDER BY t.id LIMIT 1")->fetch_assoc();
if(!$thread)throw new RuntimeException('Inbox association fixture missing.');
$address=audienceInboxAddress(inboundEmailGatewayAddress(),currentAccountKey(),(int)$thread['route_id'],accountMailSigningKey());
$mailbox=new class implements Dnr\Infrastructure\InboundMailbox {
    public array $messages=[];
    public function uidValidity():int{return 1729;}
    public function uidNext():int{return count($this->messages)+1;}
    public function uidsBetween(int $first,int $last):array{return array_values(array_filter(array_keys($this->messages),static fn($uid)=>$uid>=$first&&$uid<=$last));}
    public function fetchRawMessage(int $uid):string{return $this->messages[$uid];}
    public function abort():void{}
};
$key=inboundMailboxKey('inbox-qualification.example.test',993,'fixture','INBOX');
syncInboundMailbox($db,$mailbox,$key,'Disposable Inbox gateway');
$make=static function(string $name,string $to,string $kind='dmarc',string $extra='')use($address):string{
    $sender='inbox-person@example.test';$id='gateway-'.$name.'@example.test';
    $payload=json_encode(['kind'=>$kind,'from'=>$sender,'id'=>hash('sha256',$id)],JSON_THROW_ON_ERROR);
    $key=hash_hmac('sha256','dnr:proton-sender-auth:key:v1',Dnr\Security\InboundRoutingKey::bytes(),true);
    $assertion='v1.'.rtrim(strtr(base64_encode($payload),'+/','-_'),'=').'.'.hash_hmac('sha256',"dnr:proton-sender-auth:v1\n".$payload,$key);
    return "From: Inbox <$sender>\r\nTo: $to\r\nMessage-ID: <$id>\r\nSubject: Gateway qualification\r\nX-Dnr-Sender-Authentication: $assertion\r\n$extra"
        ."MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=qualification\r\n\r\n--qualification\r\nContent-Type: text/plain\r\n\r\nGateway qualification content\r\n--qualification\r\nContent-Type: image/png\r\nContent-Disposition: attachment; filename=qualification.png\r\nContent-Transfer-Encoding: base64\r\n\r\niVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6ks8AAAAASUVORK5CYII=\r\n--qualification--\r\n";
};
$mailbox->messages=[1=>$make('valid',$address),2=>$make('unverified',$address,'unverified'),3=>$make('automatic',$address,'internal',"Auto-Submitted: auto-replied\r\n"),4=>$make('forged',preg_replace('/\.([a-f0-9]{32})@/','.00000000000000000000000000000000@',$address))];
$mailbox->messages[5]=$mailbox->messages[1];
$chronBefore=(int)$db->query('SELECT COUNT(*) n FROM engagement_chron_entries')->fetch_assoc()['n'];
syncInboundMailbox($db,$mailbox,$key,'Disposable Inbox gateway');
$expected=['valid'=>'processed','unverified'=>'review','automatic'=>'rejected','forged'=>'review'];
foreach($expected as$name=>$status){
    $m=$db->execute_query('SELECT id FROM inbound_email_messages WHERE rfc_message_id=?',['gateway-'.$name.'@example.test'])->fetch_assoc();
    $expect($m!==null,'Mailbox discovery retains '.$name);
    $expect(processInboundEmailMessage($db,(int)$m['id'])===$status,'Gateway outcome '.$name);
}
$message=$db->query("SELECT id,thread_id FROM marketing_inbox_messages WHERE rfc_message_id='<gateway-valid@example.test>'")->fetch_assoc();
$expect((int)($message['thread_id']??0)===(int)$thread['id'],'Verified reply associates the exact signed conversation');
$expect((int)$db->query("SELECT COUNT(*) n FROM marketing_inbox_messages WHERE rfc_message_id='<gateway-valid@example.test>'")->fetch_assoc()['n']===1,'Redelivered UID creates one Inbox message');
$expect((int)$db->execute_query('SELECT COUNT(*) n FROM marketing_inbox_attachments WHERE message_id=?',[$message['id']])->fetch_assoc()['n']===1,'Gateway attachment survives native parse, queue and Inbox storage');
$expect((int)$db->query('SELECT COUNT(*) n FROM engagement_chron_entries')->fetch_assoc()['n']===$chronBefore,'Inbox messages never leak into ordinary Chron filing');
$expect(syncInboundMailbox($db,$mailbox,$key,'Disposable Inbox gateway')===0,'Repeated poll does not import completed UIDs');
$proofFor=static function(string $name)use($db):?array{return $db->execute_query('SELECT r.sender_verified,r.authentication_method FROM marketing_inbox_route_receipts r JOIN inbound_email_messages m ON m.id=r.inbound_id WHERE r.audience_id=1 AND m.rfc_message_id=?',['gateway-'.$name.'@example.test'])->fetch_assoc()?:null;};
$verified=$proofFor('valid');$unverified=$proofFor('unverified');
$expect($verified!==null&&(int)$verified['sender_verified']===1,'A valid authenticated signed message records receiving proof');
$expect($unverified!==null&&(int)$unverified['sender_verified']===0,'An unverified sender never produces verified receiving proof');
$expect($proofFor('forged')===null,'A forged account route cannot produce receiving proof');
$receiptCount=(int)$db->query('SELECT COUNT(*) n FROM marketing_inbox_route_receipts WHERE audience_id=1')->fetch_assoc()['n'];
$m=$db->execute_query('SELECT id FROM inbound_email_messages WHERE rfc_message_id=?',['gateway-valid@example.test'])->fetch_assoc();processInboundEmailMessage($db,(int)$m['id']);
$expect((int)$db->query('SELECT COUNT(*) n FROM marketing_inbox_route_receipts WHERE audience_id=1')->fetch_assoc()['n']===$receiptCount,'Repeated processing does not duplicate receiving evidence');
try{$db->query("UPDATE marketing_inbox_route_receipts SET sender_verified=0");throw new RuntimeException('Receiving worker rewrote immutable evidence');}catch(mysqli_sql_exception $e){$expect($e->getCode()===1142,'Receiving worker can insert evidence but cannot rewrite it');}
echo "Inbox gateway qualification passed ($checks checks): authenticated MIME intake, signed routes, exact thread, attachment, duplicate UID, unverified sender and automatic reply rejection.\n";
