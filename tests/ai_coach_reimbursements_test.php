<?php
declare(strict_types=1);
require_once __DIR__.'/../src/ai_coach_helpers.php';
function checkReimbursementCoach(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
foreach ([
 ['How do I add an expense?','editor','reimbursement-add-expense'],
 ['How do I upload a receipt?','editor','reimbursement-receipts'],
 ['How do I create a reimbursement draft?','editor','reimbursement-create-draft'],
 ['How do I submit a reimbursement?','editor','reimbursement-submit-request'],
 ['How do I manage the Chart of Accounts?','editor','reimbursement-chart-of-accounts'],
 ['How do I change the bookkeeper email?','admin','reimbursement-setup-recipients'],
 ['How do I set my reimbursement reviewer email?','editor','reimbursement-reviewer-email'],
 ['How do I retry a reimbursement email?','editor','reimbursement-delivery-recovery'],
 ['How do I record a reimbursement correction?','editor','reimbursement-correction'],
] as [$question,$role,$procedure]) {
 $request=aiCoachValidateRequest(['question'=>$question,'page'=>'reimbursements.php'],$role);
 $answer=aiCoachProcedureReply($request);
 checkReimbursementCoach(($answer['procedure']??'')===$procedure,'Verified procedure: '.$procedure);
 checkReimbursementCoach(str_starts_with($answer['sources'][0]['id']??'','manual-topic-reimbursements-'),'Reimbursement PDF citation: '.$procedure);
 checkReimbursementCoach(aiCoachWorkflowCandidates($question)===[],'Do not launch an unrelated interactive workflow');
}
foreach (['How do I upload a receipt PDF?','How do I submit a reimbursement?'] as $q) {
 $answer=aiCoachImmediateReply(aiCoachValidateRequest(['question'=>$q,'page'=>'reimbursements.php'],'reviewer'));
 checkReimbursementCoach(($answer['engine']??'')==='verified-permissions','Reviewer must receive read-only guidance');
}
$r=aiCoachFormRuleReply(aiCoachValidateRequest(['question'=>'What is the receipt size limit?','page'=>'reimbursement_expense.php'],'editor'));
checkReimbursementCoach(str_contains($r['message']??'','20 receipts')&&str_contains($r['message'],'15 MB'),'Use receipt limits, not presentation limits');
$r=aiCoachFormRuleReply(aiCoachValidateRequest(['question'=>'Why can I not edit a submitted expense?','page'=>'reimbursement_expense.php'],'admin'));
checkReimbursementCoach(str_contains($r['message']??'','stay locked'),'Administrators cannot unlock submitted expenses');
foreach (['clipboard receipt','Bcc reimbursement reviewer','retry reimbursement email','Chart of Accounts'] as $q) {
 $results=aiCoachRetrieve($q);
 checkReimbursementCoach(count(array_filter($results,fn($x)=>str_starts_with($x['id'],'manual-topic-reimbursements-')))>0,'Retrieve reimbursement source: '.$q);
}
echo "Reimbursement coaching procedures, roles, limits, routing, and PDF retrieval passed.\n";
