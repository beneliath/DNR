<?php if($groups): foreach($groups as$group):
    $selectedIds=array_map('intval',$contact['group_ids']);
    $archived=!empty($group['archived_at']);
    $choices=$archived?array_values(array_filter($group['options'],static fn($option)=>in_array((int)$option['id'],$selectedIds,true))):$group['options'];
    if($archived&&!$choices)continue;
?>
<fieldset class="audience-group-choices"><legend><?= $h($group['name']) ?><?= $archived?' (Archived — existing assignments only)':($group['display_type']==='hidden'?' (internal)':'') ?></legend>
    <?php if($group['display_type']==='dropdown'): ?>
        <select name="group_pick[<?= (int)$group['id'] ?>]" aria-label="<?= $h($group['name']) ?>"><option value="">None</option><?php foreach($choices as$option): ?><option value="<?= (int)$option['id'] ?>"<?= in_array((int)$option['id'],$selectedIds,true)?' selected':'' ?>><?= $h($option['name']) ?></option><?php endforeach; ?></select>
    <?php else: ?>
        <?php if($group['display_type']==='radio'): ?><label class="audience-check"><input type="radio" name="group_pick[<?= (int)$group['id'] ?>]" value=""<?= array_intersect(array_map('intval',array_column($choices,'id')),$selectedIds)?'':' checked' ?>>None</label><?php endif; ?>
        <?php foreach($choices as$option): ?><label class="audience-check"><input type="<?= $group['display_type']==='radio'?'radio':'checkbox' ?>" name="<?= $group['display_type']==='radio'?'group_pick['.(int)$group['id'].']':'group_ids[]' ?>" value="<?= (int)$option['id'] ?>"<?= in_array((int)$option['id'],$selectedIds,true)?' checked':'' ?>><?= $h($option['name']) ?></label><?php endforeach; ?>
    <?php endif; ?>
</fieldset>
<?php endforeach; endif; ?>
