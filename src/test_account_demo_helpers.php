<?php
declare(strict_types=1);

/**
 * Caller owns the transaction; never reopen or alter a finalized report.
 * @return array{id:int,title:string,created:bool}
 */
function ensureTestAccountFinancialCloseout(mysqli $conn, int $userId, string $asOf): array {
    if (PHP_SAPI !== 'cli' || !accountsEnabled() || accountIsPrimary() || currentAccountKey() !== 'test-account'
        || currentAccountProfile()['account_key'] !== 'test-account') throw new RuntimeException('Test Account member only.');
    $user = $conn->execute_query('SELECT * FROM users WHERE id=? FOR UPDATE', [$userId])->fetch_assoc();
    if (!$user || $user['username'] !== 'TestEditor' || !demoPasswordOnlyAuthenticationAllowed($user)) throw new RuntimeException('Pinned TestEditor required.');
    if (!validIsoDate($asOf)) throw new InvalidArgumentException('Choose a valid date.');
    $root = __DIR__;
    require_once $root.'/dashboard_helpers.php';
    $existing = fetchDashboardFinancialCloseouts($conn, $asOf);
    if ($existing !== []) return ['id'=>(int)$existing[0]['id'],'title'=>$existing[0]['event_title'],'created'=>false];
    $host = $conn->query('SELECT id FROM organizations WHERE is_deleted=0 ORDER BY id LIMIT 1')->fetch_assoc();
    if (!$host) throw new RuntimeException('Restore the fictional hosts before adding a closeout.');
    $ended = (new DateTimeImmutable($asOf, new DateTimeZone('America/Chicago')))->modify('-1 day')->format('Y-m-d');
    $title = '[Demo closeout] Reconcile host receipts';
    $conn->execute_query("INSERT INTO engagements (organization_id,event_title,event_description,event_start_date,event_end_date,
        event_type,confirmation_status,lifecycle_status,caller_user_id,book_table)
        VALUES (?,?,?,?,?,'study or teaching','confirmed','active',?,1)",
        [(int)$host['id'],$title,'Fictional completed teaching event. Review the draft receipts and finalize the financial report to practice dashboard closeout.',$ended,$ended,$userId]);
    $id = (int)$conn->insert_id;
    $conn->execute_query('INSERT INTO engagement_financial_drafts (engagement_id,giving_income_received,lodging_received,travel_received,book_table_received,notes,updated_by)
        VALUES (?,450.00,0.00,125.00,85.00,?,?)', [$id,'Fictional training figures; review all four amounts before finalizing.',$userId]);
    if (!recordAuditEvent($conn, ['event_category'=>'data','event_type'=>'demo_closeout_created','actor_user_id'=>null,
        'target_user_id'=>$userId,'entity_type'=>'engagement','entity_id'=>$id,'entity_label'=>$title,
        'details'=>'Operator added a fictional ended event with an unfinalized receipt draft for TestEditor.'])) throw new RuntimeException('Closeout audit failed.');
    if (!in_array($id, array_map(static fn($row)=>(int)$row['id'], fetchDashboardFinancialCloseouts($conn,$asOf)),true)) throw new RuntimeException('Closeout is not visible on the dashboard.');
    return ['id'=>$id,'title'=>$title,'created'=>true];
}

