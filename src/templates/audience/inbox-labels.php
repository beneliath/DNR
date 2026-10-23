<section class="inbox-label-library" aria-labelledby="inbox-label-heading">
    <header class="inbox-library-heading"><div><h1 id="inbox-label-heading">Labels <span><?= count($labels) ?></span></h1><p>Keep related conversations together.</p></div><a class="button primary" href="<?= $h($url(['panel'=>'labels','new'=>1])) ?>">+ Add label</a></header>
    <div class="inbox-label-grid">
        <?php foreach($labels as$l): ?>
        <article class="inbox-label-card"><span class="inbox-label-symbol" aria-hidden="true"><?php $icon('label'); ?></span><div class="inbox-label-copy"><h2><a href="<?= $h($url(['panel'=>'labels','edit'=>(int)$l['id']])) ?>"><?= $h($l['name']) ?></a></h2><a href="<?= $h($filterUrl(['label'=>(int)$l['id'],'status'=>'all','source'=>'','q'=>'','unread'=>'0'])) ?>"><?= (int)$l['message_count'] ?> conversation<?= (int)$l['message_count']===1?'':'s' ?></a></div><div class="inbox-label-actions"><a class="action-icon-button" href="<?= $h($url(['panel'=>'labels','edit'=>(int)$l['id']])) ?>" aria-label="Edit label <?= $h($l['name']) ?>" data-tooltip="Edit label"><?= actionIconSvg('edit') ?></a><form method="post" data-confirm="Remove this label? Conversation history will be kept."><?php $form('inbox_named_remove',['kind'=>'label','id'=>$l['id'],'version'=>$l['version']]); ?><button type="submit" class="action-icon-button" aria-label="Remove label <?= $h($l['name']) ?>" data-tooltip="Remove label"><?= actionIconSvg('delete') ?></button></form></div></article>
        <?php endforeach; ?>
    </div>
    <?php if(!$labels): ?><div class="inbox-list-empty"><h2>No labels yet</h2><p>Add a label to organize your conversations.</p></div><?php endif; ?>
</section>
