<?php
declare(strict_types=1);
require_once __DIR__.'/../src/ai_coach_helpers.php';
require_once __DIR__.'/../src/ai_coach_feedback_helpers.php';
function historicalExpect(bool $ok,string $m): void {if(!$ok) throw new RuntimeException($m);}
function historicalRow(int $id,string $page,string $step,string $role): array {
 return ['id'=>$id,'question'=>'Explain the available navigation','page_path'=>$page,'guided_step'=>$step,'user_role'=>$role,
 'response_json'=>'{"message":"Synthetic answer"}','feedback_context_json'=>'{}','conversation_json'=>'[]',
 'user_feedback'=>'needs_work','review_status'=>'unreviewed','outcome'=>'complete','duration_ms'=>100,
 'feedback_version'=>1,'review_version'=>0,'created_at'=>'2026-01-01 00:00:00','model_name'=>'fixture',
 'application_version'=>'fixture','ui_state_json'=>'{}','review_notes'=>null,'corrected_guidance'=>null,'guidance_revision'=>'fixture'];
}
foreach(['admin','editor','reviewer'] as $role) {
 $rows=[historicalRow(1,'audience.php','retired-step',$role),historicalRow(2,'retired-page.php','',$role),historicalRow(3,'tasks.php','',$role)];
 $packet=aiCoachFeedbackPacket($rows);$by=[];
 foreach($packet['groups'] as $group) foreach($group['requests'] as $r) $by[$r['id']]=$r;
 historicalExpect($packet['pending_in_batch']===3 && count($by)===3,'Historical rows must not drop the rest of the batch');
 historicalExpect($by[1]['original_page']==='audience.php' && $by[1]['original_step']==='retired-step','Keep original context as evidence');
 historicalExpect($by[1]['context_compatibility']['analysis_page']==='other' && $by[1]['context_compatibility']['analysis_step']==='','Unknown context has explicit neutral analysis');
 historicalExpect($by[1]['candidate_topics']===[], 'Unsupported application context must not receive unrelated coach manual citations');
 historicalExpect(!$by[1]['context_compatibility']['page_supported'] && !$by[1]['context_compatibility']['step_supported'],'Unsupported original context is marked');
 historicalExpect($by[3]['context_compatibility']['page_supported'] && $by[3]['context_compatibility']['analysis_page']==='tasks.php','Supported context retains its meaning');
 try {aiCoachValidateRequest(['question'=>'Explain navigation','page'=>'audience.php'],$role);throw new LogicException('Live unsupported page accepted');}
 catch(InvalidArgumentException $expected) {}
}
echo "Historical feedback context regression passed.\n";
