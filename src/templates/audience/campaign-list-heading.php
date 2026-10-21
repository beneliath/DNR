<nav class="campaign-view-switch" aria-label="Campaign views">
    <?php foreach(['list'=>'List','calendar'=>'Calendar']as$key=>$label): ?><a href="<?= $h($campaignUrl(['layout'=>$key])) ?>"<?= $campaignFilters['layout']===$key?' aria-current="page"':'' ?>><?php audienceNavIcon($key); ?><?= $label ?></a><?php endforeach; ?>
</nav>
<?php if($canManage): ?><button type="button" class="primary campaign-list-create" data-open-dialog="campaign-create">Create</button><?php endif; ?>
