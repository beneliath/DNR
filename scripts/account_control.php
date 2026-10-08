<?php
declare(strict_types=1);
// Operator-side control, outside the web document root. The provisioner passes
// this file to PHP over stdin; stdout is consumed privately, never logged.
if (PHP_SAPI !== 'cli') exit(1);
require_once '/var/www/html/bootstrap.php';
require_once '/var/www/html/two_factor_helpers.php';
$action = $argv[1] ?? '';
$key = $argv[2] ?? '';
if (!accountIsPrimary()) throw new RuntimeException('The primary Account is required.');
switch ($action) {
    case 'probe':
        $row = $conn->execute_query('SELECT * FROM platform_accounts WHERE account_key=?', [$key])->fetch_assoc();
        if (!$row) throw new RuntimeException('Unknown Account.');
        $row['public_url'] = accountPublicUrl($argv[3] ?? '');
        $profile = platformMemberCall($row, 'directory_profile', []);
        if (($profile['account_key'] ?? '') !== $key) throw new RuntimeException('Account identity probe failed.');
        echo '{"ok":true}';
        break;
    case 'directory':
        echo json_encode($conn->query('SELECT account_key, name, state FROM platform_accounts')->fetch_all(MYSQLI_ASSOC), JSON_THROW_ON_ERROR);
        break;
    case 'lifecycle':
        echo json_encode($conn->query("SELECT account_key, name, state FROM platform_accounts WHERE state IN ('archiving','restoring','deleting') AND lifecycle_error IS NULL")->fetch_all(MYSQLI_ASSOC), JSON_THROW_ON_ERROR);
        break;
    case 'lifecycle_failed':
        $conn->execute_query("UPDATE platform_accounts SET lifecycle_error = 'The Account action could not finish. Retry the action or contact the operator.' WHERE account_key = ? AND state IN ('archiving','restoring','deleting')", [$key]);
        echo '{}';
        break;
    case 'lifecycle_complete':
        $expected = $argv[3] ?? '';
        $next = match ($expected) { 'archiving' => 'disabled', 'restoring' => 'ready', 'deleting' => 'deleted', default => throw new RuntimeException('Invalid state.') };
        $conn->begin_transaction();
        $row = $conn->execute_query('SELECT state, name, lifecycle_requested_by FROM platform_accounts WHERE account_key = ? FOR UPDATE', [$key])->fetch_assoc();
        if (!$row || $row['state'] !== $expected || $key === currentAccountKey()) throw new RuntimeException('Account state changed.');
        if ($next === 'deleted') {
            $conn->execute_query('DELETE FROM platform_login_routes WHERE account_key = ?', [$key]);
            $conn->execute_query('DELETE FROM platform_login_claims WHERE account_key = ?', [$key]);
            $conn->execute_query('DELETE FROM platform_login_reservations WHERE account_key = ?', [$key]);
            $conn->execute_query('DELETE FROM platform_api_nonces WHERE account_key = ?', [$key]);
            $conn->execute_query('DELETE FROM platform_access_tickets WHERE account_key = ?', [$key]);
            $conn->execute_query("UPDATE platform_accounts SET api_key_encrypted = '' WHERE account_key = ?", [$key]);
        }
        $conn->execute_query('UPDATE platform_accounts SET state = ?, lifecycle_error = NULL WHERE account_key = ?', [$next, $key]);
        recordAuditEvent($conn, ['event_category' => 'security', 'event_type' => 'platform_account_' . $next,
            'actor_user_id' => $row['lifecycle_requested_by'], 'entity_type' => 'account', 'entity_label' => $row['name'],
            'details' => 'Account ' . $key . ': ' . $expected . ' → ' . $next]);
        $conn->commit();
        echo '{}';
        break;
    case 'pending':
        echo json_encode($conn->query("SELECT account_key FROM platform_accounts WHERE state IN ('pending','provisioning') ORDER BY created_at")->fetch_all(MYSQLI_ASSOC), JSON_THROW_ON_ERROR);
        break;
    case 'claim':
        $conn->execute_query("UPDATE platform_accounts SET state = 'provisioning' WHERE account_key = ? AND state = 'pending'", [$key]);
        $row = $conn->execute_query("SELECT account_key, name, api_key_encrypted FROM platform_accounts WHERE account_key = ? AND state = 'provisioning'", [$key])->fetch_assoc();
        if (!$row) throw new RuntimeException('Account is not awaiting preparation.');
        $row['api_key'] = \Dnr\Security\ApplicationKey::open($row['api_key_encrypted']);
        unset($row['api_key_encrypted']);
        echo json_encode($row, JSON_THROW_ON_ERROR);
        break;
    case 'ready':
        $url = accountPublicUrl($argv[3] ?? '');
        $conn->execute_query("UPDATE platform_accounts SET state = 'ready', public_url = ? WHERE account_key = ? AND state = 'provisioning'", [$url, $key]);
        if ($conn->affected_rows !== 1) throw new RuntimeException('Account state changed.');
        echo '{}';
        break;
    default: throw new RuntimeException('Unknown control action.');
}
