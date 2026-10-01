<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/reimbursement_admin_helpers.php';
startSecureSession();
requireLogin();
if (!hasRole(['admin', 'editor'])) { http_response_code(403); exit('Forbidden.'); }
$conn = applicationDatabaseConnection();
$view = in_array($_GET['show'] ?? '', ['archived', 'used'], true) ? $_GET['show'] : 'active';
$showArchived = $view === 'archived';
$search = mb_substr(trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : ''), 0, 200);
$sortColumns = ['coa_number' => 'c.coa_number', 'description' => 'c.description', 'expenses' => 'expense_count'];
$sortBy = is_string($_GET['sort_by'] ?? null) && isset($sortColumns[$_GET['sort_by']]) ? $_GET['sort_by'] : 'coa_number';
$sortDir = ($_GET['sort_dir'] ?? '') === 'desc' ? 'desc' : 'asc';
$listQuery = array_filter(['show' => $view === 'active' ? null : $view, 'q' => $search ?: null,
    'sort_by' => $sortBy === 'coa_number' ? null : $sortBy, 'sort_dir' => $sortDir === 'asc' ? null : $sortDir], static fn($value) => $value !== null);
$listLink = static function (array $changes = []) use ($listQuery): string {
    $query = http_build_query(array_filter(array_replace($listQuery, $changes), static fn($value) => $value !== null));
    return 'reimbursement_cost_centers.php' . ($query !== '' ? '?' . $query : '');
};
$listUrl = $listLink();
$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$error = '';
$message = (string) ($_SESSION['reimbursement_center_message'] ?? '');
unset($_SESSION['reimbursement_center_message']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = (string) ($_POST['action'] ?? '');
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    try {
        if ($action === 'delete') {
            if (!hasRole(['admin'])) { http_response_code(403); exit('Forbidden.'); }
            require_once __DIR__ . '/two_factor_helpers.php';
            requireRecentAdminElevation($listUrl);
        }
        $id = changeReimbursementCostCenter($conn,$action,$id,$_POST,(int)$_SESSION['user_id']);
        $_SESSION['reimbursement_center_message'] = $action === 'save' ? 'Account saved.' : ($action === 'delete' ? 'Account deleted.' : ($action === 'archive' ? 'Account archived.' : 'Account restored.'));
        header('Location: ' . $listUrl); exit();
    } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
    catch (mysqli_sql_exception $e) { $error = $e->getCode() === 1062 ? 'That COA number already exists.' : 'Unable to save the account.'; }
}
$where = 'c.is_archived = ?';
$params = [(int) $showArchived];
if ($view === 'used') $where .= ' AND EXISTS (SELECT 1 FROM reimbursement_expenses e WHERE e.cost_center_id = c.id)';
if ($search !== '') {
    $where .= " AND (c.coa_number LIKE ? ESCAPE '!' OR c.description LIKE ? ESCAPE '!')";
    $pattern = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    array_push($params, $pattern, $pattern);
}
$order = $sortColumns[$sortBy] . ' ' . strtoupper($sortDir) . ', c.coa_number ASC, c.description ASC, c.id ASC';
$centers = $conn->execute_query('SELECT c.*, (SELECT COUNT(*) FROM reimbursement_expenses e WHERE e.cost_center_id = c.id) AS expense_count FROM reimbursement_cost_centers c WHERE ' . $where . ' ORDER BY ' . $order, $params)->fetch_all(MYSQLI_ASSOC);
$editingCenter = $editId ? $conn->execute_query('SELECT * FROM reimbursement_cost_centers WHERE id = ?', [$editId])->fetch_assoc() : null;
if ($editId && !$editingCenter && !$error) $error = 'Account not found.';
$formValues = $editingCenter ?: ['coa_number' => '', 'description' => ''];
if ($error && ($_POST['action'] ?? '') === 'save') {
    $formValues['coa_number'] = (string) ($_POST['coa_number'] ?? '');
    $formValues['description'] = (string) ($_POST['description'] ?? '');
    $formValues['version'] = is_scalar($_POST['version'] ?? null) ? (string) $_POST['version'] : '0';
}
function reimbursementCenterH(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html><html lang="en">
<?php renderPageHead(applicationPageTitle('Reimbursement Chart of Accounts'), ['styles' => ['assets/css/style.min.css', 'assets/css/modern.min.css', 'assets/css/pages/reimbursements.css'], 'scripts' => ['assets/js/reimbursements.js']]); ?>
<body><?php include 'templates/header.php'; ?><main class="container reimbursement-page">
<nav class="breadcrumb" aria-label="Breadcrumb"><a href="reimbursements.php">Reimbursements</a><span aria-hidden="true">/</span><span>Chart of Accounts</span></nav>
<div class="page-heading"><div><h1>Chart of Accounts</h1><p class="page-intro">Editors and administrators can add, edit, archive, and restore accounts. Administrators can delete unused accounts.</p></div><div class="page-heading-actions"><a class="button-add" href="reimbursement_expense.php">+ Add Expense</a><a class="button-secondary" href="reimbursements.php">Expenses</a><a class="button-secondary" href="reimbursement_requests.php">Requests</a></div></div>
<?php if ($error): ?><p class="error" role="alert"><?= reimbursementCenterH($error) ?></p><?php endif; ?><?php if ($message): ?><p class="success" role="status"><?= reimbursementCenterH($message) ?></p><?php endif; ?>
<section class="reimbursement-card" id="cost-center-editor">
<h2><?= $editingCenter ? 'Edit Account' : 'Add Account' ?></h2>
<form method="post" class="reimbursement-inline-form reimbursement-add-center-form">
<?= csrfInput() ?><input type="hidden" name="action" value="save">
<?php if ($editingCenter): ?><input type="hidden" name="id" value="<?= (int) $editingCenter['id'] ?>"><input type="hidden" name="version" value="<?= reimbursementCenterH($formValues['version']) ?>"><?php endif; ?>
<label>COA Number <input type="text" name="coa_number" maxlength="20" required value="<?= reimbursementCenterH($formValues['coa_number']) ?>"></label>
<label>Description <input type="text" name="description" maxlength="120" required value="<?= reimbursementCenterH($formValues['description']) ?>"></label>
<div class="reimbursement-actions"><button class="save-button" type="submit"><?= $editingCenter ? 'Save Account' : 'Add Account' ?></button><?php if ($editingCenter): ?><a class="button-secondary" href="<?= reimbursementCenterH($listUrl) ?>">Cancel</a><?php endif; ?></div>
</form></section>
<section class="reimbursement-card">
<h2>Chart of Accounts</h2>
<div class="list-controls">
<form method="get" action="reimbursement_cost_centers.php" class="list-search-form" role="search">
<input type="hidden" name="show" value="<?= reimbursementCenterH($view) ?>">
<input type="hidden" name="sort_by" value="<?= reimbursementCenterH($sortBy) ?>">
<input type="hidden" name="sort_dir" value="<?= reimbursementCenterH($sortDir) ?>">
<label class="visually-hidden" for="cost-center-search">Search Chart of Accounts</label><span class="search-icon" aria-hidden="true">⌕</span>
<input type="search" id="cost-center-search" name="q" value="<?= reimbursementCenterH($search) ?>" placeholder="Search Chart of Accounts">
<?php if ($search !== ''): ?><a class="clear-search" href="<?= reimbursementCenterH($listLink(['q' => null])) ?>">Clear</a><?php endif; ?>
</form>
<nav class="control-group" aria-label="Account View">
<?php foreach (['active' => 'Active', 'used' => 'In Use', 'archived' => 'Archived'] as $value => $label): ?>
<a class="sort-button<?= $view === $value ? ' active' : '' ?>"<?= $view === $value ? ' aria-current="page"' : '' ?> href="<?= reimbursementCenterH($listLink(['show' => $value === 'active' ? null : $value])) ?>"><?= $label ?></a>
<?php endforeach; ?>
</nav>
<div class="control-group" aria-label="Account Sort Order"><span class="control-label">Sort:</span><div class="sort-buttons">
<?php foreach (['coa_number' => 'COA Number', 'description' => 'Description', 'expenses' => 'Expenses'] as $key => $label):
    $direction = $sortBy === $key ? $sortDir : ($key === 'expenses' ? 'desc' : 'asc');
    $nextDirection = $sortBy === $key ? ($sortDir === 'asc' ? 'desc' : 'asc') : $direction;
?>
<a class="sort-button<?= $sortBy === $key ? ' active' : '' ?>"<?= $sortBy === $key ? ' aria-current="true"' : '' ?> href="<?= reimbursementCenterH($listLink(['sort_by' => $key, 'sort_dir' => $nextDirection])) ?>"><?= $label ?> <?= $direction === 'asc' ? '↑' : '↓' ?></a>
<?php endforeach; ?>
</div></div></div>
<?php if ($search !== ''): ?><p class="result-context">Showing accounts matching “<?= reimbursementCenterH($search) ?>”.</p><?php endif; ?>
<?php if ($view === 'used'): ?><p class="field-help">Active accounts with one or more associated expenses.</p><?php endif; ?>
<p class="field-help">COA numbers must be unique, including numbers assigned to archived accounts. Accounts with expenses can be archived but cannot be deleted.</p>
<div class="reimbursement-table-wrap"><table class="data-table"><thead><tr><th scope="col">COA Number</th><th scope="col">Description</th><th scope="col">Expenses</th><th scope="col">Actions</th></tr></thead><tbody>
<?php foreach ($centers as $center): ?>
<tr><td><?= reimbursementCenterH($center['coa_number']) ?></td><td><?= reimbursementCenterH($center['description']) ?></td><td><?= (int) $center['expense_count'] ?></td><td><div class="reimbursement-row-actions">
<a class="action-button action-icon-button edit-button" href="<?= reimbursementCenterH($listLink(['edit' => (int) $center['id']])) ?>#cost-center-editor" aria-label="Edit Account <?= reimbursementCenterH($center['coa_number']) ?>" data-tooltip="Edit"><?= actionIconSvg('edit') ?></a>
<form method="post" action="<?= reimbursementCenterH($listUrl) ?>"><?= csrfInput() ?><input type="hidden" name="action" value="<?= $showArchived ? 'restore' : 'archive' ?>"><input type="hidden" name="id" value="<?= (int) $center['id'] ?>"><input type="hidden" name="version" value="<?= (int) $center['version'] ?>"><button class="action-button action-icon-button <?= $showArchived ? 'restore-button' : 'archive-button' ?>" type="submit" aria-label="<?= $showArchived ? 'Restore' : 'Archive' ?> Account <?= reimbursementCenterH($center['coa_number']) ?>" data-tooltip="<?= $showArchived ? 'Restore' : 'Archive' ?>"><?= actionIconSvg($showArchived ? 'restore' : 'archive') ?></button></form>
<?php if (hasRole(['admin']) && !(int) $center['expense_count']): ?><form method="post" action="<?= reimbursementCenterH($listUrl) ?>" data-confirm="This action is permanent and cannot be undone." data-confirm-title="Delete Account?" data-confirm-label="Delete Account" data-confirm-tone="danger" data-admin-unlock-required><?= csrfInput() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $center['id'] ?>"><input type="hidden" name="version" value="<?= (int) $center['version'] ?>"><button class="action-button action-icon-button delete-button" type="submit" aria-label="Delete Account <?= reimbursementCenterH($center['coa_number']) ?>" data-tooltip="Delete"><?= actionIconSvg('delete') ?></button></form><?php endif; ?>
</div></td></tr>
<?php endforeach; ?>
<?php if (!$centers): ?><tr><td colspan="4">No accounts match this view<?= $search !== '' ? ' and search' : '' ?>.</td></tr><?php endif; ?>
</tbody></table></div></section>
</main><?php include 'templates/footer.php'; ?></body></html>
