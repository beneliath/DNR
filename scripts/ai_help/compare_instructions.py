#!/usr/bin/env python3
"""Paired, thinking-off synthetic replays. Never deploys or reads business records."""
import argparse
import copy
import hashlib
import json
import os
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[2]
LOCAL = r'''
require getenv('DNR_QWEN_TEST_SOURCE').'/ai_coach_helpers.php';
putenv('DNR_AI_COACH_REASONING=0');
$job=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
$case=$job['case'];
if ($job['operation']==='prepare') {
    $request=aiCoachValidateRequest(['question'=>$case['question'],'page'=>$case['page'],
        'history'=>$case['history']??[]], 'editor');
    $topics=aiCoachRelevantTopics($request,aiCoachRankEvidence(aiCoachContextQuestion($request),'','editor'));
    $result=$case+['request'=>$request,'topics'=>$topics,'payload'=>aiCoachPayload($request,$topics)];
} elseif ($job['operation']==='payload') {
    $result=aiCoachPayload($case['request'],$case['topics']);
} elseif ($job['operation']==='immediate') {
    $result=aiCoachImmediateReply($case['request']);
} else {
    try {
        $reply=aiCoachDecodeReply($job['raw'],$case['topics'],$case['request']);
        $result=['valid'=>true,'decoded'=>aiCoachRejectEcho($reply,$case['request']['question'])];
    } catch (Throwable $error) {
        $result=['valid'=>false,'error'=>$error->getMessage()];
    }
}
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
'''
REMOTE = r'''<?php
require '/var/www/html/ai_coach_helpers.php';
$job=json_decode(base64_decode('__JOB__'),true,64,JSON_THROW_ON_ERROR);
if ($job['operation']==='metadata') {
    $hashes=[];
    foreach (['ai_coach_helpers.php','ai_coach_guidance_helpers.php','ai_coach_intent_helpers.php'] as $name)
        $hashes[$name]=hash_file('sha256','/var/www/html/'.$name);
    $result=['model'=>getenv('DNR_AI_COACH_MODEL'),'reasoning'=>getenv('DNR_AI_COACH_REASONING'),'source_hashes'=>$hashes];
    foreach (['version','tags'] as $endpoint) {
        $handle=curl_init(rtrim(getenv('DNR_AI_COACH_URL'),'/').'/api/'.$endpoint);
        curl_setopt_array($handle,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,
            CURLOPT_HTTPHEADER=>['Host: localhost'],CURLOPT_PROXY=>'']);
        $data=json_decode((string)curl_exec($handle),true,32,JSON_THROW_ON_ERROR);
        $result[$endpoint]=$endpoint==='tags' ? array_values(array_filter($data['models'],
            static fn($model):bool=>$model['name']==='qwen3:8b')) : $data;
    }
} else {
    $start=microtime(true); $metrics=[];
    try {
        $raw=aiCoachModelCall(rtrim(getenv('DNR_AI_COACH_URL'),'/'),$job['payload'],$start+20,null,$metrics);
        $result=['raw'=>$raw];
    } catch (Throwable $error) {
        $result=['transport_error'=>$error instanceof AiCoachModelFailure ? $error->failureCode : get_class($error)];
    }
    $result+=['seconds'=>round(microtime(true)-$start,4),'metrics'=>$metrics];
}
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
'''


def local(source, operation, case, **extra):
    env = dict(os.environ, DNR_QWEN_TEST_SOURCE=str(source))
    result = subprocess.run(['php', '-r', LOCAL], env=env, input=json.dumps(
        dict(operation=operation, case=case, **extra)), text=True, capture_output=True, check=True)
    return json.loads(result.stdout)


def remote(worker, job):
    import base64
    code = REMOTE.replace('__JOB__', base64.b64encode(json.dumps(job).encode()).decode())
    result = subprocess.run(['docker', 'exec', '-i', worker, 'php'], input=code,
                            text=True, capture_output=True, timeout=30, check=True)
    return json.loads(result.stdout)


def digest(value):
    return hashlib.sha256(json.dumps(value, sort_keys=True, ensure_ascii=False).encode()).hexdigest()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--frozen', type=Path, required=True)
    parser.add_argument('--original-src', type=Path, required=True)
    parser.add_argument('--worker', required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--ids', nargs='+', help='Only replay these model cases; guards still run')
    args = parser.parse_args()
    if args.output.exists():
        parser.error('Refusing to overwrite results')
    frozen = json.loads(args.frozen.read_text())
    original, cleaned = args.original_src, ROOT / 'src'
    cases = [c for c in frozen['cases'] if c['immediate'] is None]
    cases.append(local(original, 'prepare', dict(id='diagnosis-missing-error', page='edit_task.php',
        question='Saving this task failed. Can you diagnose the cause? I have not provided the error text.')))
    clarification_cases = [
        dict(id='notes-after-clarification', page='view_engagement.php',
            question='A speaker-notes PDF for a presentation. How do I add it?', history=[
                dict(role='user', content='How do I add notes?'),
                dict(role='assistant', content='Do you mean task Notes, a Chron Log entry about an event, or a speaker-notes PDF for a presentation?')]),
        dict(id='diagnosis-after-clarification', page='edit_task.php',
            question='It just failed. I do not have the error text.', history=[
                dict(role='user', content='Saving my task failed. Can you diagnose the cause?'),
                dict(role='assistant', content='What exact error does MOED show when you save? Copy the message text and the field label it names, if any.')]),
        dict(id='diagnosis-error-provided', page='edit_task.php',
            question='The error says: Waiting on is required when Status is Waiting. How do I correct it?', history=[
                dict(role='user', content='Saving my task failed. Can you diagnose the cause?'),
                dict(role='assistant', content='What exact error does MOED show when you save? Copy the message text and the field label it names, if any.')]),
        dict(id='diagnosis-new-goal', page='edit_task.php',
            question='Different question: saving a task failed. I have not provided the error text. Can you diagnose the cause?', history=[
                dict(role='user', content='How do I add notes?'),
                dict(role='assistant', content='Do you mean task Notes, a Chron Log entry about an event, or a speaker-notes PDF for a presentation?')]),
        dict(id='diagnosis-cross-owner', page='edit_task.php',
            question='Saving the task still failed when changing status from Waiting. I do not have the error text.', history=[
                dict(role='user', content='Saving this task failed. Can you diagnose the cause?'),
                dict(role='assistant', content='What exact error appeared? Copy its text and any named field; if no message appeared, say so and describe what happened instead.')]),
    ]
    cases.extend(local(original, 'prepare', case) for case in clarification_cases)
    guards = [c for c in frozen['cases'] if c['immediate'] is not None]
    guards.append(local(original, 'prepare', dict(id='waiting-diagnosis-guard', page='edit_task.php',
        question='I cannot change the status of my task from Waiting. Saving failed.')))
    results = dict(frozen_sha256=hashlib.sha256(args.frozen.read_bytes()).hexdigest(),
                   runtime_before=remote(args.worker, dict(operation='metadata')), guards=[], replays=[])
    assert results['runtime_before']['model'] == 'qwen3:8b'
    assert results['runtime_before']['reasoning'] == '0'
    results['additional_cases'] = cases[10:]
    results['instructions'] = dict(original=cases[0]['payload']['messages'][0]['content'],
        cleaned=local(cleaned, 'payload', cases[0])['messages'][0]['content'])
    results['local_sources'] = {label: {name: hashlib.sha256((source / name).read_bytes()).hexdigest()
        for name in ['ai_coach_helpers.php', 'ai_coach_guidance_helpers.php', 'ai_coach_intent_helpers.php']}
        for label, source in [('original', original), ('cleaned', cleaned)]}
    for case in guards:
        results['guards'].append(dict(id=case['id'], question=case['request']['question'],
            original=local(original, 'immediate', case), cleaned=local(cleaned, 'immediate', case)))
    for index, case in enumerate(cases):
        if args.ids and case['id'] not in args.ids:
            continue
        old = case['payload']
        # Refuse a stale or unrelated baseline; keep the entire frozen user message and schema.
        assert old['messages'][0] == local(original, 'payload', case)['messages'][0]
        new = copy.deepcopy(old)
        new['messages'][0] = local(cleaned, 'payload', case)['messages'][0]
        assert old['model'] == 'qwen3:8b' and old['think'] is False
        assert old['options'] == dict(temperature=0, num_ctx=8192, num_predict=320)
        for mode, payload, source in ([('original', old, original), ('cleaned', new, cleaned)]
                                      if index % 2 == 0 else [('cleaned', new, cleaned), ('original', old, original)]):
            result = remote(args.worker, dict(operation='replay', payload=payload))
            raw = result.get('raw', {})
            result.update(id=case['id'], mode=mode, question=case['request']['question'],
                system=payload['messages'][0]['content'],
                system_sha256=digest(payload['messages'][0]), frozen_context_sha256=digest(payload['messages'][1:]),
                schema_sha256=digest(payload['format']), options=payload['options'], think=payload['think'])
            for label, decoder in [('original_decoder', original), ('cleaned_decoder', cleaned)]:
                result[label] = local(decoder, 'decode', case, raw=raw)
            results['replays'].append(result)
            args.output.write_text(json.dumps(results, indent=2, ensure_ascii=False)+'\n')
            print(json.dumps(dict(id=case['id'], mode=mode, seconds=result['seconds'],
                valid=result['cleaned_decoder']['valid'], done_reason=raw.get('done_reason'))), flush=True)
    results['runtime_after'] = remote(args.worker, dict(operation='metadata'))
    assert results['runtime_after'] == results['runtime_before']
    args.output.write_text(json.dumps(results, indent=2, ensure_ascii=False)+'\n')


if __name__ == '__main__':
    main()
