<?php
declare(strict_types=1);

function aiCoachImprovementCategories(): array
{
    return ['routing' => 'Wrong workflow', 'ui_state' => 'Wrong control or page state', 'permissions' => 'Role or permissions',
        'retrieval' => 'Relevant manual content was missed', 'manual_gap' => 'Manual content needs clarification',
        'instruction' => 'Answer contradicted the evidence', 'reliability' => 'Timing or connection failure'];
}

function aiCoachTriageImprovement(array $request): string
{
    $answer = json_decode($request['response_json'] ?? '{}', true) ?: [];
    if ($request['user_feedback'] === 'control_missing') return 'ui_state';
    if (in_array($answer['failure']['code'] ?? '', ['connection','timeout','service_error'], true)) return 'reliability';
    if ($request['user_role'] === 'reviewer' && aiCoachMutationIntent($request['question'])) return 'permissions';
    if (!empty($answer['workflow'])) return 'routing';
    if (empty($answer['sources'])) return 'retrieval';
    return 'instruction';
}

function aiCoachSaveImprovement(mysqli $conn, array $input, int $admin): int
{
    $id = filter_var($input['id'] ?? 0, FILTER_VALIDATE_INT);
    $version = filter_var($input['version'] ?? 0, FILTER_VALIDATE_INT);
    if ($id === false || $id < 0 || $version === false || $version < 0) throw new InvalidArgumentException('Reload this improvement before saving.');
    foreach (['question','expected_guidance','user_role','page_path','guided_step','expected_workflow','category','status'] as $key) {
        if (isset($input[$key]) && !is_string($input[$key])) throw new InvalidArgumentException('Use plain text fields.');
    }
    $question = trim((string) ($input['question'] ?? ''));
    $guidance = trim((string) ($input['expected_guidance'] ?? ''));
    $role = $input['user_role'] ?? 'editor'; $page = $input['page_path'] ?? 'help.php'; $step = $input['guided_step'] ?? '';
    $workflow = $input['expected_workflow'] ?? '';
    $category = $input['category'] ?? '';
    $status = ($input['status'] ?? '') === 'approved' ? 'approved' : 'draft';
    if ($question === '' || mb_strlen($question) > 1200 || $guidance === '' || mb_strlen($guidance) > 2000
        || !in_array($role, ['admin','editor','reviewer'], true) || !isset(aiCoachPages()[$page])
        || !isset(aiCoachImprovementCategories()[$category]) || ($step !== '' && !isset(aiCoachSteps()[$step]))
        || ($workflow !== '' && !isset(aiCoachWorkflows()[$workflow]))) throw new InvalidArgumentException('Provide a question, expected guidance, and valid role, page, category, and workflow.');
    $lists = [];
    foreach (['topic_ids','required_terms','forbidden_terms'] as $key) {
        $raw = $input[$key] ?? '';
        if (!is_string($raw) || strlen($raw) > 3000) throw new InvalidArgumentException('Keep each checklist under 3,000 characters.');
        $lists[$key] = array_values(array_unique(array_filter(array_map('trim', explode("\n", $raw)))));
        if (count($lists[$key]) > 16) throw new InvalidArgumentException('Use at most 16 entries per checklist.');
    }
    foreach ($lists['topic_ids'] as $topic) {
        $entry = aiCoachManualTopics()[$topic] ?? null;
        if (!$entry || ($role !== 'admin' && $entry['chapter'] === 'operator-appendix')) throw new InvalidArgumentException('Choose current Comprehensive Manual topics available to this role.');
    }
    if ($status === 'approved' && (empty($input['verified']) || empty($input['redacted']) || $lists['topic_ids'] === []
        || ($lists['required_terms'] === [] && $workflow === '') || preg_match('/https?:\/\/|<[^>]+>/', $guidance))) {
        throw new InvalidArgumentException('Approval requires verified manual sources, a redaction check, and an expected workflow or answer checks. Keep guidance as plain text without links.');
    }
    if ($status === 'approved') {
        foreach ($lists['required_terms'] as $term) if (!str_contains(mb_strtolower($guidance), mb_strtolower($term))) throw new InvalidArgumentException('Expected guidance must pass its required phrase checks.');
        foreach ($lists['forbidden_terms'] as $term) if (str_contains(mb_strtolower($guidance), mb_strtolower($term))) throw new InvalidArgumentException('Expected guidance contains a forbidden phrase.');
    }
    $values = [$category,$status,$question,$role,$page,$step,$guidance,$workflow,
        json_encode($lists['topic_ids'], JSON_THROW_ON_ERROR),json_encode($lists['required_terms'], JSON_THROW_ON_ERROR),json_encode($lists['forbidden_terms'], JSON_THROW_ON_ERROR),
        $status === 'approved' ? $admin : null, aiCoachGuidanceRevision()];
    if ($id > 0) {
        $conn->execute_query('UPDATE ai_coach_improvements SET category=?,status=?,question=?,user_role=?,page_path=?,guided_step=?,expected_guidance=?,expected_workflow=?,
            topic_ids=?,required_terms=?,forbidden_terms=?,approved_by=?,guidance_revision=?,version=version+1,updated_at=UTC_TIMESTAMP(6) WHERE id=? AND version=?', [...$values,$id,$version]);
        if ($conn->affected_rows !== 1) throw new RuntimeException('This improvement changed. Reload it before saving.');
    } else {
        $source = filter_var($input['source_request_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $conn->execute_query('INSERT INTO ai_coach_improvements(category,status,question,user_role,page_path,guided_step,expected_guidance,expected_workflow,
            topic_ids,required_terms,forbidden_terms,approved_by,guidance_revision,source_request_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [...$values,$source]);
        $id = (int) $conn->insert_id;
    }
    return $id;
}

/** Export explicit, reviewed cases, never operational conversations or user identities. */
function aiCoachExportImprovements(mysqli $conn): array
{
    $rows = $conn->query("SELECT * FROM ai_coach_improvements WHERE status='approved' ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    return ['schema_version' => 1, 'manual_sha256' => aiCoachKnowledge()['document']['sha256'] ?? '',
        'guidance_revision' => aiCoachGuidanceRevision(), 'cases' => array_map(static fn($row): array => [
            'id' => 'review-' . $row['id'], 'question' => $row['question'], 'role' => $row['user_role'], 'page' => $row['page_path'], 'step' => $row['guided_step'],
            'category' => $row['category'], 'reference_answer' => $row['expected_guidance'], 'approved_revision' => $row['guidance_revision'],
            'expected' => ['workflow' => $row['expected_workflow'], 'topics' => json_decode($row['topic_ids'], true),
                'required_terms' => json_decode($row['required_terms'], true), 'forbidden_terms' => json_decode($row['forbidden_terms'], true)],
        ], $rows)];
}

/** Exact, role/page/step-scoped approved explanations invalidate when guidance changes. */
function aiCoachApprovedReply(mysqli $conn, array $request): ?array
{
    $rows = $conn->execute_query("SELECT * FROM ai_coach_improvements WHERE status='approved' AND guidance_revision=?
        AND user_role=? AND page_path=? AND guided_step=? AND question=? ORDER BY id DESC LIMIT 1",
        [aiCoachGuidanceRevision(),$request['role'],$request['page'],$request['step'],$request['question']])->fetch_all(MYSQLI_ASSOC);
    if (!$rows) return null;
    $row = $rows[0];
    if ($row['expected_workflow'] !== '') return aiCoachWorkflowReply($row['expected_workflow'], $request);
    // The caller applies server-owned role and critical-action guards before approved guidance.
    $topics = array_values(array_intersect_key(aiCoachManualTopics(), array_flip(json_decode($row['topic_ids'], true))));
    return ['message' => $row['expected_guidance'], 'question' => '', 'sources' => aiCoachSources(array_slice($topics,0,4)),
        'mode' => 'manual', 'engine' => 'approved-guidance', 'telemetry' => ['route' => 'approved-guidance', 'model_calls' => 0, 'case_id' => (int) $row['id']]];
}
