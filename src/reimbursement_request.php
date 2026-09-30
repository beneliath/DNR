<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/reimbursement_helpers.php';
startSecureSession();
requireLogin();
$canManage = hasRole(['admin', 'editor']);
$conn = applicationDatabaseConnection();
$userId = (int) $_SESSION['user_id'];
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) { http_response_code(400); exit('Request ID required.'); }
$returnUrl=reimbursementReturnUrl($_GET['return'] ?? null,'reimbursement_requests.php');
$mode = (string) ($_GET['mode'] ?? 'view');
if (!in_array($mode, ['view', 'edit'], true)) { http_response_code(400); exit('Invalid request mode.'); }
$isEditing = $mode === 'edit';
$request = $conn->execute_query('SELECT * FROM reimbursement_requests WHERE id = ? AND (user_id = ? OR ? = 1)',
    [$id, $userId, (int) hasRole(['admin'])])->fetch_assoc();
if (!$request) { http_response_code(404); exit('Request not found.'); }
$isOwner = (int) $request['user_id'] === $userId;
$canEditExpenses = $canManage && $isOwner && $request['status'] === 'draft' && !(int) $request['is_archived'];
$canEditBookkeeperNote = $canEditExpenses && array_key_exists('bookkeeper_note', $request);
$canEditDraft = $isEditing && $canEditExpenses;
$canDeleteRequest = $canEditDraft;
$selectionCleared = (string) ($_SESSION['reimbursement_selection_cleared'] ?? '');
unset($_SESSION['reimbursement_selection_cleared']);
$deliverySummary = $request['status'] === 'submitted' ? $conn->execute_query("SELECT status, COUNT(*) AS count FROM reimbursement_email_deliveries WHERE request_id = ? GROUP BY status", [$id])->fetch_all(MYSQLI_ASSOC) : [];
$error = '';
$message = (string) ($_SESSION['reimbursement_request_message'] ?? '');
unset($_SESSION['reimbursement_request_message']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isEditing && !in_array($_POST['action'] ?? '', ['note','retry_delivery','resend_delivery','correction','resolve_correction'],true)) { http_response_code(405); exit('This request view is read-only.'); }
    requireValidCsrfToken();
    if (!$canManage) { http_response_code(403); exit('This account has read-only access.'); }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'delete' && !$canDeleteRequest) { http_response_code(403); exit('You cannot delete this request.'); }
    if ($action === 'delete' && hasRole(['admin'])) {
        require_once __DIR__ . '/two_factor_helpers.php';
        requireRecentAdminElevation('reimbursement_request.php?id=' . $id . '&mode=edit');
    }
    if (in_array($action,['retry_delivery','resend_delivery','correction','resolve_correction'],true)) {
        if (!$isOwner && !hasRole(['admin'])) { http_response_code(403); exit('Only the owner or an administrator can manage delivery.'); }
        try {
            if ($action==='resolve_correction') resolveReimbursementCorrection($conn,$id,$userId,hasRole(['admin']),(string)($_POST['reason'] ?? ''));
            elseif ($action==='correction') correctReimbursementRequest($conn,$id,$userId,hasRole(['admin']),(string)($_POST['reason'] ?? ''));
            else recoverReimbursementDelivery($conn,$id,(int)($_POST['delivery_id'] ?? 0),$userId,hasRole(['admin']),$action,(string)($_POST['reason'] ?? ''),($_POST['provider_checked'] ?? '')==='1');
            $_SESSION['reimbursement_request_message']='Delivery action recorded. Expenses remain locked. No payment state was changed.';
            header('Location: reimbursement_request.php?id='.$id,true,303); exit();
        } catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
    } else {
    if ($action !== 'delete' && !$isOwner) { http_response_code(403); exit('Only the request owner can change it.'); }
    if ($action !== 'delete' && ($request['status'] !== 'draft' || (int) $request['is_archived'])) { http_response_code(409); exit('Restore an archived draft before changing it. Submitted requests cannot be changed.'); }
    try {
        if ($action === 'note') {
            $deleteNote = ($_POST['delete_note'] ?? '') === '1';
            $note = $deleteNote ? '' : reimbursementSubmissionNote($_POST['bookkeeper_note'] ?? '');
            $conn->begin_transaction();
            try {
                $locked = $conn->execute_query('SELECT user_id,status,is_archived,bookkeeper_note FROM reimbursement_requests WHERE id=? FOR UPDATE', [$id])->fetch_assoc();
                if (!$locked || (int) $locked['user_id'] !== $userId || $locked['status'] !== 'draft' || (int) $locked['is_archived']) throw new InvalidArgumentException('Only the owner of an active draft can update the note.');
                if (!$deleteNote && $note === '' && $locked['bookkeeper_note'] !== '') throw new InvalidArgumentException('Use Delete Note to remove the saved note, or enter a new note.');
                if ($note !== $locked['bookkeeper_note']) {
                    $conn->execute_query('UPDATE reimbursement_requests SET bookkeeper_note=? WHERE id=?', [$note,$id]);
                    reimbursementEvent($conn,'request',$id,$deleteNote ? 'bookkeeper_note_deleted' : 'bookkeeper_note_updated');
                }
                $conn->commit();
            } catch (Throwable $e) { $conn->rollback(); throw $e; }
            $_SESSION['reimbursement_request_message'] = $deleteNote ? 'Note to the bookkeeper deleted.' : 'Note to the bookkeeper saved. Review the email before submitting.';
        } elseif ($action === 'dates') {
            $start=reimbursementDate($_POST['start_date'] ?? null,'start date');
            $end=reimbursementDate($_POST['end_date'] ?? null,'end date');
            if ($start>$end) throw new InvalidArgumentException('End date must follow start date.');
            $conn->begin_transaction();
            try {
                $locked=$conn->execute_query('SELECT status,is_archived FROM reimbursement_requests WHERE id=? FOR UPDATE',[$id])->fetch_assoc();
                if ($locked['status']!=='draft' || (int)$locked['is_archived']) throw new InvalidArgumentException('Only active draft dates can change.');
                if ($conn->execute_query('SELECT e.id FROM reimbursement_expenses e JOIN reimbursement_request_items ri ON ri.expense_id=e.id WHERE ri.request_id=? AND (e.expense_date<? OR e.expense_date>?) FOR UPDATE',[$id,$start,$end])->fetch_row()) throw new InvalidArgumentException('The range must include every selected expense.');
                $conn->execute_query('UPDATE reimbursement_requests SET start_date=?,end_date=? WHERE id=?',[$start,$end,$id]);
                reimbursementEvent($conn,'request',$id,'dates_changed',$start.' to '.$end);
                $conn->commit();
            } catch (Throwable $e) { $conn->rollback(); throw $e; }
        } elseif ($action === 'remove') {
            $expenseId = filter_var($_POST['expense_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$expenseId) throw new InvalidArgumentException('Select an expense.');
            $conn->execute_query('DELETE ri FROM reimbursement_request_items ri JOIN reimbursement_requests r ON r.id = ri.request_id
                WHERE ri.request_id = ? AND ri.expense_id = ? AND r.user_id = ? AND r.status = \'draft\' AND r.is_archived = 0', [$id, $expenseId, $userId]);
            reimbursementEvent($conn,'request',$id,'expense_removed','Expense #'.$expenseId);
            $_SESSION['reimbursement_request_message'] = 'Expense removed from this draft and available for another request.';
        } elseif ($action === 'add') {
            $expenseId = filter_var($_POST['expense_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$expenseId) throw new InvalidArgumentException('Select an expense.');
            $conn->begin_transaction();
            try {
                $lockedRequest = $conn->execute_query('SELECT status, is_archived,start_date,end_date FROM reimbursement_requests WHERE id = ? AND user_id = ? FOR UPDATE', [$id, $userId])->fetch_assoc();
                if (!$lockedRequest || $lockedRequest['status'] !== 'draft' || (int) $lockedRequest['is_archived']) throw new InvalidArgumentException('Restore the draft before editing it.');
                if (!$conn->execute_query('SELECT id FROM reimbursement_expenses WHERE id = ? AND user_id = ? AND is_archived = 0 AND expense_date BETWEEN ? AND ? FOR UPDATE',
                    [$expenseId, $userId, $lockedRequest['start_date'], $lockedRequest['end_date']])->fetch_row()) throw new InvalidArgumentException('Expense is outside this request date range.');
                $conn->execute_query('INSERT INTO reimbursement_request_items (request_id, expense_id) VALUES (?, ?)', [$id, $expenseId]);
                reimbursementEvent($conn,'request',$id,'draft_updated');
                $conn->commit();
            } catch (Throwable $e) { $conn->rollback(); throw $e; }
            $_SESSION['reimbursement_request_message'] = 'Expense added to draft.';
        } elseif ($action === 'delete') {
            deleteReimbursementRequest($conn, $id, $userId, hasRole(['admin']));
            $_SESSION['reimbursement_request_message'] = 'Request deleted. Its expenses and receipts were kept and are available for another request.';
            header('Location: reimbursement_requests.php'); exit();
        } else throw new InvalidArgumentException('Unknown action.');
        if ($action === 'note' && ($_POST['continue_to_review'] ?? '') === '1') {
            unset($_SESSION['reimbursement_request_message']);
            header('Location: reimbursement_submit.php?id=' . $id, true, 303); exit();
        }
        header('Location: reimbursement_request.php?id=' . $id . '&mode='.($isEditing ? 'edit' : 'view').'&return='.rawurlencode($returnUrl), true, 303); exit();
    } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
    catch (mysqli_sql_exception $e) { $error = $e->getCode() === 1062 ? 'That expense already belongs to another request.' : 'Unable to update this request.'; }
    }
}
$items = $conn->execute_query('SELECT ri.expense_id, COALESCE(ri.expense_date, e.expense_date) AS expense_date,
    COALESCE(ri.merchant, e.merchant) AS merchant, COALESCE(ri.description, e.description) AS description,
    COALESCE(ri.amount_cents, e.amount_cents) AS amount_cents,
    COALESCE(ri.coa_number, c.coa_number) AS coa_number,
    COALESCE(ri.coa_description, c.description) AS coa_description
    FROM reimbursement_request_items ri JOIN reimbursement_expenses e ON e.id = ri.expense_id
    JOIN reimbursement_cost_centers c ON c.id = e.cost_center_id
    WHERE ri.request_id = ? ORDER BY expense_date, ri.expense_id', [$id])->fetch_all(MYSQLI_ASSOC);
$receiptFilesByExpense = [];
foreach ($conn->execute_query('SELECT rr.id, rr.expense_id, rr.filename, rr.content_type
    FROM reimbursement_receipts rr JOIN reimbursement_request_items ri ON ri.expense_id = rr.expense_id
    WHERE ri.request_id = ? ORDER BY rr.expense_id, rr.id', [$id])->fetch_all(MYSQLI_ASSOC) as $receipt) {
    $receiptFilesByExpense[(int) $receipt['expense_id']][] = reimbursementReceiptPackageFilename($receipt);
}
$total = array_sum(array_map(static fn($row) => (int) $row['amount_cents'], $items));
$available = $canEditDraft ? $conn->execute_query('SELECT e.id, e.expense_date, e.merchant, e.amount_cents FROM reimbursement_expenses e
    LEFT JOIN reimbursement_request_items ri ON ri.expense_id = e.id
    WHERE e.user_id = ? AND e.is_archived = 0 AND e.expense_date BETWEEN ? AND ? AND ri.expense_id IS NULL
    ORDER BY e.expense_date, e.id LIMIT 500', [$userId, $request['start_date'], $request['end_date']])->fetch_all(MYSQLI_ASSOC) : [];
$owner = reimbursementOwner($conn, (int) $request['user_id']);
if (!empty($request['owner_name_snapshot'])) $owner['display_name']=$request['owner_name_snapshot'];
$snapshot=$request['status']==='submitted' ? reimbursementSnapshot($conn,$id) : null;
if (!empty($snapshot['context']['owner']['display_name'])) $owner['display_name']=$snapshot['context']['owner']['display_name'];
$deliveries=$request['status']==='submitted' ? $conn->execute_query('SELECT id,recipient_type,status,attempts,sent_at,last_error,smtp_message_id FROM reimbursement_email_deliveries WHERE request_id=? ORDER BY id',[$id])->fetch_all(MYSQLI_ASSOC) : [];
$events=$conn->execute_query('SELECT e.*,u.username FROM reimbursement_events e LEFT JOIN users u ON u.id=e.actor_id WHERE e.entity_type=\'request\' AND e.entity_id=? ORDER BY e.id DESC LIMIT 100',[$id])->fetch_all(MYSQLI_ASSOC);
function reimbursementRequestH(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html><html lang="en">
<?php renderPageHead(applicationPageTitle('Reimbursement Request'), ['styles' => ['assets/css/style.min.css', 'assets/css/modern.min.css', 'assets/css/pages/reimbursements.css'], 'scripts' => ['assets/js/reimbursements.js']]); ?>
<body><?php include 'templates/header.php'; ?><main class="container reimbursement-page">
<?php if ($selectionCleared !== ''): ?><span hidden data-clear-reimbursement-selection="<?= reimbursementRequestH($selectionCleared) ?>"></span><?php endif; ?>
<nav class="breadcrumb" aria-label="Breadcrumb"><a href="reimbursements.php">Reimbursements</a><span aria-hidden="true">/</span><a href="reimbursement_requests.php">Requests</a><span aria-hidden="true">/</span><span>Request <?= reimbursementRequestH($request['request_hash']) ?></span></nav>
<div class="page-heading"><div><h1>Reimbursement Request</h1><p class="page-intro"><?= reimbursementRequestH($owner['display_name']) ?> · <?= reimbursementRequestH($request['start_date'] . ' to ' . $request['end_date']) ?> · <?= reimbursementRequestH(ucfirst($request['status'])) ?><?= (int) $request['is_archived'] ? ' · Archived' : '' ?></p></div><?php if ($items): ?><div class="page-heading-actions"><a class="button-secondary" href="download_reimbursement.php?id=<?= $id ?>&format=pdf">Download Report PDF</a><a class="button-secondary" href="download_reimbursement.php?id=<?= $id ?>&format=zip"><?= $request['status']==='submitted' ? 'Download Submitted Package' : 'Download Package ZIP' ?></a></div><?php endif; ?></div>
<?php $flowStep = $request['status'] === 'submitted' ? 4 : 2; $flowSubmitted = $request['status'] === 'submitted'; $flowRequestId = $id; $flowCount = count($items); $flowTotal = $total; include __DIR__ . '/templates/reimbursement_progress.php'; ?>
<?php if ($deliverySummary): ?>
<p class="field-help" role="status">Email delivery: <?php
$counts = array_column($deliverySummary, 'count', 'status');
if (!empty($counts['delivery_uncertain'])) echo 'Needs attention — delivery could not be confirmed. Check provider records before resending.';
elseif (!empty($counts['failed'])) echo 'Needs attention — one or more recipients could not be reached.';
elseif (!empty($counts['pending']) || !empty($counts['processing']) || !empty($counts['retry'])) echo 'Queued for delivery.';
elseif (!empty($counts['cancelled'])) echo 'Unsent delivery cancelled; review delivery history.';
else echo 'Accepted by SMTP for all recipients. This does not confirm reading or payment.';
?></p>
<?php endif; ?>
<?php if ($error): ?><p class="error" role="alert"><?= reimbursementRequestH($error) ?></p><?php endif; ?><?php if ($message): ?><p class="success" role="status"><?= reimbursementRequestH($message) ?></p><?php endif; ?>
<?php if ((int) $request['is_archived']): ?><p class="field-help">Archived request. Restore it from the Requests page before making changes.</p><?php elseif (!$canEditDraft && $request['status'] === 'draft'): ?><p class="field-help">Read-only request view.<?= $canEditExpenses ? ' Select an expense below to edit it while this request is a draft.' : '' ?></p><?php elseif ($request['status'] === 'draft'): ?><p class="field-help">Review the expenses, report, and receipt package. Downloading does not change this draft.</p><?php else: ?><p class="success">Submitted <?= reimbursementRequestH($request['submitted_at']) ?> UTC. These expenses cannot appear in another request.</p><?php endif; ?>
<nav class="reimbursement-actions" aria-label="Reimbursement Navigation"><a class="button-secondary" href="reimbursements.php">Expenses</a><a class="button-secondary" href="<?= reimbursementRequestH($returnUrl) ?>">Back to Requests</a><?php if ($canManage): ?><a class="button-secondary" href="reimbursement_cost_centers.php">Chart of Accounts</a><?php endif; ?><?php if ($canEditExpenses && !$isEditing): ?><a class="button-secondary" href="reimbursement_request.php?id=<?= $id ?>&amp;mode=edit&amp;return=<?= rawurlencode($returnUrl) ?>">Edit Draft</a><?php endif; ?><?php if ($canEditBookkeeperNote): ?><button type="submit" form="reimbursement-note-form" name="continue_to_review" value="1" class="save-button reimbursement-review-submit" data-reimbursement-progress>Review and Submit</button><?php elseif ($canEditExpenses): ?><a class="save-button reimbursement-review-submit" data-reimbursement-progress href="reimbursement_submit.php?id=<?= $id ?>">Review and Submit</a><?php endif; ?></nav>
<?php if ($canEditBookkeeperNote || $canEditExpenses): ?><p class="invitation-submit-status" data-reimbursement-progress-status role="status" hidden><span class="invitation-submit-spinner" aria-hidden="true"></span><span>Preparing your email review, report, and ZIP package… Please wait.</span></p><?php endif; ?>
<?php if ($canEditBookkeeperNote): ?><section class="reimbursement-card"><h2>Note to the Bookkeeper</h2><form id="reimbursement-note-form" method="post" data-reimbursement-note><?= csrfInput() ?><input type="hidden" name="action" value="note"><label for="bookkeeper-note">Brief note or comment (optional)</label><textarea id="bookkeeper-note" name="bookkeeper_note" rows="4" maxlength="1000"><?= reimbursementRequestH($error !== '' && isset($_POST['bookkeeper_note']) && is_string($_POST['bookkeeper_note']) ? $_POST['bookkeeper_note'] : $request['bookkeeper_note']) ?></textarea><p class="field-help">The saved note appears in the bookkeeper's email.</p><div class="reimbursement-actions"><button type="submit" class="button-secondary" data-reimbursement-save-note>Save Note</button><?php if ($request['bookkeeper_note'] !== ''): ?><button type="submit" name="delete_note" value="1" class="button-secondary">Delete Note</button><?php endif; ?></div></form></section><?php endif; ?>
<?php if ($canEditDraft): ?><div class="reimbursement-draft-controls">
<section class="reimbursement-card reimbursement-date-range"><h2>Request Date Range</h2><form method="post" class="reimbursement-date-range-form" data-reimbursement-date-range><?= csrfInput() ?><input type="hidden" name="action" value="dates"><label>Start Date<input type="date" name="start_date" required value="<?= reimbursementRequestH($request['start_date']) ?>"></label><label>End Date<input type="date" name="end_date" required value="<?= reimbursementRequestH($request['end_date']) ?>"></label><button type="submit" class="button-secondary" data-reimbursement-update-dates>Update Dates</button></form></section>
<?php if ($available): ?><section class="reimbursement-card reimbursement-add-expense"><h2>Add an Expense in This Date Range</h2><form method="post" class="reimbursement-inline-form"><?= csrfInput() ?><input type="hidden" name="action" value="add"><label>Available Expense <select name="expense_id" required><option value="">Choose an Expense</option><?php foreach ($available as $expense): ?><option value="<?= (int) $expense['id'] ?>"><?= reimbursementRequestH($expense['expense_date'] . ' · ' . $expense['merchant'] . ' · ' . reimbursementMoney((int) $expense['amount_cents'])) ?></option><?php endforeach; ?></select></label><button type="submit" class="button-secondary">Add Expense</button></form></section><?php endif; ?>
</div><?php endif; ?>
<section class="reimbursement-card" id="request-expenses"><h2>Expenses</h2><p class="field-help">Receipt filenames match the files in the receipts/ folder of the package ZIP.</p><div class="reimbursement-table-wrap"><table class="data-table"><thead><tr><th scope="col">Date</th><th scope="col">Merchant / Payee</th><th scope="col">Account</th><th scope="col">Receipt Files</th><th scope="col">Amount</th><?php if ($canEditDraft): ?><th scope="col">Action</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($items as $item): ?><tr><td><?= reimbursementRequestH($item['expense_date']) ?></td><td><a href="reimbursement_expense.php?id=<?= (int) $item['expense_id'] ?>&amp;mode=view&amp;return=<?= rawurlencode('reimbursement_request.php?id='.$id) ?>"><?= reimbursementRequestH($item['merchant']) ?></a><?php if ($item['description']): ?><small><?= reimbursementRequestH($item['description']) ?></small><?php endif; ?></td><td><?= reimbursementRequestH($item['coa_number'] . ' · ' . $item['coa_description']) ?></td><td style="overflow-wrap:anywhere"><?= implode('<br>', array_map('reimbursementRequestH', $receiptFilesByExpense[(int) $item['expense_id']] ?? ['No Receipt Attached'])) ?></td><td><?= reimbursementMoney((int) $item['amount_cents']) ?></td><?php if ($canEditDraft): ?><td><form method="post"><?= csrfInput() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="expense_id" value="<?= (int) $item['expense_id'] ?>"><button type="submit" class="button-secondary">Remove</button></form></td><?php endif; ?></tr><?php endforeach; ?>
<?php if (!$items): ?><tr><td colspan="<?= $canEditDraft ? 6 : 5 ?>">No expenses in this request.</td></tr><?php endif; ?>
</tbody><tfoot><tr><th colspan="4" scope="row">Total Amount Due</th><th><?= reimbursementMoney($total) ?></th><?php if ($canEditDraft): ?><th></th><?php endif; ?></tr></tfoot></table></div></section>

<?php if ($canDeleteRequest): ?><div class="reimbursement-actions reimbursement-request-actions"><form method="post" data-confirm-title="Delete Request?" data-confirm-label="Delete Request" data-confirm-tone="danger" data-confirm="Delete this request? Its expenses and receipts will be kept and become available for another request."<?= hasRole(['admin']) ? ' data-admin-unlock-required' : '' ?>><?= csrfInput() ?><input type="hidden" name="action" value="delete"><button class="delete-button" type="submit">Delete Request</button></form></div><?php endif; ?>
<?php include __DIR__ . '/templates/reimbursement_delivery_history.php'; ?>
</main><?php include 'templates/footer.php'; ?></body></html>
