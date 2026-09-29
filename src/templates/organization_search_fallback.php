<noscript>
    <form method="get" class="card">
        <?php foreach (['id', 'organization_id', 'created_organization_id', 'from', 'return_to'] as $search_context): ?>
            <?php if (isset($_GET[$search_context]) && is_string($_GET[$search_context])): ?>
                <input type="hidden" name="<?php echo $search_context; ?>" value="<?php echo htmlspecialchars($_GET[$search_context], ENT_QUOTES, 'UTF-8'); ?>">
            <?php endif; ?>
        <?php endforeach; ?>
        <label for="organization-search-fallback">Find an Organization</label>
        <input id="organization-search-fallback" name="organization_search" type="search" maxlength="120" value="<?php echo htmlspecialchars($organization_search, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit">Find Organizations</button>
        <p>Search before filling out the form. This reloads the page and shows up to 25 matching organizations alongside saved selections.</p>
    </form>
</noscript>
