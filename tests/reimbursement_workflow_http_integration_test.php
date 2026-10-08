<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    exit("Reimbursement workflow tests skipped (disposable environment required).\n");
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/bootstrap.php';
require_once $source . '/reimbursement_helpers.php';
require_once $source . '/reimbursement_submission_helpers.php';
require_once $source . '/reimbursement_email_helpers.php';
require_once $source . '/reimbursement_receipt_helpers.php';
require_once __DIR__ . '/integration_auth_helpers.php';
$conn = applicationDatabaseConnection();
$checks = 0;
function expectReimbursement(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function reimbursementCsrf(array $response): string {
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $response['body'], $match);
    if (empty($match[1])) throw new RuntimeException('Missing CSRF field: HTTP ' . $response['status']);
    return html_entity_decode($match[1], ENT_QUOTES);
}
function expectReimbursementRejected(callable $action, string $message): void {
    try { $action(); } catch (InvalidArgumentException $e) { expectReimbursement(true, $message); return; }
    throw new RuntimeException($message);
}
$cookies = $users = $requests = $expenses = [];
$centerId = 0; $deliveryIds = [];
$originalSetup = $conn->query('SELECT * FROM reimbursement_setup WHERE id = 1')->fetch_assoc();
try {
    $suffix = bin2hex(random_bytes(5));
    $clients = [];
    foreach (['editor', 'admin', 'reviewer'] as $role) {
        $username = 'reimburse-' . $role . '-' . $suffix;
        $password = bin2hex(random_bytes(16));
        $conn->execute_query('INSERT INTO users (username, password, role) VALUES (?, ?, ?)', [$username, password_hash($password, PASSWORD_DEFAULT), $role]);
        $users[$role] = (int) $conn->insert_id;
        $cookie = tempnam(sys_get_temp_dir(), 'reimburse-cookie-');
        $cookies[] = $cookie;
        $client = static function (string $path, ?array $post = null) use ($cookie): array {
            $curl = curl_init('http://127.0.0.1/' . $path);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
                CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie, CURLOPT_TIMEOUT => 25]);
            if ($post !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
            $raw = curl_exec($curl);
            if (!is_string($raw)) throw new RuntimeException(curl_error($curl));
            $split = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            $result = ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $split), 'body' => substr($raw, $split)];
            curl_close($curl);
            return $result;
        };
        $login = $client('login.php');
        $login = finishIntegrationTestEnrollment($client, $client('login.php', ['csrf_token' => reimbursementCsrf($login), 'username' => $username, 'password' => $password]));
        expectReimbursement($login['status'] === 302, $role . ' login');
        $clients[$role] = $client;
    }
    $editor = $clients['editor']; $admin = $clients['admin']; $reviewer = $clients['reviewer'];
    $setupPage = $admin('reimbursement_setup.php');
    $setupVersion = (int) $conn->query('SELECT version FROM reimbursement_setup WHERE id=1')->fetch_row()[0];
    expectReimbursement($setupPage['status'] === 200
        && str_contains($setupPage['body'], 'name="version" value="' . $setupVersion . '"')
        && !preg_match('/(?:Warning|Notice|Fatal error):|Undefined array key/', $setupPage['body']),
        'Setup renders its saved version without PHP diagnostics corrupting the hidden input');
    $conn->execute_query('INSERT INTO reimbursement_cost_centers (coa_number, description) VALUES (?, ?)', ['TEST-' . $suffix, 'Workflow fixture']);
    $centerId = (int) $conn->insert_id;
    $centerPage = $editor('reimbursement_cost_centers.php');
    $centerCsrf = reimbursementCsrf($centerPage);
    expectReimbursement(str_contains($centerPage['body'], '<table class="data-table">') && str_contains($centerPage['body'], '?edit=' . $centerId), 'Accounts use a table and edit actions');
    $editCenterPath = 'reimbursement_cost_centers.php?edit=' . $centerId;
    expectReimbursement(str_contains($editor($editCenterPath)['body'], 'value="TEST-' . $suffix . '"'), 'Account edit loads selected values');
    $centerSave = $editor($editCenterPath, ['csrf_token' => $centerCsrf, 'version' => '1', 'action' => 'save', 'id' => $centerId, 'coa_number' => 'TEST-' . $suffix, 'description' => 'Updated workflow fixture']);
    expectReimbursement($centerSave['status'] === 302 && $conn->execute_query('SELECT description FROM reimbursement_cost_centers WHERE id = ?', [$centerId])->fetch_row()[0] === 'Updated workflow fixture', 'Account edits persist');
    $editor('reimbursement_cost_centers.php', ['csrf_token' => $centerCsrf, 'version' => '2', 'action' => 'archive', 'id' => $centerId]);
    expectReimbursement(!str_contains($editor('reimbursement_cost_centers.php')['body'], 'TEST-' . $suffix), 'Active account filter excludes archived entries');
    $archivedCentersPage = $editor('reimbursement_cost_centers.php?show=archived');
    expectReimbursement(str_contains($archivedCentersPage['body'], 'TEST-' . $suffix) && str_contains($archivedCentersPage['body'], 'value="restore"'), 'Archived account filter includes restore action');
    $restoreCenter = $editor('reimbursement_cost_centers.php?show=archived', ['csrf_token' => $centerCsrf, 'version' => '3', 'action' => 'restore', 'id' => $centerId]);
    expectReimbursement(str_contains($restoreCenter['headers'], 'Location: reimbursement_cost_centers.php?show=archived') && !str_contains($editor('reimbursement_cost_centers.php?show=archived')['body'], 'TEST-' . $suffix), 'Restoring preserves filter and removes entry from archived view');
    $input = ['expense_date' => '2026-06-10', 'merchant' => 'Workflow fixture', 'description' => 'Synthetic receipt', 'amount' => '65.40', 'cost_center_id' => $centerId];
    $expenseId = saveReimbursementExpense($conn, $users['editor'], $input, [], null); $expenses[] = $expenseId;
    $otherId = saveReimbursementExpense($conn, $users['reviewer'], $input, [], null); $expenses[] = $otherId;
    $centerSearch = $editor('reimbursement_cost_centers.php?q=' . urlencode('TEST-' . $suffix));
    expectReimbursement(str_contains($centerSearch['body'], '<td>TEST-' . $suffix . '</td>') && !str_contains($centerSearch['body'], '<td>5125</td>'), 'Account search filters COA numbers');
    $descriptionSearch = $editor('reimbursement_cost_centers.php?q=Updated+workflow');
    expectReimbursement(str_contains($descriptionSearch['body'], '<td>TEST-' . $suffix . '</td>'), 'Account search filters descriptions');
    expectReimbursement(str_contains($editor('reimbursement_cost_centers.php?q=absent-' . $suffix)['body'], 'No accounts match'), 'Unmatched account search shows empty state');
    $usedCenters = $editor('reimbursement_cost_centers.php?show=used');
    expectReimbursement(str_contains($usedCenters['body'], '<td>TEST-' . $suffix . '</td>') && !str_contains($usedCenters['body'], '<td>5125</td>'), 'In-use view includes centers with expenses and excludes unused centers');
    foreach (['coa_number' => 1, 'description' => 2, 'expenses' => 3] as $sortKey => $cell) {
        foreach (['asc', 'desc'] as $direction) {
            $sortedCenters = $editor('reimbursement_cost_centers.php?sort_by=' . $sortKey . '&sort_dir=' . $direction);
            $document = new DOMDocument(); @$document->loadHTML($sortedCenters['body']);
            $xpath = new DOMXPath($document);
            $values = array_map(static fn($node) => trim($node->textContent), iterator_to_array($xpath->query('//table[contains(@class,"data-table")]/tbody/tr/td[' . $cell . ']')));
            $expected = $values;
            usort($expected, static fn($left, $right) => ($direction === 'asc' ? 1 : -1) * ($sortKey === 'expenses' ? ((int) $left <=> (int) $right) : strcasecmp($left, $right)));
            expectReimbursement(count($values) > 1 && $values === $expected, 'Accounts sort ' . $sortKey . ' ' . $direction);
        }
    }
    $centerFilteredEdit = $editor('reimbursement_cost_centers.php?q=' . urlencode('TEST-' . $suffix) . '&sort_by=expenses&sort_dir=desc&edit=' . $centerId);
    expectReimbursement(str_contains($centerFilteredEdit['body'], 'q=TEST-' . $suffix . '&amp;sort_by=expenses&amp;sort_dir=desc'), 'Account edit preserves search and sort context');
    $expensePath = 'reimbursement_expense.php?id=' . $expenseId;
    $csrf = reimbursementCsrf($editor($expensePath));
    $readonly = $editor($expensePath . '&mode=view');
    expectReimbursement(!str_contains($readonly['body'], '>Save Expense<') && !str_contains($readonly['body'], 'data-receipt-upload'), 'View action must show details without edit or receipt upload controls');
    expectReimbursement($editor($expensePath . '&mode=view', $input + ['csrf_token' => $csrf, 'action' => 'save'])['status'] === 405, 'Expense view must reject forged POSTs');
    $actionsList = $editor('reimbursements.php?start_date=2026-01-01&end_date=2026-12-31');
    expectReimbursement(str_contains($actionsList['body'], '>Actions</th>') && str_contains($actionsList['body'], 'form="expense-archive-' . $expenseId . '"') && !str_contains($actionsList['body'], 'form="expense-delete-' . $expenseId . '"'), 'Editor list must offer view/edit/archive but no delete');
    $availableView = $editor('reimbursements.php?state=available&start_date=2026-01-01&end_date=2026-12-31');
    $draftView = $editor('reimbursements.php?state=draft&start_date=2026-01-01&end_date=2026-12-31');
    expectReimbursement(str_contains($availableView['body'], 'data-selection-expense="' . $expenseId . '"') && !str_contains($draftView['body'], 'data-selection-expense="' . $expenseId . '"'), 'Available state shows only unclaimed expenses');
    expectReimbursement(str_contains($availableView['body'], '>State:') && str_contains($availableView['body'], 'state=available'), 'State selector and current filter appear in the expense list');
    expectReimbursement(preg_match_all('/<button[^>]*data-select-all-available[^>]*>/', $actionsList['body']) === 2, 'Available expense selection appears above and below the table');
    $availableResponse = $editor('reimbursements.php?action=available_expenses&start_date=2026-01-01&end_date=2026-12-31&q=Workflow');
    $availableRows = json_decode($availableResponse['body'], true, 512, JSON_THROW_ON_ERROR)['expenses'] ?? [];
    expectReimbursement($availableResponse['status'] === 200 && count($availableRows) === 1 && (int) $availableRows[0]['id'] === $expenseId, 'Bulk selection returns only matching available expenses owned by the current user');
    expectReimbursement($reviewer('reimbursements.php?action=available_expenses&start_date=2026-01-01&end_date=2026-12-31')['status'] === 403, 'Read-only users cannot request bulk expense selection');
    $editorDelete = $editor('reimbursements.php', ['csrf_token' => $csrf, 'action' => 'delete_expense', 'expense_id' => $expenseId]);
    expectReimbursement($editorDelete['status'] === 403, 'Editor must not delete expenses');
    $archivedExpense = $editor('reimbursements.php', ['csrf_token' => $csrf, 'action' => 'archive_expense', 'expense_id' => $expenseId]);
    expectReimbursement($archivedExpense['status'] === 303, 'Owner can archive an available expense');
    expectReimbursement(!str_contains($editor('reimbursements.php?start_date=2026-01-01&end_date=2026-12-31')['body'], 'href="' . $expensePath . '&amp;mode=view'), 'Active list excludes archived expenses');
    $archiveList = $editor('reimbursements.php?show=archived&start_date=2026-01-01&end_date=2026-12-31');
    expectReimbursement(str_contains($archiveList['body'], 'form="expense-archive-' . $expenseId . '"') && str_contains($archiveList['body'], 'value="restore_expense"') && !str_contains($archiveList['body'], 'name="expense_ids[]"'), 'Archived list must offer restore without selection');
    expectReimbursementRejected(fn() => saveReimbursementExpense($conn, $users['editor'], $input, [], $expenseId), 'Archived expenses cannot be edited');
    expectReimbursementRejected(fn() => createReimbursementRequest($conn, $users['editor'], '2026-01-01', '2026-12-31', [$expenseId]), 'Archived expenses cannot enter drafts');
    expectReimbursementRejected(fn() => changeReimbursementExpenseArchive($conn, $expenseId, $users['admin'], false), 'Admin cannot restore another owner expense');
    $restoredExpense = $editor('reimbursements.php?show=archived', ['csrf_token' => $csrf, 'action' => 'restore_expense', 'expense_id' => $expenseId]);
    expectReimbursement($restoredExpense['status'] === 303, 'Owner can restore an expense');
    $adminExpenses = $admin('reimbursements.php?owner_id=0&start_date=2026-01-01&end_date=2026-12-31');
    expectReimbursement(str_contains($adminExpenses['body'], 'form="expense-delete-' . $expenseId . '"') && str_contains($adminExpenses['body'], 'data-admin-unlock-required'), 'Admin gets a protected delete action');
    $adminDelete = $admin('reimbursements.php?owner_id=0', ['csrf_token' => reimbursementCsrf($adminExpenses), 'action' => 'delete_expense', 'expense_id' => $expenseId]);
    expectReimbursement($adminDelete['status'] === 302 && str_contains($adminDelete['headers'], 'admin_elevation.php'), 'Deleting requires Admin Unlock');
    $conn->execute_query('UPDATE reimbursement_cost_centers SET is_archived = 1 WHERE id = ?', [$centerId]);
    $saved = $editor($expensePath, $input + ['version' => $conn->execute_query('SELECT version FROM reimbursement_expenses WHERE id=?',[$expenseId])->fetch_row()[0], 'csrf_token' => $csrf, 'action' => 'save']);
    expectReimbursement($saved['status'] === 302, 'Existing archived account must remain savable');
    expectReimbursementRejected(fn() => saveReimbursementExpense($conn, $users['editor'], $input, [], null), 'New expenses must not select archived accounts');
    $conn->execute_query('UPDATE reimbursement_cost_centers SET is_archived = 0 WHERE id = ?', [$centerId]);

    $operation=bin2hex(random_bytes(16));
    $operationInput=$input+['operation_token'=>$operation];
    $one=saveReimbursementExpense($conn,$users['editor'],$operationInput,[],null);
    $two=saveReimbursementExpense($conn,$users['editor'],$operationInput,[],null);
    expectReimbursement($one===$two,'Same operation token cannot create duplicate expenses');
    saveReimbursementExpense($conn,$users['editor'],$input+['version'=>1],[],$one);
    expectReimbursementRejected(fn()=>saveReimbursementExpense($conn,$users['editor'],$input+['version'=>1],[],$one),'Stale edits do not overwrite another save');
    expectReimbursement(reimbursementReturnUrl('//attacker.example/')==='reimbursements.php','External return URLs rejected');
    expectReimbursement(reimbursementReturnUrl('reimbursements.php?q=Taxi&page=2')==='reimbursements.php?q=Taxi&page=2','Local filter context preserved');
    expectReimbursementRejected(fn()=>reimbursementReceiptsReady([['scan_state'=>'queued','size'=>10]]),'Pending receipts cannot be exported');
    expectReimbursementRejected(fn()=>reimbursementReceiptsReady([['scan_state'=>'rejected','size'=>10]]),'Rejected receipts cannot be exported');
    reimbursementReceiptsReady([['scan_state'=>'clean','size'=>REIMBURSEMENT_MAX_RECEIPT_BYTES]]);
    expectReimbursementRejected(fn()=>reimbursementReceiptsReady([['scan_state'=>'clean','size'=>REIMBURSEMENT_MAX_RECEIPT_BYTES + 1]]),'Oversized request rejected before rendering');
    $pdf = new TCPDF(); $pdf->AddPage(); $pdf->Write(8, 'Synthetic reimbursement receipt');
    $bytes = $pdf->Output('', 'S');
    $key = storePersistentFile($conn, $bytes, 'fixture.pdf', 'application/pdf');
    $conn->execute_query('INSERT INTO reimbursement_receipts (expense_id, storage_key, filename, content_type) VALUES (?, ?, ?, ?)', [$expenseId, $key, 'fixture.pdf', 'application/pdf']);
    $receiptId = (int) $conn->insert_id;
    $expectedReceiptFilename = 'expense-' . $expenseId . '-receipt-' . $receiptId . '-fixture.pdf';
    $receipt = $editor('reimbursement_receipt.php?id=' . $receiptId);
    expectReimbursement($receipt['status'] === 200 && $receipt['body'] === $bytes, 'Receipt must stream intact');
    expectReimbursement($reviewer('reimbursement_receipt.php?id=' . $receiptId)['status'] === 404, 'Other users cannot read receipts');
    $thumbnailRow=$conn->execute_query('SELECT rr.*,f.size,f.checksum FROM reimbursement_receipts rr JOIN stored_files f ON f.storage_key=rr.storage_key WHERE rr.id=?',[$receiptId])->fetch_assoc();
    reimbursementThumbnail($conn,$thumbnailRow);
    $thumbnail=$editor('reimbursement_receipt.php?id='.$receiptId.'&preview=1');
    $dimensions=@getimagesizefromstring($thumbnail['body']);
    expectReimbursement($thumbnail['status']===200 && $dimensions && $dimensions['mime']==='image/jpeg' && max($dimensions[0],$dimensions[1])<=640,'PDF first-page thumbnail is an actual bounded JPEG');
    expectReimbursement($reviewer('reimbursement_receipt.php?id='.$receiptId.'&preview=1')['status']===404,'Thumbnail retains receipt ownership protection');
    expectReimbursement($editor('reimbursement_receipt.php?id='.$receiptId.'&preview=1')['body']===$thumbnail['body'],'Subsequent previews reuse the stored derivative');
    $conn->execute_query("UPDATE reimbursement_receipts SET scan_state='queued' WHERE id=?",[$receiptId]);
    expectReimbursement($editor('reimbursement_receipt.php?id='.$receiptId)['status']===409,'Quarantine blocks original downloads');
    expectReimbursement($editor('reimbursement_receipt.php?id='.$receiptId.'&preview=1')['status']===409,'Quarantine blocks cached thumbnails');
    $conn->execute_query("UPDATE reimbursement_receipts SET scan_state='unscanned' WHERE id=?",[$receiptId]);
    $form = $editor($expensePath);
    expectReimbursement(str_contains($form['body'], 'data-receipt-thumbnail') && str_contains($form['body'], 'This action is permanent and cannot be undone.'), 'Receipt preview and project deletion dialog must render');
    $before = (int) $conn->query('SELECT COUNT(*) FROM reimbursement_requests')->fetch_row()[0];
    $invalid = $editor('reimbursements.php', ['csrf_token' => $csrf, 'start_date' => 'invalid', 'end_date' => '2026-12-31', 'expense_ids' => [$expenseId]]);
    expectReimbursement($invalid['status'] === 200 && str_contains($invalid['body'], 'Enter a valid start date.'), 'Invalid date must show validation');
    expectReimbursement((int) $conn->query('SELECT COUNT(*) FROM reimbursement_requests')->fetch_row()[0] === $before, 'Invalid date must not create a request with substituted dates');
    $created = $editor('reimbursements.php?q=Workflow&sort_by=amount&page=1', ['csrf_token' => $csrf, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'expense_ids' => [$expenseId]]);
    preg_match('/Location: reimbursement_request.php\?id=(\d+)/i', $created['headers'], $match);
    expectReimbursement($created['status'] === 302 && isset($match[1]), 'Draft creation redirects to edit');
    $requestId = (int) $match[1]; $requests[] = $requestId;
    $draftView = $editor('reimbursements.php?state=draft&start_date=2026-01-01&end_date=2026-12-31');
    $availableView = $editor('reimbursements.php?state=available&start_date=2026-01-01&end_date=2026-12-31');
    expectReimbursement(str_contains($draftView['body'], 'data-selection-expense="' . $expenseId . '"') && !str_contains($availableView['body'], 'data-selection-expense="' . $expenseId . '"'), 'Draft state includes claimed expenses and Available excludes them');
    $claimedResponse = $editor('reimbursements.php?action=available_expenses&start_date=2026-01-01&end_date=2026-12-31&q=Workflow');
    $claimedRows = json_decode($claimedResponse['body'], true, 512, JSON_THROW_ON_ERROR)['expenses'];
    expectReimbursement(!in_array($expenseId, array_map(static fn($row) => (int) $row['id'], $claimedRows), true), 'Expenses already in a request are excluded from bulk selection');
    expectReimbursementRejected(fn() => changeReimbursementExpenseArchive($conn, $expenseId, $users['editor'], true), 'Expenses in drafts cannot be archived');
    expectReimbursementRejected(fn() => deleteReimbursementExpense($conn, $expenseId), 'Expenses in requests cannot be deleted');
    $path = 'reimbursement_request.php?id=' . $requestId;
    $view = $editor($path);
    expectReimbursement(str_contains($view['body'], $expectedReceiptFilename), 'Request detail identifies the exact packaged receipt filename');
    expectReimbursement(str_contains($view['body'], 'href="' . $expensePath . '&amp;mode=view') && str_contains($view['body'], 'name="bookkeeper_note"') && !str_contains($view['body'], 'name="action" value="submit"'), 'Owner draft view links expenses and offers a bookkeeper note');
    expectReimbursement($editor($path, ['csrf_token' => $csrf, 'action' => 'submit'])['status'] === 405, 'View route must reject mutation');
    $foreign = $admin($path);
    expectReimbursement(!str_contains($foreign['body'], 'href="' . $expensePath . '"'), 'Admin must not receive edit links for another owner');
    $foreignExpense = $admin($expensePath);
    expectReimbursement(!str_contains($foreignExpense['body'], '>Save Expense<'), 'Other owner expense must be read-only for admin');
    expectReimbursement($admin($expensePath, $input + ['csrf_token' => reimbursementCsrf($foreignExpense), 'action' => 'save'])['status'] === 403, 'Admin must not mutate another owner expense');
    $adminList = $admin('reimbursements.php');
    expectReimbursement(str_contains($adminList['body'], 'value="' . $users['admin'] . '" selected'), 'Admin owner filter must default to self even without own expenses');
    $allOwners = $admin('reimbursements.php?owner_id=0&start_date=2026-01-01&end_date=2026-12-31');
    expectReimbursement(str_contains($allOwners['body'], 'class="record-link" href="' . $expensePath . '&amp;mode=view'), 'Admin explicit All users must show other owners');
    $list = $editor('reimbursement_requests.php');
    expectReimbursement(str_contains($list['body'], '>Receipts</th>') && str_contains($list['body'], 'data-confirm-title="Delete Request?"'), 'List receipt counts and standard dialog must render');
    foreach (['pdf' => '%PDF-', 'zip' => "PK\x03\x04"] as $format => $signature) {
        $download = $editor('download_reimbursement.php?id=' . $requestId . '&format=' . $format);
        expectReimbursement($download['status'] === 200 && str_starts_with($download['body'], $signature), strtoupper($format) . ' export must succeed');
        if ($format === 'zip') {
            $tmp = tempnam(sys_get_temp_dir(), 'reimburse-zip-'); file_put_contents($tmp, $download['body']);
            $zip = new ZipArchive(); $zip->open($tmp);
            expectReimbursement($zip->numFiles === 3 && str_ends_with($zip->getNameIndex(1), '.csv'), 'ZIP must include report, expense CSV, and receipt');
            $csvLines = explode("\r\n", trim($zip->getFromIndex(1)));
            $csvFields = str_getcsv($csvLines[1], ',', '"', '');
            expectReimbursement(count($csvFields) === 6 && $csvFields[0] === '6/10/2026'
                && $csvFields[1] === 'Workflow fixture' && $csvFields[3] === 'Synthetic receipt'
                && $csvFields[4] === '$65.40' && $csvFields[5] === $expectedReceiptFilename,
                'Draft ZIP CSV contains importable expense details and the exact receipt filename');
            expectReimbursement($zip->locateName('receipts/' . $expectedReceiptFilename) !== false, 'Receipt filename in request matches the actual ZIP entry'); $zip->close(); unlink($tmp);
        }
    }
    $archived = $editor('reimbursement_requests.php', ['csrf_token' => $csrf, 'action' => 'archive', 'id' => $requestId]);
    expectReimbursement($archived['status'] === 302, 'Draft archive succeeds');
    expectReimbursementRejected(fn() => saveReimbursementExpense($conn, $users['editor'], $input, [], $expenseId), 'Archived draft expense must be locked');
    expectReimbursementRejected(fn() => deleteReimbursementReceipt($conn, $expenseId, $receiptId, $users['editor']), 'Archived draft receipt must be locked');
    expectReimbursement(!str_contains($editor($path)['body'], 'href="' . $expensePath . '"'), 'Archived draft must hide expense edit links');
    $editor('reimbursement_requests.php?show=archived', ['csrf_token' => $csrf, 'action' => 'restore', 'id' => $requestId]);
    $edit = $editor($path . '&mode=edit');
    $submitted = $editor($path . '&mode=edit', ['csrf_token' => reimbursementCsrf($edit), 'action' => 'submit']);
    expectReimbursement(!str_contains($edit['body'], 'name="action" value="submit"'), 'Request editor must not offer submission');
    expectReimbursement($submitted['status'] === 200 && str_contains($submitted['body'], 'Unknown action.'), 'Request editor must reject the removed submission action');
    expectReimbursement($conn->execute_query('SELECT status FROM reimbursement_requests WHERE id = ?', [$requestId])->fetch_row()[0] === 'draft', 'Removed editor action must leave request in draft');
    $submitPath = 'reimbursement_submit.php?id=' . $requestId;
    expectReimbursement($admin($submitPath)['status'] === 404, 'Admin must not submit someone else’s draft');
    expectReimbursement($reviewer($submitPath)['status'] === 403, 'Reviewer cannot submit');
    expectReimbursement(str_contains($list['body'], $submitPath), 'Own active drafts have Submit icon');
    $conn->execute_query('UPDATE users SET email = ? WHERE id = ?', ['owner@example.test', $users['editor']]);
    $profile = $editor('profile.php');
    $profileSave = $editor('profile.php', ['csrf_token' => reimbursementCsrf($profile), 'first_name' => 'Test', 'last_name' => 'Owner', 'phone' => '', 'reimbursement_reviewer_email' => 'reviewer@example.test']);
    expectReimbursement($conn->execute_query('SELECT reimbursement_reviewer_email FROM users WHERE id = ?', [$users['editor']])->fetch_row()[0] === 'reviewer@example.test', 'Profile saves reimbursement reviewer');
    $conn->query("UPDATE reimbursement_setup SET bookkeeper_email = 'bookkeeper@example.test', cc_email = 'copy@example.test', reviewer_email = 'catalog@example.test' WHERE id = 1");
    $note = "Please use the updated account.\nCall before issuing payment <script>alert(1)</script>.";
    $draftPage = $editor($path);
    $invalidNote = $editor($path, ['csrf_token' => reimbursementCsrf($draftPage), 'action' => 'note', 'bookkeeper_note' => str_repeat('x', 1001)]);
    expectReimbursement(str_contains($invalidNote['body'], '1000 characters'), 'Oversized bookkeeper note is rejected');
    $savedNote = $editor($path, ['csrf_token' => reimbursementCsrf($draftPage), 'action' => 'note', 'bookkeeper_note' => $note, 'continue_to_review' => '1']);
    expectReimbursement($savedNote['status'] === 303 && str_contains($savedNote['headers'], $submitPath), 'Review button saves the note before opening email review');
    expectReimbursement($conn->execute_query('SELECT bookkeeper_note FROM reimbursement_requests WHERE id = ?', [$requestId])->fetch_row()[0] === $note, 'Draft stores the bookkeeper note');
    $notePage = $editor($path);
    expectReimbursement(str_contains($notePage['body'], 'name="delete_note"'), 'Saved note offers an explicit Delete Note button');
    $clearedNote = $editor($path, ['csrf_token' => reimbursementCsrf($notePage), 'action' => 'note', 'bookkeeper_note' => '']);
    expectReimbursement(str_contains($clearedNote['body'], 'Use Delete Note') && $conn->execute_query('SELECT bookkeeper_note FROM reimbursement_requests WHERE id = ?', [$requestId])->fetch_row()[0] === $note, 'Blank input cannot silently delete a saved note');
    $deletedNote = $editor($path, ['csrf_token' => reimbursementCsrf($notePage), 'action' => 'note', 'delete_note' => '1', 'bookkeeper_note' => $note]);
    expectReimbursement($deletedNote['status'] === 303 && $conn->execute_query('SELECT bookkeeper_note FROM reimbursement_requests WHERE id = ?', [$requestId])->fetch_row()[0] === '', 'Delete Note clears the saved note');
    $notePage = $editor($path);
    $editor($path, ['csrf_token' => reimbursementCsrf($notePage), 'action' => 'note', 'bookkeeper_note' => $note, 'continue_to_review' => '1']);
    $review = $editor($submitPath);
    expectReimbursement($review['status'] === 200 && str_contains($review['body'], 'catalog@example.test') && str_contains($review['body'], 'Email Preview') && str_contains($review['body'], '&lt;script&gt;'), 'Review shows recipients and the escaped bookkeeper note in the email preview');
    expectReimbursement(str_contains($review['body'], 'Email Attachment Size')
        && str_contains($review['body'], 'role="meter"') && str_contains($review['body'], 'of the 18 MB ZIP limit'),
        'Submission review includes the measured email size meter');
    preg_match('/name="review_fingerprint" value="([^"]+)"/', $review['body'], $fingerprint);
    expectReimbursement(!empty($fingerprint[1]), 'Review fingerprint exists');
    $preview = $editor($submitPath . '&preview=package');
    expectReimbursement($preview['status'] === 200 && str_starts_with($preview['body'], "PK"), 'Review package downloads');
    $context = reimbursementSubmissionContext($conn, $requestId, $users['editor']);
    $recipients = reimbursementSubmissionRecipients($context);
    expectReimbursement(count($recipients['cc']) === 3 && $recipients['bcc'] === ['catalog@example.test'], 'Recipient routes include reviewer, global Cc, owner, hidden catalog');
    $duplicate = $context; $duplicate['setup']['cc_email'] = 'owner@example.test';
    expectReimbursement(count(reimbursementSubmissionRecipients($duplicate)['cc']) === 2, 'Duplicate recipients receive one copy');
    $context['items'][0]['merchant'] = '<script>alert(1)</script>';
    expectReimbursement(!str_contains(reimbursementSubmissionMessage($context)['html_body'], '<script>'), 'Email escapes merchant markup');
    $notedMessage = reimbursementSubmissionMessage($context);
    expectReimbursement(str_contains($notedMessage['body'], $note) && str_contains($notedMessage['html_body'], 'Note to the bookkeeper') && str_contains($notedMessage['html_body'], 'Call before issuing payment &lt;script&gt;'), 'Bookkeeper note appears in both email formats and HTML is escaped');
    $blankNoteContext = $context; $blankNoteContext['request']['bookkeeper_note'] = '';
    expectReimbursement(!str_contains(reimbursementSubmissionMessage($blankNoteContext)['body'], 'Note from '), 'Empty note does not add a note section');
    try { reimbursementSubmissionNote(str_repeat('x', 1001)); throw new RuntimeException('Oversized note accepted'); }
    catch (InvalidArgumentException $e) { expectReimbursement(str_contains($e->getMessage(), '1000'), 'Oversized note is rejected'); }
    $post = ['csrf_token' => reimbursementCsrf($review), 'review_fingerprint' => $fingerprint[1]];
    $editor($submitPath, $post);
    expectReimbursement($conn->execute_query('SELECT status FROM reimbursement_requests WHERE id = ?', [$requestId])->fetch_row()[0] === 'draft', 'Confirmation is required');
    $conn->execute_query('UPDATE reimbursement_expenses SET amount_cents = amount_cents + 1 WHERE id = ?', [$expenseId]);
    $stale = $editor($submitPath, $post + ['confirm_submission' => '1']);
    expectReimbursement(str_contains($stale['body'], 'changed'), 'Changed expenses require a fresh review');
    $conn->execute_query('UPDATE reimbursement_expenses SET amount_cents = amount_cents - 1 WHERE id = ?', [$expenseId]);
    $review = $editor($submitPath);
    preg_match('/name="review_fingerprint" value="([^"]+)"/', $review['body'], $fingerprint);
    $post['review_fingerprint'] = $fingerprint[1]; $post['confirm_submission'] = '1';
    $submitted = $editor($submitPath, $post);
    expectReimbursement($submitted['status'] === 303, 'Reviewed request submits and queues email');
    $deliveries = $conn->execute_query('SELECT * FROM reimbursement_email_deliveries WHERE request_id = ? ORDER BY id', [$requestId])->fetch_all(MYSQLI_ASSOC);
    $deliveryIds = array_column($deliveries, 'id');
    expectReimbursement(count($deliveries) === 5, 'One delivery per unique recipient');
    expectReimbursement($editor($submitPath, $post)['status'] === 409, 'Repeated submit cannot enqueue duplicates');
    $queuedMessage = decryptQueuedReimbursementEmail($deliveries[0]['payload_ciphertext']);
    expectReimbursement(str_contains($queuedMessage['body'], $note) && str_contains($queuedMessage['html_body'], '&lt;script&gt;'), 'Queued email preserves the reviewed bookkeeper note safely');
    expectReimbursement(str_contains($queuedMessage['html_body'], $expectedReceiptFilename) && str_contains($queuedMessage['body'], $expectedReceiptFilename), 'HTML and plain-text email identify the exact receipt filename');
    $attachments = reimbursementEmailAttachments($conn, $deliveries[0], $queuedMessage);
    expectReimbursement(count($attachments) === 1 && $attachments[0]['content_type'] === 'application/zip',
        'Queued email attaches only the ZIP');
    $legacyMessage = $queuedMessage; unset($legacyMessage['individual_attachments']);
    $legacyAttachments = reimbursementEmailAttachments($conn, $deliveries[0], $legacyMessage);
    expectReimbursement($legacyAttachments === $attachments, 'Older queued messages also attach only the ZIP');
    $attachment = $attachments[0];
    $tmp = tempnam(sys_get_temp_dir(), 'queued-zip-'); file_put_contents($tmp, $attachment['data']);
    $zip = new ZipArchive(); $zip->open($tmp);
    expectReimbursement($zip->numFiles === 3 && $zip->getFromIndex(2) === $bytes, 'Immutable queued ZIP includes intact receipt');
    $base = substr($queuedMessage['filename'], 0, -4);
    expectReimbursement(str_starts_with($zip->getFromName($base . '.pdf'), '%PDF-'), 'ZIP retains the report PDF');
    expectReimbursement(str_contains($zip->getFromName($base . '.csv'), $expectedReceiptFilename), 'ZIP retains the CSV');
    $zip->close(); unlink($tmp);
    $mime = smtpMessageContent($queuedMessage['body'], $queuedMessage['html_body'], [...$attachments, ...$queuedMessage['inline_images']]);
    expectReimbursement(str_contains($mime['headers'][0], 'multipart/mixed')
        && substr_count($mime['body'], 'Content-Disposition: attachment;') === 1, 'SMTP message contains only one ZIP attachment');
    expectReimbursement($queuedMessage['inline_images'] === [] && str_contains($queuedMessage['html_body'], htmlspecialchars(emailBrandLogoUrl(), ENT_QUOTES, 'UTF-8')) && str_contains($queuedMessage['html_body'], 'display:block;width:227px;'), 'Submission uses the hosted digest logo at its original display size');
    require_once $source . '/notification_helpers.php';
    $digestImage = emailBrandInlineImage();
    $digestCiphertext = \Dnr\Security\ApplicationKey::seal(json_encode(['recipient' => 'owner@example.test', 'subject' => 'Digest fixture', 'body' => 'Test', 'html_body' => '<img src="cid:' . $digestImage['content_id'] . '">', 'inline_images' => [$digestImage]], JSON_THROW_ON_ERROR));
    $digestDecoded = decryptQueuedNotificationEmail($digestCiphertext);
    expectReimbursement($digestDecoded['inline_images'][0]['data'] === file_get_contents($source . '/assets/dnr-logo-email.png'), 'Notification payload encryption preserves embedded logo bytes');
    $claim = claimQueuedReimbursementEmail($conn);
    expectReimbursement($claim !== null, 'Worker claims a delivery');
    startEmailDelivery($conn, 'reimbursement_email_deliveries', $claim['id'], $claim['claim_token']);
    failQueuedReimbursementEmail($conn, $claim['id'], $claim['attempts'], new SmtpUncertainDeliveryException('Synthetic uncertain delivery'), false, $claim['claim_token']);
    expectReimbursement($conn->execute_query('SELECT status FROM reimbursement_email_deliveries WHERE id = ?', [$claim['id']])->fetch_row()[0] === 'delivery_uncertain', 'Uncertain SMTP delivery is never automatically resent');
    $claim = claimQueuedReimbursementEmail($conn);
    completeQueuedReimbursementEmail($conn, $claim['id'], $claim['claim_token']);
    expectReimbursement($conn->execute_query('SELECT payload_ciphertext FROM reimbursement_email_deliveries WHERE id = ?', [$claim['id']])->fetch_row()[0] === null, 'Delivered payload is cleared');

    $row = $conn->execute_query('SELECT status, submitted_at FROM reimbursement_requests WHERE id = ?', [$requestId])->fetch_assoc();
    expectReimbursement($row['status'] === 'submitted' && $row['submitted_at'] !== null, 'Status timestamp must be recorded');
    $submittedView = $editor('reimbursements.php?state=submitted&start_date=2026-01-01&end_date=2026-12-31');
    $draftView = $editor('reimbursements.php?state=draft&start_date=2026-01-01&end_date=2026-12-31');
    expectReimbursement(str_contains($submittedView['body'], 'data-selection-expense="' . $expenseId . '"') && !str_contains($draftView['body'], 'data-selection-expense="' . $expenseId . '"'), 'Submitted state follows the request transition');
    expectReimbursementRejected(fn() => saveReimbursementExpense($conn, $users['editor'], $input, [], $expenseId), 'Submitted expense must be locked');
    expectReimbursementRejected(fn() => deleteReimbursementReceipt($conn, $expenseId, $receiptId, $users['editor']), 'Submitted receipt must be locked');
    expectReimbursementRejected(fn() => createReimbursementRequest($conn, $users['editor'], '2026-01-01', '2026-12-31', [$expenseId]), 'An expense cannot belong to two requests');
    expectReimbursementRejected(fn() => deleteReimbursementRequest($conn, $requestId, $users['editor'], false), 'Editor must not delete submitted request');
    expectReimbursementRejected(fn()=>deleteReimbursementRequest($conn,$requestId,$users['admin'],true),'Admin cannot destroy submitted history');
    expectReimbursementRejected(fn()=>deleteReimbursementReceipt($conn,$expenseId,$receiptId,$users['editor']),'Receipt stays locked after rejected deletion');
    expectReimbursementRejected(fn()=>deleteReimbursementExpense($conn,$expenseId),'Submitted expense remains linked and undeletable');
    $originalPackage=$editor('download_reimbursement.php?id='.$requestId.'&format=zip');
    expectReimbursement(hash('sha256',$originalPackage['body'])===hash('sha256',$attachment['data']),'Submitted package is byte-identical to approved attachment');
    $conn->execute_query('UPDATE reimbursement_setup SET organization_name=? WHERE id=1',['Changed after submission']);
    $conn->execute_query('UPDATE users SET first_name=? WHERE id=?',['Changed',$users['editor']]);
    expectReimbursement($editor('download_reimbursement.php?id='.$requestId.'&format=zip')['body']===$originalPackage['body'],'Settings/profile changes do not rewrite original package');
    $originalReport=$editor('download_reimbursement.php?id='.$requestId.'&format=pdf');
    expectReimbursement($originalReport['status']===200 && str_starts_with($originalReport['body'],'%PDF-'),'Original PDF extracted from saved package');
    expectReimbursement(str_contains($editor($path)['body'],'mode=view'),'Submitted request links read-only expense view');
    $snapshot=reimbursementSnapshot($conn,$requestId);
    expectReimbursement($snapshot['recipients']['bcc']===['catalog@example.test'],'Encrypted internal snapshot preserves Bcc routing');
    $pdfPath=tempnam(sys_get_temp_dir(),'bcc-report-'); file_put_contents($pdfPath,$originalReport['body']);
    $textPath=$pdfPath.'.txt';
    exec('pdftotext '.escapeshellarg($pdfPath).' '.escapeshellarg($textPath),$output,$code);
    expectReimbursement($code===0 && !str_contains(file_get_contents($textPath),'catalog@example.test'),'Bcc identity absent from actual PDF text');
    unlink($pdfPath); unlink($textPath);
    expectReimbursement(!str_contains($queuedMessage['html_body'],'catalog@example.test') && !str_contains($queuedMessage['body'],'catalog@example.test'),'Bcc identity absent from recipient message bodies');
    $failedId=(int)$deliveries[2]['id'];
    $conn->execute_query("UPDATE reimbursement_email_deliveries SET status='failed',payload_ciphertext=NULL WHERE id=?",[$failedId]);
    expectReimbursementRejected(fn()=>recoverReimbursementDelivery($conn,$requestId,$failedId,$users['reviewer'],false,'retry_delivery','Fixture',true),'Other user cannot retry delivery');
    recoverReimbursementDelivery($conn,$requestId,$failedId,$users['editor'],false,'retry_delivery','Retry a failed fixture',true);
    $restored=$conn->execute_query('SELECT payload_ciphertext FROM reimbursement_email_deliveries WHERE id=?',[$failedId])->fetch_row()[0];
    expectReimbursement(decryptQueuedReimbursementEmail($restored)['body']===$queuedMessage['body'],'Retry restores the exact original approved message');
    expectReimbursementRejected(fn()=>recoverReimbursementDelivery($conn,$requestId,(int)$deliveries[0]['id'],$users['editor'],false,'retry_delivery','Fixture',true),'Uncertain delivery cannot use failed retry action');
    correctReimbursementRequest($conn,$requestId,$users['editor'],false,'Synthetic correction — original remains locked');
    expectReimbursement($conn->execute_query('SELECT status FROM reimbursement_email_deliveries WHERE id=?',[$failedId])->fetch_row()[0]==='cancelled','Correction cancels only unsent delivery');
    expectReimbursement($conn->execute_query('SELECT status FROM reimbursement_email_deliveries WHERE id=?',[$deliveries[0]['id']])->fetch_row()[0]==='delivery_uncertain','Correction preserves uncertain delivery for review');
    expectReimbursementRejected(fn()=>createReimbursementRequest($conn,$users['editor'],'2026-01-01','2026-12-31',[$expenseId]),'Correction does not permit duplicate reimbursement');
    // Separate disposable available expense exercises draft deletion and receipt removal.
    $expenseId=saveReimbursementExpense($conn,$users['editor'],$input,[],null); $expenses[]=$expenseId;
    $emptyId = createReimbursementRequest($conn, $users['editor'], '2026-01-01', '2026-12-31', [$expenseId]); $requests[] = $emptyId;
    $emptyPath = 'reimbursement_request.php?id=' . $emptyId . '&mode=edit';
    $editor($emptyPath, ['csrf_token' => $csrf, 'action' => 'remove', 'expense_id' => $expenseId]);
    expectReimbursement(!str_contains($editor($emptyPath)['body'], 'Download Report PDF'), 'Empty draft must not offer downloads');
    expectReimbursement($editor('download_reimbursement.php?id=' . $emptyId . '&format=pdf')['status'] === 409, 'Direct empty export must return useful conflict, not server error');
    $reviewerRequest = createReimbursementRequest($conn, $users['reviewer'], '2026-01-01', '2026-12-31', [$otherId]); $requests[] = $reviewerRequest;
    $reviewerForm = $reviewer('reimbursement_expense.php?id=' . $otherId);
    $reviewerCsrf = reimbursementCsrf($reviewerForm);
    expectReimbursement(!str_contains($reviewerForm['body'], '>Save Expense<'), 'Reviewer own expense is read-only');
    foreach (['reimbursement_expense.php?id=' . $otherId, 'reimbursements.php', 'reimbursement_request.php?id=' . $reviewerRequest . '&mode=edit', 'reimbursement_requests.php'] as $endpoint) {
        expectReimbursement($reviewer($endpoint, ['csrf_token' => $reviewerCsrf, 'action' => 'save'])['status'] === 403, 'Reviewer must not mutate ' . $endpoint);
    }
    deleteReimbursementExpense($conn, $expenseId);
    expectReimbursement($conn->execute_query('SELECT id FROM reimbursement_expenses WHERE id = ?', [$expenseId])->num_rows === 0, 'Available expense deletion succeeds');
    echo "Reimbursement workflow: {$checks} assertions passed.\n";
} finally {
    foreach ($deliveryIds as $deliveryId) $conn->execute_query('DELETE FROM reimbursement_email_deliveries WHERE id = ?', [$deliveryId]);
    if ($originalSetup) $conn->execute_query('UPDATE reimbursement_setup SET bookkeeper_email = ?, cc_email = ?, reviewer_email = ? WHERE id = 1', [$originalSetup['bookkeeper_email'], $originalSetup['cc_email'], $originalSetup['reviewer_email']]);
    foreach ($users as $user) {
        if ($conn->execute_query("SELECT id FROM reimbursement_requests WHERE user_id=? AND status='submitted' LIMIT 1",[$user])->fetch_row()) continue;
        $conn->execute_query('DELETE FROM reimbursement_requests WHERE user_id = ?', [$user]);
        $conn->execute_query('DELETE FROM reimbursement_expenses WHERE user_id = ?', [$user]);
    }
    if ($centerId && !$conn->execute_query('SELECT id FROM reimbursement_expenses WHERE cost_center_id=? LIMIT 1',[$centerId])->fetch_row()) $conn->execute_query('DELETE FROM reimbursement_cost_centers WHERE id = ?', [$centerId]);
    foreach ($users as $user) if (!$conn->execute_query('SELECT id FROM reimbursement_expenses WHERE user_id=? LIMIT 1',[$user])->fetch_row()) $conn->execute_query('DELETE FROM users WHERE id = ?', [$user]);
    foreach ($cookies as $cookie) @unlink($cookie);
}
