<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Test Account closeout integration skipped (requires disposable database).\n";
    exit(0);
}
$root = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__.'/../src';
require_once $root.'/config.php';
require_once $root.'/functions.php';
require_once $root.'/test_account_demo_helpers.php';
require_once $root.'/dashboard_helpers.php';
$environment = [];
foreach (['DNR_ACCOUNTS_ENABLED','DNR_ACCOUNT_KEY','DNR_ACCOUNT_MODE'] as $key) $environment[$key] = getenv($key);
$conn->begin_transaction();
try {
    $conn->execute_query("INSERT INTO users (username,password,role,account_status,is_superadmin,platform_identity_id,two_factor_enabled)
        VALUES ('TestEditor',?,'editor','active',0,NULL,0)", [password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT)]);
    $uid = (int)$conn->insert_id;
    $conn->execute_query("UPDATE account_profile SET account_key='test-account',name='Test Account',settings=? WHERE id=1",
        [json_encode(['demo_password_only_user_ids'=>['TestEditor'=>$uid]])]);
    putenv('DNR_ACCOUNTS_ENABLED=1'); putenv('DNR_ACCOUNT_KEY=test-account'); putenv('DNR_ACCOUNT_MODE=member');
    $conn->execute_query('INSERT INTO organizations (organization_name) VALUES (?)', ['Fictional closeout host '.bin2hex(random_bytes(4))]);
    $conn->query('UPDATE engagements SET is_deleted=1');
    $usersBefore = serialize($conn->query('SELECT * FROM users ORDER BY id')->fetch_all(MYSQLI_ASSOC));
    $profileBefore = serialize($conn->query('SELECT * FROM account_profile WHERE id=1')->fetch_assoc());
    $first = ensureTestAccountFinancialCloseout($conn,$uid,'2026-10-13');
    $event = $conn->execute_query('SELECT * FROM engagements WHERE id=?',[$first['id']])->fetch_assoc();
    $draft = $conn->execute_query('SELECT * FROM engagement_financial_drafts WHERE engagement_id=?',[$first['id']])->fetch_assoc();
    if (!$first['created'] || $event['event_end_date'] !== '2026-10-12' || (int)$event['caller_user_id'] !== $uid
        || $event['lifecycle_status'] !== 'active' || !$draft || (int)$draft['updated_by'] !== $uid) throw new RuntimeException('Closeout example is not ready for TestEditor.');
    if ((int)fetchDashboardFinancialCloseouts($conn,'2026-10-13')[0]['id'] !== $first['id']) throw new RuntimeException('Example is absent from the dashboard.');
    $second = ensureTestAccountFinancialCloseout($conn,$uid,'2026-10-13');
    if ($second['created'] || $second['id'] !== $first['id']) throw new RuntimeException('Eligible example was duplicated.');
    $conn->execute_query('INSERT INTO engagement_financial_reports (engagement_id,giving_income_received,lodging_received,travel_received,book_table_received,closed_by,updated_by,closed_at)
        VALUES (?,450.00,0.00,125.00,85.00,?,?,UTC_TIMESTAMP())',[$first['id'],$uid,$uid]);
    $reportBefore = serialize($conn->execute_query('SELECT * FROM engagement_financial_reports WHERE engagement_id=?',[$first['id']])->fetch_assoc());
    $third = ensureTestAccountFinancialCloseout($conn,$uid,'2026-10-13');
    if (!$third['created'] || $third['id'] === $first['id'] || $reportBefore !== serialize($conn->execute_query('SELECT * FROM engagement_financial_reports WHERE engagement_id=?',[$first['id']])->fetch_assoc())) throw new RuntimeException('A finalized report was reopened or altered.');
    if ($usersBefore !== serialize($conn->query('SELECT * FROM users ORDER BY id')->fetch_all(MYSQLI_ASSOC))
        || $profileBefore !== serialize($conn->query('SELECT * FROM account_profile WHERE id=1')->fetch_assoc())) throw new RuntimeException('Demo users or settings changed.');
    putenv('DNR_ACCOUNT_KEY=shalom-in-messiah');
    try { ensureTestAccountFinancialCloseout($conn,$uid,'2026-10-13'); throw new LogicException('Other Account accepted.'); }
    catch (RuntimeException $error) { if ($error->getMessage() !== 'Test Account member only.') throw $error; }
    echo "PASS: dashboard-ready TestEditor closeout, idempotency, finalized history and authentication preserved, other Accounts rejected.\n";
} finally {
    $conn->rollback();
    foreach ($environment as $key=>$value) putenv($value === false ? $key : $key.'='.$value);
}
