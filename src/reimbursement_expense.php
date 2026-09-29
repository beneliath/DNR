<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/reimbursement_helpers.php';
startSecureSession();
requireLogin();
$conn = applicationDatabaseConnection();
$userId = (int) $_SESSION['user_id'];
$canManage = hasRole(['admin', 'editor']);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
if (isset($_GET['id']) && !$id) { http_response_code(400); exit('Invalid expense ID.'); }
$mode = $_GET['mode'] ?? 'edit';
if (!in_array($mode, ['view', 'edit'], true)) { http_response_code(400); exit('Invalid expense mode.'); }
$isViewing = $mode === 'view';
if (!$id && !$canManage) { http_response_code(403); exit('This account has read-only access.'); }
$expense = $id ? reimbursementExpense($conn, $id, $userId, hasRole(['admin'])) : null;
if ($id && !$expense) { http_response_code(404); exit('Expense not found.'); }
$isOwner = !$expense || (int) $expense['user_id'] === $userId;
$error = '';
$returnUrl = reimbursementReturnUrl($_GET['return'] ?? $_POST['return'] ?? null, !empty($expense['request_id']) ? 'reimbursement_request.php?id='.(int)$expense['request_id'] : 'reimbursements.php');
$operationToken = is_string($_POST['operation_token'] ?? null) ? $_POST['operation_token'] : bin2hex(random_bytes(16));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isViewing) { http_response_code(405); exit('This expense view is read-only.'); }
    requireValidCsrfToken();
    if (!$canManage) { http_response_code(403); exit('This account has read-only access.'); }
    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'delete_expense' && !hasRole(['admin'])) { http_response_code(403); exit('Only administrators can delete expenses.'); }
    if ($action === 'delete_expense') {
        require_once __DIR__ . '/two_factor_helpers.php';
        requireRecentAdminElevation('reimbursement_expense.php?id=' . $id);
    }
    if ($action !== 'delete_expense' && !$isOwner) { http_response_code(403); exit('Only the expense owner can change it.'); }
    try {
        if ($action === 'save') {
            if (!$id && !preg_match('/\A[a-f0-9]{32}\z/D',(string)($_POST['operation_token'] ?? ''))) throw new InvalidArgumentException('This form has expired. Reload before saving.');
            if ($id && !isset($_POST['version'])) throw new InvalidArgumentException('Reload the expense before saving.');
            $files = reimbursementUploadFiles($_FILES['receipts'] ?? []);
            if (isset($_POST['receipt_file_count']) && $_POST['receipt_file_count'] !== '' && (int)$_POST['receipt_file_count'] !== count($files)) throw new InvalidArgumentException('Some receipts did not arrive. Reselect up to 20 files and try again.');
            $savedId = saveReimbursementExpense($conn, $userId, $_POST, $files, $id);
            header('Location: reimbursement_expense.php?id=' . $savedId . '&saved=1&return=' . rawurlencode($returnUrl)); exit();
        }
        if (!$expense) throw new InvalidArgumentException('Expense not found.');
        if ($action === 'delete_expense' && $expense['request_id'] !== null) {
            throw new InvalidArgumentException('Delete the reimbursement request before deleting this expense.');
        }
        if ($expense['request_status'] === 'submitted') throw new InvalidArgumentException('Submitted expenses and receipts cannot be changed.');
        if ($action === 'delete_receipt') {
            $receiptId = filter_var($_POST['receipt_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$receiptId) throw new InvalidArgumentException('Select a receipt.');
            deleteReimbursementReceipt($conn, $id, $receiptId, $userId);
            header('Location: reimbursement_expense.php?id=' . $id . '&return=' . rawurlencode($returnUrl)); exit();
        }
        if ($action === 'delete_expense') {
            deleteReimbursementExpense($conn, $id);
            header('Location: reimbursements.php'); exit();
        }
        throw new InvalidArgumentException('Unknown action.');
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
        if (!empty($_FILES['receipts']['name'][0])) $error .= ' Please reselect receipt files before retrying.';
    } catch (Throwable $e) {
        applicationLog('error', 'Unable to update reimbursement expense', ['error' => $e->getMessage()]);
        $error = 'Unable to save the expense. Please try again.';
    }
    if ($action === 'save') {
        $expense = array_replace($expense ?? [], array_intersect_key($_POST, array_flip(['expense_date', 'merchant', 'description', 'amount', 'cost_center_id'])));
    }
}
$centers = $conn->query('SELECT id, coa_number, description, is_archived FROM reimbursement_cost_centers ORDER BY is_archived, coa_number, description')->fetch_all(MYSQLI_ASSOC);
$receipts = $id ? $conn->execute_query('SELECT id, filename, content_type, scan_state, created_at FROM reimbursement_receipts WHERE expense_id = ? ORDER BY id', [$id])->fetch_all(MYSQLI_ASSOC) : [];
$locked = ($expense['request_status'] ?? null) === 'submitted' || !empty($expense['request_archived']) || !empty($expense['is_archived']);
$expense = array_replace(['expense_date' => applicationBusinessDate(), 'merchant' => '', 'description' => '', 'amount_cents' => 0, 'cost_center_id' => ''], $expense ?? []);
$canEdit = !$isViewing && $canManage && $isOwner && !$locked;
$expenseEvents=$id ? $conn->execute_query("SELECT a.action,a.detail,a.created_at,u.username FROM reimbursement_events a LEFT JOIN users u ON u.id=a.actor_id WHERE a.entity_type='expense' AND a.entity_id=? ORDER BY a.id DESC LIMIT 50",[$id])->fetch_all(MYSQLI_ASSOC) : [];
$owner = $id ? reimbursementOwner($conn, (int) $expense['user_id']) : null;
function reimbursementExpenseH(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
$amountValue = isset($expense['amount']) ? (string) $expense['amount'] : ($id ? number_format((int) $expense['amount_cents'] / 100, 2, '.', '') : '');
?>
<!DOCTYPE html><html lang="en">
<?php renderPageHead(applicationPageTitle($id ? 'Expense' : 'New Expense'), ['styles' => ['assets/css/style.min.css', 'assets/css/modern.min.css', 'assets/css/pages/reimbursements.css'], 'scripts' => ['assets/js/reimbursements.js']]); ?>
<body><?php include 'templates/header.php'; ?>
<main class="container reimbursement-page reimbursement-form-page">
<nav class="breadcrumb" aria-label="Breadcrumb"><a href="reimbursements.php">Reimbursements</a><span aria-hidden="true">/</span><span><?= $id ? 'Expense #' . $id : 'New Expense' ?></span></nav>
<div class="page-heading"><div><h1><?= $id ? 'Expense' : 'New Expense' ?></h1><p class="page-intro"><?= $canEdit ? 'Enter the expense details and attach one or more receipt files.' : 'View the expense details and attached receipts.' ?></p></div>
<?php if (isset($_GET['saved']) && $canManage): ?>
<nav class="page-heading-actions" aria-label="Reimbursement Navigation"><a class="button-add" href="reimbursement_expense.php">+ Add Expense</a><a class="button-secondary" href="reimbursement_requests.php">Requests</a></nav>
<?php elseif ($isViewing && $canManage && $isOwner && !$locked): ?><a class="button-secondary" href="reimbursement_expense.php?id=<?= $id ?>&amp;return=<?= rawurlencode($returnUrl) ?>">Edit Expense</a><?php endif; ?></div>
<?php if ($owner && !$isOwner): ?><p class="field-help">Owner: <?= reimbursementExpenseH($owner['display_name']) ?>. You can view this expense and its receipts.</p><?php endif; ?>
<?php if ($error): ?><p class="error" role="alert"><?= reimbursementExpenseH($error) ?></p><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><p class="success" role="status">Expense saved.</p><?php endif; ?>
<?php if ($id && $isOwner): $duplicates=$conn->execute_query('SELECT id FROM reimbursement_expenses WHERE user_id=? AND expense_date=? AND merchant=? AND amount_cents=? AND id<>? LIMIT 5',[$userId,$expense['expense_date'],$expense['merchant'],$expense['amount_cents'],$id])->fetch_all(MYSQLI_ASSOC); if ($duplicates): ?><p class="warning">Possible Duplicate: Another expense has the same date, merchant, and amount. <?php foreach ($duplicates as $duplicate): ?><a href="reimbursement_expense.php?id=<?= (int)$duplicate['id'] ?>&amp;mode=view">View Expense #<?= (int)$duplicate['id'] ?></a> <?php endforeach; ?></p><?php endif; endif; ?>
<?php if (!empty($expense['is_archived'])): ?><p class="warning">This expense is archived. Restore it from the Archived expenses list before editing.</p><?php elseif ($locked): ?><p class="warning"><?= !empty($expense['request_archived']) ? 'This expense belongs to an archived request. Restore that request before editing.' : 'This expense appeared in a submitted reimbursement request and is locked.' ?></p><?php elseif (!empty($expense['request_id'])): ?><p class="field-help">This expense is included in <a href="reimbursement_request.php?id=<?= (int) $expense['request_id'] ?>">draft request <?= reimbursementExpenseH($expense['request_hash']) ?></a>.</p><?php endif; ?>
<section class="reimbursement-card"><form method="post" enctype="multipart/form-data"<?= $canEdit ? ' data-expense-save' : '' ?>>
<?= csrfInput() ?><input type="hidden" name="action" value="save"><input type="hidden" name="receipt_file_count" value="" data-receipt-file-count><input type="hidden" name="operation_token" value="<?= reimbursementExpenseH($operationToken) ?>"><input type="hidden" name="version" value="<?= (int) ($_POST['version'] ?? $expense['version'] ?? 1) ?>"><input type="hidden" name="return" value="<?= reimbursementExpenseH($returnUrl) ?>">
<div class="reimbursement-form-grid">
<label>Expense Date <input type="date" name="expense_date" value="<?= reimbursementExpenseH($expense['expense_date']) ?>" required<?= !$canEdit ? ' disabled' : '' ?>></label>
<label>Merchant or Payee <input type="text" name="merchant" maxlength="160" value="<?= reimbursementExpenseH($expense['merchant']) ?>" required<?= !$canEdit ? ' disabled' : '' ?>></label>
<label>Amount (USD) <input type="number" name="amount" min="0.01" max="21474836.47" step="0.01" inputmode="decimal" value="<?= reimbursementExpenseH($amountValue) ?>" required<?= !$canEdit ? ' disabled' : '' ?>></label>
<label>Account <select name="cost_center_id" required<?= !$canEdit ? ' disabled' : '' ?>><option value="">Choose an Account</option><?php foreach ($centers as $center): ?><?php if (!$center['is_archived'] || (int) $center['id'] === (int) $expense['cost_center_id']): ?><option value="<?= (int) $center['id'] ?>"<?= (int) $center['id'] === (int) $expense['cost_center_id'] ? ' selected' : '' ?>><?= reimbursementExpenseH(($locked && (int)$center['id']===(int)$expense['cost_center_id'] ? $expense['coa_number'].' · '.$expense['coa_description'] : $center['coa_number'] . ' · ' . $center['description']) . ($center['is_archived'] ? ' (archived)' : '')) ?></option><?php endif; ?><?php endforeach; ?></select></label>
</div>
<label>Description or Purpose <textarea name="description" maxlength="500" rows="3"<?= !$canEdit ? ' disabled' : '' ?>><?= reimbursementExpenseH($expense['description']) ?></textarea></label>
<?php if ($canEdit): ?>
<div class="presentation-notes-card reimbursement-receipt-upload" data-receipt-upload data-existing-count="<?= count($receipts) ?>" data-existing-bytes="<?= $id ? (int)$conn->execute_query('SELECT COALESCE(SUM(f.size),0) FROM reimbursement_receipts rr JOIN stored_files f ON f.storage_key=rr.storage_key WHERE rr.expense_id=?',[$id])->fetch_row()[0] : 0 ?>">
    <div class="presentation-upload-details">
        <div class="presentation-asset-label">Expense Receipts</div>
        <p>Add photos or PDFs of your receipts. Up to 20 receipts and 15 MB total per expense. Larger requests may need to be split for email delivery.</p><ul class="reimbursement-staged-files" data-receipt-staging></ul>
        <div class="presentation-pdf-picker-row">
            <label class="presentation-file-picker" for="receipt-files">Upload Receipt</label>
            <input class="presentation-native-file" id="receipt-files" type="file" name="receipts[]" accept="image/jpeg,image/png,image/webp,application/pdf,.jpg,.jpeg,.png,.webp,.pdf" multiple data-receipt-files>
            <button type="button" class="button-secondary presentation-paste-button" data-receipt-paste aria-controls="receipt-paste-box" aria-expanded="false">Paste Image</button>
            <span class="presentation-selected-file" data-receipt-selected role="status" aria-live="polite">No Receipts Selected</span>
        </div>
        <div class="reimbursement-paste-box" id="receipt-paste-box" data-receipt-paste-box hidden>
            <label for="receipt-paste-target">Paste a Receipt Image</label>
            <textarea id="receipt-paste-target" data-receipt-paste-target rows="2" placeholder="Press Command+V or Ctrl+V here" aria-describedby="receipt-paste-help"></textarea>
            <p class="field-help" id="receipt-paste-help">Copy the image itself, then paste here. If your browser shows a Paste prompt, select Paste. Select Save expense to save the receipt.</p>
        </div>
        <p class="presentation-drop-status" data-receipt-drop-status role="status" aria-live="polite"></p>
        <div class="presentation-pdf-picker-row reimbursement-camera-row">
            <label class="presentation-file-picker" for="receipt-photo">Take a Receipt Photo</label>
            <input class="presentation-native-file" id="receipt-photo" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" data-receipt-photo>
            <span class="presentation-selected-file" data-receipt-photo-selected role="status" aria-live="polite">No Photo Selected</span>
        </div>
    </div>
    <div class="presentation-file-drop" data-receipt-drop>
        <button type="button" class="presentation-file-drop-button" data-receipt-drop-button aria-controls="receipt-files">
            <svg class="presentation-drop-icon" viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></svg>
            <strong>Drop Receipts Here</strong>
            <span>or click to choose · JPEG, PNG, WebP, PDF · up to 15 MB each</span>
            <span>Select Save expense to upload.</span>
        </button>

    </div>
</div>
<?php endif; ?>
<div class="reimbursement-actions"><?php if ($canEdit): ?><button type="submit" class="save-button">Save Expense</button><?php endif; ?><a class="button-secondary" href="<?= reimbursementExpenseH($returnUrl) ?>"><?= !empty($expense['request_id']) ? 'Back to Request' : 'Back to Expenses' ?></a></div></form></section>
<?php if ($id): ?><section class="reimbursement-card"><h2>Receipts (<?= count($receipts) ?>)</h2>
<?php if (!$receipts): ?><p>No receipts attached yet.</p><?php endif; ?>
<div class="reimbursement-receipts"><?php foreach ($receipts as $receipt): ?><div class="reimbursement-receipt">
<?php $receiptReady=in_array($receipt['scan_state'],['clean','unscanned'],true) && (!documentScanningEnabled() || $receipt['scan_state']==='clean'); ?>
<?php if ($receiptReady): ?><a href="reimbursement_receipt.php?id=<?= (int)$receipt['id'] ?>" target="_blank" rel="noopener"><img src="reimbursement_receipt.php?id=<?= (int)$receipt['id'] ?>&amp;preview=1" alt="Receipt Preview: <?= reimbursementExpenseH($receipt['filename']) ?>" loading="lazy" data-receipt-thumbnail><?php if ($receipt['content_type']==='application/pdf'): ?><span hidden class="reimbursement-pdf-preview" data-pdf-receipt-preview data-receipt-url="reimbursement_receipt.php?id=<?= (int)$receipt['id'] ?>" role="img" aria-label="First Page of <?= reimbursementExpenseH($receipt['filename']) ?>"><canvas hidden aria-hidden="true"></canvas><span class="reimbursement-pdf-preview-fallback" aria-hidden="true">PDF</span></span><?php endif; ?><span><?= reimbursementExpenseH($receipt['filename']) ?></span><small>Open Original Receipt</small></a><?php else: ?><p><?= reimbursementExpenseH($receipt['filename']) ?></p><p class="warning"><?= $receipt['scan_state']==='rejected' ? 'Security Scan Rejected — remove this file and attach a safe copy.' : 'Security Scan Pending — refresh to check. This file cannot be previewed or submitted yet.' ?></p><?php endif; ?>
<?php if ($canEdit): ?><form method="post" class="reimbursement-receipt-delete" data-confirm="This action is permanent and cannot be undone." data-confirm-title="Delete Receipt?" data-confirm-label="Delete Receipt" data-confirm-tone="danger"><?= csrfInput() ?><input type="hidden" name="action" value="delete_receipt"><input type="hidden" name="receipt_id" value="<?= (int) $receipt['id'] ?>"><button class="action-button action-icon-button delete-button" type="submit" aria-label="Delete Receipt <?= reimbursementExpenseH($receipt['filename']) ?>" title="Delete" data-tooltip="Delete"><?= actionIconSvg('delete') ?></button></form><?php endif; ?></div><?php endforeach; ?></div>
</section>
<?php if (!$isViewing && hasRole(['admin']) && empty($expense['request_id'])): ?><div class="reimbursement-actions"><form method="post" class="reimbursement-expense-delete" data-confirm="Delete this expense and its receipts?" data-confirm-title="Delete Expense?" data-confirm-label="Delete Expense" data-confirm-tone="danger" data-admin-unlock-required><?= csrfInput() ?><input type="hidden" name="action" value="delete_expense"><button class="delete-button" type="submit">Delete Expense</button></form></div><?php endif; ?>
<?php endif; ?>
<?php if ($expenseEvents): ?><section class="reimbursement-card"><details><summary>Expense History</summary><ul><?php foreach ($expenseEvents as $event): ?><li><?= reimbursementExpenseH($event['created_at'].' UTC · '.($event['username'] ?? 'System').' · '.ucwords(str_replace('_',' ',$event['action']))) ?><small><?= reimbursementExpenseH($event['detail']) ?></small></li><?php endforeach; ?></ul></details></section><?php endif; ?>
</main><?php include 'templates/footer.php'; renderScript('assets/js/reimbursement-receipt-preview.mjs', true, true); ?></body></html>
