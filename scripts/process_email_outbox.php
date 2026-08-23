<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "The account-email worker is available only from the CLI.\n");
    exit(1);
}

require_once '/var/www/html/bootstrap.php';
require_once '/var/www/html/email_helpers.php';

$loop = in_array('--loop', $argv, true);
$batchSize = max(1, min(100, (int) (getenv('DNR_EMAIL_OUTBOX_BATCH_SIZE') ?: 20)));
$idleSeconds = max(5, min(300, (int) (getenv('DNR_EMAIL_OUTBOX_IDLE_SECONDS') ?: 15)));
$lockPath = sys_get_temp_dir() . '/dnr-email-outbox-worker.lock';
$lock = fopen($lockPath, 'c+');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another account-email worker is already running.\n");
    exit(0);
}

do {
    $processed = 0;
    for ($index = 0; $index < $batchSize; $index++) {
        $queued = claimQueuedAccountEmail($conn);
        if ($queued === null) {
            break;
        }
        try {
            if (strtotime($queued['expires_at'] . ' UTC') <= time()) {
                throw new DomainException('The queued account link expired before delivery.');
            }
            $message = decryptQueuedAccountEmail($queued['payload_ciphertext']);
            deliverAccountEmail($message['recipient'], $message['subject'], $message['body']);
            $conn->begin_transaction();
            try {
                completeQueuedAccountEmail(
                    $conn,
                    $queued['token_id'],
                    $queued['user_id'],
                    $queued['purpose']
                );
                $conn->commit();
            } catch (Throwable $exception) {
                $conn->rollback();
                throw $exception;
            }
        } catch (Throwable $exception) {
            try {
                failQueuedAccountEmail(
                    $conn,
                    $queued['id'],
                    $queued['token_id'],
                    $exception,
                    $exception instanceof DomainException
                );
            } catch (Throwable $recordException) {
                applicationLog('error', 'Unable to record account-email delivery failure', [
                    'outbox_id' => $queued['id'],
                    'error' => $recordException->getMessage(),
                ]);
            }
            applicationLog('error', 'Queued account-email delivery failed', [
                'outbox_id' => $queued['id'],
                'purpose' => $queued['purpose'],
                'error' => $exception->getMessage(),
            ]);
        }
        $processed++;
    }

    if ($loop && $processed === 0) {
        sleep($idleSeconds);
    }
} while ($loop);

flock($lock, LOCK_UN);
fclose($lock);
