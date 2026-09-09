<?php
/** Add illustrative report/chart/map states to the fictional manual fixtures. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('DNR_MANUAL_FIXTURE')!=='disposable')throw new RuntimeException('Disposable preview only.');
require '/var/www/html/bootstrap.php';
require_once '/var/www/html/map_helpers.php';
$f=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
$org=$conn->execute_query('SELECT organization_name FROM organizations WHERE id=?',[$f['org']])->fetch_assoc();
if(($org['organization_name']??'')!=='Cedar Grove Community - Manual Example')throw new RuntimeException('Expected fictional organization.');
$user=$conn->execute_query('SELECT id FROM users WHERE username=?',[getenv('DNR_MANUAL_USER')])->fetch_assoc();$uid=(int)$user['id'];
$event=$conn->execute_query('SELECT * FROM engagements WHERE id=?',[$f['event']])->fetch_assoc();
$addr=engagementMapAddress($event);$hash=engagementMapAddressHash($addr);
$conn->execute_query('INSERT INTO engagement_map_pins(engagement_id,address_hash,latitude,longitude,confirmed_by) VALUES (?,?,41.88,-87.63,?) ON DUPLICATE KEY UPDATE latitude=41.88,longitude=-87.63',[$f['event'],$hash,$uid]);
$conn->execute_query('UPDATE contacts SET contact_birthday=? WHERE id=?',['09/18',$f['contact']]);
$links=$conn->execute_query('SELECT id,presentation_id FROM short_links WHERE engagement_id=? ORDER BY id',[$f['event']])->fetch_all(MYSQLI_ASSOC);
foreach($links as $j=>$link)for($d=1;$d<=8;$d++)$conn->execute_query('INSERT INTO short_link_stats(link_id,visit_hour,browser,os,country,referrer,visits) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE visits=VALUES(visits)',[(int)$link['id'],sprintf('2026-09-%02d 14:00:00',$d),$j%2?'Safari':'Chrome',$j%2?'iOS':'Windows',$j%3?'US':'CA',$j%2?'example.org':'',($d*3+$j)%21+1]);
$past=$conn->execute_query("SELECT id FROM engagements WHERE organization_id=? AND event_title='Summer Learning Day - Example'",[$f['org']])->fetch_assoc();
if(!$past){$conn->execute_query("INSERT INTO engagements(organization_id,event_title,event_start_date,event_end_date,event_type,confirmation_status,lifecycle_status) VALUES (?,'Summer Learning Day - Example','2026-08-15','2026-08-15','conference','under_review','completed')",[$f['org']]);$past=['id'=>(int)$conn->insert_id];$conn->execute_query('INSERT INTO engagement_financial_reports(engagement_id,giving_income_received,lodging_received,travel_received,notes,closed_by,updated_by) VALUES (?,1500,180,250,?,?,?)',[$past['id'],'Fictional completed report for the user manual.',$uid,$uid]);}
$account=$conn->execute_query("SELECT id FROM users WHERE username='casey.manual'")->fetch_assoc();
if(!$account){$conn->execute_query("INSERT INTO users(username,password,first_name,last_name,email,email_verified_at,role) VALUES ('casey.manual',?,'Casey','Taylor','casey.taylor@example.org',UTC_TIMESTAMP(),'editor')",[password_hash(bin2hex(random_bytes(30)),PASSWORD_DEFAULT)]);$account=['id'=>(int)$conn->insert_id];}
echo json_encode(['past_event'=>(int)$past['id'],'target_user'=>(int)$account['id'],'link'=>(int)$links[0]['id'],'presentation'=>(int)$links[0]['presentation_id']],JSON_PRETTY_PRINT),PHP_EOL;
