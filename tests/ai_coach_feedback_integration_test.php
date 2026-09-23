<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST')!=='1' || getenv('DNR_INTEGRATION_TARGET')!=='disposable') { echo "Feedback review tests require a disposable database.\n"; exit; }
$source=getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__.'/../src';
require_once $source.'/bootstrap.php';
require_once $source.'/ai_coach_helpers.php';
require_once $source.'/ai_coach_history_helpers.php';
require_once $source.'/ai_coach_feedback_helpers.php';
function feedbackExpect(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$root='/opt/dnr/coach-review-src';
$conn->execute_query("INSERT INTO users(username,password,role) VALUES (?,?,'editor')",['coach-feedback-'.bin2hex(random_bytes(5)),password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT)]);
$uid=(int)$conn->insert_id; $ids=[];
$make=static function(string $question='How do I add a new event?', string $outcome='complete', int $ms=200)use($conn,$uid,&$ids):array {
    $uuid=bin2hex(random_bytes(4)).'-'.bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(6));
    $request=aiCoachValidateRequest(['question'=>$question,'request_id'=>$uuid,'page'=>'help.php'],'editor');
    $created=false; $id=aiCoachRecordRequest($conn,$request,$uid,$created); $ids[]=$id;
    if ($outcome!=='pending') aiCoachCompleteRequest($conn,$id,['message'=>'Synthetic answer','sources'=>[]],$ms,$outcome);
    return [$id,$uuid];
};
$pending=static fn():array=>array_values(array_filter(aiCoachFeedbackPending($conn,500),static fn($r):bool=>(int)$r['user_id']===$uid));
$report=static function(array $rows)use($root):array{return ['guidance_revision'=>aiCoachGuidanceRevision(),'disposition'=>'verified','summary'=>'Synthetic regression evidence, no private details.','redacted'=>true,
    'sources'=>[['path'=>'src/ai_coach_feedback_helpers.php','sha256'=>hash_file('sha256',$root.'/src/ai_coach_feedback_helpers.php')]],
    'tests'=>[['command'=>'php tests/ai_coach_feedback_integration_test.php','exit_code'=>0]],
    'requests'=>array_map(static fn($r):array=>['id'=>(int)$r['id'],'feedback_version'=>(int)$r['feedback_version'],'review_version'=>(int)$r['review_version'],'outcome'=>$r['outcome']],$rows)];};
try {
    [$id,$uuid]=$make(); [$other,$otherUuid]=$make('Create an engagement');
    [$waiting,$waitingUuid]=$make('Question still running','pending');
    feedbackExpect($pending()===[],'Unrated successful and pending answers do not produce work');
    feedbackExpect(!aiCoachSaveFeedback($conn,$uid,'admin',$uuid,'needs_work',[]),'Wrong role cannot submit feedback');
    feedbackExpect(!aiCoachSaveFeedback($conn,$uid,'editor',$waitingUuid,'helpful',[]),'Pending answers cannot be rated');
    aiCoachSaveFeedback($conn,$uid,'editor',$uuid,'needs_work',['private'=>'do not export']);
    aiCoachSaveFeedback($conn,$uid,'editor',$uuid,'needs_work',['private'=>'do not export']);
    feedbackExpect((int)$pending()[0]['feedback_version']===1,'Idempotent feedback does not enqueue duplicate work');
    aiCoachSaveFeedback($conn,$uid,'editor',$otherUuid,'helpful',[]);
    $packet=aiCoachFeedbackPacket($pending());
    feedbackExpect(count($packet['groups'])===1 && $packet['groups'][0]['helpful']===1 && $packet['groups'][0]['problems']===1,'Related positive and negative questions grouped without erasing disagreement');
    feedbackExpect(!str_contains(json_encode($packet),'user_id') && !str_contains(json_encode($packet),'do not export'),'Private identities and nonstructural UI fields excluded');
    $receipt=$report($pending());
    $bad=$receipt; $bad['sources'][0]['sha256']=str_repeat('0',64);
    try { aiCoachCompleteFeedbackReview($conn,$bad,$root); throw new LogicException('Stale evidence accepted'); } catch (InvalidArgumentException $expected) {}
    $bad=$receipt; $bad['tests'][0]['exit_code']=1;
    try { aiCoachCompleteFeedbackReview($conn,$bad,$root); throw new LogicException('Failed test accepted'); } catch (InvalidArgumentException $expected) {}
    feedbackExpect(aiCoachCompleteFeedbackReview($conn,$receipt,$root)===2 && $pending()===[],'Verified investigation acknowledged');
    feedbackExpect(aiCoachCompleteFeedbackReview($conn,$receipt,$root)===0,'Completion replay is idempotent');
    aiCoachSaveFeedback($conn,$uid,'editor',$uuid,'helpful',[]);
    feedbackExpect(count($pending())===1,'Changed feedback reopens investigation');
    try { aiCoachCompleteFeedbackReview($conn,$receipt,$root); throw new LogicException('Stale snapshot accepted'); } catch (RuntimeException $expected) { feedbackExpect(!$expected instanceof LogicException,'Changed feedback rejected'); }
    [$failed]=$make('Explain this failure','unavailable'); [$slow]=$make('Explain this delay','complete',15001);
    feedbackExpect(count($pending())===3,'Failures and slow answers queued without needing a rating');
    $receipt=$report($pending()); $bad=$receipt; $bad['requests'][2]['feedback_version']=999;
    try { aiCoachCompleteFeedbackReview($conn,$bad,$root); throw new LogicException('Stale batch accepted'); } catch (RuntimeException $expected) { feedbackExpect(!$expected instanceof LogicException,'Stale batch rejected'); }
    feedbackExpect(count($pending())===3,'Stale batch rolls back earlier receipts');
    aiCoachCompleteFeedbackReview($conn,$receipt,$root);
    aiCoachSaveFeedback($conn,$uid,'editor',$uuid,'control_missing',[],['page'=>'edit_engagement.php','step'=>'notes-choose','workflow'=>'notes','target'=>'private-selector']);
    $locationPacket=aiCoachFeedbackPacket($pending());
    $entry=$locationPacket['groups'][0]['requests'][0];
    feedbackExpect($entry['original_page']==='help.php' && $entry['reported_page']==='edit_engagement.php','Feedback preserves both question and failure pages');
    feedbackExpect($entry['reported_step']==='notes-choose' && $entry['reported_target']==='pdf-picker','Feedback retains the failed step and server-owned target');
    feedbackExpect(!str_contains(json_encode($locationPacket),'private-selector'),'Feedback target comes from the verified catalog only');
    aiCoachCompleteFeedbackReview($conn,$report($pending()),$root);
    $conn->execute_query("UPDATE ai_coach_requests SET review_status='needs_work',review_notes='The event entry point is missing.',corrected_guidance='Open Engagements first.',review_version=review_version+1 WHERE id=?",[$other]);
    feedbackExpect(count($pending())===1,'New administrator assessment reopens a reviewed request');
    $adminPacket=aiCoachFeedbackPacket($pending());
    feedbackExpect($adminPacket['groups'][0]['requests'][0]['administrator_review']['proposed_correction']==='Open Engagements first.','Administrator evidence is preserved in the private handoff');
    [$echoed]=$make('A question that the model echoed');
    $conn->execute_query('UPDATE ai_coach_requests SET response_json=? WHERE id=?',[json_encode(['message'=>'Scope clarification','quality_issue'=>'echoed_question']),$echoed]);
    feedbackExpect(count($pending())===2,'Detected answer-quality failures enter the review queue without a vote');
    $count=(int)$conn->query('SELECT COUNT(*) FROM ai_coach_feedback_reviews')->fetch_row()[0];
    $conn->execute_query('DELETE FROM ai_coach_requests WHERE user_id=?',[$uid]);
    feedbackExpect((int)$conn->query('SELECT COUNT(*) FROM ai_coach_feedback_reviews')->fetch_row()[0]===$count,'Review evidence survives clearing operational requests');
} finally {
    $conn->execute_query('DELETE FROM ai_coach_requests WHERE user_id=?',[$uid]);
    $conn->execute_query('DELETE FROM users WHERE id=?',[$uid]);
}
echo "Feedback integration passed: grouping, role ownership, idempotency, evidence gates, stale feedback/batch races, automatic failure/latency intake, and retained receipts.\n";
