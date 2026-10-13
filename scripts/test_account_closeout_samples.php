<?php
/** Ensure fictional Test Account data always offers a dashboard closeout. */
declare(strict_types=1);

function runTestAccountCloseoutSamples(array $options): void {
    if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only.');
    if (getenv('DNR_ACCOUNT_KEY') !== 'test-account' || getenv('DNR_ACCOUNT_MODE') !== 'member') throw new RuntimeException('Test Account member only.');
    $root = is_file(__DIR__.'/../src/bootstrap.php') ? __DIR__.'/../src' : '/var/www/html';
    require_once $root.'/bootstrap.php';
    require_once $root.'/test_account_demo_helpers.php';
    $conn = applicationDatabaseConnection();
    $asOf = $options['as-of'] ?? applicationBusinessDate();
    $user = $conn->execute_query("SELECT * FROM users WHERE username='TestEditor'")->fetch_assoc();
    if (!$user || !demoPasswordOnlyAuthenticationAllowed($user)) throw new RuntimeException('Pinned TestEditor required.');
    if (!isset($options['apply'])) {
        echo json_encode(['account'=>'test-account','as_of'=>$asOf,'action'=>'Ensure at least one ended, unfinalized event appears in dashboard Financial Closeouts.']),"\n";
        return;
    }
    $conn->begin_transaction();
    try {
        $usersBefore = hash('sha256',serialize($conn->query('SELECT * FROM users ORDER BY id FOR UPDATE')->fetch_all(MYSQLI_ASSOC)));
        $profileBefore = serialize($conn->query('SELECT * FROM account_profile WHERE id=1')->fetch_assoc());
        $closeout = ensureTestAccountFinancialCloseout($conn,(int)$user['id'],$asOf);
        if ($usersBefore !== hash('sha256',serialize($conn->query('SELECT * FROM users ORDER BY id')->fetch_all(MYSQLI_ASSOC)))
            || $profileBefore !== serialize($conn->query('SELECT * FROM account_profile WHERE id=1')->fetch_assoc())) throw new RuntimeException('Users or settings changed.');
        $conn->commit();
        echo json_encode(['account'=>'test-account','owner'=>'TestEditor','as_of'=>$asOf,'closeout'=>$closeout,'users_and_settings_unchanged'=>true]),"\n";
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) runTestAccountCloseoutSamples(getopt('', ['apply','as-of:']));
