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
$message = (string) ($_SESSION['reimbursement_request_message'] ?? '');
unset($_SESSION['reimbursement_request_message']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    if (!$canManage) { http_response_code(403); exit('This account has read-only access.'); }
    $requestId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $action = (string) ($_POST['action'] ?? '');
    if (!$requestId || !in_array($action, ['archive', 'restore'], true)) {
        http_response_code(400); exit('Invalid request action.');
    }
    $target = $conn->execute_query('SELECT user_id, is_archived FROM reimbursement_requests WHERE id = ?', [$requestId])->fetch_assoc();
    if (!$target || ((int) $target['user_id'] !== $userId && !$canViewAll)) {
        http_response_code(404); exit('Request not found.');
    }
    $conn->execute_query('UPDATE reimbursement_requests SET is_archived = ? WHERE id = ?', [$action === 'archive' ? 1 : 0, $requestId]);
    reimbursementEvent($conn,'request',$requestId,$action);
    $_SESSION['reimbursement_request_message'] = $action === 'archive' ? 'Request archived.' : 'Request restored.';
    header('Location: '.reimbursementReturnUrl('reimbursement_requests.php?'.http_build_query($_GET),'reimbursement_requests.php')); exit();
}
$search=mb_substr(trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : ''),0,160);
$status=in_array($_GET['status'] ?? '',['draft','submitted'],true) ? $_GET['status'] : '';
$filterOwner=$canViewAll ? max(0,(int)($_GET['owner_id'] ?? 0)) : $userId;
$start=is_string($_GET['start_date'] ?? null) ? $_GET['start_date'] : '';
$end=is_string($_GET['end_date'] ?? null) ? $_GET['end_date'] : '';
foreach (['start','end'] as $dateField) { try { if ($$dateField!=='') reimbursementDate($$dateField,'filter date'); } catch (InvalidArgumentException $e) { $$dateField=''; } }
$sorts=['created'=>'r.created_at','submitted'=>'r.submitted_at','total'=>'total_cents','owner'=>'owner_name','request'=>'r.id'];
$sortDefaults = ['created' => 'desc', 'submitted' => 'desc', 'total' => 'desc', 'owner' => 'asc', 'request' => 'desc'];
$selectedSort = listSortSelection($_GET,
    array_map(static fn(string $value): array => ['sort_dir', $value], $sortDefaults), 'created');
$sort = $selectedSort['column'];
$direction = $selectedSort['direction'];
$where='r.is_archived=? AND (r.user_id=? OR ?=1)'; $params=[(int)$showArchived,$userId,(int)$canViewAll];
if ($filterOwner) { $where.=' AND r.user_id=?'; $params[]=$filterOwner; }
if ($status!=='') { $where.=' AND r.status=?'; $params[]=$status; }
if ($start!=='') { $where.=' AND r.end_date>=?'; $params[]=$start; }
if ($end!=='') { $where.=' AND r.start_date<=?'; $params[]=$end; }
if ($search!=='') {
    $where.=" AND (r.request_hash LIKE ? ESCAPE '!' OR CONCAT_WS(' ',u.first_name,u.last_name,u.username) LIKE ? ESCAPE '!' OR CAST(r.id AS CHAR)=?)";
    $pattern='%'.strtr($search,['!'=>'!!','%'=>'!%','_'=>'!_']).'%'; array_push($params,$pattern,$pattern,ltrim($search,'#'));
}
$pageSize=paginationPageSizePreference('reimbursement_requests',$_GET['per_page'] ?? null,20);
$total=(int)$conn->execute_query('SELECT COUNT(*) FROM reimbursement_requests r JOIN users u ON u.id=r.user_id WHERE '.$where,$params)->fetch_row()[0];
$pagination=paginationState($total,$pageSize,$_GET['page'] ?? null);
$requests=$conn->execute_query("SELECT r.*, COALESCE(r.owner_name_snapshot,NULLIF(TRIM(CONCAT_WS(' ',u.first_name,u.last_name)),''),u.username) AS owner_name,
    COUNT(ri.expense_id) AS expense_count,
    (SELECT COUNT(*) FROM reimbursement_request_items x JOIN reimbursement_receipts rr ON rr.expense_id=x.expense_id WHERE x.request_id=r.id) AS receipt_count,
    COALESCE(SUM(COALESCE(ri.amount_cents,e.amount_cents)),0) AS total_cents
    FROM reimbursement_requests r JOIN users u ON u.id=r.user_id LEFT JOIN reimbursement_request_items ri ON ri.request_id=r.id
    LEFT JOIN reimbursement_expenses e ON e.id=ri.expense_id WHERE ".$where.' GROUP BY r.id ORDER BY '.$sorts[$sort].' '.strtoupper($direction).',r.id DESC LIMIT ? OFFSET ?',array_merge($params,[$pageSize,$pagination['offset']]))->fetch_all(MYSQLI_ASSOC);
$listParams=['show'=>$showArchived?'archived':'active','q'=>$search,'owner_id'=>$filterOwner,'status'=>$status,'start_date'=>$start,'end_date'=>$end,'sort_by'=>$sort,'sort_dir'=>$direction,'per_page'=>$pageSize];
$listUrl='reimbursement_requests.php?'.http_build_query($listParams);
$sortUrl = static function (string $column) use ($listParams, $sort, $direction, $sortDefaults): string {
    $nextDirection = $column === $sort ? ($direction === 'asc' ? 'desc' : 'asc') : $sortDefaults[$column];
    return 'reimbursement_requests.php?' . http_build_query(array_replace($listParams, ['sort_by' => $column, 'sort_dir' => $nextDirection]));
};
$owners=$canViewAll ? $conn->query("SELECT DISTINCT u.id,COALESCE(NULLIF(TRIM(CONCAT_WS(' ',u.first_name,u.last_name)),''),u.username) AS name FROM users u JOIN reimbursement_requests r ON r.user_id=u.id ORDER BY name")->fetch_all(MYSQLI_ASSOC) : [];
function reimbursementRequestsH(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html><html lang="en">
<?php renderPageHead(applicationPageTitle('Reimbursement Requests'), ['styles' => ['assets/css/style.min.css', 'assets/css/modern.min.css', 'assets/css/pages/reimbursements.css'], 'scripts' => ['assets/js/reimbursements.js']]); ?>
<body><?php include 'templates/header.php'; ?>
<main class="container reimbursement-page">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="reimbursements.php">Reimbursements</a><span aria-hidden="true">/</span><span>Requests</span></nav>
    <div class="page-heading"><div><h1>Requests</h1><p class="page-intro">Review draft and submitted reimbursement requests.</p></div>
        <div class="page-heading-actions"><a class="button-secondary" href="reimbursements.php">Expenses</a><?php if ($canManage): ?><a class="button-secondary" href="reimbursement_cost_centers.php">Chart of Accounts</a><?php endif; ?></div></div>
    <?php if ($message): ?><p class="success" role="status"><?= reimbursementRequestsH($message) ?></p><?php endif; ?>
    <?php renderListContextTabs([
        'active' => ['Active', 'reimbursement_requests.php?' . http_build_query(array_replace($listParams, ['show' => 'active']))],
        'archived' => ['Archived', 'reimbursement_requests.php?' . http_build_query(array_replace($listParams, ['show' => 'archived']))],
    ], $showArchived ? 'archived' : 'active', 'Request Status'); ?>
    <section class="reimbursement-request-filters"><?php renderListFilterSummary(['Visibility' => $showArchived ? 'Archived' : 'Active', 'Status' => $status !== '' ? \Dnr\Domain\ReferenceData::label($status) : 'All', 'Search' => $search, 'Owner' => $filterOwner === 0 ? 'All Users' : (array_column($owners, 'name', 'id')[$filterOwner] ?? 'Me'), 'From' => $start, 'Through' => $end], 'reimbursement_requests.php'); ?><form method="get" class="reimbursement-filter-form unified-list-filter" data-reimbursement-filters>
<input type="hidden" name="show" value="<?= $showArchived?'archived':'active' ?>">
<label>Search Requests<input type="search" name="q" placeholder="Reference or Owner" value="<?= reimbursementRequestsH($search) ?>"></label>
<?php if ($canViewAll): ?><label>Owner<select name="owner_id"><option value="0">All Users</option><?php foreach ($owners as $owner): ?><option value="<?= (int)$owner['id'] ?>"<?= $filterOwner===(int)$owner['id']?' selected':'' ?>><?= reimbursementRequestsH($owner['name']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
<label>Status<select name="status"><option value="">All Statuses</option><option value="draft"<?= $status==='draft'?' selected':'' ?>>Draft</option><option value="submitted"<?= $status==='submitted'?' selected':'' ?>>Submitted</option></select></label>
<label>Expense Dates From<input type="text" data-date-picker="date" placeholder="YYYY-MM-DD" name="start_date" value="<?= reimbursementRequestsH($start) ?>"></label><label>Through<input type="text" data-date-picker="date" placeholder="YYYY-MM-DD" name="end_date" value="<?= reimbursementRequestsH($end) ?>"></label>
<input type="hidden" name="sort_by" value="<?= reimbursementRequestsH($sort) ?>"><input type="hidden" name="sort_dir" value="<?= reimbursementRequestsH($direction) ?>"><input type="hidden" name="per_page" value="<?= $pageSize ?>"><button class="button-secondary" type="submit" data-reimbursement-apply>Apply</button></form></section>
    <section class="reimbursement-card reimbursement-request-list"><div class="reimbursement-list-heading"><h2>Reimbursement Requests</h2></div>
        <?php renderPagination($pagination['total'], $pagination['page'], $pageSize, $listUrl, 'requests', 'Request pages'); ?>
        <div class="reimbursement-table-wrap"><table class="data-table reimbursement-request-table sortable-table"><thead><tr>
            <th scope="col" aria-sort="<?= listSortState($sort === 'request', $direction) ?>"><a class="table-sort-link" href="<?= reimbursementRequestsH($sortUrl('request')) ?>">Request <span aria-hidden="true"><?= listSortArrow($sort === 'request', $direction) ?></span></a></th>
            <th scope="col" aria-sort="<?= listSortState($sort === 'owner', $direction) ?>"><a class="table-sort-link" href="<?= reimbursementRequestsH($sortUrl('owner')) ?>">Owner <span aria-hidden="true"><?= listSortArrow($sort === 'owner', $direction) ?></span></a></th>
            <th scope="col">Expense Dates</th><th scope="col">Expenses</th><th scope="col">Receipts</th>
            <th scope="col" aria-sort="<?= listSortState($sort === 'total', $direction) ?>"><a class="table-sort-link" href="<?= reimbursementRequestsH($sortUrl('total')) ?>">Total <span aria-hidden="true"><?= listSortArrow($sort === 'total', $direction) ?></span></a></th>
            <th scope="col">Status</th>
            <th scope="col" aria-sort="<?= listSortState($sort === 'created', $direction) ?>"><a class="table-sort-link" href="<?= reimbursementRequestsH($sortUrl('created')) ?>">Created <span aria-hidden="true"><?= listSortArrow($sort === 'created', $direction) ?></span></a></th>
            <th scope="col" aria-sort="<?= listSortState($sort === 'submitted', $direction) ?>"><a class="table-sort-link" href="<?= reimbursementRequestsH($sortUrl('submitted')) ?>">Submitted <span aria-hidden="true"><?= listSortArrow($sort === 'submitted', $direction) ?></span></a></th>
            <th scope="col">Actions</th>
        </tr></thead><tbody>
            <?php foreach ($requests as $request): ?>
            <?php $requestId = (int) $request['id']; $requestHash = reimbursementRequestsH($request['request_hash']); $canEdit = $canManage && (int) $request['user_id'] === $userId && $request['status'] === 'draft' && !$showArchived; $canDelete = $canEdit; ?>
            <tr<?= $request['status'] === 'submitted' ? ' class="reimbursement-row-submitted"' : '' ?>><td><div class="reimbursement-request-identity"><?php if ($canEdit): ?><a href="reimbursement_submit.php?id=<?= $requestId ?>" class="action-button action-icon-button view-button" aria-label="Submit Request <?= $requestHash ?>" data-tooltip="Submit"><?= actionIconSvg('submit') ?></a><?php endif; ?><a class="record-link" href="reimbursement_request.php?id=<?= $requestId ?>&amp;return=<?= rawurlencode($listUrl.'&page='.$pagination['page']) ?>"><?= $requestHash ?></a></div><small>Request #<?= $requestId ?></small></td><td><?= reimbursementRequestsH($request['owner_name']) ?></td><td><time datetime="<?= reimbursementRequestsH($request['start_date']) ?>"><?= reimbursementRequestsH($request['start_date']) ?></time> to <time datetime="<?= reimbursementRequestsH($request['end_date']) ?>"><?= reimbursementRequestsH($request['end_date']) ?></time></td><td><?= (int) $request['expense_count'] ?></td><td><?= (int) $request['receipt_count'] ?></td><td><?= reimbursementMoney((int) $request['total_cents']) ?></td><td><?= reimbursementRequestsH(\Dnr\Domain\ReferenceData::label($request['status'])) ?></td><?php foreach (['created_at', 'submitted_at'] as $timestampField): ?><td><?php if (!empty($request[$timestampField])): ?><time class="reimbursement-request-timestamp" datetime="<?= reimbursementRequestsH(str_replace(' ', 'T', $request[$timestampField]) . 'Z') ?>"><span><?= reimbursementRequestsH(substr($request[$timestampField], 0, 10)) ?></span> <span><?= reimbursementRequestsH(substr($request[$timestampField], 11)) ?> UTC</span></time><?php else: ?>—<?php endif; ?></td><?php endforeach; ?>
            <td><div class="reimbursement-row-actions"><a href="reimbursement_request.php?id=<?= $requestId ?>&amp;return=<?= rawurlencode($listUrl.'&page='.$pagination['page']) ?>" class="action-button action-icon-button view-button" aria-label="View Request <?= $requestHash ?>" title="View" data-tooltip="View"><?= actionIconSvg('view') ?></a>
            <?php if ($canEdit): ?><a href="reimbursement_request.php?id=<?= $requestId ?>&amp;mode=edit&amp;return=<?= rawurlencode($listUrl.'&page='.$pagination['page']) ?>#request-expenses" class="action-button action-icon-button edit-button" aria-label="Edit Request <?= $requestHash ?>" title="Edit" data-tooltip="Edit"><?= actionIconSvg('edit') ?></a><?php endif; ?>
            <?php if ($canManage): ?><form method="post" action="<?= reimbursementRequestsH($listUrl) ?>"><?= csrfInput() ?><input type="hidden" name="id" value="<?= $requestId ?>"><input type="hidden" name="action" value="<?= $showArchived ? 'restore' : 'archive' ?>"><button type="submit" class="action-button action-icon-button <?= $showArchived ? 'restore-button' : 'archive-button' ?>" aria-label="<?= $showArchived ? 'Restore' : 'Archive' ?> Request <?= $requestHash ?>" title="<?= $showArchived ? 'Restore' : 'Archive' ?>" data-tooltip="<?= $showArchived ? 'Restore' : 'Archive' ?>"><?= actionIconSvg($showArchived ? 'restore' : 'archive') ?></button></form><?php endif; ?>
            <?php if ($canDelete): ?><form method="post" action="reimbursement_request.php?id=<?= $requestId ?>&amp;mode=edit" data-confirm-title="Delete Request?" data-confirm-label="Delete Request" data-confirm-tone="danger" data-confirm="Delete this request? Its expenses and receipts will remain available for another request."<?= $canViewAll ? ' data-admin-unlock-required' : '' ?>><?= csrfInput() ?><input type="hidden" name="action" value="delete"><button type="submit" class="action-button action-icon-button delete-button" aria-label="Delete Request <?= $requestHash ?>" title="Delete" data-tooltip="Delete"><?= actionIconSvg('delete') ?></button></form><?php endif; ?></div></td></tr><?php endforeach; ?>
            <?php if (!$requests): ?><tr><td colspan="10">No <?= $showArchived ? 'archived' : 'active' ?> reimbursement requests.</td></tr><?php endif; ?>
        </tbody></table></div>
        <?php renderPagination($pagination['total'], $pagination['page'], $pageSize, $listUrl, 'requests', 'Request pages'); ?>
    </section>
</main><?php include 'templates/footer.php'; ?></body></html>
