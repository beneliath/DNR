<?php

declare(strict_types=1);

use Dnr\Infrastructure\InboundMailbox;
use Dnr\Infrastructure\ImapMessageRejectedException;

require_once __DIR__ . '/inbound_email_helpers.php';

function inboundMailboxKey(string $host, int $port, string $username, string $mailbox): string
{
    return hash('sha256', json_encode([strtolower($host), $port, strtolower($username), $mailbox], JSON_THROW_ON_ERROR));
}

/** @param list<mixed> $parameters */
function inboundSyncRow(mysqli $conn, string $sql, array $parameters = []): array
{
    $result = $conn->execute_query($sql, $parameters);
    if (!$result instanceof mysqli_result) {
        throw new RuntimeException('Unable to read mail synchronization state.');
    }
    return $result->fetch_assoc() ?: [];
}

function inboundMailboxLockName(string $key): string
{
    if (preg_match('/\A[0-9a-f]{64}\z/', $key) !== 1) {
        throw new InvalidArgumentException('Invalid mailbox identity.');
    }
    return 'dnr-mail-' . substr($key, 0, 54);
}

function recordInboundMailboxFailure(mysqli $conn, string $key, string $label, Throwable $error): void
{
    $conn->execute_query(
        'INSERT INTO inbound_mailbox_state (mailbox_key, mailbox_label, uid_validity, reconcile_through_uid,
            last_error, failed_at, consecutive_failures)
         VALUES (?, ?, 0, 0, ?, UTC_TIMESTAMP(), 1)
         ON DUPLICATE KEY UPDATE last_error = VALUES(last_error), failed_at = UTC_TIMESTAMP(),
            consecutive_failures = consecutive_failures + 1',
        [$key, mb_substr($label, 0, 255), mb_substr($error->getMessage(), 0, 255)]
    );
}

function inboundImportWasRecorded(mysqli $conn, string $fingerprint): bool
{
    return inboundSyncRow($conn,
        'SELECT 1 AS found FROM inbound_email_import_receipts WHERE deduplication_hash = ? FOR UPDATE',
        [$fingerprint]
    ) !== [];
}

/**
 * Discovery is bounded by both UID span and batch size. A checkpoint and its
 * durable outcome commit together. Routing is performed by the existing queue.
 * The connection must not already be inside a transaction.
 */
function syncInboundMailbox(mysqli $conn, InboundMailbox $mailbox, string $key, string $label, int $batchSize = 20): int
{
    $lock = inboundMailboxLockName($key);
    if ((int) (inboundSyncRow($conn, 'SELECT GET_LOCK(?, 0) AS acquired', [$lock])['acquired'] ?? 0) !== 1) {
        return 0;
    }
    try {
        $validity = $mailbox->uidValidity();
        $newest = $mailbox->uidNext() - 1;
        if ($validity < 1 || $validity > 4294967295 || $newest < 0 || $newest > 4294967294) {
            throw new RuntimeException('Invalid IMAP mailbox identity or next UID.');
        }
        $batchSize = max(1, min(100, $batchSize));
        $conn->execute_query(
            'INSERT IGNORE INTO inbound_mailbox_state
             (mailbox_key, mailbox_label, uid_validity, reconcile_through_uid)
             VALUES (?, ?, ?, ?)', [$key, mb_substr($label, 0, 255), $validity, $newest]
        );
        $state = inboundSyncRow($conn, 'SELECT * FROM inbound_mailbox_state WHERE mailbox_key = ?', [$key]);
        if ((int) $state['uid_validity'] !== $validity) {
            // A new UID generation cannot reuse the old cursor. Known receipts
            // deduplicate the rescan; unknown existing mail needs reconciliation.
            $conn->begin_transaction();
            try {
                $conn->execute_query("UPDATE inbound_mail_reconciliation SET decision = 'superseded', resolved_at = UTC_TIMESTAMP()
                    WHERE mailbox_key = ? AND uid_validity <> ? AND decision = 'pending'", [$key, $validity]);
                $conn->execute_query('UPDATE inbound_mailbox_state SET uid_validity = ?, scanned_uid = 0,
                    reconcile_through_uid = ? WHERE mailbox_key = ?', [$validity, $newest, $key]);
                $conn->commit();
            } catch (Throwable $error) {
                $conn->rollback();
                throw $error;
            }
            $state['scanned_uid'] = 0;
            $state['reconcile_through_uid'] = $newest;
        }
        $cursor = (int) $state['scanned_uid'];
        if ($newest < $cursor) {
            throw new RuntimeException('IMAP UIDNEXT moved backwards without a mailbox reset.');
        }
        $conn->execute_query('UPDATE inbound_mailbox_state SET observed_uid = ? WHERE mailbox_key = ?', [$newest, $key]);
        $conn->execute_query("UPDATE inbound_mail_reconciliation candidate
            JOIN inbound_email_import_receipts receipt ON receipt.deduplication_hash = candidate.deduplication_hash
            SET candidate.decision = 'duplicate', candidate.resolved_at = UTC_TIMESTAMP()
            WHERE candidate.mailbox_key = ? AND candidate.decision = 'pending'", [$key]);
        $through = min($newest, $cursor + 1000);
        $uids = $mailbox->uidsBetween($cursor + 1, $through);
        $activity = 0;
        $aborted = false;
        foreach (array_slice($uids, 0, $batchSize) as $uid) {
            $rejection = null;
            $parsed = null;
            try {
                $raw = $mailbox->fetchRawMessage($uid);
            } catch (ImapMessageRejectedException $error) {
                // A rejected literal may still be on the wire. Close before any
                // other IMAP command, including LOGOUT, and reconnect next pass.
                $mailbox->abort();
                $aborted = true;
                $rejection = $error;
            }
            if (!$rejection) {
                try {
                    $parsed = parseInboundEmail($raw);
                } catch (Throwable $error) {
                    $rejection = $error;
                }
            }
            $conn->begin_transaction();
            try {
                $imported = false;
                if ($rejection) {
                    quarantineInboundEmailMessage($conn, $key . ':' . $validity . ':' . $uid, $rejection);
                } elseif (!inboundImportWasRecorded($conn, $parsed['deduplication_hash'])) {
                    if ($uid <= (int) $state['reconcile_through_uid']) {
                        $conn->execute_query(
                            'INSERT IGNORE INTO inbound_mail_reconciliation
                             (mailbox_key, uid_validity, uid, deduplication_hash, sender_address, subject, sent_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?)',
                            [$key, $validity, $uid, $parsed['deduplication_hash'], $parsed['sender_address'],
                                $parsed['subject'], $parsed['sent_at']]
                        );
                    } else {
                        $stored = storeInboundEmailMessage($conn, 'imap', $key . ':' . $validity . ':' . $uid, $parsed);
                        $imported = $stored['inserted'];
                    }
                }
                $conn->execute_query('UPDATE inbound_mailbox_state SET scanned_uid = ?,
                    last_imported_at = IF(?, UTC_TIMESTAMP(), last_imported_at) WHERE mailbox_key = ?',
                    [$uid, (int) $imported, $key]);
                $conn->commit();
            } catch (Throwable $error) {
                $conn->rollback();
                throw $error;
            }
            $activity++;
            if ($aborted) {
                break;
            }
        }
        // Gaps from expunged messages are safe to cross only after SEARCH and
        // every returned message in this window have completed successfully.
        if (!$aborted && count($uids) <= $batchSize) {
            $conn->execute_query('UPDATE inbound_mailbox_state SET scanned_uid = ? WHERE mailbox_key = ?', [$through, $key]);
        }
        $conn->execute_query('UPDATE inbound_mailbox_state SET last_checked_at = UTC_TIMESTAMP(),
            last_error = NULL, failed_at = NULL, consecutive_failures = 0 WHERE mailbox_key = ?', [$key]);
        return max($activity, $through > $cursor ? 1 : 0);
    } catch (Throwable $error) {
        recordInboundMailboxFailure($conn, $key, $label, $error);
        throw $error;
    } finally {
        $conn->execute_query('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}

/** Explicit, one-message reconciliation. The worker never makes this decision. */
function reconcileInboundMailboxMessage(
    mysqli $conn, InboundMailbox $mailbox, string $key, int $validity, int $uid, string $decision
): array {
    if (!in_array($decision, ['import', 'ignore'], true) || $uid < 1 || $mailbox->uidValidity() !== $validity) {
        throw new InvalidArgumentException('The reconciliation decision or mailbox identity is invalid.');
    }
    $lock = inboundMailboxLockName($key);
    if ((int) (inboundSyncRow($conn, 'SELECT GET_LOCK(?, 5) AS acquired', [$lock])['acquired'] ?? 0) !== 1) {
        throw new RuntimeException('The mailbox is busy; retry reconciliation shortly.');
    }
    try {
        $candidate = inboundSyncRow($conn, 'SELECT * FROM inbound_mail_reconciliation
            WHERE mailbox_key = ? AND uid_validity = ? AND uid = ? AND decision = \'pending\'', [$key, $validity, $uid]);
        if ($candidate === []) {
            throw new RuntimeException('No pending reconciliation entry matches this message.');
        }
        $parsed = null;
        if ($decision === 'import') {
            try {
                $parsed = parseInboundEmail($mailbox->fetchRawMessage($uid));
            } catch (ImapMessageRejectedException $error) {
                $mailbox->abort();
                throw $error;
            }
            if (!hash_equals($candidate['deduplication_hash'], $parsed['deduplication_hash'])) {
                throw new RuntimeException('Message identity changed; reconciliation stopped.');
            }
        }
        $conn->begin_transaction();
        try {
            $messageId = null;
            if (inboundImportWasRecorded($conn, $candidate['deduplication_hash'])) {
                $outcome = 'duplicate';
            } elseif ($parsed !== null) {
                $stored = storeInboundEmailMessage($conn, 'imap', $key . ':' . $validity . ':' . $uid, $parsed);
                $messageId = $stored['id'];
                $outcome = 'imported';
                $conn->execute_query('UPDATE inbound_mailbox_state SET last_imported_at = UTC_TIMESTAMP() WHERE mailbox_key = ?', [$key]);
            } else {
                $conn->execute_query('INSERT INTO inbound_email_import_receipts (deduplication_hash, ignored_at)
                    VALUES (?, UTC_TIMESTAMP())', [$candidate['deduplication_hash']]);
                $outcome = 'ignored';
            }
            $conn->execute_query('UPDATE inbound_mail_reconciliation SET decision = ?, resolved_at = UTC_TIMESTAMP()
                WHERE mailbox_key = ? AND uid_validity = ? AND uid = ?', [$outcome, $key, $validity, $uid]);
            $conn->commit();
            return ['outcome' => $outcome, 'message_id' => $messageId];
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }
    } finally {
        $conn->execute_query('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}
