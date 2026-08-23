<?php

declare(strict_types=1);

require_once __DIR__ . '/email_helpers.php';

function userAccountStatusLabel($status)
{
    return match ((string) $status) {
        'invited' => 'Invitation pending',
        'inactive' => 'Inactive',
        default => 'Active',
    };
}

function emailAddressBelongsToAnotherUser(mysqli $conn, $email, $user_id = null)
{
    $email = normalizeAccountEmail($email);
    $user_id = $user_id === null ? 0 : (int) $user_id;
    $stmt = $conn->prepare(
        'SELECT id FROM users WHERE LOWER(email) = ? AND id <> ? LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to verify email availability.');
    }
    $stmt->bind_param('si', $email, $user_id);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows === 1;
    $stmt->close();
    return $exists;
}

/** @return array{user_id: int, token: string, email: string, username: string} */
function inviteUserAccount(mysqli $conn, $username, $email, $role, $actor_user_id)
{
    $username = trim((string) $username);
    $email = normalizeAccountEmail($email);
    $role = (string) $role;
    $actor_user_id = (int) $actor_user_id;
    if ($username === '' || mb_strlen($username, 'UTF-8') > 50) {
        throw new InvalidArgumentException('Username is required and must be 50 characters or fewer.');
    }
    if (!in_array($role, \Dnr\Domain\ReferenceData::userRoles(), true)) {
        throw new InvalidArgumentException('Invalid role selected.');
    }

    $conn->begin_transaction();
    try {
        $check = $conn->prepare(
            'SELECT id FROM users WHERE username = ? OR LOWER(email) = ? FOR UPDATE'
        );
        if (!$check) {
            throw new RuntimeException('Unable to verify invitation details.');
        }
        $check->bind_param('ss', $username, $email);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();
        if ($existing) {
            throw new InvalidArgumentException('That username or email address already belongs to an account.');
        }

        // Invited accounts cannot authenticate until the bearer link is
        // accepted. A random, unrecoverable placeholder satisfies the legacy
        // non-null password column without creating a shared credential.
        $placeholder_password = \Dnr\Security\PasswordPolicy::hash(
            rtrim(strtr(base64_encode(random_bytes(54)), '+/', '-_'), '=')
        );
        $status = 'invited';
        $stmt = $conn->prepare(
            'INSERT INTO users
                (username, email, password, must_change_password, role,
                 account_status, activated_at)
             VALUES (?, ?, ?, 0, ?, ?, NULL)'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the invited account.');
        }
        $stmt->bind_param('sssss', $username, $email, $placeholder_password, $role, $status);
        $stmt->execute();
        $user_id = (int) $conn->insert_id;
        $stmt->close();

        $issued = issueUserEmailToken(
            $conn,
            $user_id,
            'invitation',
            $email,
            $actor_user_id,
            null,
            true
        );
        if (!recordAuditEvent($conn, [
            'event_category' => 'security',
            'event_type' => 'user_invited',
            'actor_user_id' => $actor_user_id,
            'target_user_id' => $user_id,
            'target_username' => $username,
            'entity_type' => 'users',
            'entity_id' => $user_id,
            'entity_label' => $username,
            'details' => 'Invitation issued to ' . $email,
        ])) {
            throw new RuntimeException('Unable to audit the account invitation.');
        }
        $conn->commit();
        return [
            'user_id' => $user_id,
            'token' => $issued['token'],
            'email' => $email,
            'username' => $username,
        ];
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

/** @return array{token: string, email: string, username: string} */
function renewUserInvitation(mysqli $conn, $user_id, $actor_user_id)
{
    $user_id = (int) $user_id;
    $actor_user_id = (int) $actor_user_id;
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "SELECT id, username, email FROM users
             WHERE id = ? AND account_status = 'invited' FOR UPDATE"
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$user || trim((string) $user['email']) === '') {
            throw new InvalidArgumentException('That pending invitation is no longer available.');
        }
        $email = normalizeAccountEmail($user['email']);
        $issued = issueUserEmailToken(
            $conn,
            $user_id,
            'invitation',
            $email,
            $actor_user_id,
            null,
            true
        );
        if (!recordAuditEvent($conn, [
            'event_category' => 'security',
            'event_type' => 'user_invitation_renewed',
            'actor_user_id' => $actor_user_id,
            'target_user_id' => $user_id,
            'target_username' => (string) $user['username'],
            'entity_type' => 'users',
            'entity_id' => $user_id,
            'entity_label' => (string) $user['username'],
        ])) {
            throw new RuntimeException('Unable to audit the renewed invitation.');
        }
        $conn->commit();
        return [
            'token' => $issued['token'],
            'email' => $email,
            'username' => (string) $user['username'],
        ];
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

/** @return array{calendar_tokens: int, task_assignments: int} */
function deactivateUserAccount(mysqli $conn, $user_id, $actor_user_id)
{
    $user_id = (int) $user_id;
    $actor_user_id = (int) $actor_user_id;
    if ($user_id < 1 || $user_id === $actor_user_id) {
        throw new InvalidArgumentException('You cannot deactivate the account backing your current session.');
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'SELECT id, username, role, account_status FROM users WHERE id = ? FOR UPDATE'
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$user) {
            throw new InvalidArgumentException('That account is no longer available.');
        }
        if ($user['account_status'] !== 'active') {
            throw new InvalidArgumentException('Only an active account can be deactivated.');
        }
        if ($user['role'] === 'admin') {
            $admins = $conn->query(
                "SELECT id FROM users
                 WHERE role = 'admin' AND account_status = 'active' FOR UPDATE"
            );
            if (!$admins || $admins->num_rows <= 1) {
                throw new InvalidArgumentException('DNR must retain at least one active administrator.');
            }
        }

        $update = $conn->prepare(
            "UPDATE users
             SET account_status = 'inactive', deactivated_at = UTC_TIMESTAMP(),
                 auth_version = auth_version + 1
             WHERE id = ? AND account_status = 'active'"
        );
        $update->bind_param('i', $user_id);
        $update->execute();
        if ($update->affected_rows !== 1) {
            throw new RuntimeException('Unable to deactivate the account.');
        }
        $update->close();

        $calendars = $conn->prepare(
            'UPDATE calendar_subscriptions SET revoked_at = UTC_TIMESTAMP()
             WHERE user_id = ? AND revoked_at IS NULL'
        );
        $calendars->bind_param('i', $user_id);
        $calendars->execute();
        $calendar_count = max(0, (int) $calendars->affected_rows);
        $calendars->close();

        $tasks = $conn->prepare(
            'UPDATE follow_up_tasks SET assigned_to = NULL WHERE assigned_to = ?'
        );
        $tasks->bind_param('i', $user_id);
        $tasks->execute();
        $task_count = max(0, (int) $tasks->affected_rows);
        $tasks->close();

        $tokens = $conn->prepare(
            'UPDATE user_email_tokens SET consumed_at = UTC_TIMESTAMP()
             WHERE user_id = ? AND consumed_at IS NULL'
        );
        $tokens->bind_param('i', $user_id);
        $tokens->execute();
        $tokens->close();

        if (!recordAuditEvent($conn, [
            'event_category' => 'security',
            'event_type' => 'user_deactivated',
            'actor_user_id' => $actor_user_id,
            'target_user_id' => $user_id,
            'target_username' => (string) $user['username'],
            'entity_type' => 'users',
            'entity_id' => $user_id,
            'entity_label' => (string) $user['username'],
            'details' => "Sessions/elevation revoked; {$calendar_count} calendar token(s) revoked; {$task_count} task(s) unassigned",
        ])) {
            throw new RuntimeException('Unable to audit account deactivation.');
        }

        $conn->commit();
        return ['calendar_tokens' => $calendar_count, 'task_assignments' => $task_count];
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function activateUserAccount(mysqli $conn, $user_id, $actor_user_id)
{
    $user_id = (int) $user_id;
    $actor_user_id = (int) $actor_user_id;
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "SELECT id, username, email_verified_at FROM users
             WHERE id = ? AND account_status = 'inactive' FOR UPDATE"
        );
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$user) {
            throw new InvalidArgumentException('Only an inactive account can be activated.');
        }
        $update = $conn->prepare(
            "UPDATE users
             SET account_status = 'active', activated_at = UTC_TIMESTAMP(),
                 deactivated_at = NULL, auth_version = auth_version + 1
             WHERE id = ? AND account_status = 'inactive'"
        );
        $update->bind_param('i', $user_id);
        $update->execute();
        if ($update->affected_rows !== 1) {
            throw new RuntimeException('Unable to activate the account.');
        }
        $update->close();

        if (!recordAuditEvent($conn, [
            'event_category' => 'security',
            'event_type' => 'user_activated',
            'actor_user_id' => $actor_user_id,
            'target_user_id' => $user_id,
            'target_username' => (string) $user['username'],
            'entity_type' => 'users',
            'entity_id' => $user_id,
            'entity_label' => (string) $user['username'],
            'details' => 'Calendar subscriptions and task assignments remain revoked',
        ])) {
            throw new RuntimeException('Unable to audit account activation.');
        }
        $conn->commit();
        return true;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
