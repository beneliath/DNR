<?php
declare(strict_types=1);

function accountLifecycleNextState(string $state, string $action, bool $failed = false): string {
    return match (true) {
        $action === 'archive' && $state === 'ready' => 'archiving',
        $action === 'restore' && $state === 'disabled' => 'restoring',
        $action === 'delete' && $state === 'disabled' => 'deleting',
        $action === 'retry' && $failed && in_array($state, ['archiving', 'restoring', 'deleting'], true) => $state,
        default => throw new InvalidArgumentException('This action is not available for the Account’s current status. Reload the page and try again.'),
    };
}

function platformRequestAccountLifecycle(mysqli $conn, string $key, string $action, string $confirmation): void {
    if (!accountIsPrimary() || !isSuperAdmin() || $key === currentAccountKey()) {
        throw new InvalidArgumentException('The primary Account cannot be archived or deleted.');
    }
    $conn->begin_transaction();
    try {
        $account = $conn->execute_query('SELECT name, state, lifecycle_error FROM platform_accounts WHERE account_key = ? FOR UPDATE', [$key])->fetch_assoc();
        if (!$account) throw new InvalidArgumentException('Account unavailable.');
        $next = accountLifecycleNextState($account['state'], $action, !empty($account['lifecycle_error']));
        if ($next === 'deleting' && !hash_equals($key, $confirmation)) {
            throw new InvalidArgumentException('Enter the Account’s exact address label to confirm permanent deletion.');
        }
        $actor = (int) $_SESSION['user_id'];
        $conn->execute_query('UPDATE platform_accounts SET state = ?, lifecycle_error = NULL, lifecycle_requested_by = ? WHERE account_key = ?', [$next, $actor, $key]);
        $conn->execute_query('DELETE FROM platform_access_tickets WHERE account_key = ?', [$key]);
        recordAuditEvent($conn, [
            'event_category' => 'security', 'event_type' => 'platform_account_' . $action . '_requested',
            'actor_user_id' => $actor, 'actor_username' => (string) $_SESSION['username'],
            'entity_type' => 'account', 'entity_label' => $account['name'],
            'details' => 'Account ' . $key . ': ' . $account['state'] . ' → ' . $next,
        ]);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
