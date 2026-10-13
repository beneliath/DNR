<?php
/** Operator-only examples that distinguish My Active Tasks from All Active Tasks. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden.'); }
$options = getopt('', ['apply', 'as-of:']);
$root = is_file(__DIR__ . '/../src/bootstrap.php') ? __DIR__ . '/../src' : '/var/www/html';
require_once $root . '/bootstrap.php';
require_once $root . '/follow_up_task_helpers.php';
require_once $root . '/test_account_demo_helpers.php';
if (!accountsEnabled() || accountIsPrimary() || currentAccountKey() !== 'test-account'
    || currentAccountProfile()['account_key'] !== 'test-account') throw new RuntimeException('Test Account member only.');
$anchor = $options['as-of'] ?? applicationBusinessDate();
if (!validIsoDate($anchor)) throw new InvalidArgumentException('Choose a valid date.');
$today = new DateTimeImmutable($anchor);
$users = [];
foreach (['TestEditor'=>'editor', 'TestAdmin'=>'admin', 'TestReviewer'=>'reviewer'] as $name=>$role) {
    $user = $conn->execute_query('SELECT * FROM users WHERE username=? AND role=? AND account_status=\'active\' AND is_superadmin=0 AND platform_identity_id IS NULL', [$name,$role])->fetch_assoc();
    if (!$user || !demoPasswordOnlyAuthenticationAllowed($user)) throw new RuntimeException('The pinned demo login must remain eligible: ' . $name);
    $users[$name] = (int)$user['id'];
}
$examples = [
    ['TestAdmin', 'Confirm the shared planning call', 'open', 'high', 0],
    ['TestReviewer', 'Review the venue accessibility checklist', 'in_progress', 'normal', 0],
    ['TestAdmin', 'Check the host welcome materials', 'open', 'normal', 1],
    ['TestReviewer', 'Review the draft session handouts', 'open', 'high', 1],
    ['TestAdmin', 'Coordinate the volunteer arrival schedule', 'waiting', 'normal', 3],
    ['TestReviewer', 'Review the resource-table preparation notes', 'open', 'low', 3],
];
$plan = array_map(static fn($item)=>['owner'=>$item[0], 'title'=>'[Demo team task] '.$item[1],
    'status'=>$item[2], 'priority'=>$item[3], 'due_date'=>$today->modify('+'.$item[4].' days')->format('Y-m-d')], $examples);
if (!isset($options['apply'])) {
    echo json_encode(['account'=>'test-account','as_of'=>$anchor,'tasks'=>$plan],JSON_PRETTY_PRINT),"\n";
    exit;
}
$conn->begin_transaction();
try {
    $usersBefore = hash('sha256',serialize($conn->query('SELECT * FROM users ORDER BY id FOR UPDATE')->fetch_all(MYSQLI_ASSOC)));
    $profileBefore = serialize($conn->query('SELECT * FROM account_profile WHERE id=1')->fetch_assoc());
    $ids=[];
    foreach ($plan as $task) {
        $existing = $conn->execute_query('SELECT id,assigned_to FROM follow_up_tasks WHERE title=?',[$task['title']])->fetch_assoc();
        if ($existing) {
            if ((int)$existing['assigned_to'] !== $users[$task['owner']]) throw new RuntimeException('An existing sample has a different owner.');
            continue;
        }
        $ids[] = insertFollowUpTask($conn,array_merge($task,[
            'details'=>'Fictional Test Account team-work example. This task belongs to another demo user so All Active Tasks differs from TestEditor\'s My Active Tasks.',
            'waiting_on'=>$task['status']==='waiting'?'Fictional host volunteer coordinator':null,
            'subject_type'=>'general','engagement_id'=>null,'organization_id'=>null,'contact_id'=>null,'inquiry_id'=>null,
            'assigned_to'=>$users[$task['owner']]]),$users['TestEditor']);
    }
    $closeout = ensureTestAccountFinancialCloseout($conn,$users['TestEditor'],$anchor);
    if ($usersBefore !== hash('sha256',serialize($conn->query('SELECT * FROM users ORDER BY id')->fetch_all(MYSQLI_ASSOC)))
        || $profileBefore !== serialize($conn->query('SELECT * FROM account_profile WHERE id=1')->fetch_assoc())) throw new RuntimeException('Users or account settings changed.');
    $conn->commit();
    echo json_encode(['account'=>'test-account','as_of'=>$anchor,'added_task_ids'=>$ids,'tasks'=>$plan,'financial_closeout'=>$closeout,'users_and_settings_unchanged'=>true],JSON_PRETTY_PRINT),"\n";
} catch (Throwable $error) {$conn->rollback(); throw $error;}
