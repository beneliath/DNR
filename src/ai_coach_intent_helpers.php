<?php
declare(strict_types=1);

/** Interpret the speech act before attempting an operation. A noun is not a command. */
function aiCoachIntentMode(array $request): string
{
    $q = aiCoachNormalize($request['question']);
    if (preg_match('/^(done|next|continue|what now|what next|what do i do next|repeat|explain this step)$/', $q)) return 'followup';
    if (preg_match('/\b(cannot|can t|unable|missing|not visible|not showing|doesn t|does not|won t|wrong|failed|error)\b/', $q)) return 'troubleshoot';
    if (preg_match('/^(why|what (is|are|does|happens|happen|s the|does it mean)|how (does|do .* work|is))\b/', $q)
        || preg_match('/\b(difference|different from|meaning|purpose|pros and cons)\b/', $q)) return 'explain';
    if (preg_match('/\b(don t|do not|not want|instead|rather than)\b/', $q)) return 'ambiguous';
    if (aiCoachMatchWorkflow($q) !== '' || preg_match('/^(a )?new (task|organization|contact)$/', $q)) return 'perform';
    if (preg_match('/\b(where|find|locate)\b/', $q) && !aiCoachMutationIntent($q)) return 'navigate';
    if (aiCoachMutationIntent($q) || preg_match('/\b(how do i|how can i|walk me through|guide me|show me|i need|i want|can i|could i|hand .* to|delegate|remind|subscribe)\b/', $q)) return 'perform';
    return 'explain';
}

function aiCoachPersonalCalendarIntent(string $question): bool
{
    $q=aiCoachNormalize($question);
    return (bool)preg_match('/\b(calendar|outlook|iphone)\b/',$q)
        && (bool)preg_match('/\b(subscription|subscribe|feeds?|outlook|apple calendar|google calendar|my calendar|private link|my phone)\b/',$q)
        && !aiCoachCreationIntent($q) && !preg_match('/\b(other user|another user|someone else)\b/',$q);
}

/** A sequence of operations must not be reduced to whichever noun matches first. */
function aiCoachCompoundGoal(string $question): bool
{
    $q = aiCoachNormalize($question);
    $operation = '(?:add(?:ing)?|creat(?:e|ing)|assign(?:ing)?|chang(?:e|ing)|edit(?:ing)?|upload(?:ing)?|attach(?:ing)?|remov(?:e|ing)|delet(?:e|ing)|archiv(?:e|ing)|restor(?:e|ing)|complet(?:e|ing)|convert(?:ing)?|link(?:ing)?|connect(?:ing)?|schedul(?:e|ing))';
    return (bool)preg_match('/\b'.$operation.'\b.+\b(?:and|then|after|before|also)\b.+?\b'.$operation.'\b/', $q);
}

/** Verified form rules can answer common validation questions without guessing a cause. */
function aiCoachFormRuleReply(array $request): ?array
{
    $q=aiCoachNormalize($request['question']);
    foreach (aiCoachForms() as $form) {
        if (!in_array($request['role'],$form['roles'],true)) continue;
        foreach ($form['guidance_rules']??[] as $rule) {
            if (!in_array(aiCoachIntentMode($request),$rule['modes'],true)) continue;
            foreach ($rule['patterns'] as $pattern) if (!preg_match('~'.$pattern.'~',$q)) continue 2;
            $source=aiCoachManualTopics()[$rule['source']]??null;
            if (!$source) continue;
            return ['message'=>$rule['on_pages'][$request['page']]??$rule['message'],'question'=>$rule['question'],
                'sources'=>aiCoachSources([$source]),'engine'=>'verified-form-rule','mode'=>'guide'];
        }
    }
    return null;
}

/** Contracts are trusted repository data, invalidated when their reviewed source changes. */
function aiCoachForms(): array
{
    static $forms = null;
    if ($forms !== null) return $forms;
    $forms = [];
    $catalog = json_decode((string)file_get_contents(__DIR__.'/data/ai-coach-forms.json'), true, 32, JSON_THROW_ON_ERROR);
    foreach ($catalog['forms'] as $id => $form) {
        foreach ($form['source_files'] as $path => $hash) {
            if (!is_file(__DIR__.'/'.$path) || hash_file('sha256',__DIR__.'/'.$path) !== $hash) continue 2;
        }
        $forms[$id] = $form;
    }
    return $forms;
}

function aiCoachControlCatalog(): array
{
    $controls = [];
    foreach (aiCoachForms() as $form) $controls += $form['controls'];
    return $controls;
}

/** One definition supplies the source, permitted roles, steps, buttons and browser rules. */
function aiCoachInteractiveProcedures(): array
{
    $result = [];
    foreach (aiCoachProcedures() as $procedure) {
        if (!isset($procedure['walkthrough'])) continue;
        foreach ($procedure['forms'] as $form) if (!isset(aiCoachForms()[$form])) continue 2;
        $result[$procedure['id']] = $procedure;
    }
    return $result;
}

function aiCoachBrowserWorkflows(): array
{
    $result = [];
    foreach (aiCoachWorkflows() as $id => $workflow) {
        $result[$id] = ['label' => $workflow['label'], 'request' => $workflow['request'], 'roles' => $workflow['roles'] ?? ['admin','editor']];
    }
    foreach (aiCoachInteractiveProcedures() as $id => $procedure) {
        $result[$id] += array_intersect_key($procedure['walkthrough'], array_flip(['rules','default_step']));
    }
    return $result;
}

/** Retrieve plausible operations; the model chooses by meaning in the answer call, not a second call. */
function aiCoachProcedureCandidates(array $request): array
{
    $q = aiCoachNormalize(aiCoachContextQuestion($request));
    $ranked = [];
    foreach (aiCoachProcedures() as $procedure) {
        if (!in_array($request['role'], $procedure['roles'], true)) continue;
        $subject = false;
        foreach ($procedure['subjects'] ?? [] as $word) {
            if (preg_match('/\b'.preg_quote($word,'/').'s?\b/', $q)) $subject = true;
        }
        // An unqualified pronoun can use a form's topic; explicit new topics cannot.
        if (!$subject) continue;
        $score = 0;
        foreach ($procedure['patterns'] as $pattern) if (preg_match('~'.$pattern.'~', $q)) $score += 3;
        foreach (aiCoachSearchTerms($procedure['id']) as $term) if (in_array($term,aiCoachSearchTerms($q),true)) $score++;
        if (isset($procedure['on_pages'][$request['page']])) $score++;
        $ranked[]=['score'=>$score,'procedure'=>$procedure];
    }
    usort($ranked,static fn($a,$b):int=>$b['score']<=>$a['score']);
    return array_column(array_slice($ranked,0,5),'procedure');
}

function aiCoachTaskEvidence(array $request): array
{
    $forms = [];
    foreach (aiCoachProcedureCandidates($request) as $procedure) {
        foreach ($procedure['forms'] ?? [] as $id) if (isset(aiCoachForms()[$id])) $forms[$id]=aiCoachForms()[$id];
    }
    foreach (aiCoachForms() as $id => $form) {
        if (in_array($request['page'],$form['pages'],true) && in_array($request['role'],$form['roles'],true)) $forms[$id]=$form;
    }
    $evidence=[];
    foreach (array_slice($forms,0,3,true) as $id=>$form) {
        $controls=[];
        foreach ($form['controls'] as $key=>$control) $controls[]=['id'=>$key,'label'=>$control['label'],
            'visibility'=>in_array($key,$request['ui']['visible_controls']??[],true) ? 'reported visible'
                : (($request['ui']['observed']??false) && in_array($request['page'],$form['pages'],true) ? 'not reported visible' : 'unknown')];
        $evidence[]=['form'=>$id,'pages'=>$form['pages'],'permitted_roles'=>$form['roles'],
            'entry'=>$form['entry'],'conditions'=>$form['condition'],'facts'=>$form['facts']??[],'controls'=>$controls];
    }
    return $evidence;
}

/** Pick PDF passages for the actual candidate task, without discarding the lexical baseline. */
function aiCoachRelevantTopics(array $request, array $ranked): array
{
    $topics = aiCoachOverviewTopics($request['question']);
    if ($topics !== []) return $topics;
    $byId=[];
    foreach (array_slice(aiCoachIntentMode($request)==='explain' ? [] : aiCoachProcedureCandidates($request),0,2) as $procedure) {
        if (isset(aiCoachManualTopics()[$procedure['topic']])) $byId[$procedure['topic']]=aiCoachManualTopics()[$procedure['topic']];
    }
    foreach ($ranked as $match) $byId[$match['topic']['id']]=$match['topic'];
    return array_slice(array_values($byId),0,4);
}

function aiCoachProcedureAnswer(array $procedure, array $request): array
{
    $required=$procedure['required_on_page'][$request['page']] ?? [];
    foreach ($procedure['required_when']??[] as $condition) {
        if ($condition['page']===$request['page'] && $condition['active_tab']===($request['ui']['active_tab']??'')) $required=array_merge($required,$condition['controls']);
    }
    if (($request['ui']['observed']??false) && array_diff($required,$request['ui']['visible_controls']??[]) !== []) {
        $missing=array_values(array_diff($required,$request['ui']['visible_controls']??[]));
        $label=aiCoachControlCatalog()[$missing[0]]['label'] ?? 'the required control';
        return ['message'=>'This task uses '.$label.', but that control was not visible in the reported page state. I cannot tell you to select it here or determine why it is missing. Check the manual procedure below; you can mark this answer needs work to report the mismatch.',
            'question'=>'','sources'=>aiCoachSources([aiCoachManualTopics()[$procedure['topic']]]),'mode'=>'guide','engine'=>'verified-missing-control','procedure'=>$procedure['id']];
    }
    $steps=$procedure['steps'];
    if (isset($procedure['on_pages'][$request['page']])) $steps[0]=$procedure['on_pages'][$request['page']];
    $lines=[]; foreach ($steps as $index=>$step) $lines[]=($index+1).'. '.$step;
    $reply=['message'=>implode("\n",$lines),'question'=>'','sources'=>aiCoachSources([aiCoachManualTopics()[$procedure['topic']]]),
        'mode'=>'guide','engine'=>'verified-procedure','procedure'=>$procedure['id'],
        'telemetry'=>['route'=>'verified-procedure','procedure'=>$procedure['id'],'model_calls'=>0]];
    if (isset(aiCoachInteractiveProcedures()[$procedure['id']])) {
        $reply['workflow']=$procedure['id']; $reply['start_workflow']=true;
    }
    return $reply;
}

/** Confidence is a selection gate, not an accuracy percentage. Authorization stays in PHP. */
function aiCoachResolveSelection(array $decoded, array $request): ?array
{
    $id=$decoded['procedure'] ?? '';
    if ($id==='') return null;
    $mode=aiCoachIntentMode($request);
    if (!in_array($mode,['perform','navigate'],true) || ($decoded['intent']??'')!=='action') return null;
    // Do not let another user's password or a task field edit become a different operation.
    $q=aiCoachNormalize(aiCoachContextQuestion($request));
    if (aiCoachCompoundGoal($q) || preg_match('/\b(and then|as well as|also|instead|rather than)\b/',$q)) return null;
    if ($id==='change-own-password' && preg_match('/\b(another|other|someone|user s)\b/',$q)) return null;
    if ($id==='create-task' && preg_match('/\b(task|follow up)\b.*\b(notes|description|owner|assignee|due date)\b/',$q)) return null;
    foreach (aiCoachProcedureCandidates($request) as $procedure) {
        if ($procedure['id']!==$id) continue;
        if (($decoded['confidence']??'')!=='high') {
            return ['message'=>'I need to check which change you mean before giving you steps.',
                'question'=>trim($decoded['question']??'') ?: 'What would you like to change or accomplish?',
                'sources'=>[], 'mode'=>'conversation', 'engine'=>'intent-clarification'];
        }
        return aiCoachProcedureAnswer($procedure,$request);
    }
    return null;
}
