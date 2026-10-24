<?php if($canManage&&$canDelete): ?>
<form id="definition-selection" method="post" class="definition-selection" data-definition-selection <?= $view==='tags'?'data-tag-delete-form':'data-segment-delete-form' ?> data-admin-unlock-required data-confirm="Delete the selected <?= $view==='tags'?'tags and remove their tag assignments':'saved segments' ?>? Connections will be kept.">
    <?= $csrf ?><?php audienceInputs($baseForm+['action'=>'delete_selected_definitions','kind'=>$view]); ?>
    <label class="audience-check"><input type="checkbox" data-definition-all aria-label="Select all <?= $h($view) ?> on this page"><span>Select all on this page</span></label>
    <div data-definition-actions hidden><span role="status" data-definition-count>0 Selected</span><button type="submit" data-admin-unlock-required disabled>Delete <?= $view==='tags'?'tags':'segments' ?></button><button type="button" data-definition-clear>Clear selection</button></div>
</form>
<?php endif; ?>
