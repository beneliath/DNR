<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/reimbursement_submission_helpers.php';
startSecureSession();
requireLogin();
releaseApplicationSessionLock();
$conn = applicationDatabaseConnection();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$format = (string) ($_GET['format'] ?? 'pdf');
if (!$id || !in_array($format, ['pdf', 'zip'], true)) { http_response_code(400); exit('Invalid report request.'); }
$request = $conn->execute_query('SELECT * FROM reimbursement_requests WHERE id = ? AND (user_id = ? OR ? = 1)',
    [$id, (int) $_SESSION['user_id'], (int) hasRole(['admin'])])->fetch_assoc();
if (!$request) { http_response_code(404); exit('Request not found.'); }
try {
    if ($request['status'] === 'submitted') {
        $original = $conn->execute_query('SELECT f.* FROM reimbursement_submissions s JOIN stored_files f ON f.storage_key=s.attachment_key WHERE s.id=?',[$id])->fetch_assoc();
        if (!$original) { http_response_code(409); exit('The original package is unavailable for this legacy request. Contact the owner; it will not be regenerated as an original.'); }
        $file = openPersistentFile($original,true);
        header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
        $base = reimbursementPackageBase($request);
        if ($format === 'zip') {
            header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="'.$base.'.zip"');
            header('Content-Length: '.(int)$original['size']); fpassthru($file); fclose($file); exit();
        }
        fclose($file);
        $zip = new ZipArchive();
        if ($zip->open(persistentFilePath($original['storage_key']))!==true) throw new RuntimeException('Original package unavailable.');
        try { $pdf = $zip->getFromName($base.'.pdf'); } finally { $zip->close(); }
        if ($pdf===false) throw new RuntimeException('Original report unavailable.');
        header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="'.$base.'.pdf"');
        header('Content-Length: '.strlen($pdf)); echo $pdf; exit();
    }
    $owner = reimbursementOwner($conn, (int) $request['user_id']);
    $setup = reimbursementSetup($conn);
    $items = $conn->execute_query('SELECT ri.expense_id,
        COALESCE(ri.expense_date, e.expense_date) AS expense_date,
        COALESCE(ri.merchant, e.merchant) AS merchant,
        COALESCE(ri.description, e.description) AS description,
        COALESCE(ri.amount_cents, e.amount_cents) AS amount_cents,
        COALESCE(ri.coa_number, c.coa_number) AS coa_number,
        COALESCE(ri.coa_description, c.description) AS coa_description
        FROM reimbursement_request_items ri JOIN reimbursement_expenses e ON e.id = ri.expense_id
        JOIN reimbursement_cost_centers c ON c.id = e.cost_center_id
        WHERE ri.request_id = ? ORDER BY expense_date, ri.expense_id', [$id])->fetch_all(MYSQLI_ASSOC);
    if (!$items) { http_response_code(409); exit('Add an expense to this request before downloading a report or package.'); }
    $receiptRows = $conn->execute_query('SELECT rr.*, sf.size, sf.checksum, rf.size AS report_size, rf.checksum AS report_checksum FROM reimbursement_receipts rr
        JOIN stored_files sf ON sf.storage_key = rr.storage_key
        LEFT JOIN stored_files rf ON rf.storage_key = rr.report_key
        JOIN reimbursement_request_items ri ON ri.expense_id = rr.expense_id
        WHERE ri.request_id = ? ORDER BY rr.expense_id, rr.id', [$id])->fetch_all(MYSQLI_ASSOC);
    reimbursementReceiptsReady($receiptRows);
    $receipts = [];
    foreach ($receiptRows as $receipt) {
        $file = openPersistentFile($receipt, true);
        fclose($file);
        $receipts[(int) $receipt['expense_id']][] = $receipt;
    }
    if (!class_exists('TCPDF')) throw new RuntimeException('PDF library unavailable.');
    $pdf = renderReimbursementPdf($request, $owner, $items, $receipts, $setup);
    $base = 'reimbursement-' . $request['request_hash'] . '-' . $request['start_date'] . '-to-' . $request['end_date'];
    if ($format === 'pdf') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $base . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
        exit();
    }
    $temporary = createReimbursementZip($pdf, reimbursementExpenseCsv($items, $receiptRows), $receiptRows, $base);
    try {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $base . '.zip"');
        header('Content-Length: ' . filesize($temporary));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($temporary);
    } finally { unlink($temporary); }
} catch (ReimbursementReceiptPdfException $e) {
    applicationLog('error', 'Unable to export reimbursement request', ['request_id' => $id, 'error' => $e->getMessage()]);
    if (!headers_sent()) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        exit($e->getMessage());
    }
} catch (Throwable $e) {
    applicationLog('error', 'Unable to export reimbursement request', ['request_id' => $id, 'error' => $e->getMessage()]);
    if (!headers_sent()) { http_response_code(503); exit('Unable to prepare the reimbursement package.'); }
}
