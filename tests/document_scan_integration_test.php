<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST')!=='1' || getenv('DNR_INTEGRATION_TARGET')!=='disposable' || getenv('DNR_DOCUMENT_SCANNING')!=='1') { echo "Document scan integration skipped.\n"; exit(0); }
require_once '/var/www/html/config.php';
require_once '/var/www/html/document_scanning_helpers.php';
function scanExpect(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); }
$conn->execute_query('INSERT INTO organizations (organization_name) VALUES (?)',['Scan fixture '.bin2hex(random_bytes(4))]); $org=(int)$conn->insert_id;
$conn->execute_query("INSERT INTO engagements (organization_id,event_title,event_start_date,event_end_date,event_type,confirmation_status) VALUES (?,'Scan fixture','2026-10-01','2026-10-01','conference','under_review')",[$org]); $event=(int)$conn->insert_id;
$speaker=(int)$conn->query('SELECT id FROM speakers ORDER BY id LIMIT 1')->fetch_row()[0];
$conn->execute_query("INSERT INTO presentations (engagement_id,speaker_id,topic_title) VALUES (?,?,'Scan fixture')",[$event,$speaker]); $presentation=(int)$conn->insert_id;
$asset=static fn(string $bytes)=>['data'=>$bytes,'filename'=>'fixture.pdf','mime_type'=>'application/pdf','size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes,true)];
$queue=static function(array $file)use($conn,$presentation,$speaker):void {
    $conn->begin_transaction();
    $conn->execute_query('SELECT id FROM presentations WHERE id=? FOR UPDATE',[$presentation]);
    queueDocumentScan($conn,$presentation,$speaker,'notes',$file,null); $conn->commit();
};
$published=static fn()=>$conn->execute_query('SELECT storage_key FROM presentation_notes WHERE presentation_id=? AND speaker_id=?',[$presentation,$speaker])->fetch_row()[0]??null;
try {
    $queue($asset('First synthetic clean document'));
    scanExpect($published()===null,'Unscanned document was published');
    $job=claimDocumentScan($conn); scanExpect($job!==null,'No scan job claimed');
    finishDocumentScan($conn,$job,true); $first=$published(); scanExpect($first!==null,'Clean document not published');
    $queue($asset('Rejected replacement')); $job=claimDocumentScan($conn); finishDocumentScan($conn,$job,false);
    scanExpect($published()===$first,'Rejected replacement removed approved document');
    $queue($asset('Older pending replacement')); $old=claimDocumentScan($conn);
    $queue($asset('Newer pending replacement')); finishDocumentScan($conn,$old,true);
    scanExpect($published()===$first,'Old scan overwrote newer pending replacement');
    $job=claimDocumentScan($conn); cancelDocumentScan($conn,$presentation,$speaker,'notes'); finishDocumentScan($conn,$job,true);
    scanExpect($published()===$first,'Canceled scan was published');
    $queue($asset('Real ClamAV clean fixture')); scanExpect(processDocumentScan($conn),'Clean scan not processed');
    $second=$published(); scanExpect($second!==null && $second!==$first,'Real scanner failed to approve harmless data');
    // Standard inert antivirus test string, never an executable malware payload.
    $eicar='X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
    $queue($asset($eicar)); scanExpect(processDocumentScan($conn),'Antivirus fixture not processed');
    scanExpect($published()===$second,'Scanner published antivirus test signature');
    scanExpect($conn->execute_query('SELECT state FROM document_scan_jobs WHERE presentation_id=?',[$presentation])->fetch_row()[0]==='rejected','Detection not recorded');
    echo "Document quarantine, stale claims, cancellation, real clean scan and EICAR detection passed.\n";
} finally {
    $conn->execute_query('DELETE FROM engagements WHERE id=?',[$event]);
    $conn->execute_query('DELETE FROM organizations WHERE id=?',[$org]);
}
