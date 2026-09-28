<?php
declare(strict_types=1);
require_once __DIR__ . '/persistent_file_helpers.php';

function documentScanningEnabled(): bool { return getenv('DNR_DOCUMENT_SCANNING') === '1'; }
function documentAssetTable(string $kind): string {
    return match ($kind) { 'notes' => 'presentation_notes', 'slidedeck' => 'presentation_slidedecks',
        default => throw new InvalidArgumentException('Unknown document kind.') };
}

/** Caller holds the presentation lock and transaction; a pending file is never published. */
function queueDocumentScan(mysqli $conn, int $presentation, int $speaker, string $kind, array $asset, ?int $uploader): void
{
    $table = documentAssetTable($kind);
    $key = isset($asset['path'])
        ? storePersistentFileFromPath($conn,$asset['path'],$asset['filename'],$asset['mime_type'] ?? 'application/pdf',$asset['size'],bin2hex($asset['sha256']))
        : storePersistentFile($conn,$asset['data'],$asset['filename'],$asset['mime_type'] ?? 'application/pdf');
    $version = $conn->execute_query("SELECT updated_at FROM {$table} WHERE presentation_id=? AND speaker_id=? FOR UPDATE",[$presentation,$speaker])->fetch_row()[0] ?? null;
    $conn->execute_query("INSERT INTO document_scan_jobs (presentation_id,speaker_id,asset_kind,storage_key,uploaded_by,base_version)
        VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE storage_key=VALUES(storage_key),uploaded_by=VALUES(uploaded_by),
        base_version=VALUES(base_version),state='queued',claim_token=NULL,attempts=0,retry_at=UTC_TIMESTAMP(6),status_detail=NULL,created_at=UTC_TIMESTAMP(6)",
        [$presentation,$speaker,$kind,$key,$uploader,$version]);
}

function cancelDocumentScan(mysqli $conn, int $presentation, int $speaker, string $kind): void
{
    $conn->execute_query('DELETE FROM document_scan_jobs WHERE presentation_id=? AND speaker_id=? AND asset_kind=?',[$presentation,$speaker,$kind]);
}

/** The host is operator configuration, never a submitted URL or a public scanner service. */
function openDocumentScanner()
{
    $host = getenv('DNR_CLAMD_HOST') ?: 'document-scanner';
    if (!preg_match('/\A[a-zA-Z0-9.-]+\z/', $host)) throw new RuntimeException('Invalid scanner host.');
    $port = (int) (getenv('DNR_CLAMD_PORT') ?: 3310);
    if ($port < 1 || $port > 65535) throw new RuntimeException('Invalid scanner port.');
    $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $code, $error, 5);
    if (!$socket) throw new RuntimeException('Document scanning is temporarily unavailable.');
    stream_set_timeout($socket, 120);
    return $socket;
}

function documentScannerWrite($socket, string $bytes): void
{
    for ($offset=0,$size=strlen($bytes);$offset<$size;) {
        $written = fwrite($socket,substr($bytes,$offset));
        if (!$written) throw new RuntimeException('Document scanner connection failed.');
        $offset += $written;
    }
}

function documentScannerReply($socket): string
{
    $reply = stream_get_line($socket,4096,"\0");
    if (!is_string($reply) || $reply === '' || stream_get_meta_data($socket)['timed_out']) {
        throw new RuntimeException('Document scanner did not complete its check.');
    }
    return trim($reply);
}

/** Fail closed on stale signatures, incomplete scans, timeouts and unknown responses. */
function scanDocumentStream($input, int $size): bool
{
    if ($size < 1 || $size > 500*1024*1024) throw new RuntimeException('Document exceeds scan capacity.');
    $versionSocket = openDocumentScanner();
    try {
        documentScannerWrite($versionSocket,"zVERSION\0");
        $version = explode('/',documentScannerReply($versionSocket));
        if (count($version)!==3) throw new RuntimeException('Scanner signature status is unavailable.');
        try { $signatureDate = new DateTimeImmutable($version[2],new DateTimeZone('UTC')); }
        catch (Throwable $e) { throw new RuntimeException('Scanner signature date is invalid.'); }
        $age = time()-$signatureDate->getTimestamp();
        if ($age < -3600 || $age > 72*3600) throw new RuntimeException('Scanner signatures need updating.');
    } finally { fclose($versionSocket); }
    $socket = openDocumentScanner(); $sent=0; $deadline=microtime(true)+120;
    try {
        documentScannerWrite($socket,"zINSTREAM\0");
        while (!feof($input)) {
            if (microtime(true)>$deadline) throw new RuntimeException('Document scan timed out.');
            $chunk=fread($input,65536);
            if ($chunk===false) throw new RuntimeException('Unable to read document for scanning.');
            if ($chunk==='') break;
            $sent+=strlen($chunk);
            if ($sent>$size) throw new RuntimeException('Document changed during scanning.');
            documentScannerWrite($socket,pack('N',strlen($chunk)).$chunk);
        }
        if ($sent!==$size) throw new RuntimeException('Document scan was incomplete.');
        documentScannerWrite($socket,pack('N',0));
        $reply=documentScannerReply($socket);
        if ($reply==='stream: OK') return true;
        if (str_starts_with($reply,'stream: ') && str_ends_with($reply,' FOUND')) return false;
        throw new RuntimeException('Document scanner could not verify this file.');
    } finally { fclose($socket); }
}

function claimDocumentScan(mysqli $conn): ?array
{
    $conn->begin_transaction();
    try {
        $job=$conn->query("SELECT * FROM document_scan_jobs WHERE state IN ('queued','scanning') AND retry_at<=UTC_TIMESTAMP(6)
            ORDER BY retry_at,id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch_assoc();
        if ($job) {
            $job['claim_token']=bin2hex(random_bytes(16));
            $conn->execute_query("UPDATE document_scan_jobs SET state='scanning',claim_token=?,attempts=attempts+1,
                retry_at=UTC_TIMESTAMP(6)+INTERVAL 5 MINUTE WHERE id=?",[$job['claim_token'],$job['id']]);
        }
        $conn->commit(); return $job;
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
}

/** Recheck the upload generation, presentation speaker and published version before promotion. */
function finishDocumentScan(mysqli $conn, array $job, bool $clean): void
{
    $conn->begin_transaction();
    try {
        $presentation=$conn->execute_query('SELECT speaker_id FROM presentations WHERE id=? FOR UPDATE',[$job['presentation_id']])->fetch_assoc();
        $current=$conn->execute_query('SELECT * FROM document_scan_jobs WHERE id=? FOR UPDATE',[$job['id']])->fetch_assoc();
        if (!$current || $current['claim_token']!==$job['claim_token'] || $current['state']!=='scanning') { $conn->commit(); return; }
        $table=documentAssetTable($job['asset_kind']);
        $version=$conn->execute_query("SELECT updated_at FROM {$table} WHERE presentation_id=? AND speaker_id=? FOR UPDATE",[$job['presentation_id'],$job['speaker_id']])->fetch_row()[0] ?? null;
        $state=$clean?'clean':'rejected';
        if (!$presentation || (int)$presentation['speaker_id']!==(int)$job['speaker_id'] || $version!==$current['base_version']) $state='superseded';
        if ($state==='clean') {
            $file=$conn->execute_query('SELECT * FROM stored_files WHERE storage_key=?',[$current['storage_key']])->fetch_assoc();
            if (!$file) throw new RuntimeException('Scanned document is missing.');
            if ($job['asset_kind']==='notes') {
                $conn->execute_query("INSERT INTO presentation_notes (presentation_id,speaker_id,storage_key,filename,size,sha256,uploaded_by,uploaded_by_username_snapshot)
                    VALUES (?,?,?,?,?,UNHEX(?),?,(SELECT username FROM users WHERE id=?))
                    ON DUPLICATE KEY UPDATE pdf=NULL,storage_key=VALUES(storage_key),filename=VALUES(filename),size=VALUES(size),sha256=VALUES(sha256),
                    uploaded_by=VALUES(uploaded_by),uploaded_by_username_snapshot=VALUES(uploaded_by_username_snapshot),updated_at=UTC_TIMESTAMP(6)",
                    [$job['presentation_id'],$job['speaker_id'],$file['storage_key'],$file['filename'],$file['size'],$file['checksum'],$current['uploaded_by'],$current['uploaded_by']]);
            } else {
                $conn->execute_query("INSERT INTO presentation_slidedecks (presentation_id,speaker_id,storage_key,filename,mime_type,size,sha256,uploaded_by,uploaded_by_username_snapshot)
                    VALUES (?,?,?,?,?,?,UNHEX(?),?,(SELECT username FROM users WHERE id=?))
                    ON DUPLICATE KEY UPDATE storage_key=VALUES(storage_key),filename=VALUES(filename),mime_type=VALUES(mime_type),size=VALUES(size),sha256=VALUES(sha256),
                    uploaded_by=VALUES(uploaded_by),uploaded_by_username_snapshot=VALUES(uploaded_by_username_snapshot),updated_at=UTC_TIMESTAMP(6)",
                    [$job['presentation_id'],$job['speaker_id'],$file['storage_key'],$file['filename'],$file['content_type'],$file['size'],$file['checksum'],$current['uploaded_by'],$current['uploaded_by']]);
            }
        }
        $conn->execute_query('UPDATE document_scan_jobs SET state=?,claim_token=NULL,status_detail=? WHERE id=?',
            [$state,$state==='rejected'?'The document did not pass the security scan.':null,$job['id']]);
        $conn->commit();
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
}

function processDocumentScan(mysqli $conn): bool
{
    $job=claimDocumentScan($conn);
    if (!$job) return false;
    try {
        $file=$conn->execute_query('SELECT * FROM stored_files WHERE storage_key=?',[$job['storage_key']])->fetch_assoc();
        if (!$file) throw new RuntimeException('Queued document is missing.');
        $input=openPersistentFile($file,true);
        try { $clean=scanDocumentStream($input,(int)$file['size']); } finally { fclose($input); }
        finishDocumentScan($conn,$job,$clean);
    } catch (Throwable $error) {
        $conn->execute_query("UPDATE document_scan_jobs SET state='queued',claim_token=NULL,status_detail='Security scan delayed; the previous approved file remains available.',
            retry_at=UTC_TIMESTAMP(6)+INTERVAL 5 MINUTE WHERE id=? AND claim_token=?",[$job['id'],$job['claim_token']]);
        throw $error;
    }
    return true;
}

/** Fetch once in the page controller, not once per upload pane. */
function documentScanMessages(mysqli $conn, int $engagement): array
{
    $rows=$conn->execute_query('SELECT j.presentation_id,j.speaker_id,j.asset_kind,j.state FROM document_scan_jobs j
        INNER JOIN presentations p ON p.id=j.presentation_id WHERE p.engagement_id=? LIMIT 1000',[$engagement])->fetch_all(MYSQLI_ASSOC);
    $messages=[];
    foreach ($rows as $row) {
        $messages[$row['presentation_id'].':'.$row['speaker_id'].':'.$row['asset_kind']]=match($row['state']) {
            'queued','scanning'=>'The new document is awaiting a security scan. Any previously approved file remains available. Reload to check its status.',
            'rejected'=>'The new document did not pass the security scan and was not published. Any previously approved file remains available.',
            'superseded'=>'The document changed while the scan was running. Upload it again if this is still the version you want to publish.',
            default=>''
        };
    }
    return $messages;
}
