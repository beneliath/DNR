<?php
$templatePreviewIdentity=audienceBrandIdentity(audienceJson($audience['settings_json']??'{}'),$audience['name']??'');
foreach(['starters'=>'Starter templates','saved'=>'Saved templates']as$templateSource=>$templateHeading):
    $templateEntries=$designerConfig[$templateSource];
?>
<h3 id="design-<?= $templateSource ?>-title"><?= $templateHeading ?></h3>
<div id="design-<?= $templateSource ?>" class="designer-template-options" aria-labelledby="design-<?= $templateSource ?>-title">
<?php foreach($templateEntries as$templateKey=>$templateEntry): ?>
<article class="template-card designer-template-card">
    <div class="template-thumbnail"><iframe title="Preview <?= $h($templateEntry['name']) ?>" tabindex="-1" inert sandbox="allow-same-origin" referrerpolicy="no-referrer" loading="lazy" data-template-preview data-email-preview="<?= $h(audienceDesignPreviewHtml($templateEntry['design'],contentSecurityPolicyNonce(),null,false,$templatePreviewIdentity)) ?>"></iframe></div>
    <button type="button" class="designer-template-select" data-design-template-source="<?= $templateSource ?>" data-design-template-key="<?= $h($templateKey) ?>" aria-label="Use template: <?= $h($templateEntry['name']) ?>"><span class="designer-template-name"><?= $h($templateEntry['name']) ?></span><span class="designer-template-use" aria-hidden="true">Use template</span></button>
</article>
<?php endforeach; ?>
<?php if(!$templateEntries): ?><p>No saved templates yet.</p><?php endif; ?>
</div>
<?php endforeach; ?>
