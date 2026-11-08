<?php
foreach(['starters'=>'Starter templates','saved'=>'Saved templates']as$templateSource=>$templateHeading):
    $templateEntries=$templateChoices[$templateSource]??[];
?>
<h3 id="design-<?= $templateSource ?>-title"><?= $templateHeading ?></h3>
<div id="design-<?= $templateSource ?>" class="designer-template-options" aria-labelledby="design-<?= $templateSource ?>-title">
<?php foreach($templateEntries as$templateKey=>$templateName): ?>
<article class="template-card designer-template-card">
    <div class="template-thumbnail"></div>
    <button type="button" class="designer-template-select" data-design-template-source="<?= $templateSource ?>" data-design-template-key="<?= $h($templateKey) ?>" aria-label="Use template: <?= $h($templateName) ?>"><span class="designer-template-name"><?= $h($templateName) ?></span><span class="designer-template-use" aria-hidden="true">Use template</span></button>
</article>
<?php endforeach; ?>
<?php if(!$templateEntries): ?><p>No saved templates yet.</p><?php endif; ?>
</div>
<?php endforeach; ?>
