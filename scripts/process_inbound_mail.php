<?php

declare(strict_types=1);

use Dnr\Infrastructure\ImapClient;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "The inbound mail worker is available only from the CLI.\n");
    exit(1);
}

require_once '/var/www/html/bootstrap.php';
require_once '/var/www/html/inbound_email_helpers.php';

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
    $activity = 0;
    $client = new ImapClient(
        $host,
        $port,
        $security,
        $verifyPeer,
        15,
        inboundEmailMaximumRawBytes()
    );
    try {
        $client->connect($username, $password, $mailbox);
        $uidValidity = $client->uidValidity();
        foreach ($client->unseenUids($batchSize) as $uid) {
            try {
                $rawMessage = $client->fetchRawMessage($uid);
                $parsed = parseInboundEmail($rawMessage);
                storeInboundEmailMessage(
                    $conn,
                    'imap',
                    $uidValidity . ':' . $uid,
                    $parsed
                );
                $client->markSeen($uid);
                $activity++;
            } catch (Throwable $exception) {
                applicationLog('error', 'Unable to import an IMAP message', [
                    'uid_validity' => $uidValidity,
                    'uid' => $uid,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    } catch (Throwable $exception) {
        applicationLog('error', 'Inbound IMAP polling failed', [
            'error' => $exception->getMessage(),
        ]);
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
            try {
                failInboundEmailMessage($conn, $messageId, $exception);
            } catch (Throwable $recordException) {
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
        $activity++;
    }

    if ($loop && $activity === 0) {
        sleep($idleSeconds);
    }
} while ($loop);

flock($lock, LOCK_UN);
fclose($lock);
