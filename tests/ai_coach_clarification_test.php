<?php
declare(strict_types=1);
require_once __DIR__.'/../src/ai_coach_helpers.php';

function expectClarification(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

// Application-generated questions are independent of the model's prompt.
foreach (['Hello', 'Can I archive the required financial closeout reminder?',
          'How do I upload speaker notes and a PowerPoint?'] as $question) {
    $request = aiCoachValidateRequest(['question'=>$question, 'page'=>'view_engagement.php'], 'editor');
    $reply = aiCoachImmediateReply($request);
    expectClarification($reply !== null && $reply['question'] === '', 'An answered request needs no continuation question');
    expectClarification(!str_contains($reply['message'], '?'), 'Do not hide a follow-up in the message');
}
$request = aiCoachValidateRequest(['question'=>'How do I add notes?', 'page'=>'help.php'], 'editor');
$reply = aiCoachImmediateReply($request);
expectClarification($reply['engine'] === 'intent-clarification' && $reply['question'] !== '', 'Unspecified notes still need clarification');
expectClarification(!str_contains($reply['message'], '?') && empty($reply['start_workflow']), 'Clarify once before selecting a notes workflow');
foreach (['task Notes', 'Chron Log', 'speaker-notes PDF'] as $choice)
    expectClarification(str_contains($reply['question'], $choice), 'The clarification itself supplies each supported choice');
$notesQuestion = $reply['question'];
$repeat = aiCoachValidateRequest(['question'=>'How do I add notes?', 'page'=>'help.php',
    'history'=>[['role'=>'user', 'content'=>'How do I add notes?'], ['role'=>'assistant', 'content'=>$reply['message'].' '.$notesQuestion, 'page'=>'help.php']]], 'editor');
$repeatReply = aiCoachImmediateReply($repeat);
expectClarification($repeatReply['question'] === '' && empty($repeatReply['start_workflow']), 'An incomplete notes reply cannot start a second clarification round or guess a workflow');
expectClarification(str_contains($repeatReply['message'], 'cannot select') && str_contains($repeatReply['message'], 'Chron Log'), 'Explain the remaining choice without another request for information');
$repeat['history'][] = ['role'=>'user', 'content'=>'I am not sure.'];
$repeat['history'][] = ['role'=>'assistant', 'content'=>$repeatReply['message']];
expectClarification(aiCoachImmediateReply($repeat)['question'] === '', 'A limitation response does not reset the same notes clarification');
$request = aiCoachValidateRequest(['question'=>'A speaker-notes PDF for a presentation. How do I add it?', 'page'=>'view_engagement.php',
    'history'=>[['role'=>'user', 'content'=>'How do I add notes?'], ['role'=>'assistant', 'content'=>$notesQuestion]]], 'editor');
$reply = aiCoachImmediateReply($request);
expectClarification(($reply['workflow']??'') === 'notes' && $reply['question'] === '', 'One reply to the notes clarification is enough to continue');

$request = aiCoachValidateRequest(['question'=>'I cannot change the status of my task from Waiting. Saving failed.', 'page'=>'edit_task.php'], 'editor');
$reply = aiCoachImmediateReply($request);
expectClarification($reply['engine'] === 'verified-form-rule' && $reply['question'] !== '', 'Diagnosis still asks for the missing error');
expectClarification(str_contains($reply['message'], 'only when') && !isset($reply['workflow']), 'Explain the conditional rule without asserting an unseen cause or starting an action');
expectClarification(!str_contains($reply['message'], '?'), 'Missing error clarification is only in question');
expectClarification(str_contains($reply['question'], 'Copy') && str_contains($reply['question'], 'field label'), 'Explain exactly what to provide for a useful diagnosis');
expectClarification(mb_strlen($reply['question']) <= 180, 'Helpful clarification fits the existing response contract');
$request['history'] = [['role'=>'user', 'content'=>$request['question']],
    ['role'=>'assistant', 'content'=>$reply['message'].' '.$reply['question'], 'page'=>'edit_task.php']];
$repeatReply = aiCoachImmediateReply($request);
expectClarification($repeatReply['question'] === '' && str_contains($repeatReply['message'], 'only when'), 'Repeated failure details receive conditional guidance instead of the same error question');
$request['history'][1] = ['role'=>'assistant', 'content'=>'Which calendar subscription do you mean?', 'page'=>'edit_task.php'];
$request['history'][0]['content'] = 'How do I add a calendar subscription?';
expectClarification(aiCoachImmediateReply($request) === null, 'A different prior question defers semantic goal resolution instead of blindly consuming or repeating clarification');
$request['history'][1] = ['role'=>'assistant', 'content'=>$reply['question'], 'page'=>'edit_contact.php'];
expectClarification(aiCoachImmediateReply($request) === null, 'A form change needs semantic goal resolution rather than an assumed clarification reset');
$request['history'][1] = ['role'=>'assistant', 'content'=>'What exact error appeared? Copy its text and any named field.'];
$request['history'][0]['content'] = 'Saving my task failed.';
expectClarification(aiCoachImmediateReply($request)['question'] === '', 'A model-generated diagnostic question cannot be followed by the built-in diagnostic question');

$request = aiCoachValidateRequest(['question'=>'What does this button do?', 'page'=>'other'], 'editor');
$reply = aiCoachImmediateReply($request);
expectClarification($reply['question'] !== '' && mb_strlen($reply['question']) <= 180 && str_contains($reply['question'], 'no label'), 'An unknown control gets label, example, and fallback response guidance within the contract');
$request['history'] = [['role'=>'assistant', 'content'=>$reply['message'].' '.$reply['question']]];
expectClarification(aiCoachImmediateReply($request)['question'] === '', 'An unidentified control cannot repeat the same clarification');

$request = aiCoachValidateRequest(['question'=>'How do I upload speaker-notes PDF?', 'page'=>'view_engagement.php'], 'reviewer');
$reply = aiCoachImmediateReply($request);
expectClarification($reply['engine'] === 'verified-permissions' && $reply['question'] === '', 'Permission denial is direct and preserves the authenticated role');

// A weak procedure selection must not manufacture a question about an already stated goal.
$request = aiCoachValidateRequest(['question'=>'I need to hand this follow-up to a colleague. Where do I do that?', 'page'=>'edit_task.php'], 'editor');
$topics = aiCoachRetrieve('assign task');
$answer = ['intent'=>'action', 'procedure'=>'assign-task', 'confidence'=>'low',
    'kind'=>'information', 'message'=>'Assigned To sets the task’s owner in Edit Task.', 'question'=>'', 'sources'=>[]];
$raw = ['done'=>true, 'done_reason'=>'stop', 'message'=>['content'=>json_encode($answer)]];
$decoded = aiCoachDecodeReply($raw, $topics, $request);
expectClarification($decoded['question'] === '' && $decoded['message'] === $answer['message'], 'Keep a supported answer without a fabricated clarification');
expectClarification(empty($decoded['start_workflow']) && $decoded['sources'] === [], 'Low confidence cannot start a workflow; application-supported answers need no PDF citation');
$answer['question'] = 'Should this task be assigned to you or another user?';
expectClarification(aiCoachResolveSelection($answer, $request)['question'] === $answer['question'], 'A supplied clarification remains available');
$answer['confidence'] = 'high';
$answer['question'] = '';
expectClarification(aiCoachResolveSelection($answer, $request)['procedure'] === 'assign-task', 'Clear actions retain verified procedures');
$answer['intent'] = 'explanation';
expectClarification(aiCoachResolveSelection($answer, $request) === null, 'An explanation cannot start a procedure');

// Model instructions alone are insufficient: enforce identifiable repeat questions in decoding.
$request = aiCoachValidateRequest(['question'=>'It still failed. I do not have the error text.', 'page'=>'edit_task.php',
    'history'=>[['role'=>'user', 'content'=>'Saving my task failed.'],
        ['role'=>'assistant', 'content'=>'What exact error appeared? Copy its text and any named field.']]], 'editor');
$answer = ['intent'=>'troubleshoot', 'procedure'=>'', 'confidence'=>'low', 'kind'=>'uncertain',
    'message'=>'The cause remains unknown without the error text.',
    'question'=>'What exact error appeared? Copy its text and any field label; if none appears, describe what happened.', 'sources'=>[]];
$decode = static fn(array $answer, array $request): array => aiCoachDecodeReply(
    ['done'=>true, 'done_reason'=>'stop', 'message'=>['content'=>json_encode($answer)]], [], $request);
expectClarification($decode($answer, $request)['question'] === '', 'An incomplete reply cannot receive the model’s repeated clarification');
$answer['message'] .= ' '.$answer['question'];
$reply = $decode($answer, $request);
expectClarification($reply['question'] === '' && !str_contains($reply['message'], '?') && $reply['engine'] === 'clarification-limit', 'A duplicate hidden in message becomes a clear limitation instead of a second question');
$answer['question'] = 'Did you select a related record before saving?';
$answer['message'] = 'The failure was caused by a missing related record.';
$reply = $decode($answer, $request);
expectClarification($reply['question'] === '' && $reply['engine'] === 'clarification-limit' && !str_contains($reply['message'], 'missing related record'), 'A differently worded second diagnostic question cannot bypass the round limit or retain a guessed cause');
$continuation = $request;
$continuation['history'][] = ['role'=>'user', 'content'=>'It still failed. I do not have the error text.'];
$continuation['history'][] = ['role'=>'assistant', 'content'=>$reply['message']];
expectClarification($decode($answer, $continuation)['question'] === '', 'A limitation response does not reset the diagnostic round budget');
$answer['message'] = 'I cannot determine the cause from the information available.';
$request['history'][1]['content'] = 'Do you mean task Notes or a speaker-notes PDF?';
$request['history'][0]['content'] = 'How do I add notes?';
expectClarification($decode($answer, $request)['question'] === $answer['question'], 'A different goal’s first necessary clarification stays available');
$fresh = $request;
$fresh['history'] = [];
expectClarification(aiCoachPayload($request, [])['messages'][0] === aiCoachPayload($fresh, [])['messages'][0], 'An unrelated notes clarification does not select the exhausted-diagnosis prompt');
$answer['intent'] = 'explanation';
$answer['message'] = 'A task due date and its engagement dates are separate.';
$answer['question'] = 'Would you like help changing it?';
expectClarification($decode($answer, $request)['question'] === '', 'An explanation cannot append a generic offer to continue');

putenv('DNR_AI_COACH_MODEL');
putenv('DNR_AI_COACH_REASONING');
$payload = aiCoachPayload($request, $topics);
expectClarification($payload['model'] === 'qwen3.5:9b-q4_K_M' && $payload['think'] === false, 'Qwen remains the model with thinking off by default');
expectClarification($payload['options'] === ['temperature'=>0, 'num_ctx'=>8192, 'num_predict'=>320], 'Keep the existing inference budget');
expectClarification(in_array('question', $payload['format']['required'], true), 'Clarification remains part of the structured contract');
echo "Coach direct-answer and necessary-clarification checks passed.\n";
