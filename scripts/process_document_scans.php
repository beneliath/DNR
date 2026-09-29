<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once '/var/www/html/config.php';
require_once '/var/www/html/document_scanning_helpers.php';
require_once '/var/www/html/reimbursement_receipt_helpers.php';
require_once '/var/www/html/worker_health_helpers.php';
if (!documentScanningEnabled()) { fwrite(STDERR,"Document scanning is not enabled.\n"); exit(64); }
$loop=in_array('--loop',$argv,true);
do {
    try {
        $receiptProcessed=processReimbursementReceiptScan($conn);
        $processed=processDocumentScan($conn) || $receiptProcessed;
        recordWorkerHeartbeat('document-scans',true);
    } catch (Throwable $error) {
        recordWorkerHeartbeat('document-scans',false);
        applicationLog('error','Document scan delayed',['type'=>get_class($error)]);
        exit(1); // Supervisor reconnects after database/scanner failure.
    }
    if ($loop && !$processed) sleep(10);
} while ($loop);
