<?php
declare(strict_types=1);
require_once __DIR__.'/../src/ai_coach_helpers.php';
$cases=json_decode(file_get_contents(__DIR__.'/../scripts/ai_help/regression-cases.json'),true,32,JSON_THROW_ON_ERROR)['cases'];
foreach($cases as $case) {
    $request=aiCoachValidateRequest(['question'=>$case['question'],'page'=>$case['page']??'help.php','history'=>$case['history']??[],'step'=>$case['step']??'','ui'=>$case['ui']??[]],$case['role']);
    $reply=aiCoachImmediateReply($request);
    foreach(['workflow','engine','procedure'] as $key) if(isset($case['expected'][$key]) && ($reply[$key]??'')!==$case['expected'][$key]) throw new RuntimeException($case['id'].': unexpected '.$key);
    if ($reply !== null) {
        $text=mb_strtolower(($reply['message']??'').' '.($reply['question']??''));
        foreach($case['expected']['required_terms']??[] as $term) if(!str_contains($text,mb_strtolower($term))) throw new RuntimeException($case['id'].': missing '.$term);
        foreach($case['expected']['forbidden_terms']??[] as $term) if(str_contains($text,mb_strtolower($term))) throw new RuntimeException($case['id'].': forbidden '.$term);
    }
}
echo count($cases)." coach intent and permission regression cases passed.\n";
