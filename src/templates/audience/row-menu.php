<?php
// List actions stay visible in each row; destructive actions come last.
$menuJson=$h(json_encode($menuModel,JSON_THROW_ON_ERROR));
?>
<?php if($menuKind==='tag'): ?>
<div class="audience-tag-actions">
    <a class="action-icon-button" href="<?= $h($url(['tags'=>[$menuModel['id']]])) ?>" aria-label="View connections tagged <?= $h($menuModel['name']) ?>" data-tooltip="View connections"><?= actionIconSvg('view') ?></a>
    <button type="button" class="action-icon-button" data-tag-edit="<?= $menuJson ?>" aria-label="Rename tag <?= $h($menuModel['name']) ?>" data-tooltip="Rename tag"><?= actionIconSvg('rename') ?></button>
    <?php if($canExport): ?><form method="post"><?= $csrf ?><?php audienceInputs($baseForm+['action'=>'queue_export','selection_scope'=>'all','tags'=>[$menuModel['id']]]); ?><button type="submit" class="action-icon-button" aria-label="Export connections tagged <?= $h($menuModel['name']) ?> as ZIP" data-tooltip="Export as ZIP"><?= actionIconSvg('export-csv') ?></button></form><?php endif; ?>
    <?php foreach(['regular'=>['Send regular email','create-email'],'plain-text'=>['Send plain-text email','plain-text-email']]as$emailType=>[$label,$icon]): ?><a class="action-icon-button" href="<?= $h($url(['view'=>'campaigns','create'=>1,'target'=>'tag:'.$menuModel['id'],'email_type'=>$emailType])) ?>" aria-label="Create a <?= $emailType==='plain-text'?'plain-text':'regular' ?> email draft for tag <?= $h($menuModel['name']) ?>" data-tooltip="<?= $label ?>"><?= actionIconSvg($icon) ?></a><?php endforeach; ?>
    <?php if($canDelete): ?><form method="post" data-tag-delete-form data-admin-unlock-required data-confirm="Delete this tag? Connections will be kept, but this tag will be removed from them."><?= $csrf ?><?php audienceInputs($baseForm+['action'=>'delete_tag','id'=>$menuModel['id'],'version'=>$menuModel['version']]); ?><button type="submit" class="action-icon-button" data-admin-unlock-required aria-label="Delete tag <?= $h($menuModel['name']) ?>" data-tooltip="Delete"><?= actionIconSvg('delete') ?></button></form><?php endif; ?>
</div>
<?php else: ?>
<div class="audience-segment-actions">
    <button type="button" class="action-icon-button" data-segment-edit="<?= $menuJson ?>" aria-label="Edit segment <?= $h($menuModel['name']) ?>" data-tooltip="Edit segment"><?= actionIconSvg('edit') ?></button>
    <button type="button" class="action-icon-button" data-segment-rename="<?= $menuJson ?>" aria-label="Rename segment <?= $h($menuModel['name']) ?>" data-tooltip="Rename segment"><?= actionIconSvg('rename') ?></button>
    <?php if($canExport): ?><form method="post"><?= $csrf ?><?php audienceInputs($baseForm+['action'=>'queue_export','selection_scope'=>'all','rules'=>json_encode($menuModel['rules'],JSON_THROW_ON_ERROR)]); ?><button type="submit" class="action-icon-button" aria-label="Export connections in segment <?= $h($menuModel['name']) ?> as ZIP" data-tooltip="Export as ZIP"><?= actionIconSvg('export-csv') ?></button></form><?php endif; ?>
    <?php foreach(['regular'=>['Send regular email','create-email'],'plain-text'=>['Send plain-text email','plain-text-email']]as$emailType=>[$label,$icon]): ?><a class="action-icon-button" href="<?= $h($url(['view'=>'campaigns','create'=>1,'target'=>'segment:'.$menuModel['id'],'email_type'=>$emailType])) ?>" aria-label="Create a <?= $emailType==='plain-text'?'plain-text':'regular' ?> email draft for segment <?= $h($menuModel['name']) ?>" data-tooltip="<?= $label ?>"><?= actionIconSvg($icon) ?></a><?php endforeach; ?>
    <button type="button" class="action-icon-button" data-segment-copy="<?= $menuJson ?>" aria-label="Replicate segment <?= $h($menuModel['name']) ?>" data-tooltip="Replicate segment"><?= actionIconSvg('duplicate') ?></button>
    <?php if($canDelete): ?><form method="post" data-segment-delete-form data-admin-unlock-required data-confirm="Delete this saved segment? Its connections will be kept."><?= $csrf ?><?php audienceInputs($baseForm+['action'=>'delete_segment','id'=>$menuModel['id'],'version'=>$menuModel['version']]); ?><button type="submit" class="action-icon-button" data-admin-unlock-required aria-label="Delete segment <?= $h($menuModel['name']) ?>" data-tooltip="Delete"><?= actionIconSvg('delete') ?></button></form><?php endif; ?>
</div>
<?php endif; ?>
