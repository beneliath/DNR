<?php
declare(strict_types=1);
require_once __DIR__ . '/document_scanning_helpers.php';
require_once __DIR__ . '/reimbursement_pdf_receipt_helpers.php';

/** One leased receipt per iteration. No locks are held during the scanner call. */
function processReimbursementReceiptScan(mysqli $conn): bool
{
    if (!documentScanningEnabled()) return false;
    $conn->begin_transaction();
    try {
        $job=$conn->query("SELECT id,storage_key FROM reimbursement_receipts WHERE scan_state IN ('queued','scanning','unscanned') AND (scan_retry_at IS NULL OR scan_retry_at<=UTC_TIMESTAMP()) ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch_assoc();
        if (!$job) { $conn->commit(); return false; }
        $token=bin2hex(random_bytes(16));
        $conn->execute_query("UPDATE reimbursement_receipts SET scan_state='scanning',scan_token=?,scan_attempts=scan_attempts+1,scan_retry_at=UTC_TIMESTAMP()+INTERVAL 5 MINUTE WHERE id=?",[$token,$job['id']]);
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); throw $e; }
    try {
        $file=$conn->execute_query('SELECT * FROM stored_files WHERE storage_key=?',[$job['storage_key']])->fetch_assoc();
        if (!$file) throw new RuntimeException('Receipt missing.');
        $stream=openPersistentFile($file,true);
        try { $clean=scanDocumentStream($stream,(int)$file['size']); } finally { fclose($stream); }
        $conn->execute_query('UPDATE reimbursement_receipts SET scan_state=?,scan_token=NULL,scan_retry_at=NULL WHERE id=? AND scan_token=?',[$clean?'clean':'rejected',$job['id'],$token]);
    } catch (Throwable $e) {
        $conn->execute_query("UPDATE reimbursement_receipts SET scan_state='queued',scan_token=NULL,scan_retry_at=UTC_TIMESTAMP()+INTERVAL 5 MINUTE WHERE id=? AND scan_token=?",[$job['id'],$token]);
        throw $e;
    }
    return true;
}

/** Private, bounded derivative. Originals remain immutable and authorization is checked by the endpoint. */
function reimbursementThumbnail(mysqli $conn, array $receipt): ?array
{
    if (!empty($receipt['thumbnail_key'])) return $conn->execute_query('SELECT * FROM stored_files WHERE storage_key=?',[$receipt['thumbnail_key']])->fetch_assoc() ?: null;
    $guard=fopen(sys_get_temp_dir().'/dnr-receipt-preview-'.hash('sha256',$receipt['storage_key']).'.lock','c');
    if (!$guard || !flock($guard,LOCK_EX|LOCK_NB)) { if ($guard) fclose($guard); return null; }
    $temporary=null;
    try {
        $existing=$conn->execute_query('SELECT thumbnail_key FROM reimbursement_receipts WHERE id=?',[$receipt['id']])->fetch_assoc();
        if (!$existing) return null;
        if ($existing['thumbnail_key']) return $conn->execute_query('SELECT * FROM stored_files WHERE storage_key=?',[$existing['thumbnail_key']])->fetch_assoc() ?: null;
        $stream=openPersistentFile($receipt,true); fclose($stream);
        $path=persistentFilePath($receipt['storage_key']);
        if ($receipt['content_type']==='application/pdf') {
            if (!function_exists('proc_open') || !is_executable('/usr/bin/pdftoppm')) return null;
            $temporary=tempnam(sys_get_temp_dir(),'receipt-thumb-');
            $command=['/usr/bin/timeout','12','/usr/bin/prlimit','--as=268435456','--cpu=10','--','/usr/bin/pdftoppm','-f','1','-singlefile','-scale-to','640','-jpeg',$path,$temporary];
            $process=proc_open($command,[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
            if (!is_resource($process) || proc_close($process)!==0 || !is_file($temporary.'.jpg')) return null;
            $image=@imagecreatefromjpeg($temporary.'.jpg');
        } else {
            $info=@getimagesize($path);
            if (!$info || $info[0]*$info[1]>24000000) return null;
            $image=match($receipt['content_type']) {'image/jpeg'=>@imagecreatefromjpeg($path),'image/png'=>@imagecreatefrompng($path),'image/webp'=>@imagecreatefromwebp($path),default=>false};
        }
        if (!$image) return null;
        $ratio=min(1,640/max(imagesx($image),imagesy($image)));
        $thumb=imagecreatetruecolor(max(1,(int)(imagesx($image)*$ratio)),max(1,(int)(imagesy($image)*$ratio)));
        imagefill($thumb,0,0,imagecolorallocate($thumb,255,255,255));
        imagecopyresampled($thumb,$image,0,0,0,0,imagesx($thumb),imagesy($thumb),imagesx($image),imagesy($image));
        ob_start(); imagejpeg($thumb,null,78); $bytes=ob_get_clean(); unset($image,$thumb);
        $key=storePersistentFile($conn,$bytes,'receipt-preview-'.$receipt['checksum'].'.jpg','image/jpeg');
        $conn->execute_query('UPDATE reimbursement_receipts SET thumbnail_key=? WHERE id=? AND storage_key=?',[$key,$receipt['id'],$receipt['storage_key']]);
        return $conn->execute_query('SELECT * FROM stored_files WHERE storage_key=?',[$key])->fetch_assoc();
    } finally {
        if ($temporary!==null) { @unlink($temporary); @unlink($temporary.'.jpg'); }
        flock($guard,LOCK_UN); fclose($guard);
    }
}
