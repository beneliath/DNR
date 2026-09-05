<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "The account-email worker is available only from the CLI.\n");
    exit(1);
}

require_once '/var/www/html/bootstrap.php';
require_once '/var/www/html/worker_health_helpers.php';
require_once '/var/www/html/email_helpers.php';
require_once '/var/www/html/notification_helpers.php';
require_once '/var/www/html/engagement_email_helpers.php';

$loop = in_array('--loop', $argv, true);
$batchSize = max(1, min(100, (int) (getenv('DNR_EMAIL_OUTBOX_BATCH_SIZE') ?: 20)));
$notificationBatchSize = max(
    1,
    min(100, (int) (getenv('DNR_NOTIFICATION_OUTBOX_BATCH_SIZE') ?: 20))
);
$engagementBatchSize = max(
    1,
    min(100, (int) (getenv('DNR_ENGAGEMENT_EMAIL_OUTBOX_BATCH_SIZE') ?: 20))
);
$idleSeconds = max(5, min(300, (int) (getenv('DNR_EMAIL_OUTBOX_IDLE_SECONDS') ?: 15)));
$scheduleInterval = max(
    60,
    min(3600, (int) (getenv('DNR_NOTIFICATION_SCHEDULE_INTERVAL_SECONDS') ?: 300))
);
$lastScheduleCheck = 0;
$lockPath = sys_get_temp_dir() . '/dnr-email-outbox-worker.lock';
$lock = fopen($lockPath, 'c+');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another account-email worker is already running.\n");
    exit(0);
}

do {
    $pass_succeeded = true;
    $processed = 0;
    $smtpSession = null;
    if (!$loop || time() - $lastScheduleCheck >= $scheduleInterval) {
        try {
            $processed += queueDueDailyTaskDigests($conn);
        } catch (Throwable $exception) {
            $pass_succeeded = false;
            applicationLog('error', 'Unable to schedule daily work digests', [
                'error' => $exception->getMessage(),
            ]);
        }
        $lastScheduleCheck = time();
    }

    $businessDate = applicationBusinessDate();
    foreach ([
        'account email' => static fn() => maintainQueuedAccountEmail($conn),
        'notification email' => static fn() => maintainQueuedNotificationEmail($conn, $businessDate),
        'engagement email' => static fn() => maintainQueuedEngagementEmail($conn),
    ] as $queueName => $maintainQueue) {
        try {
            // Sweep invalid or abandoned leases once per bounded worker cycle,
            // rather than repeating table-wide maintenance before every claim.
            $maintainQueue();
        } catch (Throwable $exception) {
            $pass_succeeded = false;
            applicationLog('error', 'Unable to maintain outbound queue leases', [
                'queue' => $queueName,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    for ($index = 0; $index < $batchSize; $index++) {
        $queued = claimQueuedAccountEmail($conn, 600, false);
        if ($queued === null) {
            break;
        }
        try {
            if (strtotime($queued['expires_at'] . ' UTC') <= time()) {
                throw new DomainException('The queued account link expired before delivery.');
            }
            $message = decryptQueuedAccountEmail($queued['payload_ciphertext']);
            deliverApplicationEmailWithSession(
                $smtpSession,
                $message['recipient'],
                $message['subject'],
                $message['body']
            );
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
            $pass_succeeded = false;
                $conn->rollback();
                throw $exception;
            }
        } catch (Throwable $exception) {
            $pass_succeeded = false;
            try {
                failQueuedAccountEmail(
                    $conn,
                    $queued['id'],
                    $queued['token_id'],
                    $exception,
                    $exception instanceof DomainException
                );
            } catch (Throwable $recordException) {
            $pass_succeeded = false;
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
        recordWorkerHeartbeat('mail-dispatch', $pass_succeeded);
        $processed++;
    }

    for ($index = 0; $index < $notificationBatchSize; $index++) {
        $queued = claimQueuedNotificationEmail($conn, $businessDate, 600, false);
        if ($queued === null) {
            break;
        }
        try {
            $message = decryptQueuedNotificationEmail($queued['payload_ciphertext']);
            deliverApplicationEmailWithSession(
                $smtpSession,
                $message['recipient'],
                $message['subject'],
                $message['body'],
                '',
                is_string($message['html_body'] ?? null) ? $message['html_body'] : null
            );
            completeQueuedNotificationEmail($conn, $queued['id']);
        } catch (Throwable $exception) {
            $pass_succeeded = false;
            try {
                failQueuedNotificationEmail(
                    $conn,
                    $queued['id'],
                    $exception,
                    $exception instanceof DomainException
                );
            } catch (Throwable $recordException) {
            $pass_succeeded = false;
                applicationLog('error', 'Unable to record notification delivery failure', [
                    'outbox_id' => $queued['id'],
                    'error' => $recordException->getMessage(),
                ]);
            }
            applicationLog('error', 'Queued notification delivery failed', [
                'outbox_id' => $queued['id'],
                'notification_type' => $queued['notification_type'],
                'error' => $exception->getMessage(),
            ]);
        }
        recordWorkerHeartbeat('mail-dispatch', $pass_succeeded);
        $processed++;
    }

    for ($index = 0; $index < $engagementBatchSize; $index++) {
        $queued = claimQueuedEngagementEmail($conn, 600, false);
        if ($queued === null) {
            break;
        }
        try {
            $message = decryptQueuedEngagementEmail($queued['payload_ciphertext']);
            deliverApplicationEmailWithSession(
                $smtpSession,
                $message['recipient'],
                $message['subject'],
                $message['body'],
                $message['reply_to']
            );
            completeQueuedEngagementEmail($conn, $queued['id']);
        } catch (Throwable $exception) {
            $pass_succeeded = false;
            try {
                failQueuedEngagementEmail(
                    $conn,
                    $queued['id'],
                    $queued['attempts'],
                    $exception,
                    $exception instanceof DomainException
                );
            } catch (Throwable $recordException) {
            $pass_succeeded = false;
                applicationLog('error', 'Unable to record engagement-email delivery failure', [
                    'delivery_id' => $queued['id'],
                    'error' => $recordException->getMessage(),
                ]);
            }
            applicationLog('error', 'Queued engagement-email delivery failed', [
                'delivery_id' => $queued['id'],
                'message_id' => $queued['message_id'],
                'error' => $exception->getMessage(),
            ]);
        }
        recordWorkerHeartbeat('mail-dispatch', $pass_succeeded);
        $processed++;
    }

    if ($smtpSession instanceof SmtpSession) {
        $smtpSession->close();
        $smtpSession = null;
    }

    recordWorkerHeartbeat('mail-dispatch', $pass_succeeded);

    if ($loop && $processed === 0) {
        sleep($idleSeconds);
    }
} while ($loop);

flock($lock, LOCK_UN);
fclose($lock);
