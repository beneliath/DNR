<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TARGET') !== 'disposable') exit(64);
require_once '/var/www/html/bootstrap.php';
require_once '/var/www/html/presentation_helpers.php';
require_once '/var/www/html/presentation_slidedeck_helpers.php';
$speaker = (int) $conn->query('SELECT id FROM speakers ORDER BY id LIMIT 1')->fetch_row()[0];
$conn->execute_query('INSERT INTO organizations (organization_name) VALUES (?)', ['Download capacity fixture']);
$organization = (int) $conn->insert_id;
$conn->execute_query("INSERT INTO engagements (organization_id,event_title,event_start_date,event_end_date,event_type,confirmation_status)
    VALUES (?,'Capacity fixture','2026-10-01','2026-10-01','conference','under_review')", [$organization]);
$event = (int) $conn->insert_id;
$conn->execute_query("INSERT INTO presentations (engagement_id,speaker_id,topic_title) VALUES (?,?,'Capacity fixture')", [$event, $speaker]);
$presentation = (int) $conn->insert_id;
$data = str_repeat('DNR-disposable-load-test-', 3 * 1024 * 1024);
applyPresentationSlidedeckChange($conn, $presentation, $event, ['action'=>'replace', 'asset'=>[
    'data'=>$data,'filename'=>'capacity.pptx','size'=>strlen($data),'mime_type'=>PRESENTATION_SLIDEDECK_MIMES['pptx'],'sha256'=>hash('sha256',$data,true)]]);
$code = bin2hex(random_bytes(8));
$conn->execute_query("INSERT INTO short_links (code,engagement_id,presentation_id,speaker_id,link_type,target_url) VALUES (?,?,?,?,'slidedeck',NULL)", [$code, $event, $presentation, $speaker]);
echo json_encode(['path'=>'/surls/'.$code.'/ppt-slidedeck','bytes'=>strlen($data)], JSON_THROW_ON_ERROR);
