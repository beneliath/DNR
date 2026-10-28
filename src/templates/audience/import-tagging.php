<?php
$importTagIds=array_map('intval',$params['tag_ids']??[]);
$importGroupIds=array_map('intval',$params['group_ids']??[]);
$importSelectedTags=array_values(array_filter($tags,static fn($tag)=>in_array((int)$tag['id'],$importTagIds,true)));
$importSelectedGroups=0;
foreach($groups as$group)foreach($group['options']as$option)if(in_array((int)$option['id'],$importGroupIds,true))++$importSelectedGroups;
?>
<div class="import-tagging" data-import-tagging>
    <header class="import-tagging-intro"><h2>Tag your connections</h2><p>Apply tags and groups to successfully imported connections.</p></header>
    <div class="import-tagging-panels">
        <section class="import-choice-panel" aria-labelledby="import-tags-heading">
            <div class="import-choice-heading"><h3 id="import-tags-heading">Tags</h3><span class="import-choice-count" data-import-tag-count aria-live="polite"><?= count($importSelectedTags) ?> selected</span></div>
            <p class="import-choice-description">Choose the labels to apply to this import.</p>
            <?php if($tags): ?>
            <label class="import-tag-search" data-import-tag-search-wrap hidden><span class="sr-only">Find a tag</span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg><input type="search" placeholder="Find a tag" autocomplete="off" data-import-tag-search></label>
            <div class="import-selected-tags" aria-label="Selected tags" data-import-selected-tags><?php foreach($importSelectedTags as$tag): ?><span class="import-selected-tag"><?= $h($tag['name']) ?></span><?php endforeach; ?><?php if(!$importSelectedTags): ?><span class="import-selection-empty">No tags selected</span><?php endif; ?></div>
            <div class="import-tag-options" data-import-tag-options>
                <?php foreach($tags as$tag): ?><label class="audience-check import-tag-option" data-import-tag-option><input type="checkbox" name="tag_ids[]" value="<?= (int)$tag['id'] ?>"<?= in_array((int)$tag['id'],$importTagIds,true)?' checked':'' ?>><span><?= $h($tag['name']) ?></span></label><?php endforeach; ?>
            </div>
            <p class="import-no-matches" data-import-tags-empty role="status" hidden>No tags match your search.</p>
            <?php else: ?><p class="audience-help">No tags yet. You can map a Tags column or add tags after import.</p><?php endif; ?>
        </section>
        <section class="import-choice-panel import-groups-panel" aria-labelledby="import-groups-heading">
            <div class="import-choice-heading"><h3 id="import-groups-heading">Groups</h3><span class="import-choice-count" data-import-group-count aria-live="polite"><?= $importSelectedGroups ?> selected</span></div>
            <p class="import-choice-description">Choose any groups these connections should join.</p>
            <?php foreach($groups as$group): ?><fieldset class="audience-group-choices import-group-options"><legend><?= $h($group['name']) ?></legend><?php foreach($group['options']as$option): ?><label class="audience-check import-group-option"><input type="checkbox" name="group_ids[]" value="<?= (int)$option['id'] ?>"<?= in_array((int)$option['id'],$importGroupIds,true)?' checked':'' ?>><span><?= $h($option['name']) ?></span></label><?php endforeach; ?></fieldset><?php endforeach; ?>
            <?php if(!$groups): ?><p class="audience-help">No groups available. You can add them after import.</p><?php endif; ?>
        </section>
    </div>
</div>
