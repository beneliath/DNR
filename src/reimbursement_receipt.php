<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/reimbursement_helpers.php';
require_once __DIR__ . '/reimbursement_receipt_helpers.php';
startSecureSession();
requireLogin();
$conn = applicationDatabaseConnection();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) { http_response_code(400); exit('Receipt ID required.'); }
$row = $conn->execute_query('SELECT rr.id, rr.scan_state, rr.thumbnail_key, rr.storage_key, rr.filename, rr.content_type, sf.size, sf.checksum
    FROM reimbursement_receipts rr JOIN reimbursement_expenses e ON e.id = rr.expense_id
    JOIN stored_files sf ON sf.storage_key = rr.storage_key
    WHERE rr.id = ? AND (e.user_id = ? OR ? = 1)', [$id, (int) $_SESSION['user_id'], (int) hasRole(['admin'])])->fetch_assoc();
if (!$row) { http_response_code(404); exit('Receipt not found.'); }
releaseApplicationSessionLock();
try { reimbursementReceiptsReady([$row]); }
catch (InvalidArgumentException $e) { http_response_code(409); exit($e->getMessage()); }
if (($_GET['preview'] ?? '') === '1') {
    try { $thumbnail = reimbursementThumbnail($conn,$row); }
    catch (Throwable $e) { $thumbnail=null; applicationLog('warning','Receipt preview unavailable',['receipt_id'=>$id,'type'=>get_class($e)]); }
    if (!$thumbnail) { http_response_code(404); exit('Preview unavailable. Open the original receipt.'); }
    $row=$thumbnail;
}
$etag='"receipt-'.$row['checksum'].'"';
header('Cache-Control: private, max-age=0, must-revalidate');
header('ETag: '.$etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit(); }
try { $file = openPersistentFile($row); }
catch (Throwable $e) { applicationLog('error', 'Unable to open reimbursement receipt', ['id' => $id, 'error' => $e->getMessage()]); http_response_code(503); exit('Receipt unavailable.'); }
$filename = preg_replace('/[^A-Za-z0-9._ -]+/', '_', basename($row['filename'])) ?: 'receipt';
header('Content-Type: ' . $row['content_type']);
header('Content-Disposition: inline; filename="' . addcslashes($filename, '"\\') . '"');
header('Content-Length: ' . (int) $row['size']);

header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
fpassthru($file);
fclose($file);
