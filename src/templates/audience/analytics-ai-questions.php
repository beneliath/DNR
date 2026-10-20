<nav class="analytics-ai-questions" aria-label="Quick questions for Analytics AI">
<?php foreach(['Summarize recent campaign performance'=>'email','What is my campaign performance by geo and device?'=>'email','How has my contact list changed?'=>'audience']as$question=>$dataset): ?>
    <a class="audience-button analytics-ai-question" href="<?= $h('audience.php?'.http_build_query(array_replace($analyticsAiQuery,['dataset'=>$dataset,'prompt'=>$question]))) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m14 7 2.5 5.5L22 15l-5.5 2.5L14 23l-2.5-5.5L6 15l5.5-2.5L14 7ZM5 1l1.3 2.7L9 5 6.3 6.3 5 9 3.7 6.3 1 5l2.7-1.3L5 1Z"/></svg><span><?= $h($question) ?></span></a>
<?php endforeach; ?>
</nav>
