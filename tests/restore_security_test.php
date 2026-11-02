<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/email_helpers.php';
require_once __DIR__ . '/../src/audience_ses_worker.php';

$root = sys_get_temp_dir() . '/dnr-restore-controls-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
putenv('DNR_FILE_STORAGE_PATH=' . $root);
putenv('DNR_2FA_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
$checks = 0;
$expect = static function (bool $ok, string $why) use (&$checks): void {
    if (!$ok) throw new RuntimeException($why);
    $checks++;
};
$reject = static function (callable $operation, string $why) use ($expect): void {
    try { $operation(); } catch (RuntimeException|InvalidArgumentException $error) { $expect(true, $why); return; }
    throw new RuntimeException($why);
};
$defer = static function (callable $operation, string $why) use ($expect): void {
    try { $operation(); } catch (MailBudgetDeferredException $error) { $expect(true, $why); return; }
    throw new RuntimeException($why);
};
try {
    $expect(accountRestoreGeneration() === '' && !accountOutboundMailHeld(), 'Existing deployments start without a recovery hold.');
    $identity = ['id' => 7, 'auth_version' => 5];
    $grant = platformIssueIdentityGrant('account-alpha', $identity);
    $switch = platformIssueSwitchIntent($identity, 'account-alpha');
    $elevation = platformIssueElevationGrant('account-alpha', $identity, time() + 300);
    platformVerifyIdentityGrant($grant, 'account-alpha', $identity);
    $first = prepareAccountRestoreSecurity();
    $expect(strlen($first['generation']) === 64 && $first['mail_hold'], 'Restore publishes a durable random generation and mail hold.');
    $expect((fileperms(accountRestoreSecurityPath()) & 0777) === 0600, 'Restore controls remain private.');
    $reject(static fn() => platformVerifyIdentityGrant($grant, 'account-alpha', $identity), 'An old platform session cannot regain authority after restore.');
    $reject(static fn() => platformVerifySwitchIntent($switch, $identity, 'account-alpha'), 'An old browser switch proof is revoked.');
    $reject(static fn() => platformVerifyElevationGrant($elevation['token'], 'account-alpha', $identity), 'An old platform elevation proof is revoked.');
    $defer(static fn() => new SmtpSession(), 'The SMTP gate runs before connecting or accepting credentials.');
    putenv('DNR_MAIL_TRANSPORT=log');
    $defer(static fn() => deliverApplicationEmail('synthetic@example.test', 'Fixture', 'Fixture'), 'Development delivery cannot bypass the recovery hold.');
    $defer(static fn() => audienceSesDeliver(new mysqli(), []), 'The SES hold is checked before database or provider access.');
    $other = $root . '-other'; mkdir($other, 0700); putenv('DNR_FILE_STORAGE_PATH=' . $other);
    $expect(accountRestoreGeneration() === '' && !accountOutboundMailHeld(), 'A separate account volume does not inherit another account recovery state.');
    putenv('DNR_FILE_STORAGE_PATH=' . $root);
    $expect(accountRestoreGeneration() === $first['generation'] && accountOutboundMailHeld(), 'The original account hold remains unchanged.');
    rmdir($other);
    $reject(static fn() => releaseAccountRestoreMailHold($first['generation'], 'UNREVIEWED'), 'Mail cannot resume without explicit reconciliation.');
    $second = prepareAccountRestoreSecurity();
    $expect($second['generation'] !== $first['generation'], 'Repeated restores never rewind the generation.');
    $reject(static fn() => releaseAccountRestoreMailHold($first['generation'], 'RECONCILED'), 'A stale operator confirmation cannot release a newer restore.');
    releaseAccountRestoreMailHold($second['generation'], 'RECONCILED');
    $expect(!accountOutboundMailHeld() && accountRestoreGeneration() === $second['generation'], 'Reconciliation releases only the matching hold without revalidating old sessions.');
    file_put_contents(accountRestoreSecurityPath(), '{invalid');
    $reject(static fn() => accountOutboundMailHeld(), 'Malformed control state fails closed.');
    unlink(accountRestoreSecurityPath());
    symlink($root . '/.restore-security.lock', accountRestoreSecurityPath());
    $reject(static fn() => accountRestoreGeneration(), 'A symlink cannot replace the account control record.');
    unlink(accountRestoreSecurityPath());
    putenv('DNR_FILE_STORAGE_PATH=' . $root . '/missing');
    $reject(static fn() => accountOutboundMailHeld(), 'A missing configured volume fails closed.');
    echo "Restore session generation, account scope, and outbound hold: $checks checks passed.\n";
} finally {
    putenv('DNR_FILE_STORAGE_PATH');
    foreach (glob($root . '/.*') ?: [] as $file) if (is_file($file) || is_link($file)) unlink($file);
    rmdir($root);
}
