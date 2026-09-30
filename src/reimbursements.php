<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/reimbursement_helpers.php';
startSecureSession();
requireLogin();
$canManage = hasRole(['admin', 'editor']);
$conn = applicationDatabaseConnection();
$userId = (int) $_SESSION['user_id'];
$canViewAll = hasRole(['admin']);
$showArchived = ($_GET['show'] ?? '') === 'archived';
$stateLabels = ['all' => 'All', 'available' => 'Available', 'draft' => 'Draft', 'submitted' => 'Submitted'];
$state = is_string($_GET['state'] ?? null) && isset($stateLabels[$_GET['state']]) ? $_GET['state'] : 'all';
$canSelect = $canManage && !$showArchived;
$returnUrl = 'reimbursements.php?' . http_build_query(array_intersect_key($_GET,
    array_flip(['show', 'state', 'owner_id', 'start_date', 'end_date', 'q', 'sort_by', 'sort_dir', 'per_page', 'page'])));
$message = (string) ($_SESSION['reimbursement_expense_message'] ?? '');
unset($_SESSION['reimbursement_expense_message']);
$selectionRemoved = (int) ($_SESSION['reimbursement_selection_removed'] ?? 0);
unset($_SESSION['reimbursement_selection_removed']);
$selectedOwner = filter_input(INPUT_GET, 'owner_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$ownerFilterId = $canViewAll && is_int($selectedOwner) ? $selectedOwner : $userId;
$expenseOwners = $canViewAll ? $conn->execute_query('SELECT u.id,
    COALESCE(NULLIF(TRIM(CONCAT_WS(\' \', u.first_name, u.last_name)), \'\'), u.username) AS owner_name
    FROM users u WHERE u.id = ? OR EXISTS (SELECT 1 FROM reimbursement_expenses e WHERE e.user_id = u.id)
    ORDER BY owner_name, u.id', [$userId])->fetch_all(MYSQLI_ASSOC) : [];
$today = applicationBusinessDate();
$start = (string) ($_POST['start_date'] ?? $_GET['start_date'] ?? substr($today, 0, 4) . '-01-01');
$end = (string) ($_POST['end_date'] ?? $_GET['end_date'] ?? $today);
$search = is_string($_GET['q'] ?? null) ? trim(mb_substr($_GET['q'], 0, 100)) : '';
$sortLabels = ['date' => 'Date', 'merchant' => 'Merchant', 'owner' => 'Owner', 'cost_center' => 'Account', 'amount' => 'Amount', 'status' => 'State'];
$sortDefaults = ['date' => 'desc', 'merchant' => 'asc', 'owner' => 'asc', 'cost_center' => 'asc', 'amount' => 'desc', 'status' => 'asc'];
$sortBy = is_string($_GET['sort_by'] ?? null) && isset($sortLabels[$_GET['sort_by']]) ? $_GET['sort_by'] : 'date';
$sortDir = is_string($_GET['sort_dir'] ?? null) && in_array($_GET['sort_dir'], ['asc', 'desc'], true) ? $_GET['sort_dir'] : $sortDefaults[$sortBy];
$error = '';
try {
    reimbursementDate($start, 'start date');
    reimbursementDate($end, 'end date');
    if ($start > $end) throw new InvalidArgumentException('End date must be on or after start date.');
} catch (InvalidArgumentException $e) {
    $error = $e->getMessage();
    $start = substr($today, 0, 4) . '-01-01';
    $end = $today;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    if (!$canManage) { http_response_code(403); exit('This account has read-only access.'); }
    $action = $_POST['action'] ?? 'create_request';
    if ($action === 'delete_expense') {
        if (!$canViewAll) { http_response_code(403); exit('Only administrators can delete expenses.'); }
        require_once __DIR__ . '/two_factor_helpers.php';
        requireRecentAdminElevation($returnUrl);
    }
    try {
        if (in_array($action, ['archive_expense', 'restore_expense', 'delete_expense'], true)) {
            $expenseId = filter_var($_POST['expense_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$expenseId) throw new InvalidArgumentException('Select an expense.');
            if ($action === 'delete_expense') {
                deleteReimbursementExpense($conn, $expenseId);
                $_SESSION['reimbursement_expense_message'] = 'Expense and its receipts deleted.';
            } else {
                changeReimbursementExpenseArchive($conn, $expenseId, $userId, $action === 'archive_expense');
                $_SESSION['reimbursement_expense_message'] = $action === 'archive_expense' ? 'Expense archived.' : 'Expense restored.';
            }
            $_SESSION['reimbursement_selection_removed'] = $expenseId;
            header('Location: ' . $returnUrl, true, 303); exit();
        }
        if ($action !== 'create_request') throw new InvalidArgumentException('Unknown action.');
        if ($error !== '') throw new InvalidArgumentException($error);
        $ids = $_POST['expense_ids'] ?? [];
        if (!is_array($ids)) throw new InvalidArgumentException('Select valid expenses.');
        $requestId = createReimbursementRequest($conn, $userId, $start, $end, $ids);
        $_SESSION['reimbursement_selection_cleared'] = 'reimbursement-selection-' . $userId;
        header('Location: reimbursement_request.php?id=' . $requestId . '&mode=edit');
        exit();
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (mysqli_sql_exception $e) {
        $error = $e->getCode() === 1062 ? 'An expense was added to another request. Refresh and try again.' : 'Unable to create the reimbursement request.';
        if ($e->getCode() !== 1062) applicationLog('error', 'Reimbursement request creation failed', ['error' => $e->getMessage()]);
    }
}
$pageSize = paginationPageSizePreference('reimbursement_expenses', $_GET['per_page'] ?? null, 20);
$showAllOwners = (int) ($canViewAll && $ownerFilterId === 0);
$fromSql = ' FROM reimbursement_expenses e
    JOIN users u ON u.id = e.user_id
    JOIN reimbursement_cost_centers c ON c.id = e.cost_center_id
    LEFT JOIN reimbursement_request_items ri ON ri.expense_id = e.id
    LEFT JOIN reimbursement_requests r ON r.id = ri.request_id';
$whereSql = ' WHERE (e.user_id = ? OR ? = 1) AND e.expense_date BETWEEN ? AND ? AND e.is_archived = ?';
$queryParams = [$ownerFilterId, $showAllOwners, $start, $end, (int) $showArchived];
if ($search !== '') {
    $whereSql .= " AND (LOCATE(LOWER(?), LOWER(e.merchant)) > 0
        OR LOCATE(LOWER(?), LOWER(e.description)) > 0
        OR LOCATE(LOWER(?), LOWER(COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username))) > 0
        OR LOCATE(LOWER(?), LOWER(c.coa_number)) > 0
        OR LOCATE(LOWER(?), LOWER(c.description)) > 0
        OR LOCATE(LOWER(?), LOWER(COALESCE(r.status, 'available'))) > 0)";
    array_push($queryParams, ...array_fill(0, 6, $search));
}
if ($state === 'available') {
    $whereSql .= ' AND ri.request_id IS NULL';
} elseif ($state !== 'all') {
    $whereSql .= ' AND r.status = ?';
    $queryParams[] = $state;
}
if (($_GET['action'] ?? '') === 'available_expenses') {
    if (!$canSelect || $error !== '') { http_response_code(403); exit(); }
    $available = $conn->execute_query('SELECT e.id, e.amount_cents,
        (SELECT COUNT(*) FROM reimbursement_receipts x WHERE x.expense_id = e.id) AS receipt_count
        ' . $fromSql . $whereSql . ' AND e.user_id = ? AND ri.request_id IS NULL
        ORDER BY e.id LIMIT 501', array_merge($queryParams, [$userId]))->fetch_all(MYSQLI_ASSOC);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(['too_many' => count($available) > 500, 'expenses' => count($available) > 500 ? [] : $available], JSON_THROW_ON_ERROR);
    exit();
}
$sortDirection = strtoupper($sortDir);
$orderSql = match ($sortBy) {
    'merchant' => "e.merchant {$sortDirection}",
    'owner' => "owner_name {$sortDirection}",
    'amount' => "e.amount_cents {$sortDirection}",
    'cost_center' => "c.coa_number {$sortDirection}, c.description {$sortDirection}",
    'status' => "CASE WHEN r.status = 'draft' THEN 1 WHEN r.status = 'submitted' THEN 2 ELSE 0 END {$sortDirection}",
    default => "e.expense_date {$sortDirection}",
};
$expenseTotal = (int) $conn->execute_query('SELECT COUNT(*)' . $fromSql . $whereSql, $queryParams)->fetch_row()[0];
$pagination = paginationState($expenseTotal, $pageSize, $_GET['page'] ?? null);
$listParams = array_merge(['start_date' => $start, 'end_date' => $end], $canViewAll ? ['owner_id' => $ownerFilterId] : [],
    ['q' => $search, 'sort_by' => $sortBy, 'sort_dir' => $sortDir, 'per_page' => $pageSize, 'show' => $showArchived ? 'archived' : 'active', 'state' => $state]);
$listUrl = 'reimbursements.php?' . http_build_query($listParams);
$stateUrl = static fn(string $value): string => 'reimbursements.php?' . http_build_query(array_merge($listParams, ['state' => $value, 'page' => 1]));
$sortUrl = static function (string $column) use ($listParams, $sortBy, $sortDir, $sortDefaults): string {
    $direction = $column === $sortBy ? ($sortDir === 'asc' ? 'desc' : 'asc') : $sortDefaults[$column];
    return 'reimbursements.php?' . http_build_query(array_merge($listParams, ['sort_by' => $column, 'sort_dir' => $direction]));
};
$expenses = $conn->execute_query('SELECT e.id, e.user_id, e.expense_date, e.merchant, e.description, e.amount_cents,
    COALESCE(NULLIF(TRIM(CONCAT_WS(\' \', u.first_name, u.last_name)), \'\'), u.username) AS owner_name,
    COALESCE(ri.coa_number,c.coa_number) AS coa_number, COALESCE(ri.coa_description,c.description) AS coa_description, ri.request_id, r.request_hash, r.status AS request_status, r.is_archived AS request_archived,
    (SELECT COUNT(*) FROM reimbursement_receipts x WHERE x.expense_id = e.id) AS receipt_count
    ' . $fromSql . $whereSql . ' ORDER BY ' . $orderSql . ', e.id DESC LIMIT ? OFFSET ?',
    array_merge($queryParams, [$pageSize, $pagination['offset']]))->fetch_all(MYSQLI_ASSOC);
function reimbursementH(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html><html lang="en">
<?php renderPageHead(applicationPageTitle('Reimbursements'), ['styles' => ['assets/css/style.min.css', 'assets/css/modern.min.css', 'assets/css/pages/reimbursements.css'], 'scripts' => ['assets/js/reimbursements.js']]); ?>
<body><?php include 'templates/header.php'; ?>
<main class="container reimbursement-page">
    <?php if ($selectionRemoved): ?><span hidden data-remove-reimbursement-selection="<?= $selectionRemoved ?>" data-selection-key="reimbursement-selection-<?= $userId ?>"></span><?php endif; ?>
    <div class="page-heading"><div><h1>Reimbursements</h1><p class="page-intro">Record expenses and receipts, then prepare a dated request for the bookkeeper.</p></div>
    <div class="page-heading-actions"><?php if ($canManage): ?><a class="button-add" href="reimbursement_expense.php">+ Add Expense</a><?php endif; ?><a class="button-secondary" href="reimbursement_requests.php">Requests</a><?php if (hasRole(['admin','editor'])): ?><a class="button-secondary" href="reimbursement_cost_centers.php">Chart of Accounts</a><?php endif; ?></div></div>
    <?php $flowStep = 1; include __DIR__ . '/templates/reimbursement_progress.php'; ?>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?= reimbursementH($error) ?></p><?php endif; ?>
    <?php if ($message !== ''): ?><p class="success" role="status"><?= reimbursementH($message) ?></p><?php endif; ?>
    <section class="reimbursement-card">
        <div class="reimbursement-list-heading reimbursement-expenses-list-heading"><h2 class="reimbursement-expenses-heading">Expenses</h2><nav class="control-group" aria-label="Expense Archive"><a class="sort-button<?= !$showArchived ? ' active' : '' ?>"<?= !$showArchived ? ' aria-current="page"' : '' ?> href="<?= reimbursementH('reimbursements.php?' . http_build_query(array_merge($listParams, ['show' => 'active']))) ?>">Active</a><a class="sort-button<?= $showArchived ? ' active' : '' ?>"<?= $showArchived ? ' aria-current="page"' : '' ?> href="<?= reimbursementH('reimbursements.php?' . http_build_query(array_merge($listParams, ['show' => 'archived']))) ?>">Archived</a></nav></div>
        <div class="reimbursement-filter-block">
            <?php renderListFilterSummary(['Archive' => $showArchived ? 'Archived' : 'Active', 'State' => $stateLabels[$state], 'Search' => $search, 'Owner' => $ownerFilterId === 0 ? 'All users' : (array_column($expenseOwners, 'owner_name', 'id')[$ownerFilterId] ?? 'Me'), 'From' => $start, 'Through' => $end], 'reimbursements.php'); ?>
        <form method="get" class="reimbursement-inline-form" data-reimbursement-filters>
                <input type="hidden" name="show" value="<?= $showArchived ? 'archived' : 'active' ?>"><input type="hidden" name="state" value="<?= reimbursementH($state) ?>"><input type="hidden" name="sort_by" value="<?= reimbursementH($sortBy) ?>"><input type="hidden" name="sort_dir" value="<?= reimbursementH($sortDir) ?>"><input type="hidden" name="per_page" value="<?= $pageSize ?>">
                <div class="reimbursement-search-field"><label for="reimbursement-search">Search Expenses</label><div class="list-search-form reimbursement-search-form"><span class="search-icon" aria-hidden="true">⌕</span><input type="search" id="reimbursement-search" name="q" value="<?= reimbursementH($search) ?>" maxlength="100" placeholder="Search Expenses"><?php if ($search !== ''): ?><a class="clear-search" href="<?= reimbursementH('reimbursements.php?' . http_build_query(array_merge($listParams, ['q' => '']))) ?>">Clear</a><?php endif; ?></div></div>
                <label>Start Date <input type="date" name="start_date" value="<?= reimbursementH($start) ?>" required></label>
                <label>End Date <input type="date" name="end_date" value="<?= reimbursementH($end) ?>" required></label>
                <?php if ($canViewAll): ?><label>Expense Owner <select name="owner_id"><option value="0">All Users</option><?php foreach ($expenseOwners as $expenseOwner): ?><option value="<?= (int) $expenseOwner['id'] ?>"<?= $ownerFilterId === (int) $expenseOwner['id'] ? ' selected' : '' ?>><?= reimbursementH($expenseOwner['owner_name']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
                <button class="button-secondary" type="submit" data-reimbursement-apply>Apply</button>
            </form>
            <nav class="reimbursement-sort-group" aria-label="Expense State Filter"><span class="control-label">State:</span><div class="reimbursement-sort-buttons">
                <?php foreach ($stateLabels as $stateValue => $stateLabel): ?>
                    <a href="<?= reimbursementH($stateUrl($stateValue)) ?>" class="sort-button<?= $state === $stateValue ? ' active' : '' ?>"<?= $state === $stateValue ? ' aria-current="page"' : '' ?>><?= reimbursementH($stateLabel) ?></a>
                <?php endforeach; ?>
            </div></nav>
            <div class="reimbursement-sort-group" aria-label="Expense Sort Order"><span class="control-label">Sort:</span><div class="reimbursement-sort-buttons">
                <?php foreach ($sortLabels as $sortKey => $sortLabel): $activeSort = $sortBy === $sortKey; $arrow = ($activeSort ? $sortDir : $sortDefaults[$sortKey]) === 'asc' ? '↑' : '↓'; ?>
                    <a href="<?= reimbursementH($sortUrl($sortKey)) ?>" class="sort-button sort-selection<?= $activeSort ? ' active' : '' ?>"<?= $activeSort ? ' aria-current="true"' : '' ?>><?= reimbursementH($sortLabel) ?> <?= $arrow ?></a>
                <?php endforeach; ?>
            </div></div>
            <?php if ($canSelect): ?><p class="field-help">Select your unclaimed expenses below to make a draft request. An expense already in a draft or submitted request cannot be selected again.</p><?php elseif ($showArchived): ?><p class="field-help">Restore an archived expense before editing it or including it in a request.</p><?php endif; ?>
        </div>
        <?php if ($search !== ''): ?><p class="result-context">Showing expenses matching “<?= reimbursementH($search) ?>”.</p><?php endif; ?>
        <?php if ($canSelect): ?><div class="reimbursement-actions reimbursement-create-actions reimbursement-create-actions-top"><button type="submit" form="reimbursement-create-form" class="save-button" data-create-reimbursement>Create Draft Request from Selected Expenses</button></div><?php endif; ?>
        <?php renderPagination($pagination['total'], $pagination['page'], $pageSize, $listUrl, 'expenses', 'Expense pages'); ?>
        <form method="post" action="<?= reimbursementH($listUrl . '&page=' . $pagination['page']) ?>" id="reimbursement-create-form"<?= $canSelect ? ' data-reimbursement-selection data-available-url="' . reimbursementH($listUrl . '&action=available_expenses') . '"' : '' ?> data-selection-key="reimbursement-selection-<?= $userId ?>" data-selection-scope="<?= hash('sha256', json_encode([$start, $end, $ownerFilterId, $search])) ?>">
            <?= csrfInput() ?><?php if ($canSelect): ?><details><summary>Dates for New Request</summary><p class="field-help">These dates apply to the draft you create. The browsing filters above stay unchanged.</p><div class="reimbursement-inline-form"><label>Request Start Date<input type="date" name="start_date" required value="<?= reimbursementH($_POST['start_date'] ?? $start) ?>"></label><label>Request End Date<input type="date" name="end_date" required value="<?= reimbursementH($_POST['end_date'] ?? $end) ?>"></label></div></details><?php endif; ?>
            <?php if ($canSelect): ?><div class="reimbursement-selection-summary" hidden><span data-selection-count role="status" aria-live="polite"></span><button type="button" class="button-secondary" data-select-all-available>Select All Available</button><button type="button" class="button-secondary" data-clear-selection>Clear Selection</button><span data-selection-total></span><span data-selection-error role="alert" hidden></span></div><?php endif; ?>
            <div class="reimbursement-table-wrap"><table class="data-table reimbursement-expense-table"><thead><tr><th scope="col">Include</th><th scope="col">Date</th><th scope="col">Merchant / Payee</th><th scope="col">Owner</th><th scope="col">Account</th><th scope="col">Receipts</th><th scope="col">Amount</th><th scope="col">State</th><th scope="col">Actions</th></tr></thead><tbody>
                <?php foreach ($expenses as $expense):
                    $expenseId = (int) $expense['id'];
                    $canEditExpense = $canSelect && (int) $expense['user_id'] === $userId && $expense['request_status'] !== 'submitted' && !(int) $expense['request_archived'];
                    $canArchiveExpense = $canManage && (int) $expense['user_id'] === $userId && $expense['request_id'] === null;
                    $canDeleteExpense = $canViewAll && $expense['request_id'] === null;
                ?><tr<?= $expense['request_status'] === 'submitted' ? ' class="reimbursement-row-submitted"' : '' ?> data-selection-expense="<?= (int) $expense['id'] ?>">
                    <td><?php if ($canSelect && $expense['request_id'] === null && (int) $expense['user_id'] === $userId): ?><input type="checkbox" data-amount-cents="<?= (int)$expense['amount_cents'] ?>" data-receipt-count="<?= (int)$expense['receipt_count'] ?>" name="expense_ids[]" value="<?= (int) $expense['id'] ?>" <?= in_array((string) $expense['id'], array_map('strval', is_array($_POST['expense_ids'] ?? null) ? $_POST['expense_ids'] : []), true) ? ' checked' : '' ?> aria-label="Include <?= reimbursementH($expense['merchant']) ?> on <?= reimbursementH($expense['expense_date']) ?>"><?php else: ?>—<?php endif; ?></td>
                    <td><?= reimbursementH($expense['expense_date']) ?></td>
                    <td><a class="record-link" href="reimbursement_expense.php?id=<?= (int) $expense['id'] ?>&amp;mode=view&amp;return=<?= rawurlencode($listUrl . '&page=' . $pagination['page']) ?>"><?= reimbursementH($expense['merchant']) ?></a><?php if ($expense['description'] !== ''): ?><small><?= reimbursementH($expense['description']) ?></small><?php endif; ?><details class="reimbursement-mobile-details"><summary>Expense Details</summary><p>Owner: <?= reimbursementH($expense['owner_name']) ?><br>Account: <?= reimbursementH($expense['coa_number'].' · '.$expense['coa_description']) ?><br>Receipts: <?= (int)$expense['receipt_count'] ?></p><a href="reimbursement_expense.php?id=<?= $expenseId ?>&amp;mode=view&amp;return=<?= rawurlencode($listUrl . '&page=' . $pagination['page']) ?>">View Receipts and Details</a></details></td>
                    <td><?= reimbursementH($expense['owner_name']) ?></td>
                    <td><?= reimbursementH($expense['coa_number'] . ' · ' . $expense['coa_description']) ?></td>
                    <td><?= (int) $expense['receipt_count'] ?></td><td><?= reimbursementMoney((int) $expense['amount_cents']) ?></td>
                    <td><?php if ($expense['request_id']): ?><a href="reimbursement_request.php?id=<?= (int) $expense['request_id'] ?>"><?= reimbursementH(ucfirst((string) $expense['request_status'])) ?></a><?php else: ?>Available<?php endif; ?></td>
                    <td><div class="reimbursement-row-actions">
                        <a href="reimbursement_expense.php?id=<?= $expenseId ?>&amp;mode=view" class="action-button action-icon-button view-button" aria-label="View Expense <?= reimbursementH($expense['merchant']) ?>" title="View" data-tooltip="View"><?= actionIconSvg('view') ?></a>
                        <?php if ($canEditExpense): ?><a href="reimbursement_expense.php?id=<?= $expenseId ?>&amp;mode=edit&amp;return=<?= rawurlencode($listUrl . '&page=' . $pagination['page']) ?>" class="action-button action-icon-button edit-button" aria-label="Edit Expense <?= reimbursementH($expense['merchant']) ?>" title="Edit" data-tooltip="Edit"><?= actionIconSvg('edit') ?></a><?php endif; ?>
                        <?php if ($canArchiveExpense): ?><button type="submit" form="expense-archive-<?= $expenseId ?>" class="action-button action-icon-button <?= $showArchived ? 'restore-button' : 'archive-button' ?>" aria-label="<?= $showArchived ? 'Restore' : 'Archive' ?> Expense <?= reimbursementH($expense['merchant']) ?>" title="<?= $showArchived ? 'Restore' : 'Archive' ?>" data-tooltip="<?= $showArchived ? 'Restore' : 'Archive' ?>"><?= actionIconSvg($showArchived ? 'restore' : 'archive') ?></button><?php endif; ?>
                        <?php if ($canDeleteExpense): ?><button type="submit" form="expense-delete-<?= $expenseId ?>" class="action-button action-icon-button delete-button" aria-label="Delete Expense <?= reimbursementH($expense['merchant']) ?>" title="Delete" data-tooltip="Delete"><?= actionIconSvg('delete') ?></button><?php endif; ?>
                    </div></td>
                </tr><?php endforeach; ?>
                <?php if (!$expenses): ?><tr><td colspan="9"><?= $search !== '' || $state !== 'all' ? 'No expenses match these filters.' : 'No expenses in this date range.' ?></td></tr><?php endif; ?>
            </tbody></table></div>
            <?php if ($canSelect): ?><div class="reimbursement-selection-summary" hidden><span data-selection-count></span><button type="button" class="button-secondary" data-select-all-available>Select All Available</button><button type="button" class="button-secondary" data-clear-selection>Clear Selection</button><span data-selection-total></span><span data-selection-error role="alert" hidden></span></div><?php endif; ?>
        </form>
        <?php foreach ($expenses as $expense): $expenseId = (int) $expense['id']; ?>
            <?php if ($canManage && (int) $expense['user_id'] === $userId && $expense['request_id'] === null): ?><form method="post" id="expense-archive-<?= $expenseId ?>" action="<?= reimbursementH($returnUrl) ?>" hidden><?= csrfInput() ?><input type="hidden" name="action" value="<?= $showArchived ? 'restore_expense' : 'archive_expense' ?>"><input type="hidden" name="expense_id" value="<?= $expenseId ?>"></form><?php endif; ?>
            <?php if ($canViewAll && $expense['request_id'] === null): ?><form method="post" id="expense-delete-<?= $expenseId ?>" action="<?= reimbursementH($returnUrl) ?>" data-confirm="This expense and its receipts will be permanently deleted. This action cannot be undone." data-confirm-title="Delete Expense?" data-confirm-label="Delete Expense" data-confirm-tone="danger" data-admin-unlock-required hidden><?= csrfInput() ?><input type="hidden" name="action" value="delete_expense"><input type="hidden" name="expense_id" value="<?= $expenseId ?>"></form><?php endif; ?>
        <?php endforeach; ?>
        <?php renderPagination($pagination['total'], $pagination['page'], $pageSize, $listUrl, 'expenses', 'Expense pages'); ?>
        <?php if ($canSelect): ?><div class="reimbursement-actions reimbursement-create-actions"><button type="submit" form="reimbursement-create-form" class="save-button" data-create-reimbursement>Create Draft Request from Selected Expenses</button></div><?php endif; ?>
    </section>
</main><?php include 'templates/footer.php'; ?></body></html>
