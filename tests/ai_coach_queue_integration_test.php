<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') { echo "Coach queue tests require a disposable database.\n"; exit; }
$source=getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__.'/../src';
require_once $source.'/bootstrap.php';
require_once $source.'/ai_coach_helpers.php';
require_once $source.'/ai_coach_history_helpers.php';
function queueExpect(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$conn->execute_query("INSERT INTO users(username,password,role) VALUES (?,?,'editor')",['coach-queue-'.bin2hex(random_bytes(5)),password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT)]);
$uid=(int)$conn->insert_id;
$make=static function()use($conn,$uid):array {
    $uuid=bin2hex(random_bytes(4)).'-'.bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(6));
    $request=aiCoachValidateRequest(['question'=>'Explain the calendar subscription','request_id'=>$uuid,'page'=>'help.php'],'editor');
    $created=false; $id=aiCoachRecordRequest($conn,$request,$uid,$created);
    queueExpect($created && $id!==null,'Durable request created'); return [$id,$request];
};
try {
    [$id,$request]=$make();
    queueExpect(aiCoachCancelRequest($conn,$uid,'editor',$request['request_id']),'Cancellation works before enqueue');
    queueExpect(!aiCoachEnqueue($conn,$id,$request),'Cancelled request cannot be enqueued');
    aiCoachCompleteRequest($conn,$id,['message'=>'Late answer'],100);
    queueExpect($conn->execute_query('SELECT outcome FROM ai_coach_requests WHERE id=?',[$id])->fetch_row()[0]==='cancelled','Late completion cannot overwrite cancellation');
    [$id,$request]=$make(); queueExpect(aiCoachEnqueue($conn,$id,$request),'Admission accepts work');
    queueExpect(!aiCoachCancelRequest($conn,$uid,'admin',$request['request_id']),'Wrong role cannot cancel');
    $job=aiCoachClaimJob($conn); queueExpect((int)$job['request_id']===$id,'Only pending work is claimed');
    queueExpect(aiCoachClaimJob($conn)===null,'A job is claimed once');
    queueExpect(!aiCoachJobCancelled($conn,$job),'Claim is initially live');
    queueExpect(aiCoachCancelRequest($conn,$uid,'editor',$request['request_id']),'Running work cancels');
    queueExpect(aiCoachJobCancelled($conn,$job),'Worker observes cancellation');
    aiCoachFinishJob($conn,$job,['message'=>'Late answer'],200);
    queueExpect($conn->execute_query('SELECT outcome FROM ai_coach_requests WHERE id=?',[$id])->fetch_row()[0]==='cancelled','Worker cannot overwrite cancellation');
    [$id,$request]=$make(); aiCoachEnqueue($conn,$id,$request);
    $conn->execute_query('UPDATE ai_coach_jobs SET deadline_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE request_id=?',[$id]);
    queueExpect(aiCoachClaimJob($conn)===null,'Expired jobs are never started');
    $status=aiCoachRequestStatus($conn,$uid,'editor',$request['request_id']);
    queueExpect($status['state']==='complete' && $status['response']['reason']==='timeout','Expired jobs resolve promptly with explicit timeout');
    for($i=0;$i<8;$i++){[$id,$request]=$make();queueExpect(aiCoachEnqueue($conn,$id,$request),'Queue admits bounded workload');}
    [$id,$request]=$make();queueExpect(!aiCoachEnqueue($conn,$id,$request),'Ninth pending request is rejected');
} finally {
    $conn->execute_query('DELETE FROM ai_coach_requests WHERE user_id=?',[$uid]);
    $conn->execute_query('DELETE FROM users WHERE id=?',[$uid]);
}
echo "Coach queue integration passed: admission, claims, cancellation races, ownership, and deadline.\n";
