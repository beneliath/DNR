<?php
// Tag actions are direct icons; segments retain their existing action menu.
$menuId=$menuKind.'-actions-'.(int)$menuModel['id'];
$menuJson=$h(json_encode($menuModel,JSON_THROW_ON_ERROR));
?>
<?php if($menuKind==='tag'): ?>
<div class="audience-tag-actions">
    <a class="action-icon-button" href="<?= $h($url(['tags'=>[$menuModel['id']]])) ?>" aria-label="View connections tagged <?= $h($menuModel['name']) ?>" data-tooltip="View connections"><?= actionIconSvg('view') ?></a>
    <button type="button" class="action-icon-button" data-tag-edit="<?= $menuJson ?>" aria-label="Rename tag <?= $h($menuModel['name']) ?>" data-tooltip="Rename tag"><?= actionIconSvg('rename') ?></button>
    <?php if($canDelete): ?><form method="post" data-tag-delete-form data-admin-unlock-required data-confirm="Delete this tag? Connections will be kept, but this tag will be removed from them."><?= $csrf ?><?php audienceInputs($baseForm+['action'=>'delete_tag','id'=>$menuModel['id'],'version'=>$menuModel['version']]); ?><button type="submit" class="action-icon-button" data-admin-unlock-required aria-label="Delete tag <?= $h($menuModel['name']) ?>" data-tooltip="Delete tag (Admin Unlock required)"><?= actionIconSvg('delete') ?></button></form><?php endif; ?>
    <?php if($canExport): ?><form method="post"><?= $csrf ?><?php audienceInputs($baseForm+['action'=>'queue_export','selection_scope'=>'all','tags'=>[$menuModel['id']]]); ?><button type="submit" class="action-icon-button" aria-label="Export connections tagged <?= $h($menuModel['name']) ?> as CSV" data-tooltip="Export as CSV"><?= actionIconSvg('export-csv') ?></button></form><?php endif; ?>
    <?php foreach(['regular'=>['Send regular email','create-email'],'plain-text'=>['Send plain-text email','plain-text-email']]as$emailType=>[$label,$icon]): ?><a class="action-icon-button" href="<?= $h($url(['view'=>'campaigns','create'=>1,'target'=>'tag:'.$menuModel['id'],'email_type'=>$emailType])) ?>" aria-label="Create a <?= $emailType==='plain-text'?'plain-text':'regular' ?> email draft for tag <?= $h($menuModel['name']) ?>" data-tooltip="<?= $label ?>"><?= actionIconSvg($icon) ?></a><?php endforeach; ?>
</div>
<?php else: ?>
<div class="audience-split-button">
    <button type="button" data-segment-edit="<?= $menuJson ?>">Edit</button>
    <button type="button" class="audience-menu-toggle" popovertarget="<?= $menuId ?>" aria-haspopup="menu" aria-label="Actions for <?= $h($menuModel['name']) ?>"><?php audienceNavIcon('chevron'); ?></button>
</div>
<div id="<?= $menuId ?>" class="audience-action-menu with-icons" popover="auto" role="menu" aria-label="<?= $h($menuModel['name']) ?> actions">
    <?php foreach(['mail'=>'Send email','text'=>'Send plain-text email']as$icon=>$label): ?><a role="menuitem" href="<?= $h($url(['view'=>'campaigns','create'=>1,'target'=>'segment:'.$menuModel['id'],'email_type'=>$icon==='text'?'plain-text':'regular'])) ?>" data-audience-tooltip="Create a draft for this segment. Sending becomes available after delivery setup."><?php audienceNavIcon($icon); ?><span><?= $label ?></span></a><?php endforeach; ?>
    <?php if($canExport): ?><form method="post"><?= $csrf ?><?php audienceInputs($baseForm+['action'=>'queue_export','selection_scope'=>'all','rules'=>json_encode($menuModel['rules'],JSON_THROW_ON_ERROR)]); ?><button role="menuitem"><?php audienceNavIcon('export'); ?>Export as CSV</button></form><?php endif; ?>
    <hr><button type="button" role="menuitem" data-segment-rename="<?= $menuJson ?>"><?php audienceNavIcon('rename'); ?>Rename Segment</button>
    <button type="button" role="menuitem" data-segment-copy="<?= $menuJson ?>"><?php audienceNavIcon('copy'); ?>Replicate Segment</button>
    <hr><form method="post" data-confirm="Delete this saved segment? Its connections will be kept."><?= $csrf ?><?php audienceInputs($baseForm+['action'=>'delete_segment','id'=>$menuModel['id'],'version'=>$menuModel['version']]); ?><button role="menuitem"><?php audienceNavIcon('trash'); ?>Delete</button></form>
</div>
<?php endif; ?>
