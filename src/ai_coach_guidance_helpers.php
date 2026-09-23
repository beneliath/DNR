<?php
declare(strict_types=1);

function aiCoachGuidanceRevision(): string
{
    $hashes = [];
    foreach (['assets/docs/moed-comprehensive-user-manual.pdf', 'ai_coach_helpers.php', 'ai_coach_guidance_helpers.php', 'ai_coach_intent_helpers.php', 'ai_coach_improvement_helpers.php', 'data/ai-coach-forms.json',
              'data/ai-coach-application-map.json', 'data/ai-coach-procedures.json', 'assets/js/ai-coach.min.js'] as $path) {
        if (is_file(__DIR__ . '/' . $path)) $hashes[] = hash_file('sha256', __DIR__ . '/' . $path);
    }
    $catalog = json_decode((string)file_get_contents(__DIR__.'/data/ai-coach-procedures.json'),true);
    $files=[]; foreach ($catalog['procedures'] ?? [] as $procedure) foreach ($procedure['source_files'] as $file=>$hash) $files[$file]=true;
    foreach (aiCoachForms() as $form) foreach ($form['source_files'] as $file=>$hash) $files[$file]=true;
    foreach (array_keys($files) as $file) $hashes[]=is_file(__DIR__.'/'.$file) ? hash_file('sha256',__DIR__.'/'.$file) : 'missing';
    return hash('sha256', implode('', $hashes));
}

/** Resolve a short follow-up without carrying unrelated previous questions into search. */
function aiCoachContextQuestion(array $request): string
{
    if (aiCoachPageExplanation($request)) {
        $pageName = aiCoachPages()[$request['page']] ?? '';
        $tab = $request['ui']['active_tab'] ?? '';
        return $pageName . ($tab !== '' && preg_match('/\btab\b/', aiCoachNormalize($request['question'])) ? ' ' . $tab : '');
    }
    $question = $request['question'];
    $q = aiCoachNormalize($question);
    if (!preg_match('/\b(it|one|that|this|them|those|next)\b/', $q)
        || preg_match('/\b(event|engagement|task|contact|speaker|notes|pdf|powerpoint|pptx?|calendar|organization|inquiry|invoice)\b/', $q)) return $question;
    $step = $request['step'] ?? '';
    if (str_starts_with($step, 'notes-')) return $question . ' (speaker notes PDF)';
    if (str_starts_with($step, 'presentation-')) return $question . ' (PowerPoint)';
    foreach (array_reverse($request['history'] ?? []) as $message) {
        $text = aiCoachNormalize($message['content']);
        foreach (['speaker notes PDF' => '/\b(speaker notes|notes pdf|pdf speaker notes)\b/',
                  'PowerPoint' => '/\b(powerpoint|pptx?|slidedeck)\b/',
                  'task' => '/\btasks?\b/', 'engagement' => '/\b(events?|engagements?)\b/',
                  'calendar subscription' => '/\bcalendar\b/'] as $subject => $pattern) {
            if (preg_match($pattern, $text)) return $question . ' (' . $subject . ')';
        }
    }
    return $question;
}

/** Generic references to the current interface describe the page, not a prior task. */
function aiCoachPageExplanation(array $request): bool
{
    $q = aiCoachNormalize($request['question'] ?? '');
    if (preg_match('/^(where (am i|are we)( now)?|what (page|screen|tab) (am i|are we) (on|looking at)( now)?)$/', $q)) return true;
    if (!(preg_match('/\b(this|current)\b/', $q) && preg_match('/\b(interface|page|screen|section|view|tab)\b/', $q))
        && !preg_match('/\bhere\b/', $q)) return false;
    if (aiCoachIntentMode($request) !== 'explain' && !preg_match('/^what (can|could) (i|we) do\b/', $q)) return false;
    $generic = explode(' ', 'function functionality part interface page screen section view tab purpose explain describe work used here current area now');
    return array_diff(aiCoachSearchTerms($q), $generic) === [];
}

/** Preserve the transcript, but a self-contained location question needs no old page description. */
function aiCoachAnswerHistory(array $request): array
{
    return aiCoachPageExplanation($request) ? [] : array_slice($request['history'], -4);
}

function aiCoachLocationContext(array $request): array
{
    $previous = null;
    foreach (array_reverse($request['history']) as $message) {
        if (!isset($message['page'])) continue;
        $previous = ['page' => $message['page'], 'page_name' => aiCoachPages()[$message['page']], 'active_tab' => $message['active_tab'] ?? ''];
        break;
    }
    return ['page' => $request['page'], 'page_name' => aiCoachPages()[$request['page']], 'active_tab' => $request['ui']['active_tab'] ?? '',
        'previous_exchange_location' => $previous,
        'changed_since_previous_exchange' => $previous === null ? null
            : ($previous['page'] !== $request['page'] || $previous['active_tab'] !== ($request['ui']['active_tab'] ?? ''))];
}

function aiCoachHasApplicationEvidence(array $request): bool
{
    if ($request === []) return false;
    return aiCoachApplicationContext($request['page'])['static_controls_not_live_visibility'] !== []
        || isset(aiCoachSteps()[$request['step']]) || aiCoachTaskEvidence($request) !== [];
}

function aiCoachMutationIntent(string $question): bool
{
    $q = aiCoachNormalize($question);
    return (bool) preg_match('/\b(add|adding|create|creating|edit|editing|change|changing|assign|reassign|upload|replace|remove|delete|archive|restore|unarchive|convert|reschedule|complete|finish|reopen|mark|set|save)\b/', $q);
}

function aiCoachCreationIntent(string $question): bool
{
    $q = aiCoachNormalize($question);
    if (preg_match('/\b(not|never|don t|do not|already|existing|current|second day|another day|extend|reschedule|date|dates|duration)\b/', $q)) return false;
    return (bool) preg_match('/\b(add(?:ing)?|creat(?:e|ing)|schedul(?:e|ing)|set up|start|put)\s+(?:(?:a|an|the|another)\s+)?(?:brand\s+)?(?:new\s+)?(event|engagement|conference|meeting|seminar|retreat)\b|^(?:a )?new (event|engagement)$|\bagreed to host\b/', $q);
}

/** A compact structural contract: labels/state only, never field values or record IDs. */
function aiCoachVisibleControls(): array
{
    return array_keys(aiCoachControlCatalog());
}

function aiCoachValidateUi(mixed $input): array
{
    if (!is_array($input)) return ['visible_controls' => [], 'active_tab' => '', 'form_errors' => false, 'observed' => false];
    $controls = array_values(array_filter(is_array($input['visible_controls'] ?? null) ? $input['visible_controls'] : [],
        static fn($id): bool => is_string($id) && in_array($id, aiCoachVisibleControls(), true)));
    $tab = $input['active_tab'] ?? '';
    return ['visible_controls' => array_slice(array_values(array_unique($controls)), 0, 48),
        'active_tab' => is_string($tab) && in_array($tab, ['activity', 'correspondence', 'tasks', 'presentations', 'contacts', 'logistics', 'financials'], true) ? $tab : '',
        'form_errors' => ($input['form_errors'] ?? false) === true, 'observed' => ($input['observed'] ?? false) === true];
}

function aiCoachSearchTerms(string $text): array
{
    $text = aiCoachNormalize($text);
    $aliases = ['events' => 'engagement', 'event' => 'engagement', 'engagements' => 'engagement',
        'ppt' => 'powerpoint', 'pptx' => 'powerpoint', 'slidedeck' => 'powerpoint',
        'add' => 'create', 'adding' => 'create', 'creating' => 'create',
        'assigning' => 'assign', 'assignment' => 'assign', 'assigned' => 'assign', 'owner' => 'assign',
        'blocked' => 'waiting', 'blocking' => 'waiting', 'reschedule' => 'dates', 'rescheduling' => 'dates',
        'coworker' => 'user', 'colleague' => 'user', 'subscribe' => 'subscription'];
    $stop = array_flip(explode(' ', 'a an and are as at be but by can could do does for from how i in is it me my of on or that the this to up we what when where which with would you your please help walk through one time some tell about'));
    $terms = [];
    foreach (explode(' ', $text) as $term) {
        if (isset($stop[$term]) || $term === '') continue;
        $term = $aliases[$term] ?? $term;
        if (strlen($term) > 4 && str_ends_with($term, 's') && !str_ends_with($term, 'ss')) $term = substr($term, 0, -1);
        $terms[] = $term;
    }
    return $terms;
}

/** Cross-chapter BM25 ranking with exact-title and verified-intent boosts. No arbitrary fallback. */
function aiCoachRankEvidence(string $query, string $topicId = '', string $role = 'editor', array $chapters = []): array
{
    static $documents = null;
    if ($documents === null) {
        $documents = [];
        foreach (aiCoachManualTopics() as $topic) {
            if ($topic['text'] === '' || in_array($topic['title'], ['Topic finder', 'Edition and source notes'], true)) continue;
            $tokens = aiCoachSearchTerms($topic['title'] . ' ' . $topic['title'] . ' ' . $topic['text']);
            $documents[] = ['topic' => $topic, 'terms' => array_count_values($tokens), 'length' => count($tokens),
                'title' => array_flip(aiCoachSearchTerms($topic['title']))];
        }
    }
    $terms = array_unique(aiCoachSearchTerms($query));
    $count = count($documents);
    if ($count === 0) return [];
    $average = max(1, array_sum(array_column($documents, 'length')) / $count);
    $frequency = [];
    foreach ($terms as $term) $frequency[$term] = count(array_filter($documents, static fn($doc): bool => isset($doc['terms'][$term])));
    $ranked = [];
    foreach ($documents as $doc) {
        $topic = $doc['topic'];
        if (($role !== 'admin' && $topic['chapter'] === 'operator-appendix') || ($chapters !== [] && !in_array($topic['chapter'], $chapters, true))) continue;
        $score = $topic['id'] === $topicId ? 4.0 : 0.0;
        $matched = 0;
        foreach ($terms as $term) {
            $tf = $doc['terms'][$term] ?? 0;
            if ($tf === 0) continue;
            $matched++;
            $idf = log(1 + ($count - $frequency[$term] + .5) / ($frequency[$term] + .5));
            $score += $idf * ($tf * 2.2 / ($tf + 1.2 * (.25 + .75 * $doc['length'] / $average)));
            if (isset($doc['title'][$term])) $score += $idf * 1.5;
        }
        if ($score > 0 && ($matched > 0 || $topic['id'] === $topicId)) $ranked[] = ['topic' => $topic, 'score' => round($score, 3)];
    }
    usort($ranked, static fn($a, $b): int => $b['score'] <=> $a['score']);
    return array_slice($ranked, 0, 6);
}

function aiCoachImmediateReply(array $request, bool $includeWorkflows = true): ?array
{
    $support = aiCoachSupportReply($request);
    if ($support !== null) return $support;
    $q = aiCoachNormalize($request['question']);
    // Preserve grounded current-page explanations. Structural metadata alone
    // does not identify an unnamed button or field the user is pointing at.
    $element = '(?:this|that) (?:button|field|control|section|part(?: of (?:the |this )?(?:interface|screen|page))?)';
    if ((!aiCoachPageExplanation($request) || $request['page'] === 'other')
        && preg_match('/^(?:what does '.$element.' do|what is '.$element.'(?: for)?|what is the (?:purpose|function) of '.$element.'|explain '.$element.')(?: please)?$/', $q)) {
        return ['message'=>'Please tell me the button, field, or section label you mean. I receive page information, but I cannot see where you are pointing.',
            'question'=>'', 'sources'=>[], 'mode'=>'conversation', 'engine'=>'interface-clarification'];
    }
    if (preg_match('/^(thanks|thank you|thank you very much|thanks that helped|great thanks|got it|ok|okay|hello|hi|hey)$/', $q)) {
        return ['message' => preg_match('/^(hello|hi|hey)$/', $q) ? 'Hello! What would you like to learn about MOED?' : 'You’re welcome. I’m here when you need help with the next step.',
            'question' => '', 'sources' => [], 'mode' => 'conversation', 'engine' => 'conversation'];
    }
    $contextQuestion = aiCoachContextQuestion($request);
    if ($request['role'] === 'reviewer' && in_array(aiCoachIntentMode($request), ['perform','navigate'], true) && (aiCoachMutationIntent($contextQuestion) || aiCoachMatchWorkflow($contextQuestion) !== '')
        && !aiCoachPersonalCalendarIntent($contextQuestion)
        && !preg_match('/\b(calendar subscription|private link|theme|my password|my profile)\b/', $q)
        && !(preg_match('/\b(change|update) (my |own |the )?password\b/', $q) && !preg_match('/\b(another|other|user|someone)\b/', $q))) {
        return ['message' => 'Your current role is read-only. You can view the available records, but an editor or administrator must make this change. I can explain the process without directing you to controls your role cannot use.',
            'question' => '', 'sources' => aiCoachSources(array_values(array_intersect_key(aiCoachManualTopics(), array_flip(['manual-topic-roles-roles-and-access'])))),
            'mode' => 'guide', 'engine' => 'verified-permissions'];
    }
    if (preg_match('/\b(invoice|invoices)\b/', $q) && preg_match('/\b(create|generate|send|produce|make)\b/', $q)) {
        return ['message' => 'MOED does not have a documented invoice-generation tool. Financials records event payments, expenses, and closeout information; it does not create an invoice for you.',
            'question' => '', 'sources' => aiCoachSources(aiCoachRetrieve('financial closeout payments expenses')), 'mode' => 'guide', 'engine' => 'verified-capabilities'];
    }
    if (preg_match('/\b(closeout|close out|financial reminder)\b/', $q)
        && preg_match('/\b(remove|archive|delete|disable|bypass|skip|turn off|stop|do not want|don t want)\b/', $q)) {
        return ['message' => 'The built-in financial closeout reminder is required. It cannot be removed or archived to bypass closeout. Are you trying to complete a closeout, or change an optional Standard Event Task?',
            'question' => '', 'sources' => aiCoachSources(aiCoachRetrieve('financial closeout reminder required')), 'mode' => 'guide', 'engine' => 'verified-capabilities'];
    }
    if (preg_match('/^(what (time|date) is it|what is (the time|today s date)|what day is it)( now| today)?$/', $q)) {
        $zone = function_exists('applicationTimezone') ? applicationTimezone() : new DateTimeZone('America/Chicago');
        $now = new DateTimeImmutable('now', $zone);
        return ['message'=>'MOED’s configured local time is '.$now->format('g:i A T').' on '.$now->format('F j, Y').'.','question'=>'','sources'=>[],'mode'=>'conversation','engine'=>'application-clock'];
    }
    if (!$includeWorkflows) return null;
    if (preg_match('/\bevents?\b/', $q) && preg_match('/\bengagements?\b/', $q) && preg_match('/\b(different|difference|same)\b/', $q)) {
        return ['message'=>'In MOED, “event” and “engagement” refer to the same event record. An engagement belongs to an organization and can contain presentations, contacts, tasks, and planning details. A presentation is one session within that engagement.',
            'question'=>'','sources'=>aiCoachSources([aiCoachManualTopics()['manual-topic-orientation-the-record-model-at-a-glance']]), 'mode'=>'manual','engine'=>'verified-concept'];
    }
    if (preg_match('/\b(powerpoint|pptx?|slidedeck|slide deck)\b/', $q) && preg_match('/\b(size|limit|mb|megabytes?)\b/', $q)) {
        $comparison = '';
        if (preg_match('/\b(\d+(?:\.\d+)?)\s*(?:mb|megabytes?)\b/', $q, $size)) $comparison = ' A ' . $size[1] . ' MB file ' . ((float)$size[1]<=500 ? 'is within' : 'exceeds') . ' that size limit.';
        return ['message'=>'The limit is 500 MB per PowerPoint (.ppt or .pptx) file, attached to an individual presentation.' . $comparison . ' Select the file for the intended presentation, then use Save Changes. Speaker-notes PDFs have a separate 100 MB limit.',
            'question'=>'','sources'=>aiCoachSources(aiCoachRetrieve('PowerPoint 500 MB')), 'mode'=>'manual','engine'=>'verified-file-limits'];
    }
    if (preg_match('/^(can i |how do i |how can i )?(add|write|record)( some| a)? notes?$/', $q)) {
        return ['message'=>'MOED has task Notes, Chron Log entries, and speaker-notes PDFs on presentations. Which kind of note do you want to add?', 'question'=>'', 'sources'=>[], 'mode'=>'conversation', 'engine'=>'intent-clarification'];
    }
    if (!aiCoachCreationIntent($q) && preg_match('/\b(booked|agreed)\b/', $q) && preg_match('/\b(visit|conference|event)\b/', $q) && preg_match('/\b(schedule|system|enter|add|record|start)\b/', $q)) {
        return ['message'=>'If this already has a Booking Pipeline inquiry, open that inquiry and select Convert to Engagement after its organization, title, and preferred dates are ready. Otherwise, open Engagements and select + New Engagement, then complete the event form. Archiving an inquiry is not a step for putting a visit on the schedule.',
            'question'=>'','sources'=>aiCoachSources(array_values(array_intersect_key(aiCoachManualTopics(),array_flip(['manual-topic-booking-pipeline-inquiry-first-engagement-after-booking','manual-topic-engagements-create-or-edit-an-engagement'])))), 'mode'=>'guide','engine'=>'verified-booking-path'];
    }
    if (aiCoachCompoundGoal($contextQuestion)) return null;
    $formRule = aiCoachFormRuleReply($request);
    if ($formRule !== null) return $formRule;
    if (aiCoachIntentMode($request) === 'perform' && $request['page'] === 'edit_task.php' && !preg_match('/\b(stop waiting|no longer waiting|finish|complete)\b/', $q)
        && preg_match('/\b(wait for|blocked by|waiting for)\b/', $q)) return aiCoachWorkflowReply('waiting',$request);
    if (!str_starts_with($q, 'why ') && preg_match('/\b(task|work)\b/', $q) && preg_match('/\b(cannot|can t|unable to)\s+(?:finish|complete)\b/', $q)
        && preg_match('/\b(until|reply|replies|response|decision)\b/', $q)) return aiCoachWorkflowReply('waiting',$request);
    if ($request['page'] === 'edit_engagement.php' && preg_match('/\b(chosen|selected) (the |a )?file\b/', $q)
        && preg_match('/\b(next|now|click|save)\b/', $q)) {
        return ['message'=>'If you selected the intended file for the correct presentation, select Save Changes in the bottom bar. Selection alone has not saved the file. Check MOED’s save confirmation and the presentation’s saved filename afterward.', 'question'=>'',
            'sources'=>aiCoachSources([aiCoachManualTopics()['manual-topic-engagements-save-a-pending-presentation-file-change']]), 'mode'=>'guide','engine'=>'verified-file-save'];
    }
    if ($request['page'] === 'view_engagement.php' && preg_match('/\b(next|can t see|cannot see|cannot find|can t find|where is)\b/', $q)
        && preg_match('/\b(presentations|speaker notes|powerpoint)\b/', aiCoachNormalize($contextQuestion))) {
        $selected = ($request['ui']['active_tab'] ?? '') === 'presentations' || preg_match('/\b(selected|opened) the presentations tab\b/', $q);
        if ($selected && preg_match('/\b(cannot find|can t find|cannot see|can t see)\b/', $q)
            && !in_array('edit-presentations', $request['ui']['visible_controls'] ?? [], true)) {
            return ['message'=>'Edit Presentations normally appears inside the Presentations tab. Your reported page state does not show that control, so I cannot guide you to click it. Use “i can’t see that control” on the walkthrough to report the missing control. I cannot determine the cause from the available page state.',
                'question'=>'', 'sources'=>aiCoachSources([aiCoachManualTopics()['manual-topic-engagements-pdf-speaker-notes']]), 'mode'=>'guide','engine'=>'verified-missing-control'];
        }
        return ['message'=>$selected ? 'Now select Edit Presentations. In the editor, find the intended presentation before choosing its PDF or PPT file. File changes are saved with Save Changes in the bottom bar.' : 'Select the Presentations tab first. Edit Presentations is inside that tab; it is not an Activity-tab control.',
            'question'=>'','sources'=>aiCoachSources([aiCoachManualTopics()['manual-topic-engagements-pdf-speaker-notes']]),'mode'=>'guide','engine'=>'verified-tab-path'];
    }

    $workflow = in_array(aiCoachIntentMode($request), ['explain','ambiguous','troubleshoot'], true) && $q !== 'understand waiting tasks' ? '' : aiCoachMatchWorkflow($contextQuestion);
    if ($workflow === 'attachments') return ['message' => 'Speaker-notes PDFs and PowerPoint files are separate attachments on each presentation. Which one would you like to work on first?',
        'question' => '', 'sources' => aiCoachSources(aiCoachRetrieve('PDF Speaker Notes PPT Slidedeck')), 'mode' => 'guide', 'workflow_options' => ['notes', 'presentation']];
    if ($workflow !== '') return aiCoachWorkflowReply($workflow, $request);
    $procedure = aiCoachProcedureReply($request);
    if ($procedure !== null) return $procedure;
    $step = aiCoachSteps()[$request['step']] ?? null;
    if ($step !== null && preg_match('/^(done|next|continue|show me|help|what now|what next|what do i do next|repeat|explain this step)$/', $q)) {
        $source = aiCoachManualTopics()[$step['source']] ?? null;
        return ['message' => $step['message'], 'question' => '', 'sources' => $source ? aiCoachSources([$source]) : [], 'mode' => 'guide', 'engine' => 'verified-step'];
    }
    return null;
}

/** Source-checked procedures supply exact actions without another model call. */
function aiCoachProcedures(): array
{
    static $procedures = null;
    if ($procedures !== null) return $procedures;
    $catalog = json_decode((string)file_get_contents(__DIR__.'/data/ai-coach-procedures.json'), true, 32, JSON_THROW_ON_ERROR);
    $procedures = [];
    foreach ($catalog['procedures'] as $procedure) {
        if (!isset(aiCoachManualTopics()[$procedure['topic']])) continue;
        foreach ($procedure['source_files'] as $file=>$hash) {
            if (!is_file(__DIR__.'/'.$file) || hash_file('sha256',__DIR__.'/'.$file)!==$hash) continue 2;
        }
        $procedures[] = $procedure;
    }
    return $procedures;
}

function aiCoachProcedureReply(array $request): ?array
{
    $q = aiCoachNormalize(aiCoachContextQuestion($request));
    if (aiCoachCompoundGoal($q)) return null;
    if (!in_array(aiCoachIntentMode($request), ['perform','navigate'], true)) return null;
    if (preg_match('/\b(do not|don t|not want|never|cannot|can t|unable)\b/', $q)) return null;
    $matches = [];
    foreach (aiCoachProcedures() as $procedure) {
        if (!in_array($request['role'],$procedure['roles'],true)) continue;
        foreach ($procedure['patterns'] as $pattern) if (!preg_match('~'.$pattern.'~', $q)) continue 2;
        // Adding existing related records is different from creating those records.
        if (in_array($procedure['id'],['create-contact','create-organization'],true) && preg_match('/\b(event|engagement|existing)\b/',$q)) continue;
        if ($procedure['id']==='edit-event-dates' && preg_match('/\b(task|tasks|due|follow up|presentation)\b/',$q)) continue;
        if ($procedure['id']==='change-own-password' && preg_match('/\b(another|other user|someone)\b/',$q)) continue;
        $matches[] = $procedure;
    }
    // Conflicting requested operations need interpretation instead of the first keyword hit.
    if (count($matches)===1) {
        $procedure = $matches[0];
        return aiCoachProcedureAnswer($procedure, $request);
    }
    return null;
}
