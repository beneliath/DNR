<?php
/** Read-only evidence that the inspiration account's business records stayed intact. */
declare(strict_types=1);
require '/var/www/html/config.php';
$conn->query('SET TRANSACTION READ ONLY'); $conn->begin_transaction();
try {
    $profile=$conn->query('SELECT account_key,name FROM account_profile WHERE id=1')->fetch_assoc();
    if (($profile['account_key'] ?? '')!=='shalom-in-messiah') throw new RuntimeException('Wrong inspiration account.');
    $result=['account'=>$profile,'tables'=>[]];
    foreach(['organizations','contacts','contact_organizations','speakers','engagements','engagement_contacts','engagement_contact_history',
        'presentations','presentation_notes','presentation_slidedecks','follow_up_tasks','standard_event_tasks','booking_inquiries',
        'booking_inquiry_stage_history','booking_inquiry_chron_entries','engagement_chron_entries','organization_chron_entries','contact_chron_entries',
        'engagement_financial_reports','engagement_financial_drafts','engagement_financial_revisions','reimbursement_setup','reimbursement_cost_centers',
        'reimbursement_expenses','reimbursement_requests','reimbursement_request_items','reimbursement_receipts','reimbursement_submissions','stored_files'] as $table) {
        $keys=$conn->query("SHOW KEYS FROM `$table` WHERE Key_name='PRIMARY'")->fetch_all(MYSQLI_ASSOC);
        $order=implode(',',array_map(static fn($k)=>'`'.$k['Column_name'].'`',$keys));
        $rows=$conn->query('SELECT * FROM `'.$table.'`'.($order?' ORDER BY '.$order:''));
        $hash=hash_init('sha256');$n=0;
        while($row=$rows->fetch_assoc()){hash_update($hash,serialize($row));$n++;}
        $result['tables'][$table]=['count'=>$n,'sha256'=>hash_final($hash)];
    }
    echo json_encode($result,JSON_PRETTY_PRINT),"\n";
}finally{$conn->rollback();}
