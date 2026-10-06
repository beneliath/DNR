<?php

declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TARGET')!=='disposable') { echo "Data management tests require a disposable database.\n"; exit; }
$src=getenv('DNR_TEST_SOURCE_DIR')?:__DIR__.'/../src';
require_once $src.'/config.php';
require_once $src.'/record_merge_helpers.php';
require_once $src.'/record_creation_helpers.php';
require_once $src.'/engagement_contact_helpers.php';
require_once $src.'/reimbursement_admin_helpers.php';
require_once $src.'/data_consistency_helpers.php';
function dataExpect(bool $ok,string $message):void { if(!$ok) throw new RuntimeException($message); }
function dataReject(callable $action):void { try{$action();}catch(InvalidArgumentException|mysqli_sql_exception $expected){return;} throw new RuntimeException('Expected rejection.'); }
$tag='data-'.bin2hex(random_bytes(5)); $orgs=$contacts=[]; $event=$actor=$center=0;
try {
    $conn->execute_query("INSERT INTO users (username,password,role,email,account_status) VALUES (?,?,'admin',?,'active')",[$tag,password_hash('SyntheticOnly123!',PASSWORD_DEFAULT),$tag.'@example.invalid']); $actor=(int)$conn->insert_id;
    for($i=0;$i<2;$i++) { $conn->execute_query('INSERT INTO organizations (organization_name) VALUES (?)',[$tag]); $orgs[]=(int)$conn->insert_id; }
    dataExpect($orgs[0]!==$orgs[1],'Distinct organizations may share names.');
    foreach($orgs as $i=>$org) { $conn->execute_query("INSERT INTO contacts (organization_id,contact_first_name,contact_last_name,contact_role,contact_email) VALUES (?,?,'Fixture','pastor',?)",[$org,$tag.$i,$tag.$i.'@example.invalid']); $contacts[]=(int)$conn->insert_id; }
    $conn->execute_query("INSERT INTO engagements (organization_id,event_title,event_start_date,event_end_date,event_type,confirmation_status) VALUES (?,'Data fixture','2026-10-01','2026-10-01','conference','under_review')",[$orgs[0]]); $event=(int)$conn->insert_id;
    syncEngagementContacts($conn,$event,[['contact_id'=>$contacts[0],'contact_role'=>'primary_host']],$actor);
    $assigned=$conn->execute_query('SELECT created_at FROM engagement_contacts WHERE engagement_id=?',[$event])->fetch_row()[0];
    $conn->execute_query('DELETE FROM contact_organizations WHERE contact_id=? AND organization_id=?',[$contacts[0],$orgs[0]]);
    $rows=fetchEngagementContacts($conn,$event);
    dataExpect(count($rows)===1 && (bool)$rows[0]['historical_affiliation'],'Affiliation removal must retain visible event history.');
    dataExpect(fetchActiveEngagementEmailContacts($conn,$event)===[],'Historical addresses must not become live email recipients');
    $conn->execute_query('UPDATE engagements SET organization_id=? WHERE id=?',[$orgs[1],$event]);
    dataExpect(count(fetchEngagementContacts($conn,$event))===1,'Organization change must retain event contact.');
    $conn->begin_transaction(); syncEngagementContacts($conn,$event,[['contact_id'=>$contacts[0],'contact_role'=>'primary_host']],$actor); $conn->commit();
    dataExpect($conn->execute_query('SELECT created_at FROM engagement_contacts WHERE engagement_id=?',[$event])->fetch_row()[0]===$assigned,'Unchanged roles must retain assignment date.');
    dataReject(fn()=>validateEngagementContactAssignments($conn,$orgs[1],[['contact_id'=>$contacts[0],'contact_role'=>'billing']],$event));
    $conn->begin_transaction(); syncEngagementContacts($conn,$event,[],$actor); $conn->commit();
    dataExpect(count(fetchEngagementContactHistory($conn,$event))===1,'Removed role needs immutable historical snapshot.');
    $conn->execute_query('INSERT INTO engagement_financial_reports (engagement_id,giving_income_received,lodging_received,travel_received,closed_by,updated_by) VALUES (?,100.01,20.02,3.03,?,?)',[$event,$actor,$actor]);
    dataReject(fn()=>$conn->execute_query('UPDATE engagement_financial_reports SET giving_income_received=101.01 WHERE engagement_id=?',[$event]));
    $conn->query("SET @dnr_financial_correction_reason='Corrected receipt'");
    $conn->execute_query('UPDATE engagement_financial_reports SET giving_income_received=101.01 WHERE engagement_id=?',[$event]); $conn->query('SET @dnr_financial_correction_reason=NULL');
    dataExpect((int)$conn->execute_query('SELECT COUNT(*) FROM engagement_financial_revisions WHERE engagement_id=?',[$event])->fetch_row()[0]===2,'Financial correction must preserve both amounts.');
    dataExpect($conn->execute_query('SELECT book_table_received FROM engagement_financial_reports WHERE engagement_id=?',[$event])->fetch_row()[0]===null,'Older closeouts must retain an unrecorded book-table amount.');
    dataReject(fn()=>$conn->execute_query('UPDATE engagement_financial_reports SET book_table_received=5.67 WHERE engagement_id=?',[$event]));
    $conn->query("SET @dnr_financial_correction_reason='Record book-table receipt'");
    dataReject(fn()=>$conn->execute_query('UPDATE engagement_financial_reports SET book_table_received=-0.01 WHERE engagement_id=?',[$event]));
    $conn->execute_query('UPDATE engagement_financial_reports SET book_table_received=5.67 WHERE engagement_id=?',[$event]);
    $conn->query('SET @dnr_financial_correction_reason=NULL');
    $bookRevisions=fetchEngagementFinancialRevisions($conn,$event);
    dataExpect(count($bookRevisions)===3 && $bookRevisions[0]['book_table_received']==='5.67' && $bookRevisions[1]['book_table_received']===null,'Book-table-only corrections must retain unknown history and the exact new amount.');
    dataReject(fn()=>$conn->execute_query('DELETE FROM engagements WHERE id=?',[$event]));
    dataReject(fn()=>$conn->execute_query('DELETE FROM organizations WHERE id=?',[$orgs[1]]));
    $token=bin2hex(random_bytes(16)); $conn->begin_transaction(); dataExpect(beginRecordCreation($conn,$actor,$token,'contact')===null,'First save claim'); completeRecordCreation($conn,$actor,$token,$contacts[0]); $conn->commit();
    $conn->begin_transaction(); dataExpect(beginRecordCreation($conn,$actor,$token,'contact')===$contacts[0],'Repeated save must return original ID'); $conn->commit();
    dataReject(fn()=>completedRecordCreation($conn,$actor,$token,'organization'));
    $rolled=bin2hex(random_bytes(16)); $conn->begin_transaction(); beginRecordCreation($conn,$actor,$rolled,'contact'); $conn->rollback(); dataExpect(completedRecordCreation($conn,$actor,$rolled,'contact')===null,'Rolled-back save must not leave a receipt');
    $center=changeReimbursementCostCenter($conn,'save',null,['coa_number'=>'T'.substr($tag,-8),'description'=>'Test'],$actor);
    changeReimbursementCostCenter($conn,'save',$center,['coa_number'=>'T'.substr($tag,-8),'description'=>'New','version'=>'1'],$actor);
    dataReject(fn()=>changeReimbursementCostCenter($conn,'save',$center,['coa_number'=>'T'.substr($tag,-8),'description'=>'Stale','version'=>'1'],$actor));
    dataExpect($conn->execute_query('SELECT description FROM reimbursement_cost_centers WHERE id=?',[$center])->fetch_row()[0]==='New','Stale save overwrote newer values');
    dataReject(fn()=>changeReimbursementCostCenter($conn,'save',$center,['coa_number'=>'T'.substr($tag,-8),'description'=>'Unlogged change','version'=>'2'],PHP_INT_MAX));
    $unchanged=$conn->execute_query('SELECT description,version FROM reimbursement_cost_centers WHERE id=?',[$center])->fetch_assoc();
    dataExpect($unchanged['description']==='New' && (int)$unchanged['version']===2,'Audit failure must roll back the business change and version');
    // Merge a contact without conflicting affiliations, then reverse it.
    $before=$conn->execute_query('SELECT updated_at FROM contacts WHERE id=?',[$contacts[0]])->fetch_row()[0]; $target=$conn->execute_query('SELECT updated_at FROM contacts WHERE id=?',[$contacts[1]])->fetch_row()[0];
    mergeRecords($conn,'contact',$contacts[0],$contacts[1],$before,$target,[],$actor);
    $journal=(int)$conn->query('SELECT MAX(id) FROM record_merge_journal')->fetch_row()[0];
    undoRecordMerge($conn,$journal,$actor);
    dataExpect((int)$conn->execute_query('SELECT is_deleted FROM contacts WHERE id=?',[$contacts[0]])->fetch_row()[0]===0,'Undo must restore source');
    $before=$conn->execute_query('SELECT updated_at FROM contacts WHERE id=?',[$contacts[0]])->fetch_row()[0]; $target=$conn->execute_query('SELECT updated_at FROM contacts WHERE id=?',[$contacts[1]])->fetch_row()[0];
    mergeRecords($conn,'contact',$contacts[0],$contacts[1],$before,$target,[],$actor); $journal=(int)$conn->query('SELECT MAX(id) FROM record_merge_journal')->fetch_row()[0];
    $conn->execute_query("UPDATE contacts SET contact_notes='Later edit' WHERE id=?",[$contacts[1]]); dataReject(fn()=>undoRecordMerge($conn,$journal,$actor));
    // Organization reversal must move the original engagement back without
    // deleting or rewriting its retained financial report and revisions.
    $sourceVersion=$conn->execute_query('SELECT updated_at FROM organizations WHERE id=?',[$orgs[1]])->fetch_row()[0];
    $targetVersion=$conn->execute_query('SELECT updated_at FROM organizations WHERE id=?',[$orgs[0]])->fetch_row()[0];
    mergeRecords($conn,'organization',$orgs[1],$orgs[0],$sourceVersion,$targetVersion,[],$actor);
    $journal=(int)$conn->query('SELECT MAX(id) FROM record_merge_journal')->fetch_row()[0];
    undoRecordMerge($conn,$journal,$actor);
    dataExpect((int)$conn->execute_query('SELECT organization_id FROM engagements WHERE id=?',[$event])->fetch_row()[0]===$orgs[1]
        && (int)$conn->execute_query('SELECT COUNT(*) FROM engagement_financial_revisions WHERE engagement_id=?',[$event])->fetch_row()[0]===3,
        'Organization undo must preserve the engagement and its financial history');
    dataExpect(refreshApplicationDataConsistency($conn,true),'Consistency report not recorded');
    echo "Data management integration passed: history, financial revisions and retention, retry receipts, optimistic locking, merge undo and later-edit rejection.\n";
} finally {
    if($event) { $conn->execute_query('DELETE FROM engagement_financial_revisions WHERE engagement_id=?',[$event]); $conn->execute_query('DELETE FROM engagement_financial_reports WHERE engagement_id=?',[$event]); $conn->execute_query('DELETE FROM engagements WHERE id=?',[$event]); }
    foreach($contacts as $id) $conn->execute_query('UPDATE contacts SET merged_into_id=NULL WHERE id=?',[$id]);
    foreach($contacts as $id) $conn->execute_query('DELETE FROM contacts WHERE id=?',[$id]);
    foreach($orgs as $id) $conn->execute_query('DELETE FROM organizations WHERE id=?',[$id]);
    if($center) $conn->execute_query('DELETE FROM reimbursement_cost_centers WHERE id=?',[$center]);
    if($actor) { $conn->execute_query('DELETE FROM record_merge_journal WHERE actor_user_id=?',[$actor]); $conn->execute_query('DELETE FROM users WHERE id=?',[$actor]); }
}
