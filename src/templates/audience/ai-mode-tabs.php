<nav class="audience-tabs" aria-label="AI assistance">
<a href="<?= $h($url(['view'=>'assistant','mode'=>'analytics'])) ?>"<?= $aiMode==='analytics'?' aria-current="page"':'' ?>>Analytics AI</a>
<?php if($canManage): ?><a href="<?= $h($url(['view'=>'assistant','mode'=>'content'])) ?>"<?= $aiMode==='content'?' aria-current="page"':'' ?>>Write with AI</a><?php endif; ?>
</nav>
