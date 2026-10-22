<?php
declare(strict_types=1);

/** Fictional UI examples: raw bearer tokens are discarded and never published. */
function seedTestAccountCalendarSamples(mysqli $conn, int $userId, string $asOf): int {
    if (getenv('DNR_ACCOUNT_KEY') !== 'test-account' || getenv('DNR_ACCOUNT_MODE') !== 'member'
        || currentAccountProfile()['account_key'] !== 'test-account') throw new RuntimeException('Test Account only.');
    $user = $conn->execute_query('SELECT username,role,is_superadmin,platform_identity_id FROM users WHERE id=?', [$userId])->fetch_assoc();
    if (!$user || $user['username'] !== 'TestAdmin' || $user['role'] !== 'admin'
        || $user['is_superadmin'] || $user['platform_identity_id']) throw new RuntimeException('TestAdmin only.');
    $today = new DateTimeImmutable($asOf, new DateTimeZone('America/Chicago'));
    $created = 0;
    foreach ([['Demo phone calendar', 1, 1, 'my', 1, -14, -1, null],
        ['Demo planning calendar', 1, 0, 'all', 0, -7, null, null],
        ['Demo retired tablet', 1, 1, 'none', 0, -30, -21, -14]] as [$label,$events,$presentations,$work,$birthdays,$age,$used,$revoked]) {
        if ($conn->execute_query('SELECT id FROM calendar_subscriptions WHERE user_id=? AND label=?', [$userId,$label])->num_rows) continue;
        $date = static fn(int $days): string => $today->modify(sprintf('%+d days', $days))->setTime(12,0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $conn->execute_query('INSERT INTO calendar_subscriptions (user_id,label,token_hash,include_events,include_presentations,work_scope,include_birthdays,created_at,last_used_at,revoked_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$userId,$label,hash('sha256',random_bytes(32),true),$events,$presentations,$work,$birthdays,$date($age),$used === null ? null : $date($used),$revoked === null ? null : $date($revoked)]);
        $created++;
    }
    return $created;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    require '/var/www/html/config.php'; require_once '/var/www/html/functions.php';
    $user = $conn->execute_query('SELECT id FROM users WHERE username=?', ['TestAdmin'])->fetch_assoc();
    $conn->begin_transaction();
    try {
        $count=seedTestAccountCalendarSamples($conn, (int) ($user['id'] ?? 0), (new DateTimeImmutable('now',new DateTimeZone('America/Chicago')))->format('Y-m-d'));
        $conn->commit(); echo json_encode(['account'=>'test-account','calendar_samples_added'=>$count]),"\n";
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
}
