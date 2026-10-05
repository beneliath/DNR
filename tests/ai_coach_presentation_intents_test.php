<?php
declare(strict_types=1);
require_once __DIR__.'/../src/ai_coach_helpers.php';
function expectPresentation(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
foreach (['admin','editor','reviewer'] as $role) {
    foreach (['dashboard.php','view_engagement.php','edit_engagement.php'] as $page) {
        foreach (['How do I record actual attendance?', 'How do I enter the final attendance for an engagement?'] as $q) {
            $r=aiCoachValidateRequest(['question'=>$q,'page'=>$page],$role);
            expectPresentation(!in_array('edit-event-dates',array_column(aiCoachProcedureCandidates($r),'id'),true),'Attendance must not offer event date changes');
            $a=aiCoachImmediateReply($r);
            if ($role==='reviewer') expectPresentation(($a['engine']??'')==='verified-permissions','Reviewer attendance edits remain restricted');
            else {
                expectPresentation(($a['procedure']??'')==='record-presentation-attendance','Attendance has a source-verified route');
                expectPresentation(str_contains($a['message'],'Actual Attendance') && str_contains($a['message'],'presentation') && str_contains($a['message'],'Save Changes'),'Identify per-presentation field and save');
            }
        }
        $a=aiCoachImmediateReply(aiCoachValidateRequest(['question'=>'How do I delete a saved presentation?','page'=>$page],$role));
        if ($role==='admin') {
            expectPresentation(($a['procedure']??'')==='delete-saved-presentation','Delete saved presentation has its own route');
            expectPresentation(str_contains($a['message'],'Admin Unlock') && str_contains($a['message'],'Delete'),'Require administrator confirmation');
            expectPresentation(!str_contains($a['message'],'Restore') && !str_contains($a['message'],'archive the engagement'),'Do not substitute restoration or engagement archive');
        } else expectPresentation(($a['engine']??'')==='verified-permissions','Editors and reviewers cannot delete presentations');
    }
}
foreach (['How do I remove the PDF from a presentation?','How do I delete an archived presentation?','How do I remove an unsaved presentation?','How do I delete an engagement?','Why would I delete a presentation?'] as $q) {
    $r=aiCoachValidateRequest(['question'=>$q,'page'=>'edit_engagement.php'],'admin');
    expectPresentation(!in_array('delete-saved-presentation',array_column(aiCoachProcedureCandidates($r),'id'),true) || str_starts_with($q,'Why'),'Nearby intents do not offer active-presentation deletion');
    expectPresentation((aiCoachImmediateReply($r)['procedure']??'')!=='delete-saved-presentation','Do not route nearby intents to active-presentation deletion');
}
$r=aiCoachValidateRequest(['question'=>'How do I change the dates of an event?','page'=>'dashboard.php'],'editor');
expectPresentation((aiCoachImmediateReply($r)['procedure']??'')==='edit-event-dates','Date changes keep their existing procedure');
echo "Presentation intent regression tests passed.\n";
