<?php
declare(strict_types=1);

require_once __DIR__ . '/persistent_file_helpers.php';

/** This control file is deliberately outside the database and registered-file backups. */
function accountRestoreSecurityPath(): string
{
    return rtrim((string) (getenv('DNR_FILE_STORAGE_PATH') ?: '/var/lib/dnr/files'), '/') . '/.restore-security.json';
}

/** @return array{version: int, generation: string, mail_hold: bool, prepared_at: string} */
function accountRestoreSecurityState(): array
{
    $path = accountRestoreSecurityPath();
    clearstatcache(true, $path);
    // Configured deployments must fail closed if their control volume disappears.
    if (getenv('DNR_FILE_STORAGE_PATH')) persistentFileRoot();
    if (is_link($path)) throw new RuntimeException('Invalid account restore control file.');
    if (!file_exists($path)) return ['version' => 1, 'generation' => '', 'mail_hold' => false, 'prepared_at' => ''];
    $raw = @file_get_contents($path, false, null, 0, 8193);
    if ($raw === false || strlen($raw) > 8192) throw new RuntimeException('Account restore controls are unavailable.');
    try { $state = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); }
    catch (JsonException $error) { throw new RuntimeException('Account restore controls are invalid.', 0, $error); }
    if (!is_array($state) || ($state['version'] ?? null) !== 1
        || !is_string($state['generation'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $state['generation']) !== 1
        || !is_bool($state['mail_hold'] ?? null) || !is_string($state['prepared_at'] ?? null)) {
        throw new RuntimeException('Account restore controls are invalid.');
    }
    return ['version' => 1, 'generation' => $state['generation'], 'mail_hold' => $state['mail_hold'], 'prepared_at' => $state['prepared_at']];
}

function accountRestoreGeneration(): string { return accountRestoreSecurityState()['generation']; }
function accountOutboundMailHeld(): bool { return accountRestoreSecurityState()['mail_hold']; }

function requireAccountOutboundMailAllowed(): void
{
    if (accountOutboundMailHeld()) {
        require_once __DIR__ . '/audience_mail_budget.php';
        throw new MailBudgetDeferredException(gmdate('Y-m-d H:i:s', time() + 300),
            'Outbound email is held after database recovery. An operator must reconcile delivery and consent records.');
    }
}

/** Serialize operator updates and publish a complete, durable control record. */
function updateAccountRestoreSecurity(callable $change): array
{
    $root = persistentFileRoot();
    $lockPath = $root . '/.restore-security.lock';
    if (is_link($lockPath)) throw new RuntimeException('Invalid account restore control lock.');
    $lock = @fopen($lockPath, 'c+b');
    if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Unable to lock account restore controls.');
    $temporary = null;
    try {
        $state = $change(accountRestoreSecurityState());
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        $temporary = tempnam($root, '.restore-control-');
        if ($temporary === false) throw new RuntimeException('Unable to stage account restore controls.');
        $file = fopen($temporary, 'wb');
        if ($file === false) throw new RuntimeException('Unable to write account restore controls.');
        try {
            if (!chmod($temporary, 0600) || fwrite($file, $json) !== strlen($json) || !fflush($file) || !fsync($file)) {
                throw new RuntimeException('Unable to persist account restore controls.');
            }
        } finally { fclose($file); }
        if (!rename($temporary, accountRestoreSecurityPath())) throw new RuntimeException('Unable to publish account restore controls.');
        $directory = fopen($root, 'r');
        if ($directory === false) throw new RuntimeException('Unable to flush account restore controls.');
        try { if (!fsync($directory)) throw new RuntimeException('Unable to flush account restore controls.'); }
        finally { fclose($directory); }
        return $state;
    } finally {
        if (is_string($temporary) && is_file($temporary)) unlink($temporary);
        flock($lock, LOCK_UN); fclose($lock);
    }
}

/** Rotate before touching restored rows. Failed restores deliberately leave this hold active. */
function prepareAccountRestoreSecurity(): array
{
    return updateAccountRestoreSecurity(static fn(array $previous): array => [
        'version' => 1, 'generation' => bin2hex(random_bytes(32)), 'mail_hold' => true,
        'prepared_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
}

/** Caller must first reconcile post-snapshot deliveries, opt-outs and complaints. */
function releaseAccountRestoreMailHold(string $generation, string $confirmation): array
{
    if ($confirmation !== 'RECONCILED') throw new InvalidArgumentException('Confirm reconciliation with RECONCILED.');
    return updateAccountRestoreSecurity(static function (array $state) use ($generation): array {
        if (!$state['mail_hold'] || $generation === '' || !hash_equals($state['generation'], $generation)) {
            throw new InvalidArgumentException('The recovery generation changed or its mail hold is not active.');
        }
        $state['mail_hold'] = false;
        return $state;
    });
}

function revokeRestoredAccountCredentials(mysqli $conn, array $tableNames): void
{
    if (in_array('calendar_subscriptions', $tableNames, true)) {
        $conn->query('UPDATE calendar_subscriptions SET revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP())');
    }
    foreach (['platform_access_tickets', 'account_login_handoffs'] as $table) {
        if (in_array($table, $tableNames, true)) $conn->query("UPDATE `$table` SET consumed_at=COALESCE(consumed_at,UTC_TIMESTAMP())");
    }
}

/** Preserve receipts, but never replay snapshot queues automatically. No rows are deleted. */
function quarantineRestoredOutboundMail(mysqli $conn, array $tableNames): void
{
    foreach (['email_outbox', 'notification_outbox', 'engagement_email_deliveries', 'reimbursement_email_deliveries', 'marketing_ses_alert_outbox'] as $table) {
        if (!in_array($table, $tableNames, true)) continue;
        $conn->query("UPDATE `$table` SET status=IF(delivery_started_at IS NULL,'failed','delivery_uncertain'),
            claim_token=NULL, processing_started_at=NULL,
            last_error='Quarantined after database restore; reconcile provider receipts before creating new mail.'
            WHERE status IN ('pending','processing','retry')");
    }
    foreach (['marketing_campaign_recipients', 'marketing_service_mail'] as $table) {
        if (!in_array($table, $tableNames, true)) continue;
        $conn->query("UPDATE `$table` SET state=IF(delivery_started_at IS NULL,'cancelled','uncertain'),claim_token=NULL,
            error_message='Quarantined after database restore; reconcile provider receipts before creating new mail.'
            WHERE state IN ('queued','processing')");
    }
    if (in_array('marketing_campaign_deliveries', $tableNames, true)) $conn->query("UPDATE marketing_campaign_deliveries
        SET state='paused',version=version+1,error_message='Paused after database restore; review before starting new delivery.' WHERE state IN ('scheduled','sending')");
    if (in_array('marketing_campaigns', $tableNames, true)) $conn->query("UPDATE marketing_campaigns SET status='paused',version=version+1 WHERE status IN ('scheduled','sending')");
    if (in_array('marketing_flows', $tableNames, true)) $conn->query("UPDATE marketing_flows SET state='paused',version=version+1 WHERE state='running'");
    if (in_array('marketing_delivery_setup_jobs', $tableNames, true)) $conn->query("UPDATE marketing_delivery_setup_jobs
        SET state=IF(delivery_started_at IS NULL,'failed','uncertain'),payload_ciphertext='',
        error_message='Quarantined after database restore; request fresh verification.' WHERE state IN ('queued','processing')");
}
