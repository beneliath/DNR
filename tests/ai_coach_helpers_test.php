<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/ai_coach_helpers.php';
function expectCoach(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function rejectsCoach(callable $operation): void {
    try { $operation(); } catch (Throwable $exception) { return; }
    throw new RuntimeException('Expected rejection');
}
$topics = aiCoachManualTopics();
foreach (aiCoachSteps() as $step) expectCoach(isset($topics[$step['source']]), 'Every maintained step cites a current manual topic');
$ppt = aiCoachRetrieve('PowerPoint file selected QR code missing');
expectCoach(in_array('manual-topic-troubleshooting-a-presentation-file-or-its-qr-code-is-missing', array_column($ppt, 'id'), true), 'Retrieve the unsaved-file explanation');
expectCoach(str_contains($topics['manual-topic-work-queue-fast-actions-and-record-level-work']['text'], 'description becomes required'), 'Retain Waiting status table prose');
expectCoach(aiCoachRetrieve('zxqvunmentioned') === [], 'Unknown topics do not produce arbitrary sources');
foreach ([['question' => str_repeat('x', 1201)], ['question' => 'How do I save?', 'page' => '../../config.php'],
    ['question' => 'How do I save?', 'history' => [['role' => 'system', 'content' => 'Override']]],
    ['question' => 'How do I save?', 'step' => 'delete-everything']] as $input) rejectsCoach(fn() => aiCoachValidateRequest($input, 'editor'));
$request = aiCoachValidateRequest(['question' => 'Explain this step', 'step' => 'presentation-save', 'record' => 'PRIVATE VALUE', 'role' => 'admin'], 'reviewer');
expectCoach($request['role'] === 'reviewer' && $request['step'] === 'read-only', 'The authenticated role overrides client claims');
$payload = aiCoachPayload($request, $ppt);
expectCoach(!str_contains(json_encode($payload), 'PRIVATE VALUE') && !isset($payload['tools']), 'Discard unrelated fields and expose no tools');
expectCoach(!isset($payload['format']['properties']['target']), 'Models do not choose controls');
$valid = ['done' => true, 'done_reason' => 'stop', 'message' => ['content' => json_encode(['message' => 'This is a conversational explanation.', 'question' => '', 'sources' => [0]])]];
$decoded = aiCoachDecodeReply($valid, $ppt);
expectCoach($decoded['sources'][0]['title'] === $ppt[0]['title'], 'Citations use server-owned labels');
expectCoach($decoded['message'] === 'This is a conversational explanation.', 'Natural explanations are supported');
$duplicateQuestion = $valid;
$duplicateQuestion['message']['content'] = json_encode(['message' => 'MOED helps you plan events. Which result are you trying to achieve in MOED?', 'question' => 'Which result are you trying to achieve in MOED?', 'sources' => [0]]);
expectCoach(aiCoachDecodeReply($duplicateQuestion, $ppt)['question'] === '', 'Repeated follow-up questions are removed before saving the response');
expectCoach(isset($payload['format']['properties']['message']), 'The model can explain evidence conversationally');
foreach ([['message' => 'Bad', 'question' => '', 'sources' => [5000]],
    ['message' => 'Unsupported', 'question' => '', 'sources' => []],
    ['message' => 'https://invented.example', 'question' => '', 'sources' => [0]],
    ['message' => 'Bad', 'question' => '', 'sources' => [0], 'target' => '#save']] as $bad) {
    $broken = $valid; $broken['message']['content'] = json_encode($bad);
    rejectsCoach(fn() => aiCoachDecodeReply($broken, $ppt));
}
$broken = $valid; $broken['done_reason'] = 'length'; rejectsCoach(fn() => aiCoachDecodeReply($broken, $ppt));
$broken = $valid; $broken['message']['tool_calls'] = [['name' => 'delete']]; rejectsCoach(fn() => aiCoachDecodeReply($broken, $ppt));
$broken = $valid; $broken['message']['content'] = '{'; rejectsCoach(fn() => aiCoachDecodeReply($broken, $ppt));
$catalog = aiCoachTopicCatalog('editor');
expectCoach(count($catalog) > 200 && !in_array('operator-appendix', array_column($catalog, 'chapter'), true), 'Semantic routing sees the whole user manual, without operator-only topics for editors');
$routePayload = aiCoachRoutePayload($request, $catalog);
expectCoach($routePayload['think'] === false, 'Catalog selection stays fast');
putenv('DNR_AI_COACH_REASONING=1');
expectCoach(aiCoachNeedsReasoning(['question' => 'Why can I not confirm this event?']), 'Reasoning is available when explicitly configured');
putenv('DNR_AI_COACH_REASONING=0');
expectCoach(!aiCoachNeedsReasoning(['question' => 'What is the purpose of MOED?']), 'Simple explanations avoid unnecessary reasoning latency');
$routeResponse = ['done' => true, 'done_reason' => 'stop', 'message' => ['content' => json_encode(['workflow' => 'engagement', 'chapters' => ['engagements'], 'search_query' => 'create engagement'])]];
$eventRequest = aiCoachValidateRequest(['question' => 'We have agreed to host a conference. How do I put it in the system?'], 'editor');
expectCoach(aiCoachDecodeRoute($routeResponse, $catalog, $eventRequest)['workflow'] === 'engagement', 'Validated semantic workflow selection');
$taskRequest = aiCoachValidateRequest(['question' => 'How do I add a task to an event?', 'page' => 'view_engagement.php'], 'editor');
expectCoach(!in_array('engagement', aiCoachRoutePayload($taskRequest, $catalog)['format']['properties']['workflow']['enum'], true), 'Adding a task must not create another event');
expectCoach(aiCoachDecodeRoute($routeResponse, $catalog, $taskRequest)['workflow'] === 'none', 'Server rejects an inappropriate create-event route even if returned by the model');
$calendarRoute = $routeResponse; $calendarRoute['message']['content'] = json_encode(['workflow' => 'engagement', 'chapters' => ['map-calendar'], 'search_query' => 'private calendar']);
expectCoach(aiCoachDecodeRoute($calendarRoute, $catalog, $eventRequest)['workflow'] === 'none', 'Calendar guidance cannot launch a create-event walkthrough');
expectCoach(aiCoachWorkflowCandidates('How do I add a task to an event?') === [], 'Task creation has no matching interactive walkthrough');
$routeResponse['message']['content'] = json_encode(['workflow' => 'delete-all', 'chapters' => ['engagements']]);
rejectsCoach(fn() => aiCoachDecodeRoute($routeResponse, $catalog));
expectCoach(aiCoachKnowledge()['document']['pages'] === 149, 'Uses the complete PDF');
expectCoach($topics['manual-topic-engagements-create-or-edit-an-engagement']['page'] === 24, 'PDF page citation retained');
expectCoach(str_contains($topics['manual-topic-engagements-create-or-edit-an-engagement']['text'], '06 Set planning states'), 'Complete numbered creation workflow retained');
expectCoach(count(aiCoachApplicationContext('index.php')['static_controls_not_live_visibility']) > 10, 'Application source index supplies actual form labels');
putenv('DNR_AI_COACH_URL=');
$answer = aiCoachGenerate(aiCoachValidateRequest(['question' => 'How do I attach a PowerPoint?'], 'editor'));
expectCoach($answer['mode'] === 'guide' && str_contains($answer['message'], 'individual presentation') && $answer['workflow'] === 'presentation', 'PowerPoints belong only to individual presentations');
$answer = aiCoachGenerate(aiCoachValidateRequest(['question' => 'How do I attach a PowerPoint?'], 'reviewer'));
expectCoach(str_contains($answer['message'], 'read-only'), 'PowerPoint guidance respects the authenticated role');
$answer = aiCoachGenerate(aiCoachValidateRequest(['question' => 'How do calendar subscriptions work?'], 'editor'));
expectCoach($answer['mode'] === 'manual' && count($answer['sources']) > 0 && $answer['reason'] === 'unavailable', 'Offline explanations preserve manual help');
$answer = aiCoachGenerate(aiCoachValidateRequest(['question' => 'Can I archive the required financial closeout reminder?', 'step' => 'presentation-save'], 'editor'));
expectCoach($answer['mode'] === 'guide' && str_contains($answer['message'], 'cannot'), 'Critical closeout restriction never relies on inference');
foreach (['how do i add speaker notes', 'on this page, how do i add speaker notes PDF?', 'How do I replace the speaker notes?'] as $question) {
    $answer = aiCoachGenerate(aiCoachValidateRequest(['question' => $question, 'page' => 'view_engagement.php', 'step' => 'presentation-edit'], 'editor'));
    expectCoach($answer['workflow'] === 'notes' && $answer['start_workflow'] === true, 'Speaker notes questions start the PDF workflow even during a different walkthrough');
    expectCoach($answer['sources'][0]['id'] === 'manual-topic-engagements-pdf-speaker-notes', 'PDF guidance cites the PDF manual topic');
}
$answer = aiCoachGenerate(aiCoachValidateRequest(['question' => 'How do I add speaker notes PDF?'], 'reviewer'));
expectCoach(str_contains($answer['message'], 'read-only'), 'PDF questions respect the current role');
$answer = aiCoachGenerate(aiCoachValidateRequest(['question' => 'done', 'step' => 'notes-save'], 'editor'));
expectCoach(str_contains($answer['message'], 'not saved yet'), 'Done cannot claim a selected PDF has been saved');
expectCoach(aiCoachSteps()['notes-choose']['target'] === 'pdf-picker', 'PDF workflow highlights the PDF field');
expectCoach(!str_contains(aiCoachSteps()['notes-choose']['message'], 'Choose PPT'), 'PDF guidance never asks for a PowerPoint');
echo "AI coach helper tests passed.\n";

foreach (['how do i add an event?', 'how do I add a new event?', 'Can you help me create an engagement?', 'I am creating a new event', 'schedule an event', 'set up an engagement'] as $question) {
    $answer = aiCoachGenerate(aiCoachValidateRequest(['question' => $question, 'page' => 'view_organization.php'], 'editor'));
    expectCoach($answer['workflow'] === 'engagement' && $answer['start_workflow'], 'New event questions route from any page');
    expectCoach(str_contains($answer['message'], 'New Engagement'), 'Open the form before choosing the organization');
}
foreach (['how do I add an event task?', 'add a contact to an event', 'what is the purpose of this application?', 'why can I not create an event?', 'remove an event', 'create a new event type', 'convert an inquiry to an event'] as $question) {
    expectCoach(aiCoachMatchWorkflow($question) === '', 'Other goals require semantic interpretation: ' . $question);
}
expectCoach(aiCoachMatchWorkflow('Help with uploading speaker notes') === 'notes', 'Inflected upload wording');
expectCoach(aiCoachMatchWorkflow('put this task into waiting') === 'waiting', 'Waiting natural language');
expectCoach(aiCoachSteps()['engagement-start']['href'] === 'index.php', 'Actual New Engagement entry point');
expectCoach(aiCoachSteps()['engagement-organization']['target'] === 'new-organization', 'Organization picker is inside the new form');
echo "Conversational routing and PDF grounding tests passed.\n";

expectCoach(count(aiCoachOverviewTopics('what are some of the functions of the moed app?')) >= 2, 'Broad application questions use introductory context directly');

$oldFailure = 'I cannot look up an answer right now. You can still use the guided steps and open the matching manual topics below.';
$request = aiCoachValidateRequest(['question' => 'What are the available walkthroughs?', 'history' => [
    ['role' => 'assistant', 'content' => $oldFailure], ['role' => 'user', 'content' => 'Why can you not answer?'],
    ['role' => 'assistant', 'content' => 'A PowerPoint belongs to a presentation.'],
]], 'editor');
expectCoach(count($request['history']) === 2 && $request['history'][0]['role'] === 'user', 'Exclude historical outages while retaining user questions and useful replies');
$answer = aiCoachGenerate($request);
expectCoach($answer['engine'] === 'verified-capabilities' && $answer['workflow_options'] === array_keys(aiCoachWorkflows())
    && !isset($answer['reason']), 'Available guides are answered from the application catalog while inference is offline');
$answer = aiCoachGenerate(aiCoachValidateRequest(['question' => 'What can you walk me through?'], 'reviewer'));
expectCoach(str_contains($answer['message'], 'read-only'), 'Guide menu respects reviewers');
foreach (["why can you not look up an answer right now?", "why can't you answer?"] as $question) {
    $answer = aiCoachGenerate(aiCoachValidateRequest(['question' => $question], 'editor'));
    expectCoach($answer['engine'] === 'verified-capabilities' && str_contains($answer['message'], 'does not tell us'), 'Explain a prior failure without inventing current availability');
}
expectCoach(aiCoachSupportReply(aiCoachValidateRequest(['question' => 'How do I upload speaker notes PDF?'], 'editor')) === null, 'Catalog shortcut does not intercept an actual workflow');
$echo = $valid; $echo['message']['content'] = json_encode(['message' => $oldFailure, 'question' => '', 'sources' => [0]]);
rejectsCoach(fn() => aiCoachDecodeReply($echo, $ppt));
foreach ([new AiCoachModelFailure('connection', 0, 7), new AiCoachModelFailure('timeout', 0, 28), new RuntimeException('Bad answer')] as $failure) {
    $answer = aiCoachFailureReply([], 'answer', $failure);
    expectCoach($answer['failure']['stage'] === 'answer' && count($answer['workflow_options']) === 8, 'Failures retain stage and offline guide choices');
}
expectCoach(aiCoachFailureReply([], 'route', new RuntimeException('Bad route'))['reason'] === 'response_error', 'Invalid model output is not mislabeled as a lost connection');
echo "Coach availability and failure-history regression tests passed.\n";

foreach (['How do I add a location to an event?', 'How do I add a new day to an event?', "I don't want to create an event. Edit the existing event.", 'How do I add a task to an event?'] as $question) {
    expectCoach(aiCoachMatchWorkflow($question)==='', 'Adding to an existing record never creates a new event: '.$question);
}
$ui=aiCoachValidateUi(['active_tab'=>'presentations','visible_controls'=>['edit-presentations','private-field','edit-presentations'],'form_errors'=>true,'record'=>'PRIVATE']);
expectCoach($ui===['visible_controls'=>['edit-presentations'],'active_tab'=>'presentations','form_errors'=>true,'observed'=>false], 'UI contract drops private and arbitrary state');
$followup=aiCoachValidateRequest(['question'=>'Can you walk me through adding one?','history'=>[['role'=>'user','content'=>'Where do speaker notes PDFs go?']]],'editor');
expectCoach(aiCoachImmediateReply($followup)['workflow']==='notes','Pronoun follow-up retains its subject');
expectCoach(aiCoachImmediateReply(aiCoachValidateRequest(['question'=>'Thanks, that helped'],'editor'))['mode']==='conversation','Acknowledgements need no citation');
$ack=$valid; $ack['message']['content']=json_encode(['kind'=>'conversation','message'=>'You are welcome.','question'=>'','sources'=>[]]);
expectCoach(aiCoachDecodeReply($ack,[])['sources']===[],'Conversation is valid without false evidence');
$payload=aiCoachPayload(aiCoachValidateRequest(['question'=>'Explain the task status'],'editor'),aiCoachRetrieve('task status'));
expectCoach($payload['options']['num_predict']<=320,'Answer generation is bounded');
echo "Coaching defect regressions passed.\n";
expectCoach(count(aiCoachProcedures())===19,'Every verified procedure still matches the current source files and PDF');
foreach ([['How do I assign a task to someone?','tasks.php','assign-task','Assigned to'],['How do I add a second day to an existing event?','view_engagement.php','edit-event-dates','Edit Engagement'],['How can I see these events in Apple Calendar on my phone?','view_calendar.php','calendar-subscription','Create Private Link'],['How do I restore an archived organization?','organizations.php','restore-organization','Restore organization'],['How do I remove a PowerPoint?','edit_engagement.php','remove-ppt','Remove current PPT']] as [$q,$page,$procedure,$label]) {
    $r=aiCoachImmediateReply(aiCoachValidateRequest(['question'=>$q,'page'=>$page],'editor'));
    expectCoach(($r['procedure']??'')===$procedure && str_contains($r['message'],$label),'Verified next-action procedure: '.$procedure);
}
foreach (['create-task'=>'How do I add a task?', 'complete-task'=>'How do I complete a task?', 'archive-task'=>'How do I archive a task?', 'restore-task'=>'How do I restore a task?', 'convert-inquiry'=>'How do I convert an inquiry?', 'restore-contact'=>'How do I restore a contact?', 'restore-event'=>'How do I restore an engagement?', 'remove-pdf'=>'How do I remove speaker notes PDF?', 'create-organization'=>'How do I create an organization?', 'create-contact'=>'How do I create a contact?', 'event-chron-entry'=>'How do I add a Chron entry to an event?'] as $id=>$question) {
    $reply=aiCoachImmediateReply(aiCoachValidateRequest(['question'=>$question],'editor'));
    expectCoach(($reply['procedure']??'')===$id,'Procedure intent: '.$id);
    $reply=aiCoachImmediateReply(aiCoachValidateRequest(['question'=>$question],'reviewer'));
    expectCoach(($reply['engine']??'')==='verified-permissions','Read-only procedure guard: '.$id);
}
$reply=aiCoachImmediateReply(aiCoachValidateRequest(['question'=>"I've chosen the file. What do I click now?",'page'=>'edit_engagement.php'],'editor'));
expectCoach($reply['sources'][0]['id']==='manual-topic-engagements-save-a-pending-presentation-file-change','File-save follow-up cites the actual save instructions, not backups');

$echo=aiCoachRejectEcho(['message'=>'What does MOED do?','question'=>'','sources'=>[]], 'what does moed do');
expectCoach(($echo['quality_issue']??'')==='echoed_question' && $echo['sources']===[], 'An echoed question is surfaced as a quality failure without false sources');
$answer=['message'=>'MOED coordinates events and related work.','question'=>'','sources'=>[]];
expectCoach(aiCoachRejectEcho($answer,'What does MOED do?')===$answer,'Useful answers pass through unchanged');

foreach (['Why would I assign a task to someone?', 'How does a private calendar subscription work?', "Why can’t I change the status of a task that is waiting for someone?", "I can’t change the status of a task that is waiting for someone."] as $q) {
    $r=aiCoachValidateRequest(['question'=>$q,'page'=>'edit_task.php'],'editor');
    $reply=aiCoachImmediateReply($r);
    expectCoach(!isset($reply['workflow']), 'Explanation or diagnosis cannot start a mutation: '.$q);
}
foreach (['How do I create a new event and assign a task?', 'Can you help me assign a task after I create an event?', 'Can you help me assign a task after creating an event?'] as $q) {
    $r=aiCoachValidateRequest(['question'=>$q,'page'=>'dashboard.php'],'editor');
    expectCoach(aiCoachImmediateReply($r)===null && aiCoachProcedureReply($r)===null, 'Compound goal must retain all operations');
    expectCoach(aiCoachResolveSelection(['procedure'=>'assign-task','intent'=>'action','confidence'=>'high'],$r)===null, 'Model selection cannot collapse a compound goal');
}
expectCoach(!aiCoachCompoundGoal('Change the start and end dates'), 'Two fields are not two operations');
foreach (['How do I create a private calendar feed?', 'How do I subscribe in Outlook?'] as $q) {
    $r=aiCoachValidateRequest(['question'=>$q,'page'=>'view_calendar.php'],'reviewer');
    expectCoach((aiCoachImmediateReply($r)['engine']??'')!=='verified-permissions', 'Reviewers may manage their own subscriptions');
}
$r=aiCoachValidateRequest(['question'=>'How do I change the event dates?','page'=>'view_engagement.php','ui'=>['observed'=>true,'visible_controls'=>['tasks-tab']]],'editor');
expectCoach(aiCoachImmediateReply($r)['engine']==='verified-missing-control', 'Do not offer Edit Engagement when absent on the reported page');
$r=aiCoachValidateRequest(['question'=>'How do I add a task?','page'=>'view_engagement.php','ui'=>['observed'=>true,'active_tab'=>'tasks','visible_controls'=>['tasks-tab']]],'editor');
expectCoach(aiCoachImmediateReply($r)['engine']==='verified-missing-control', 'Do not offer Add Task when absent from the open Tasks tab');
$r=aiCoachValidateRequest(['question'=>'I need to hand this follow-up to a colleague. Where do I do that?','page'=>'edit_task.php'],'editor');
$selected=aiCoachResolveSelection(['procedure'=>'assign-task','intent'=>'action','confidence'=>'high'],$r);
expectCoach(($selected['workflow']??'')==='assign-task' && str_contains($selected['message'],'Assigned to'), 'Clear semantic assignment uses the verified form');
expectCoach(aiCoachResolveSelection(['procedure'=>'assign-task','intent'=>'action','confidence'=>'low','question'=>'Do you want to change the task owner?'],$r)['engine']==='intent-clarification','Uncertain selection asks before supplying steps');
expectCoach(aiCoachResolveSelection(['procedure'=>'assign-task','intent'=>'explanation','confidence'=>'high'],$r)===null,'Explanations do not launch a procedure');
$payload=aiCoachPayload($r,aiCoachRetrieve('assign task'));
$context=json_decode($payload['messages'][1]['content'],true);
expectCoach(in_array('assign-task',array_column($context['known_procedures'],'id'),true),'Assignment is offered to the interpreter');
expectCoach(str_contains(json_encode($context['target_form_evidence']),'Find a related record'), 'Evidence names the real search control');
expectCoach(!str_contains(json_encode($context['target_form_evidence']),'selector'), 'Browser selectors do not enter model evidence');
expectCoach(count(aiCoachForms())===7 && count(aiCoachInteractiveProcedures())===4, 'All reviewed form and interactive procedure source hashes match');
foreach (aiCoachInteractiveProcedures() as $procedure) foreach ($procedure['walkthrough']['steps'] as $step) {
    expectCoach(!isset($step['target']) || isset(aiCoachControlCatalog()[$step['target']]), 'Every new highlight target comes from a reviewed control');
}
$r=aiCoachValidateRequest(['question'=>'Why is a completed task still visible?','page'=>'tasks.php'],'editor');
expectCoach(str_contains(json_encode(aiCoachTaskEvidence($r)), 'does not automatically archive'), 'Task evidence distinguishes completion from archive');
foreach (["Why can’t I change the status of a task that is waiting for someone?", "I can’t change the status of a task that is waiting for someone."] as $q) {
    $reply=aiCoachImmediateReply(aiCoachValidateRequest(['question'=>$q,'page'=>'edit_task.php'],'editor'));
    expectCoach($reply['engine']==='verified-form-rule' && str_contains($reply['message'],'only when') && $reply['question']!=='', 'Conditional validation is explained without asserting an unseen cause');
}
$reply=aiCoachImmediateReply(aiCoachValidateRequest(['question'=>'How do I change the status of my task from Waiting to Completed?','page'=>'edit_task.php'],'editor'));
expectCoach($reply['engine']==='verified-form-rule' && str_contains($reply['message'],'cleared') && !isset($reply['workflow']), 'Leaving Waiting does not require entering Waiting on');
$reply=aiCoachImmediateReply(aiCoachValidateRequest(['question'=>'How do I change the status of this task from Waiting to Canceled?','page'=>'add_task.php'],'editor'));
expectCoach(str_contains($reply['message'],'select Add task') && !str_contains($reply['message'],'Save changes') && !str_contains($reply['message'],'Completed'), 'A new task status change stays in its draft and uses Add task');
echo "Intent, source-contract, permission and compound-goal regressions passed.\n";
