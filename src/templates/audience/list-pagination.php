<?php // Use audiencePagination() so every MessageMonkey list shares this component. ?>
<nav class="mm-pagination" data-mm-pagination aria-label="<?= $escape($options['label']??'Results pages') ?>">
    <form class="mm-pagination-size" method="get" action="<?= $escape(parse_url($base,PHP_URL_PATH)??'') ?>">
        <?php $inputs($params); ?>
        <input type="hidden" name="page_size_change" value="<?= $escape($sizeKey) ?>">
        <label>Items per page <select name="<?= $escape($sizeKey) ?>" data-mm-page-size><?php foreach([10,25,50,100]as$value): ?><option value="<?= $value ?>"<?= $size===$value?' selected':'' ?>><?= $value ?></option><?php endforeach; ?></select></label>
        <noscript><button type="submit">Apply</button></noscript>
    </form>
    <span class="mm-pagination-range" aria-live="polite"><?= $total?number_format(($page-1)*$size+1).'–'.number_format(min($page*$size,$total)):'0' ?> of <?= number_format($total) ?></span>
    <div class="mm-pagination-controls">
        <?php foreach(['Previous page'=>[$page-1,'M20 12H4m7-7-7 7 7 7',$page>1],'Next page'=>[$page+1,'M4 12h16m-7-7 7 7-7 7',$page<$pages]]as$label=>[$target,$path,$enabled]): $arrow='<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="'.$path.'"/></svg>'; ?>
            <?php if($label==='Next page'): ?><span class="mm-pagination-page"><?= $page ?> / <?= $pages ?></span><?php endif; ?>
            <?php if($enabled): ?><a class="mm-pagination-arrow" href="<?= $escape($link([$pageKey=>$target,$sizeKey=>$size])) ?>" aria-label="<?= $label ?>" data-tooltip="<?= $label ?>"><?= $arrow ?></a><?php else: ?><button class="mm-pagination-arrow" type="button" aria-label="<?= $label ?>" disabled><?= $arrow ?></button><?php endif; ?>
        <?php endforeach; ?>
    </div>
</nav>
