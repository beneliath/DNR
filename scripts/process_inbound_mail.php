<?php

declare(strict_types=1);

use Dnr\Infrastructure\ImapClient;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "The inbound mail worker is available only from the CLI.\n");
    exit(1);
}

require_once '/var/www/html/bootstrap.php';
require_once '/var/www/html/worker_health_helpers.php';
require_once '/var/www/html/inbound_ingestion_helpers.php';

$loop = in_array('--loop', $argv, true);
$host = trim((string) (getenv('DNR_IMAP_HOST') ?: ''));
$security = strtolower(trim((string) (getenv('DNR_IMAP_SECURITY') ?: 'starttls')));
$port = (int) (getenv('DNR_IMAP_PORT') ?: ($security === 'tls' ? 993 : 1143));
$username = trim((string) (getenv('DNR_IMAP_USERNAME') ?: ''));
$password = configurationSecret('DNR_IMAP_PASSWORD');
$mailbox = trim((string) (getenv('DNR_IMAP_MAILBOX') ?: 'INBOX'));
$verifyPeerSetting = getenv('DNR_IMAP_VERIFY_PEER');
$verifyPeer = ($verifyPeerSetting === false ? '1' : trim($verifyPeerSetting)) !== '0';
$batchSize = max(1, min(100, (int) (getenv('DNR_INBOUND_BATCH_SIZE') ?: 20)));
$idleSeconds = max(5, min(300, (int) (getenv('DNR_INBOUND_IDLE_SECONDS') ?: 30)));
$lockPath = sys_get_temp_dir() . '/dnr-inbound-mail-worker.lock';
$mailboxKey = inboundMailboxKey($host, $port, $username, $mailbox);
$mailboxLabel = $username . ' / ' . $mailbox;

if ($host === '' || $username === '' || $password === '') {
    fwrite(STDERR, "DNR_IMAP_HOST, DNR_IMAP_USERNAME, and an IMAP password value or file are required.\n");
    exit(1);
}

$lock = fopen($lockPath, 'c+');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another inbound mail worker is already running.\n");
    exit(0);
}

setDatabaseAuditContext($conn, null, 'Email Gateway');

do {
    $pass_succeeded = true;
    $activity = 0;
    $client = new ImapClient(
        $host,
        $port,
        $security,
        $verifyPeer,
        15,
        inboundEmailMaximumRawBytes()
    );
    $synchronizing = false;
    try {
        $client->connect($username, $password, $mailbox);
        $synchronizing = true;
        $activity += syncInboundMailbox($conn, $client, $mailboxKey, $mailboxLabel, $batchSize);
    } catch (Throwable $exception) {
        $pass_succeeded = false;
        if (!$synchronizing) {
            try {
                recordInboundMailboxFailure($conn, $mailboxKey, $mailboxLabel, $exception);
            } catch (Throwable $stateException) {
                applicationLog('error', 'Unable to record mailbox failure', ['error' => $stateException->getMessage()]);
            }
        }
        applicationLog('error', 'Inbound IMAP polling failed', ['error' => $exception->getMessage()]);
    } finally {
        $client->close();
    }

    for ($index = 0; $index < $batchSize; $index++) {
        $messageId = claimInboundEmailMessage($conn);
        if ($messageId === null) {
            break;
        }
        try {
            processInboundEmailMessage($conn, $messageId);
        } catch (Throwable $exception) {
            $pass_succeeded = false;
            try {
                failInboundEmailMessage($conn, $messageId, $exception);
            } catch (Throwable $recordException) {
                $pass_succeeded = false;
                applicationLog('error', 'Unable to record inbound email failure', [
                    'message_id' => $messageId,
                    'error' => $recordException->getMessage(),
                ]);
            }
            applicationLog('error', 'Inbound email routing failed', [
                'message_id' => $messageId,
                'error' => $exception->getMessage(),
            ]);
        }
        recordWorkerHeartbeat('mail-ingest', $pass_succeeded);
        $activity++;
    }

    recordWorkerHeartbeat('mail-ingest', $pass_succeeded);

    if ($loop && $activity === 0) {
        sleep($idleSeconds);
    }
} while ($loop);

flock($lock, LOCK_UN);
fclose($lock);
