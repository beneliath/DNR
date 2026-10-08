<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once '/opt/dnr/vendor/autoload.php';
require_once '/var/www/html/config.php';
require_once '/var/www/html/reimbursement_receipt_helpers.php';
require_once '/var/www/html/worker_health_helpers.php';
$loop=in_array('--loop',$argv,true);
do {
    try {
        $conn->begin_transaction();
        $scan=documentScanningEnabled() ? "rr.scan_state='clean'" : "rr.scan_state IN ('clean','unscanned')";
        $receipt=$conn->query("SELECT rr.id,rr.storage_key,rr.filename,rr.content_type,rr.thumbnail_key,rr.report_key,f.size,f.checksum FROM reimbursement_receipts rr JOIN stored_files f ON f.storage_key=rr.storage_key
            WHERE ((rr.thumbnail_key IS NULL AND (rr.thumbnail_attempted_at IS NULL OR rr.thumbnail_attempted_at<UTC_TIMESTAMP()-INTERVAL 1 HOUR))
                OR (rr.content_type='application/pdf' AND rr.report_key IS NULL AND (rr.report_attempted_at IS NULL OR rr.report_attempted_at<UTC_TIMESTAMP()-INTERVAL 1 HOUR))) AND {$scan}
            ORDER BY rr.id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch_assoc();
        if ($receipt) $conn->execute_query('UPDATE reimbursement_receipts SET thumbnail_attempted_at=UTC_TIMESTAMP(),report_attempted_at=UTC_TIMESTAMP() WHERE id=?',[$receipt['id']]);
        $conn->commit();
        if ($receipt) {
            if ($receipt['report_key'] === null) prepareReimbursementPdfReceipt($conn, $receipt);
            reimbursementThumbnail($conn,$receipt);
        }
        recordWorkerHeartbeat('receipt-previews',true);
    } catch (Throwable $error) {
        $conn->rollback();
        recordWorkerHeartbeat('receipt-previews',false);
        applicationLog('error','Receipt preview delayed',['type'=>get_class($error)]);
        exit(1);
    }
    if ($loop && !$receipt) sleep(10);
} while ($loop);
