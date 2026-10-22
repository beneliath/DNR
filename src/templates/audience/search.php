<dialog id="audience-search" class="audience-search-dialog" aria-label="Search MessageMonkey">
    <div class="audience-search-header">
        <div class="audience-search-top">
            <form role="search" data-workspace-search>
                <label for="workspace-search-input" class="sr-only">Search MessageMonkey</label>
                <input type="search" id="workspace-search-input" name="q" placeholder="What can we help you find today?" maxlength="200" autocomplete="off" autofocus>
                <button type="submit" class="primary" aria-label="Search"><?php audienceNavIcon('search'); ?></button>
            </form>
            <button type="button" class="audience-search-close" data-close-dialog aria-label="Close search">×</button>
        </div>
        <p class="audience-search-filter-label">Filter by</p>
        <div class="audience-search-filters" role="group" aria-label="Filter search results">
            <?php foreach(audienceSearchCategories() as $key=>$label): ?><button type="button" data-search-category="<?= $key ?>" aria-pressed="false"><?= $label ?></button><?php endforeach; ?>
        </div>
    </div>
    <p class="sr-only" data-workspace-search-status role="status" aria-live="polite"></p>
    <div class="audience-search-results" data-workspace-search-results></div>
</dialog>
