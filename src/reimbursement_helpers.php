<?php

declare(strict_types=1);

require_once __DIR__ . '/persistent_file_helpers.php';
require_once __DIR__ . '/document_scanning_helpers.php';
require_once __DIR__ . '/reimbursement_workflow_helpers.php';
require_once __DIR__ . '/financial_report_helpers.php';

function reimbursementSubmissionNote(mixed $value): string
{
    if (!is_string($value)) throw new InvalidArgumentException('Enter a note of at most 1000 characters.');
    $note = trim(str_replace(["\r\n", "\r"], "\n", $value));
    if (mb_strlen($note, 'UTF-8') > 1000 || preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $note)) {
        throw new InvalidArgumentException('Enter a note of at most 1000 characters without control characters.');
    }
    return $note;
}

/** The exact, unique filename used in the package's receipts directory. */
function reimbursementReceiptPackageFilename(array $receipt): string
{
    $extension = match ($receipt['content_type']) {
        'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        default => throw new RuntimeException('Unsupported receipt type.'),
    };
    $stem = substr(preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($receipt['filename'], PATHINFO_FILENAME)) ?: 'receipt', 0, 100);
    return 'expense-' . (int) $receipt['expense_id'] . '-receipt-' . (int) $receipt['id'] . '-' . $stem . '.' . $extension;
}

function reimbursementDate(mixed $value, string $label): string
{
    $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException("Enter a valid {$label}.");
    }
    return $value;
}

function reimbursementAmountCents(mixed $value): int
{
    $value = trim((string) $value);
    if (!preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/D', $value)) {
        throw new InvalidArgumentException('Enter a valid amount with no more than two decimal places.');
    }
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $cents = \Dnr\Domain\Money::cents(\Dnr\Domain\Money::amount($value, 'Amount', '21474836.47'));
    if ($cents < 1) throw new InvalidArgumentException('Amount is outside the supported range.');
    return $cents;
}

function reimbursementMoney(int $cents): string
{
    return formatFinancialAmount(\Dnr\Domain\Money::fromCents($cents));
}

function reimbursementOwner(mysqli $conn, int $userId): array
{
    $row = $conn->execute_query('SELECT id, username, first_name, last_name FROM users WHERE id = ?', [$userId])->fetch_assoc();
    if (!$row) throw new InvalidArgumentException('Account not found.');
    $name = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
    $row['display_name'] = $name !== '' ? $name : $row['username'];
    return $row;
}

function reimbursementSetup(mysqli $conn): array
{
    $setup = $conn->query('SELECT organization_name, bookkeeper_first_name, bookkeeper_last_name,
        bookkeeper_email, bookkeeper_phone, reviewer_email, cc_email, version
        FROM reimbursement_setup WHERE id = 1')->fetch_assoc();
    if (!$setup) throw new RuntimeException('Reimbursement setup is unavailable.');
    return $setup;
}

function reimbursementExpense(mysqli $conn, int $id, int $userId, bool $canViewAll = false): ?array
{
    return $conn->execute_query('SELECT e.*, COALESCE(ri.coa_number,c.coa_number) AS coa_number, COALESCE(ri.coa_description,c.description) AS coa_description,
        ri.request_id, r.request_hash, r.status AS request_status, r.is_archived AS request_archived
        FROM reimbursement_expenses e
        JOIN reimbursement_cost_centers c ON c.id = e.cost_center_id
        LEFT JOIN reimbursement_request_items ri ON ri.expense_id = e.id
        LEFT JOIN reimbursement_requests r ON r.id = ri.request_id
        WHERE e.id = ? AND (e.user_id = ? OR ? = 1)', [$id, $userId, (int) $canViewAll])->fetch_assoc() ?: null;
}

function reimbursementUploadFiles(array $upload): array
{
    $files = [];
    $names = $upload['name'] ?? [];
    if (!is_array($names)) return [];
    if (count($names)>REIMBURSEMENT_MAX_RECEIPTS) throw new InvalidArgumentException('Upload at most 20 receipts per expense.');
    foreach ($names as $i => $name) {
        $error = (int) ($upload['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error !== UPLOAD_ERR_OK) throw new InvalidArgumentException('A receipt upload failed. Each file must be 15 MB or smaller.');
        $path = (string) ($upload['tmp_name'][$i] ?? '');
        $size = (int) ($upload['size'][$i] ?? 0);
        if (!is_uploaded_file($path) || $size < 1 || $size > 15 * 1024 * 1024) {
            throw new InvalidArgumentException('Each receipt must be an uploaded file of 15 MB or less.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new InvalidArgumentException('Receipts must be PDF, JPEG, PNG, or WebP files.');
        }
        if ($mime === 'application/pdf') {
            $stream = fopen($path, 'rb');
            $signature = $stream ? fread($stream, 5) : '';
            if ($stream) fclose($stream);
            if ($signature !== '%PDF-') throw new InvalidArgumentException('A receipt PDF is invalid.');
        } else {
            $image = @getimagesize($path);
            if (!$image || (int) $image[0] * (int) $image[1] > 24000000) {
                throw new InvalidArgumentException('A receipt image is invalid or exceeds 24 megapixels.');
            }
        }
        $filename = substr(basename(str_replace('\\', '/', (string) $name)), 0, 255);
        if ($filename === '') $filename = 'receipt-' . ($i + 1);
        $files[] = ['path' => $path, 'size' => $size, 'mime' => $mime,
            'filename' => $filename, 'sha256' => hash_file('sha256', $path)];
    }
    if (array_sum(array_column($files,'size'))>REIMBURSEMENT_MAX_EXPENSE_BYTES) throw new InvalidArgumentException('Receipt files must total 15 MB or less per expense.');
    return $files;
}

function saveReimbursementExpense(mysqli $conn, int $userId, array $input, array $files, ?int $id): int
{
    $date = reimbursementDate($input['expense_date'] ?? null, 'expense date');
    $merchant = trim((string) ($input['merchant'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $amount = reimbursementAmountCents($input['amount'] ?? '');
    $costCenter = filter_var($input['cost_center_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($merchant === '' || mb_strlen($merchant) > 160 || mb_strlen($description) > 500) {
        throw new InvalidArgumentException('Enter a merchant or payee (160 characters or fewer) and a description of 500 characters or fewer.');
    }
    $token=$input['operation_token'] ?? null;
    if ($token!==null && (!is_string($token) || !preg_match('/\A[a-f0-9]{32}\z/D',$token))) throw new InvalidArgumentException('Invalid save token. Reload the form.');
    $conn->begin_transaction();
    try {
        if ($id===null && $token!==null) {
            $conn->execute_query('SELECT id FROM users WHERE id=? FOR UPDATE',[$userId]);
            $existing=$conn->execute_query('SELECT id FROM reimbursement_expenses WHERE user_id=? AND operation_token=? FOR UPDATE',[$userId,$token])->fetch_row();
            if ($existing) { $conn->commit(); return (int)$existing[0]; }
        }
        $expense = $id !== null ? lockEditableReimbursementExpense($conn, $id, $userId) : null;
        if ($expense && isset($input['version']) && (int)$input['version'] !== (int)$expense['version']) throw new InvalidArgumentException('This expense changed in another tab. Your entered values are preserved below. Open a fresh view to compare before saving again.');
        $stored=$id ? $conn->execute_query('SELECT COUNT(*) AS count, COALESCE(SUM(f.size),0) AS bytes FROM reimbursement_receipts rr JOIN stored_files f ON f.storage_key=rr.storage_key WHERE rr.expense_id=?',[$id])->fetch_assoc() : ['count'=>0,'bytes'=>0];
        if ((int)$stored['count']+count($files)>REIMBURSEMENT_MAX_RECEIPTS || (int)$stored['bytes']+array_sum(array_column($files,'size'))>REIMBURSEMENT_MAX_EXPENSE_BYTES) throw new InvalidArgumentException('An expense can hold at most 20 receipts totaling 15 MB. Remove unused files or reduce image sizes.');
        $center = $costCenter ? $conn->execute_query('SELECT is_archived FROM reimbursement_cost_centers WHERE id = ? FOR SHARE', [$costCenter])->fetch_assoc() : null;
        if (!$center || ((int) $center['is_archived'] && (int) ($expense['cost_center_id'] ?? 0) !== $costCenter)) {
            throw new InvalidArgumentException('Choose an active account. You may keep the archived account already assigned to this expense.');
        }
        if ($id !== null) {
            if ($expense['status'] === 'draft') {
                $range = $conn->execute_query('SELECT r.start_date, r.end_date FROM reimbursement_requests r
                    JOIN reimbursement_request_items ri ON ri.request_id = r.id WHERE ri.expense_id = ?', [$id])->fetch_assoc();
                if ($date < $range['start_date'] || $date > $range['end_date']) {
                    throw new InvalidArgumentException('This expense belongs to a draft request. Keep its date inside that request range or remove it from the draft first.');
                }
            }
            $conn->execute_query('UPDATE reimbursement_expenses SET expense_date = ?, merchant = ?, description = ?, amount_cents = ?, cost_center_id = ?, version = version + 1 WHERE id = ? AND user_id = ?',
                [$date, $merchant, $description, $amount, $costCenter, $id, $userId]);
        } else {
            $conn->execute_query('INSERT INTO reimbursement_expenses (user_id, expense_date, merchant, description, amount_cents, cost_center_id, operation_token) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$userId, $date, $merchant, $description, $amount, $costCenter, $token]);
            $id = (int) $conn->insert_id;
        }
        foreach ($files as $file) {
            $key = storePersistentFileFromPath($conn, $file['path'], $file['filename'], $file['mime'], $file['size'], $file['sha256']);
            $conn->execute_query('INSERT INTO reimbursement_receipts (expense_id, storage_key, filename, content_type, scan_state) VALUES (?, ?, ?, ?, ?)',
                [$id, $key, $file['filename'], $file['mime'], documentScanningEnabled() ? 'queued' : 'unscanned']);
            reimbursementEvent($conn,'expense',$id,'receipt_added','Receipt #'.$conn->insert_id,$userId);
        }
        reimbursementEvent($conn,'expense',$id,'saved','Version '.(($expense['version'] ?? 0)+1),$userId);
        $conn->commit();
        return $id;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

// Call inside a transaction so receipt changes and expense saves cannot bypass
// a concurrent request submission or archive operation.
function lockEditableReimbursementExpense(mysqli $conn, int $id, int $userId): array
{
    $linked=$conn->execute_query('SELECT request_id FROM reimbursement_request_items WHERE expense_id=?',[$id])->fetch_row();
    if ($linked) $conn->execute_query('SELECT id FROM reimbursement_requests WHERE id=? FOR UPDATE',[(int)$linked[0]]);
    $expense = $conn->execute_query('SELECT e.id, e.version, e.cost_center_id, e.is_archived AS expense_archived, r.status, r.is_archived
        FROM reimbursement_expenses e
        LEFT JOIN reimbursement_request_items ri ON ri.expense_id = e.id
        LEFT JOIN reimbursement_requests r ON r.id = ri.request_id
        WHERE e.id = ? AND e.user_id = ? FOR UPDATE', [$id, $userId])->fetch_assoc();
    if (!$expense) throw new InvalidArgumentException('Expense not found.');
    if ((int) $expense['expense_archived']) throw new InvalidArgumentException('Restore this expense before changing it or its receipts.');
    if ($expense['status'] === 'submitted') throw new InvalidArgumentException('Submitted expenses and receipts cannot be changed.');
    if ((int) $expense['is_archived']) throw new InvalidArgumentException('Restore the archived request before changing its expenses or receipts.');
    return $expense;
}

function deleteReimbursementReceipt(mysqli $conn, int $expenseId, int $receiptId, int $userId): void
{
    $conn->begin_transaction();
    try {
        lockEditableReimbursementExpense($conn, $expenseId, $userId);
        $conn->execute_query('DELETE FROM reimbursement_receipts WHERE id = ? AND expense_id = ?', [$receiptId, $expenseId]);
        if ($conn->affected_rows !== 1) throw new InvalidArgumentException('Receipt not found.');
        $conn->execute_query('UPDATE reimbursement_expenses SET version=version+1 WHERE id=?',[$expenseId]);
        reimbursementEvent($conn,'expense',$expenseId,'receipt_deleted','Receipt #'.$receiptId,$userId);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function changeReimbursementExpenseArchive(mysqli $conn, int $id, int $userId, bool $archived): void
{
    $conn->begin_transaction();
    try {
        $expense = $conn->execute_query('SELECT id FROM reimbursement_expenses WHERE id = ? AND user_id = ? FOR UPDATE', [$id, $userId])->fetch_assoc();
        if (!$expense) throw new InvalidArgumentException('Only the expense owner can archive or restore it.');
        if ($conn->execute_query('SELECT request_id FROM reimbursement_request_items WHERE expense_id = ? FOR UPDATE', [$id])->fetch_row()) {
            throw new InvalidArgumentException('Remove this expense from its draft or remove it from its draft before archiving it.');
        }
        $conn->execute_query('UPDATE reimbursement_expenses SET is_archived = ?,version=version+1 WHERE id = ?', [(int) $archived, $id]);
        reimbursementEvent($conn,'expense',$id,$archived?'archived':'restored','',$userId);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function deleteReimbursementExpense(mysqli $conn, int $id): void
{
    // Routes enforce administrator access and recent Admin Unlock before calling.
    $conn->begin_transaction();
    try {
        if (!$conn->execute_query('SELECT id FROM reimbursement_expenses WHERE id = ? FOR UPDATE', [$id])->fetch_row()) {
            throw new InvalidArgumentException('Expense not found.');
        }
        if ($conn->execute_query('SELECT request_id FROM reimbursement_request_items WHERE expense_id = ? FOR UPDATE', [$id])->fetch_row()) {
            throw new InvalidArgumentException('Remove this expense from its draft first. Submitted expenses cannot be deleted.');
        }
        reimbursementEvent($conn,'expense',$id,'deleted');
        $conn->execute_query('DELETE FROM reimbursement_expenses WHERE id = ?', [$id]);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function createReimbursementRequest(mysqli $conn, int $userId, string $start, string $end, array $ids): int
{
    $start = reimbursementDate($start, 'start date');
    $end = reimbursementDate($end, 'end date');
    if ($start > $end) throw new InvalidArgumentException('End date must be on or after start date.');
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids || count($ids) > 500 || min($ids) < 1) throw new InvalidArgumentException('Select between 1 and 500 eligible expenses.');
    $conn->begin_transaction();
    try {
        $conn->execute_query('INSERT INTO reimbursement_requests (request_hash, user_id, start_date, end_date) VALUES (?, ?, ?, ?)',
            [bin2hex(random_bytes(8)), $userId, $start, $end]);
        $requestId = (int) $conn->insert_id;
        sort($ids,SORT_NUMERIC);
        foreach ($ids as $id) {
            $expense = $conn->execute_query('SELECT id FROM reimbursement_expenses WHERE id = ? AND user_id = ? AND is_archived = 0 AND expense_date BETWEEN ? AND ? FOR UPDATE',
                [$id, $userId, $start, $end])->fetch_row();
            if (!$expense) throw new InvalidArgumentException('An expense is outside the request date range or does not belong to you.');
            if ($conn->execute_query('SELECT expense_id FROM reimbursement_request_items WHERE expense_id = ?', [$id])->fetch_row()) {
                throw new InvalidArgumentException('An expense already belongs to a reimbursement request.');
            }
            $conn->execute_query('INSERT INTO reimbursement_request_items (request_id, expense_id) VALUES (?, ?)', [$requestId, $id]);
        }
        reimbursementEvent($conn,'request',$requestId,'draft_created','',$userId);
        $conn->commit();
        return $requestId;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function submitReimbursementRequest(mysqli $conn, int $id, int $userId, bool $manageTransaction = true): void
{
    if ($manageTransaction) $conn->begin_transaction();
    try {
        $request = $conn->execute_query('SELECT * FROM reimbursement_requests WHERE id = ? AND user_id = ? FOR UPDATE', [$id, $userId])->fetch_assoc();
        if (!$request || $request['status'] !== 'draft' || (int) $request['is_archived']) throw new InvalidArgumentException('Restore the draft before submitting it.');
        $items = $conn->execute_query('SELECT ri.expense_id, e.expense_date, e.merchant, e.description, e.amount_cents,
            c.coa_number, c.description AS coa_description FROM reimbursement_request_items ri
            JOIN reimbursement_expenses e ON e.id = ri.expense_id
            JOIN reimbursement_cost_centers c ON c.id = e.cost_center_id
            WHERE ri.request_id = ? ORDER BY e.id FOR UPDATE', [$id])->fetch_all(MYSQLI_ASSOC);
        if (!$items) throw new InvalidArgumentException('Add expenses before submitting a request.');
        foreach ($items as $item) {
            if ($item['expense_date'] < $request['start_date'] || $item['expense_date'] > $request['end_date']) {
                throw new InvalidArgumentException('An expense is outside this request date range.');
            }
            $conn->execute_query('UPDATE reimbursement_request_items SET expense_date = ?, merchant = ?, description = ?,
                amount_cents = ?, coa_number = ?, coa_description = ? WHERE request_id = ? AND expense_id = ?',
                [$item['expense_date'], $item['merchant'], $item['description'], $item['amount_cents'],
                 $item['coa_number'], $item['coa_description'], $id, $item['expense_id']]);
        }
        $conn->execute_query("UPDATE reimbursement_requests SET status = 'submitted', submitted_at = UTC_TIMESTAMP() WHERE id = ?", [$id]);
        if ($manageTransaction) $conn->commit();
    } catch (Throwable $e) {
        if ($manageTransaction) $conn->rollback();
        throw $e;
    }
}

function deleteReimbursementRequest(mysqli $conn, int $id, int $userId, bool $isAdmin): void
{
    $conn->begin_transaction();
    try {
        $request = $conn->execute_query('SELECT user_id, status, is_archived FROM reimbursement_requests WHERE id = ? FOR UPDATE', [$id])->fetch_assoc();
        if (!$request) throw new InvalidArgumentException('Request not found.');
        if ($request['status'] === 'submitted') {
            throw new InvalidArgumentException('Submitted requests are permanent records. Archive the request or record a correction note instead.');
        }
        if ($request['status'] === 'draft' && (int) $request['user_id'] !== $userId) {
            throw new InvalidArgumentException('Only the request owner can delete a draft.');
        }
        if ($request['status'] === 'draft' && (int) $request['is_archived']) {
            throw new InvalidArgumentException('Restore the draft before deleting it.');
        }
        // Request items cascade away; their expenses and receipts remain available.
        reimbursementEvent($conn,'request',$id,'draft_deleted','',$userId);
        $conn->execute_query('DELETE FROM reimbursement_requests WHERE id = ?', [$id]);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
