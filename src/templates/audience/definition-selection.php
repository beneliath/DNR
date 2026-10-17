<?php if($canManage): ?>
<form id="definition-selection" method="post" class="definition-selection" data-definition-selection data-confirm="Delete the selected <?= $view==='tags'?'tags and remove their tag assignments':'saved segments' ?>? Connections will be kept.">
    <?= $csrf ?><?php audienceInputs($baseForm+['action'=>'delete_selected_definitions','kind'=>$view]); ?>
    <label class="audience-check"><input type="checkbox" data-definition-all aria-label="Select all <?= $h($view) ?> on this page"><span class="sr-only">Select all on this page</span></label>
    <div data-definition-actions hidden><span role="status" data-definition-count>0 Selected</span><button type="submit" disabled>Delete <?= $view==='tags'?'tags':'segments' ?></button><button type="button" class="text-button" data-definition-clear>Clear selection</button></div>
</form>
<?php endif; ?>
