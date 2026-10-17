<?php
// Shared split-button menus; supported email actions open a targeted draft creation screen.
$menuId=$menuKind.'-actions-'.(int)$menuModel['id'];
$menuJson=$h(json_encode($menuModel,JSON_THROW_ON_ERROR));
?>
<div class="audience-split-button">
    <?php if($menuKind==='tag'): ?><a class="button" href="<?= $h($url(['tags'=>[$menuModel['id']]])) ?>">View</a><?php else: ?><button type="button" data-segment-edit="<?= $menuJson ?>">Edit</button><?php endif; ?>
    <button type="button" class="audience-menu-toggle" popovertarget="<?= $menuId ?>" aria-haspopup="menu" aria-label="Actions for <?= $h($menuModel['name']) ?>"><?php audienceNavIcon('chevron'); ?></button>
</div>
<div id="<?= $menuId ?>" class="audience-action-menu <?= $menuKind==='segment'?'with-icons':'' ?>" popover="auto" role="menu" aria-label="<?= $h($menuModel['name']) ?> actions">
    <?php if($menuKind==='tag'): ?>
        <button type="button" role="menuitem" data-tag-edit="<?= $menuJson ?>">Rename</button>
        <form method="post" data-confirm="Delete this tag? Connections will be kept, but this tag will be removed from them."><?= $csrf ?><?php audienceInputs($baseForm+['action'=>'delete_tag','id'=>$menuModel['id'],'version'=>$menuModel['version']]); ?><button role="menuitem">Delete</button></form>
    <?php else: ?>
        <?php foreach(['mail'=>'Send email','text'=>'Send plain-text email','testing'=>'Send multivariate testing email']as$icon=>$label): ?><?php if($icon!=='testing'): ?><a role="menuitem" href="<?= $h($url(['view'=>'campaigns','create'=>1,'target'=>'segment:'.$menuModel['id'],'email_type'=>$icon==='text'?'plain-text':'regular'])) ?>" data-audience-tooltip="Create a draft for this segment. Sending becomes available after delivery setup."><?php audienceNavIcon($icon); ?><span><?= $label ?></span></a><?php else: ?><span role="menuitem" tabindex="-1" aria-disabled="true" data-audience-tooltip="Multivariate email creation is coming in a later stage."><?php audienceNavIcon($icon); ?><span><?= $label ?></span></span><?php endif; endforeach; ?>
    <?php endif; ?>
    <?php if($canExport): ?><form method="post"><?= $csrf ?><?php audienceInputs($baseForm+['action'=>'queue_export','selection_scope'=>'all']+($menuKind==='tag'?['tags'=>[$menuModel['id']]]:['rules'=>json_encode($menuModel['rules'],JSON_THROW_ON_ERROR)])); ?><button role="menuitem"><?php if($menuKind==='segment')audienceNavIcon('export'); ?>Export as CSV</button></form><?php endif; ?>
    <?php if($menuKind==='tag'): ?>
        <?php foreach(['Send regular email','Send multivariate testing email','Send plain-text email','Send RSS email']as$label): ?><?php if(in_array($label,['Send regular email','Send plain-text email'],true)): ?><a role="menuitem" href="<?= $h($url(['view'=>'campaigns','create'=>1,'target'=>'tag:'.$menuModel['id'],'email_type'=>$label==='Send plain-text email'?'plain-text':'regular'])) ?>" data-audience-tooltip="Create a draft for tagged Connections. Sending becomes available after delivery setup."><?= $label ?></a><?php else: ?><span role="menuitem" tabindex="-1" aria-disabled="true" data-audience-tooltip="This email type will be available in a later stage."><?= $label ?></span><?php endif; endforeach; ?>
    <?php else: ?>
        <hr><button type="button" role="menuitem" data-segment-rename="<?= $menuJson ?>"><?php audienceNavIcon('rename'); ?>Rename Segment</button>
        <button type="button" role="menuitem" data-segment-copy="<?= $menuJson ?>"><?php audienceNavIcon('copy'); ?>Replicate Segment</button>
        <hr><form method="post" data-confirm="Delete this saved segment? Its connections will be kept."><?= $csrf ?><?php audienceInputs($baseForm+['action'=>'delete_segment','id'=>$menuModel['id'],'version'=>$menuModel['version']]); ?><button role="menuitem"><?php audienceNavIcon('trash'); ?>Delete</button></form>
    <?php endif; ?>
</div>
