<nav class="pagination numbered-pagination" aria-label="<?php echo htmlspecialchars($aria_label, ENT_QUOTES, 'UTF-8'); ?>">
    <div class="page-size-selector" aria-label="<?php echo htmlspecialchars(ucfirst($label) . ' per page', ENT_QUOTES, 'UTF-8'); ?>">
        <span class="page-size-label">Records per page:</span>
        <?php foreach ($allowed_sizes as $allowed_size): ?>
            <a href="<?php echo $url(1, $allowed_size); ?>" class="sort-button page-size-button<?php echo $size === $allowed_size ? ' active' : ''; ?>"<?php echo $size === $allowed_size ? ' aria-current="true"' : ''; ?>><?php echo $allowed_size; ?></a>
        <?php endforeach; ?>
    </div>
    <?php if ($state['pages'] > 1): ?>
    <div class="pagination-bar">
        <?php if ($state['page'] > 1): ?>
            <a class="page-control page-previous" href="<?php echo $url($state['page'] - 1, $size); ?>" aria-label="Previous page" rel="prev"><span aria-hidden="true">‹</span><span class="page-direction-label">Previous</span></a>
        <?php else: ?>
            <span class="page-control page-previous" aria-disabled="true" aria-label="Previous page"><span aria-hidden="true">‹</span><span class="page-direction-label">Previous</span></span>
        <?php endif; ?>
        <ol class="page-numbers">
            <?php foreach ($page_numbers as $page_number): ?>
                <li>
                    <?php if ($page_number === null): ?>
                        <span class="page-ellipsis" aria-hidden="true">…</span>
                    <?php elseif ($page_number === $state['page']): ?>
                        <span class="page-control page-number" aria-current="page" aria-label="Page <?php echo $page_number; ?>"><?php echo $page_number; ?></span>
                    <?php else: ?>
                        <a class="page-control page-number" href="<?php echo $url($page_number, $size); ?>" aria-label="Go to page <?php echo $page_number; ?>"><?php echo $page_number; ?></a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php if ($state['page'] < $state['pages']): ?>
            <a class="page-control page-next" href="<?php echo $url($state['page'] + 1, $size); ?>" aria-label="Next page" rel="next"><span class="page-direction-label">Next</span><span aria-hidden="true">›</span></a>
        <?php else: ?>
            <span class="page-control page-next" aria-disabled="true" aria-label="Next page"><span class="page-direction-label">Next</span><span aria-hidden="true">›</span></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <span class="pagination-status">Showing <?php echo $state['total'] > 0 ? number_format($state['offset'] + 1) . '–' . number_format(min($state['total'], $state['offset'] + $size)) : '0'; ?> of <?php echo number_format($state['total']); ?> <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
</nav>
