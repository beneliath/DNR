<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/global_search_helpers.php';
startSecureSession();
requireLogin();
$conn = applicationDatabaseConnection();
$query = trim(\Dnr\Http\RequestInput::string($_GET, 'q', '', 100));
$type = \Dnr\Http\RequestInput::enum($_GET, 'type', array_keys(globalSearchDefinitions()), '');
$page = max(1, min(1000, (int) (filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1)));
$groups = [];
$error = '';
try { $groups = fetchGlobalSearchResults($conn, $query, $type, $page); }
catch (Throwable $exception) {
    applicationLog('error', 'Global search failed', ['error' => $exception->getMessage()]);
    $error = 'Search is temporarily unavailable. Please try again.';
}
function searchH(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
$searchUrl = static fn(array $changes): string => 'search.php?' . http_build_query(array_merge(['q' => $query, 'type' => $type], $changes));
?>
<!DOCTYPE html><html lang="en">
<?php renderPageHead(applicationPageTitle('Search'), ['styles' => ['assets/css/style.min.css', 'assets/css/modern.min.css']]); ?>
<body><?php include 'templates/header.php'; ?>
<main class="container global-search-page">
    <div class="page-heading"><div><h1>Search All Records</h1><p class="page-intro">Find engagements, booking inquiries, organizations, contacts, and tasks in one place.</p></div></div>
    <form method="get" action="search.php" class="global-search-form" role="search">
        <label for="global-query">Search records<input type="search" id="global-query" name="q" value="<?= searchH($query) ?>" placeholder="Name, organization, or task" minlength="2" maxlength="100" required></label>
        <label for="search-type">Record type<select id="search-type" name="type"><option value="">All records</option><?php foreach (globalSearchDefinitions() as $key => $definition): ?><option value="<?= searchH($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= searchH($definition['label']) ?></option><?php endforeach; ?></select></label>
        <button type="submit" class="button-add">Search</button>
        <?php if ($query !== '' || $type !== ''): ?><a href="search.php" class="button-secondary">Clear Filters</a><?php endif; ?>
    </form>
    <p class="field-help">Search includes unarchived records. Use each section’s archive view to find archived records.</p>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?= searchH($error) ?></p>
    <?php elseif (mb_strlen($query) < 2): ?><p>Enter at least two characters to find a record.</p>
    <?php else: ?>
    <p role="status">Results for “<?= searchH($query) ?>”<?= $type !== '' ? ' · Page ' . $page : '' ?></p>
    <div class="global-search-results">
    <?php foreach ($groups as $key => $group): ?>
        <section class="search-result-group" aria-labelledby="results-<?= searchH($key) ?>">
            <h2 id="results-<?= searchH($key) ?>"><?= searchH($group['label']) ?></h2>
            <?php if (!$group['rows']): ?><p class="field-help">No matches in this section</p><?php else: ?><ul>
            <?php foreach ($group['rows'] as $row):
                $url = $key === 'tasks' ? 'tasks.php?' . http_build_query(['scope' => 'everyone', 'view' => in_array($row['status'], ['completed', 'canceled'], true) ? 'completed' : 'all', 'task_id' => $row['id']])
                    : $group['route'] . '?id=' . (int) $row['id']; ?>
                <li><a href="<?= searchH($url) ?>"><?= searchH($row['title']) ?></a><span><?= searchH(str_replace('_', ' ', $row['detail'] ?? '')) ?></span></li>
            <?php endforeach; ?></ul><?php endif; ?>
            <?php if ($group['more'] && $type === ''): ?><a href="<?= searchH($searchUrl(['type' => $key, 'page' => 1])) ?>">More <?= searchH(strtolower($group['label'])) ?></a><?php endif; ?>
        </section>
    <?php endforeach; ?>
    </div>
    <?php if ($type !== ''): ?><nav class="search-pagination" aria-label="Search result pages">
        <?php if ($page > 1): ?><a class="button-secondary" href="<?= searchH($searchUrl(['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
        <a href="<?= searchH($searchUrl(['type' => '', 'page' => 1])) ?>">All Record Types</a>
        <?php if (!empty($groups[$type]['more'])): ?><a class="button-secondary" href="<?= searchH($searchUrl(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
    </nav><?php endif; ?>
    <?php endif; ?>
</main><?php include 'templates/footer.php'; ?></body></html>
