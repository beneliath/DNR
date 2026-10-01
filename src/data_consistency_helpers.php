<?php

declare(strict_types=1);

/** Read-only anomaly counts. Historical event affiliations are deliberately valid. */
function applicationDataConsistencyCounts(mysqli $conn): array
{
    $checks=[
        'Primary affiliations missing'=>"SELECT COUNT(*) FROM contacts c WHERE c.organization_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM contact_organizations co WHERE co.contact_id=c.id AND co.organization_id=c.organization_id)",
        'Primary role titles differ'=>"SELECT COUNT(*) FROM contacts c JOIN contact_organizations co ON co.contact_id=c.id AND co.organization_id=c.organization_id WHERE co.role_title<>CASE c.contact_role WHEN 'pastor' THEN 'Pastor' WHEN 'admin' THEN 'Admin' WHEN 'other' THEN COALESCE(c.contact_role_other,'') ELSE '' END",
        'Active merged contacts'=>"SELECT COUNT(*) FROM contacts WHERE merged_into_id IS NOT NULL AND is_deleted=0",
        'Active merged organizations'=>"SELECT COUNT(*) FROM organizations WHERE merged_into_id IS NOT NULL AND is_deleted=0",
        'Contact merge redirect chains'=>"SELECT COUNT(*) FROM contacts c JOIN contacts t ON t.id=c.merged_into_id WHERE t.merged_into_id IS NOT NULL OR t.id=c.id",
        'Organization merge redirect chains'=>"SELECT COUNT(*) FROM organizations c JOIN organizations t ON t.id=c.merged_into_id WHERE t.merged_into_id IS NOT NULL OR t.id=c.id",
        'Financial revisions missing'=>"SELECT COUNT(*) FROM engagement_financial_reports f WHERE NOT EXISTS (SELECT 1 FROM engagement_financial_revisions r WHERE r.engagement_id=f.engagement_id)",
        'Inquiry task relationships differ'=>"SELECT COUNT(*) FROM booking_inquiries i JOIN follow_up_tasks t ON t.id=i.next_action_task_id WHERE i.stage<>'booked' AND (t.subject_type<>'inquiry' OR NOT(t.inquiry_id<=>i.id))",
        'Inquiry next-action summaries differ'=>"SELECT COUNT(*) FROM booking_inquiries i JOIN follow_up_tasks t ON t.id=i.next_action_task_id WHERE i.stage<>'booked' AND ((t.status='completed' AND (i.next_action IS NOT NULL OR i.next_action_due_date IS NOT NULL)) OR (t.status<>'completed' AND (NOT(i.next_action<=>t.title) OR NOT(i.next_action_due_date<=>t.due_date))))",
        'Expired network samples remaining'=>"SELECT COUNT(*) FROM network_performance_samples WHERE recorded_at<UTC_TIMESTAMP(6)-INTERVAL 30 DAY",
        'Expired merge snapshots remaining'=>"SELECT COUNT(*) FROM record_merge_journal WHERE expires_at<UTC_TIMESTAMP(6) AND snapshot_ciphertext IS NOT NULL",
    ];
    $result=[];
    foreach($checks as $label=>$sql) $result[$label]=(int)$conn->query($sql)->fetch_row()[0];
    return $result;
}

function refreshApplicationDataConsistency(mysqli $conn, bool $force=false): bool
{
    if(!$force && $conn->query("SELECT 1 FROM data_management_health WHERE name='consistency' AND checked_at>UTC_TIMESTAMP(6)-INTERVAL 1 HOUR")->fetch_row()) return false;
    $conn->begin_transaction();
    try {
        $state=json_encode(applicationDataConsistencyCounts($conn),JSON_THROW_ON_ERROR);
        $conn->execute_query("INSERT INTO data_management_health (name,state_json) VALUES ('consistency',?) ON DUPLICATE KEY UPDATE state_json=?,checked_at=UTC_TIMESTAMP(6)",[$state,$state]);
        $conn->commit();
        return true;
    } catch(Throwable $exception) { $conn->rollback(); throw $exception; }
}
