<?php
declare(strict_types=1);
require_once __DIR__.'/../src/ai_coach_helpers.php';
function checkPresentationDelete(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
foreach (['Delete a presentation','How do I permanently delete this saved presentation?','Walk me through deleting a presentation'] as $question) {
    foreach (['admin','editor','reviewer'] as $role) {
        $reply=aiCoachImmediateReply(aiCoachValidateRequest(['question'=>$question,'page'=>'help.php'],$role));
        checkPresentationDelete(($reply['procedure']??'')==='delete-saved-presentation' && ($reply['workflow']??'')==='delete-saved-presentation' && ($reply['start_workflow']??false), 'Deletion questions start the verified presentation guide for learning');
        checkPresentationDelete(($reply['sources'][0]['id']??'')==='manual-topic-engagements-archive-or-delete-a-presentation','Deletion cites the correct manual topic');
        checkPresentationDelete(str_contains($reply['message'],'Only an administrator'),'Only an administrator may delete');
    }
}
foreach (['How do I remove a PDF from a presentation?','How do I remove the PowerPoint from a presentation?','What happens when I delete a presentation?','Do not delete a presentation','How do I delete an engagement?'] as $question) {
    $reply=aiCoachProcedureReply(aiCoachValidateRequest(['question'=>$question,'page'=>'edit_engagement.php'],'admin'));
    checkPresentationDelete(($reply['procedure']??'')!=='delete-saved-presentation','File removal, explanation, negation and event deletion keep their own scope');
}
$workflows=aiCoachBrowserWorkflows();
checkPresentationDelete($workflows['delete-saved-presentation']['label']==='Delete a Presentation','Button uses the requested Title Case');
$steps=aiCoachSteps();
foreach (['presentation-delete-action','presentation-delete-confirm'] as $id) checkPresentationDelete(!isset($steps[$id]['target']),'Coach must not choose the first Delete button or activate final confirmation');
checkPresentationDelete(str_contains($steps['presentation-delete-check']['message'],'does not prove deletion succeeded'),'Navigation is not proof of completion');
$reply=aiCoachImmediateReply(aiCoachValidateRequest(['question'=>'next','page'=>'view_engagement.php','step'=>'presentation-delete-edit','history'=>[['role'=>'user','content'=>'How do I delete a presentation?']]],'admin'));
checkPresentationDelete(!str_contains($reply['message'],'PDF or PPT'),'Deletion follow-up does not switch to attachment upload');
echo "Presentation deletion routing, permissions, source, title and confirmation boundaries passed.\n";
