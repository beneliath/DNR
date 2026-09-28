<?php

declare(strict_types=1);
require_once __DIR__ . '/application_runtime.php';
use Dnr\Security\ApplicationKey;

const APPLICATION_ENCRYPTED_COLUMNS = [
    'users' => 'totp_secret_encrypted',
    'email_outbox' => 'payload_ciphertext',
    'notification_outbox' => 'payload_ciphertext',
    'engagement_email_deliveries' => 'payload_ciphertext',
];

/** Repeat with next_id; rerunning an applied batch is harmless. Never log plaintext. */
function rotateApplicationKeyBatch(mysqli $conn, string $table, int $after = 0, int $limit = 100, bool $apply = false): array
{
    if (!ApplicationKey::versioned()) throw new RuntimeException('Configure a versioned keyring before re-encryption.');
    $column = APPLICATION_ENCRYPTED_COLUMNS[$table] ?? null;
    if ($column === null || $after < 0) throw new InvalidArgumentException('Choose a supported encrypted table and cursor.');
    $conn->begin_transaction();
    try {
        $rows = $conn->execute_query("SELECT id, {$column} AS payload FROM {$table}
            WHERE id > ? AND {$column} IS NOT NULL ORDER BY id LIMIT ? FOR UPDATE",
            [$after, max(1, min(500, $limit))])->fetch_all(MYSQLI_ASSOC);
        $changed = 0;
        foreach ($rows as $row) {
            $after = (int) $row['id'];
            $payload = (string) $row['payload'];
            $plaintext = ApplicationKey::open($payload); // Authenticate even a current-key payload.
            if (!str_starts_with($payload, 'dnr1:' . ApplicationKey::activeId() . ':')) {
                $changed++;
                if ($apply) $conn->execute_query("UPDATE {$table} SET {$column}=? WHERE id=?", [ApplicationKey::seal($plaintext), $after]);
            }
            sodium_memzero($plaintext);
        }
        $conn->commit();
        return ['table' => $table, 'scanned' => count($rows), 'rewrapped' => $apply ? $changed : 0,
            'needs_rotation' => $changed, 'next_id' => $after, 'finished' => count($rows) < max(1, min(500, $limit))];
    } catch (Throwable $exception) { $conn->rollback(); throw $exception; }
}

/** Retirement requires both ciphertext migration and recovery-code reissuance. */
function applicationKeyRetirementCheck(mysqli $conn, string $id): array
{
    if ($id === ApplicationKey::activeId()) throw new InvalidArgumentException('The active key cannot be retired.');
    if (preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]{0,47}\z/', $id) !== 1) throw new InvalidArgumentException('Invalid key identifier.');
    $counts = [];
    foreach (APPLICATION_ENCRYPTED_COLUMNS as $table => $column) {
        $counts[$table] = (int) $conn->execute_query("SELECT COUNT(*) FROM {$table} WHERE {$column} IS NOT NULL
            AND (LEFT({$column}, ?) = ? OR (LEFT({$column}, 5) <> 'dnr1:' AND ? = ?))",
            [strlen('dnr1:' . $id . ':'), 'dnr1:' . $id . ':', $id, ApplicationKey::legacyId()])->fetch_row()[0];
    }
    $counts['unused_recovery_codes'] = (int) $conn->execute_query('SELECT COUNT(*) FROM user_recovery_codes
        WHERE used_at IS NULL AND (key_id=? OR (key_id IS NULL AND ?=?))',
        [$id, $id, ApplicationKey::legacyId()])->fetch_row()[0];
    return ['key_id' => $id, 'live_dependencies' => $counts, 'live_data_ready' => array_sum($counts) === 0,
        'backup_warning' => 'Retained backups may still require this key. A live-data check does not authorize key destruction.'];
}
