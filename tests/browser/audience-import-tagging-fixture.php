<?php
declare(strict_types=1);
require __DIR__.'/../../src/functions.php';
$h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$names=['2023 December Donors','2024 CLIPBOARD PROJECT','2024 Donor','2025 Donor','B2Z Contacts','B2Z Hosts','devotions','donor','Gospel','Israel Service Interest','Israel Tour interest','news','prayer letter','prayer team','SiMM launch donor','Skin in the Game','SMS','subsplash donor','WPForms'];
$tags=array_map(static fn($name,$i)=>['id'=>$i+1,'name'=>$name],$names,array_keys($names));
$options=['1-Minute Daily Devotional','Interest in next Tour to Israel','Interest in service trip to Israel','News Alerts','Prayer Letter','SMS messages'];
$groups=[['id'=>1,'name'=>'Self-Subscribed Content','options'=>array_map(static fn($name,$i)=>['id'=>$i+1,'name'=>$name],$options,array_keys($options))]];
$params=['tag_ids'=>[8,12,13,18],'group_ids'=>[]];
if(($argv[1]??'')==='empty'){$tags=$groups=[];$params=['tag_ids'=>[],'group_ids'=>[]];}
echo '<!doctype html><html lang="en">';renderPageHead('Import connections — layout preview',['styles'=>['assets/css/pages/audience.min.css'],'scripts'=>['assets/js/audience-import.min.js']]);
?>
<body class="audience-app">
<style>
.mock-import-header{border-bottom:1px solid var(--audience-border);padding:26px 40px;background:var(--audience-surface)}
.mock-import-header>div{max-width:1100px;margin:auto}.mock-import-header small{display:block;color:var(--audience-muted);margin-bottom:7px;font-size:13px}.mock-import-header h1{margin:0;font-size:26px;line-height:1.3}
.mock-import-main{max-width:1180px;margin:auto;padding:30px 40px 36px}.mock-import-main .audience-import-steps{margin-bottom:30px;font-size:14px}.mock-import-main .form-actions{margin-top:26px;gap:12px}.mock-import-main .form-actions .primary{padding:10px 22px;min-height:42px}.mock-import-main .form-actions .button{min-height:42px;padding-inline:18px}.mock-import-main .form-actions .text-link{margin-left:4px;font-size:14px}
@media(max-width:600px){.mock-import-header{padding:20px}.mock-import-main{padding:24px 18px}.mock-import-main .audience-import-steps{gap:20px}.mock-import-header h1{font-size:23px}}
</style>
<header class="mock-import-header"><div><small>MessageMonkey / Audience</small><h1>Import Connections</h1></div></header>
<main class="mock-import-main">
<nav class="audience-import-steps" aria-label="Import progress"><?php foreach(['Choose Method','Organize','Tag','Match','Subscribe','Complete']as$step): ?><span<?= $step==='Tag'?' aria-current="step"':'' ?>><?= $step ?></span><?php endforeach; ?></nav>
<form class="audience-import-wizard">
<?php require __DIR__.'/../../src/templates/audience/import-tagging.php'; ?>
<div class="form-actions"><button class="primary">Continue</button><a href="#" class="button">Back</a><a href="#" class="text-link">Finish later</a></div>
</form>
</main>
<script>document.querySelector('form').addEventListener('submit',event=>{event.preventDefault();window.fixtureSubmittedData=Array.from(new FormData(event.target));});</script>
</body></html>
