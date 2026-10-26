<?php if($canManage): ?>
<?php $collectionRecord=$h(json_encode(array_intersect_key($collectionEntry,array_flip(['id','name','version'])),JSON_THROW_ON_ERROR)); ?>
<?php if($collectionEntry['deleted_at']): ?>
<form method="post"><?= $csrf ?><?php audienceInputs(['action'=>'collection_operation','id'=>$collectionEntry['id'],'version'=>$collectionEntry['version'],'operation'=>'restore']); ?><button type="submit" class="action-icon-button" aria-label="Restore <?= $h($collectionEntry['name']) ?>" data-tooltip="Restore as draft"><?= actionIconSvg('restore') ?></button></form>
<?php if($canDelete): ?><button type="button" class="action-icon-button" data-open-dialog="collection-delete" data-admin-unlock-required data-collection-lifecycle="delete" data-collection-record="<?= $collectionRecord ?>" aria-label="Permanently delete <?= $h($collectionEntry['name']) ?>" data-tooltip="Permanently delete (Admin Unlock required)"><?= actionIconSvg('delete') ?></button><?php endif; ?>
<?php else: ?>
<button type="button" class="action-icon-button" data-open-dialog="collection-archive" data-collection-lifecycle="archive" data-collection-record="<?= $collectionRecord ?>" aria-label="Archive <?= $h($collectionEntry['name']) ?>" data-tooltip="Archive"><?= actionIconSvg('archive') ?></button>
<?php endif; ?>
<?php endif; ?>
