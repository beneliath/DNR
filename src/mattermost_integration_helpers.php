<?php

declare(strict_types=1);

require_once __DIR__ . '/follow_up_task_helpers.php';
require_once __DIR__ . '/chron_log_helpers.php';

const MATTERMOST_LINK_CODE_TTL_SECONDS = 600;
const MATTERMOST_LINK_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

function mattermostIntegrationToken(): string
{
    return configurationSecret('DNR_MATTERMOST_TOKEN');
}

function mattermostIntegrationInstanceId(): string
{
    $instanceId = trim((string) (getenv('DNR_MATTERMOST_INSTANCE_ID') ?: 'primary'));
    return preg_match('/\A[A-Za-z0-9._-]{1,100}\z/', $instanceId) === 1
        ? $instanceId
        : '';
}

function mattermostIntegrationConfigured(): bool
{
    return strlen(mattermostIntegrationToken()) >= 32
        && mattermostIntegrationInstanceId() !== '';
}

function normalizeMattermostLinkCode(string $code): string
{
    return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', trim($code)));
}

function generateMattermostLinkCode(mysqli $conn, int $userId): array
{
    if ($userId < 1) {
        throw new InvalidArgumentException('A valid user is required.');
    }

    $raw = '';
    for ($index = 0; $index < 8; $index++) {
        $raw .= MATTERMOST_LINK_CODE_ALPHABET[
            random_int(0, strlen(MATTERMOST_LINK_CODE_ALPHABET) - 1)
        ];
    }
    $displayCode = substr($raw, 0, 4) . '-' . substr($raw, 4);
    $codeHash = hash('sha256', $raw, true);
    $expiresAt = gmdate('Y-m-d H:i:s.u', time() + MATTERMOST_LINK_CODE_TTL_SECONDS);

    $conn->begin_transaction();
    try {
        $delete = $conn->prepare(
            'DELETE FROM mattermost_link_codes
             WHERE user_id = ?'
        );
        if (!$delete) {
            throw new RuntimeException('Unable to prepare account-link cleanup.');
        }
        $delete->bind_param('i', $userId);
        $delete->execute();
        $delete->close();

        $insert = $conn->prepare(
            'INSERT INTO mattermost_link_codes (user_id, code_hash, expires_at)
             VALUES (?, ?, ?)'
        );
        if (!$insert) {
            throw new RuntimeException('Unable to prepare an account-link code.');
        }
        $insert->bind_param('iss', $userId, $codeHash, $expiresAt);
        $insert->execute();
        $codeId = (int) $conn->insert_id;
        $insert->close();

        if (!recordAuditEvent($conn, [
            'event_category' => 'security',
            'event_type' => 'mattermost_link_code_created',
            'actor_user_id' => $userId,
            'target_user_id' => $userId,
            'entity_type' => 'mattermost_link_code',
            'entity_id' => $codeId,
            'details' => 'One-time Mattermost link code created',
        ])) {
            throw new RuntimeException('Unable to audit the account-link code.');
        }
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    return [
        'code' => $displayCode,
        'expires_at' => $expiresAt,
        'expires_in_seconds' => MATTERMOST_LINK_CODE_TTL_SECONDS,
    ];
}

function mattermostLinksForUser(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare(
        'SELECT id, instance_id, mattermost_user_id, mattermost_username,
                linked_at, last_used_at
         FROM mattermost_user_links
         WHERE user_id = ?
         ORDER BY linked_at DESC, id DESC'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare Mattermost account lookup.');
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $links = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $links;
}

function revokeMattermostLink(mysqli $conn, int $userId, int $linkId): bool
{
    $conn->begin_transaction();
    try {
        $lookup = $conn->prepare(
            'SELECT mattermost_username FROM mattermost_user_links
             WHERE id = ? AND user_id = ? FOR UPDATE'
        );
        if (!$lookup) {
            throw new RuntimeException('Unable to prepare Mattermost link revocation.');
        }
        $lookup->bind_param('ii', $linkId, $userId);
        $lookup->execute();
        $link = $lookup->get_result()->fetch_assoc();
        $lookup->close();
        if (!$link) {
            $conn->rollback();
            return false;
        }

        $delete = $conn->prepare(
            'DELETE FROM mattermost_user_links WHERE id = ? AND user_id = ?'
        );
        if (!$delete) {
            throw new RuntimeException('Unable to prepare Mattermost link revocation.');
        }
        $delete->bind_param('ii', $linkId, $userId);
        $delete->execute();
        $deleted = $delete->affected_rows === 1;
        $delete->close();
        if (!$deleted || !recordAuditEvent($conn, [
            'event_category' => 'security',
            'event_type' => 'mattermost_account_unlinked',
            'actor_user_id' => $userId,
            'target_user_id' => $userId,
            'entity_type' => 'mattermost_user_link',
            'entity_id' => $linkId,
            'entity_label' => (string) $link['mattermost_username'],
        ])) {
            throw new RuntimeException('Unable to audit Mattermost link revocation.');
        }
        $conn->commit();
        return true;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function redeemMattermostLinkCode(
    mysqli $conn,
    string $instanceId,
    string $mattermostUserId,
    string $mattermostUsername,
    string $code
): array {
    $normalizedCode = normalizeMattermostLinkCode($code);
    if (preg_match('/\A[A-HJ-NP-Z2-9]{8}\z/', $normalizedCode) !== 1) {
        throw new InvalidArgumentException('Enter a valid one-time link code.');
    }
    if ($instanceId !== mattermostIntegrationInstanceId()) {
        throw new InvalidArgumentException('This Mattermost instance is not authorized.');
    }
    if (preg_match('/\A[a-zA-Z0-9]{1,64}\z/', $mattermostUserId) !== 1) {
        throw new InvalidArgumentException('Mattermost did not provide a valid user identity.');
    }
    $mattermostUsername = trim($mattermostUsername);
    if ($mattermostUsername === '' || mb_strlen($mattermostUsername, 'UTF-8') > 64) {
        throw new InvalidArgumentException('Mattermost did not provide a valid username.');
    }
    $codeHash = hash('sha256', $normalizedCode, true);

    $conn->begin_transaction();
    try {
        $codeStmt = $conn->prepare(
            "SELECT code.id, code.user_id, users.username, users.role
             FROM mattermost_link_codes code
             INNER JOIN users ON users.id = code.user_id
             WHERE code.code_hash = ?
               AND code.consumed_at IS NULL
               AND code.expires_at > UTC_TIMESTAMP(6)
               AND users.account_status = 'active'
             FOR UPDATE"
        );
        if (!$codeStmt) {
            throw new RuntimeException('Unable to prepare the account-link request.');
        }
        $codeStmt->bind_param('s', $codeHash);
        $codeStmt->execute();
        $user = $codeStmt->get_result()->fetch_assoc();
        $codeStmt->close();
        if (!$user) {
            throw new InvalidArgumentException('That link code is invalid, expired, or already used.');
        }
        $userId = (int) $user['user_id'];

        $externalStmt = $conn->prepare(
            'SELECT id, user_id FROM mattermost_user_links
             WHERE instance_id = ? AND mattermost_user_id = ? FOR UPDATE'
        );
        if (!$externalStmt) {
            throw new RuntimeException('Unable to check the Mattermost identity.');
        }
        $externalStmt->bind_param('ss', $instanceId, $mattermostUserId);
        $externalStmt->execute();
        $externalLink = $externalStmt->get_result()->fetch_assoc();
        $externalStmt->close();
        if ($externalLink && (int) $externalLink['user_id'] !== $userId) {
            throw new InvalidArgumentException('That Mattermost account is already linked to another Moed user.');
        }

        $moedStmt = $conn->prepare(
            'SELECT id, mattermost_user_id FROM mattermost_user_links
             WHERE instance_id = ? AND user_id = ? FOR UPDATE'
        );
        if (!$moedStmt) {
            throw new RuntimeException('Unable to check the Moed identity.');
        }
        $moedStmt->bind_param('si', $instanceId, $userId);
        $moedStmt->execute();
        $moedLink = $moedStmt->get_result()->fetch_assoc();
        $moedStmt->close();
        if ($moedLink && !hash_equals((string) $moedLink['mattermost_user_id'], $mattermostUserId)) {
            throw new InvalidArgumentException('This Moed account is already linked to another Mattermost user.');
        }

        if ($externalLink) {
            $update = $conn->prepare(
                'UPDATE mattermost_user_links
                 SET mattermost_username = ?, last_used_at = UTC_TIMESTAMP(6)
                 WHERE id = ?'
            );
            if (!$update) {
                throw new RuntimeException('Unable to refresh the Mattermost account link.');
            }
            $linkId = (int) $externalLink['id'];
            $update->bind_param('si', $mattermostUsername, $linkId);
            $update->execute();
            $update->close();
        } else {
            $insert = $conn->prepare(
                'INSERT INTO mattermost_user_links (
                    instance_id, mattermost_user_id, mattermost_username, user_id,
                    last_used_at
                 ) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6))'
            );
            if (!$insert) {
                throw new RuntimeException('Unable to create the Mattermost account link.');
            }
            $insert->bind_param(
                'sssi',
                $instanceId,
                $mattermostUserId,
                $mattermostUsername,
                $userId
            );
            $insert->execute();
            $linkId = (int) $conn->insert_id;
            $insert->close();
        }

        $consume = $conn->prepare(
            'UPDATE mattermost_link_codes
             SET consumed_at = UTC_TIMESTAMP(6)
             WHERE id = ? AND consumed_at IS NULL'
        );
        if (!$consume) {
            throw new RuntimeException('Unable to consume the account-link code.');
        }
        $codeId = (int) $user['id'];
        $consume->bind_param('i', $codeId);
        $consume->execute();
        $consumed = $consume->affected_rows === 1;
        $consume->close();
        if (!$consumed || !recordAuditEvent($conn, [
            'event_category' => 'security',
            'event_type' => 'mattermost_account_linked',
            'actor_user_id' => $userId,
            'target_user_id' => $userId,
            'entity_type' => 'mattermost_user_link',
            'entity_id' => $linkId,
            'entity_label' => $mattermostUsername,
            'details' => 'Mattermost instance: ' . $instanceId,
        ])) {
            throw new RuntimeException('Unable to audit the Mattermost account link.');
        }
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    return [
        'id' => $userId,
        'username' => (string) $user['username'],
        'role' => (string) $user['role'],
    ];
}

function mattermostLinkedUser(
    mysqli $conn,
    string $instanceId,
    string $mattermostUserId,
    string $mattermostUsername = ''
): ?array {
    $stmt = $conn->prepare(
        "SELECT users.id, users.username, users.first_name, users.last_name,
                users.role, links.id AS link_id
         FROM mattermost_user_links links
         INNER JOIN users ON users.id = links.user_id
         WHERE links.instance_id = ?
           AND links.mattermost_user_id = ?
           AND users.account_status = 'active'"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare Mattermost identity lookup.');
    }
    $stmt->bind_param('ss', $instanceId, $mattermostUserId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) {
        return null;
    }

    $mattermostUsername = trim($mattermostUsername);
    $update = $conn->prepare(
        'UPDATE mattermost_user_links
         SET last_used_at = UTC_TIMESTAMP(6),
             mattermost_username = CASE WHEN ? = \'\' THEN mattermost_username ELSE ? END
         WHERE id = ?'
    );
    if ($update) {
        $linkId = (int) $user['link_id'];
        $update->bind_param('ssi', $mattermostUsername, $mattermostUsername, $linkId);
        $update->execute();
        $update->close();
    }
    unset($user['link_id']);
    $user['id'] = (int) $user['id'];
    $user['display_name'] = trim((string) $user['first_name'] . ' ' . (string) $user['last_name'])
        ?: (string) $user['username'];
    unset($user['first_name'], $user['last_name']);
    return $user;
}

function mattermostPublicUrl(string $path, array $query = []): string
{
    $baseUrl = rtrim(trim((string) (getenv('DNR_PUBLIC_BASE_URL') ?: '')), '/');
    $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
    if ($baseUrl === ''
        || !filter_var($baseUrl, FILTER_VALIDATE_URL)
        || !in_array($scheme, ['http', 'https'], true)
        || parse_url($baseUrl, PHP_URL_USER) !== null
        || parse_url($baseUrl, PHP_URL_PASS) !== null
        || parse_url($baseUrl, PHP_URL_QUERY) !== null
        || parse_url($baseUrl, PHP_URL_FRAGMENT) !== null
    ) {
        throw new RuntimeException('DNR_PUBLIC_BASE_URL is required for Mattermost links.');
    }
    $url = $baseUrl . '/' . ltrim($path, '/');
    return $query === [] ? $url : $url . '?' . http_build_query($query);
}

function mattermostTaskPayload(array $task, string $role): array
{
    $subject = followUpTaskSubjectFromRow($task);
    $allowedActions = [];
    if (canManageFollowUpTasks($role)) {
        if (empty($task['assigned_to'])) {
            $allowedActions[] = 'assign_to_me';
        }
        if ($task['status'] === 'open') {
            $allowedActions[] = 'start';
        }
        if (in_array($task['status'], ['open', 'in_progress', 'waiting'], true)) {
            $allowedActions[] = 'complete';
        }
        if (in_array($task['status'], ['completed', 'canceled'], true)) {
            $allowedActions[] = 'reopen';
        }
    }
    return [
        'id' => (int) $task['id'],
        'title' => (string) $task['title'],
        'details' => (string) ($task['details'] ?? ''),
        'status' => (string) $task['status'],
        'priority' => (string) $task['priority'],
        'due_date' => $task['due_date'] ?: null,
        'waiting_on' => $task['waiting_on'] ?: null,
        'assignee' => $task['assignee_username'] ?: null,
        'subject' => (string) $subject['label'],
        'updated_at' => (string) $task['updated_at'],
        'url' => mattermostPublicUrl((string) $subject['url']),
        'allowed_actions' => $allowedActions,
    ];
}

function mattermostTasksForUser(mysqli $conn, array $user, int $limit = 10): array
{
    $limit = max(1, min($limit, 25));
    $userId = (int) $user['id'];
    $sql = followUpTaskSelectSql()
        . " WHERE t.assigned_to = ?
              AND t.status IN ('open', 'in_progress', 'waiting')
            ORDER BY
              CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END,
              t.due_date ASC,
              FIELD(t.priority, 'urgent', 'high', 'normal', 'low'),
              t.id ASC
            LIMIT ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the Mattermost task list.');
    }
    $stmt->bind_param('ii', $userId, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(
        static fn(array $task): array => mattermostTaskPayload($task, (string) $user['role']),
        $rows
    );
}

function mattermostTaskSummaryForUser(mysqli $conn, int $userId): array
{
    $businessDate = applicationBusinessDate();
    $nextSevenDays = (new DateTimeImmutable($businessDate, new DateTimeZone('UTC')))
        ->modify('+7 days')
        ->format('Y-m-d');
    $stmt = $conn->prepare(
        "SELECT
            SUM(due_date < ?) AS overdue_count,
            SUM(due_date = ?) AS due_today_count,
            SUM(due_date > ? AND due_date <= ?) AS next_seven_days_count,
            SUM(status = 'waiting') AS waiting_count
         FROM follow_up_tasks
         WHERE assigned_to = ?
           AND status IN ('open', 'in_progress', 'waiting')"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the Mattermost task summary.');
    }
    $stmt->bind_param(
        'ssssi',
        $businessDate,
        $businessDate,
        $businessDate,
        $nextSevenDays,
        $userId
    );
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return [
        'overdue' => (int) ($summary['overdue_count'] ?? 0),
        'due_today' => (int) ($summary['due_today_count'] ?? 0),
        'next_seven_days' => (int) ($summary['next_seven_days_count'] ?? 0),
        'waiting' => (int) ($summary['waiting_count'] ?? 0),
    ];
}

function mattermostUpcomingEngagements(mysqli $conn, int $limit = 5): array
{
    $limit = max(1, min($limit, 15));
    $businessDate = applicationBusinessDate();
    $stmt = $conn->prepare(
        "SELECT e.id, e.event_title, e.event_start_date, e.event_end_date,
                e.event_type, e.event_type_other, e.confirmation_status,
                e.lifecycle_status, o.organization_name
         FROM engagements e
         INNER JOIN organizations o ON o.id = e.organization_id
         WHERE e.is_deleted = 0
           AND o.is_deleted = 0
           AND e.lifecycle_status = 'active'
           AND COALESCE(e.event_end_date, e.event_start_date) >= ?
         ORDER BY e.event_start_date ASC, e.id ASC
         LIMIT ?"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare upcoming engagements.');
    }
    $stmt->bind_param('si', $businessDate, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(static function (array $row): array {
        $row['id'] = (int) $row['id'];
        $row['title'] = trim((string) $row['event_title']) ?: (string) $row['organization_name'];
        $row['url'] = mattermostPublicUrl('view_engagement.php', ['id' => $row['id']]);
        unset($row['event_title']);
        return $row;
    }, $rows);
}

function mattermostSearchEngagements(mysqli $conn, string $query, int $limit = 10): array
{
    $query = trim($query);
    if (mb_strlen($query, 'UTF-8') < 2) {
        throw new InvalidArgumentException('Search terms must contain at least two characters.');
    }
    $limit = max(1, min($limit, 20));
    $like = '%' . addcslashes($query, '%_\\') . '%';
    $stmt = $conn->prepare(
        "SELECT e.id, e.event_title, e.event_start_date, e.event_end_date,
                e.confirmation_status, e.lifecycle_status, o.organization_name
         FROM engagements e
         INNER JOIN organizations o ON o.id = e.organization_id
         WHERE e.is_deleted = 0
           AND o.is_deleted = 0
           AND (e.event_title LIKE ? ESCAPE '\\\\'
                OR o.organization_name LIKE ? ESCAPE '\\\\')
         ORDER BY e.event_start_date DESC, e.id DESC
         LIMIT ?"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare engagement search.');
    }
    $stmt->bind_param('ssi', $like, $like, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(static function (array $row): array {
        $row['id'] = (int) $row['id'];
        $row['title'] = trim((string) $row['event_title']) ?: (string) $row['organization_name'];
        $row['url'] = mattermostPublicUrl('view_engagement.php', ['id' => $row['id']]);
        unset($row['event_title']);
        return $row;
    }, $rows);
}

function mattermostEngagement(mysqli $conn, int $engagementId): ?array
{
    $stmt = $conn->prepare(
        "SELECT e.id, e.event_title, e.event_description, e.event_start_date,
                e.event_end_date, e.event_type, e.event_type_other,
                e.confirmation_status, e.lifecycle_status,
                e.event_address_line_1, e.event_address_line_2,
                e.event_city, e.event_state, e.event_zipcode, e.event_country,
                o.organization_name
         FROM engagements e
         INNER JOIN organizations o ON o.id = e.organization_id
         WHERE e.id = ? AND e.is_deleted = 0 AND o.is_deleted = 0"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the engagement card.');
    }
    $stmt->bind_param('i', $engagementId);
    $stmt->execute();
    $engagement = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$engagement) {
        return null;
    }

    $presentationStmt = $conn->prepare(
        'SELECT topic_title, presentation_date, presentation_time, speaker_name
         FROM presentations
         WHERE engagement_id = ? AND is_archived = 0
         ORDER BY presentation_date ASC, presentation_time ASC, id ASC
         LIMIT 10'
    );
    if (!$presentationStmt) {
        throw new RuntimeException('Unable to prepare the presentation schedule.');
    }
    $presentationStmt->bind_param('i', $engagementId);
    $presentationStmt->execute();
    $presentations = $presentationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $presentationStmt->close();

    $taskStmt = $conn->prepare(
        "SELECT
            SUM(status IN ('open', 'in_progress', 'waiting')) AS active_count,
            SUM(status IN ('open', 'in_progress', 'waiting')
                AND due_date < ?) AS overdue_count,
            SUM(status IN ('open', 'in_progress', 'waiting')
                AND assigned_to IS NULL) AS unassigned_count
         FROM follow_up_tasks
         WHERE engagement_id = ?"
    );
    if (!$taskStmt) {
        throw new RuntimeException('Unable to prepare the engagement work summary.');
    }
    $businessDate = applicationBusinessDate();
    $taskStmt->bind_param('si', $businessDate, $engagementId);
    $taskStmt->execute();
    $taskSummary = $taskStmt->get_result()->fetch_assoc() ?: [];
    $taskStmt->close();

    $engagement['id'] = (int) $engagement['id'];
    $engagement['title'] = trim((string) $engagement['event_title'])
        ?: (string) $engagement['organization_name'];
    $engagement['url'] = mattermostPublicUrl('view_engagement.php', ['id' => $engagementId]);
    $engagement['presentations'] = $presentations;
    $engagement['task_summary'] = [
        'active' => (int) ($taskSummary['active_count'] ?? 0),
        'overdue' => (int) ($taskSummary['overdue_count'] ?? 0),
        'unassigned' => (int) ($taskSummary['unassigned_count'] ?? 0),
    ];
    unset($engagement['event_title']);
    return $engagement;
}

function mattermostIdempotentResponse(
    mysqli $conn,
    string $instanceId,
    string $key,
    int $userId,
    string $action
): ?array {
    if (preg_match('/\A[A-Za-z0-9._:-]{8,100}\z/', $key) !== 1) {
        throw new InvalidArgumentException('A valid idempotency key is required.');
    }
    $stmt = $conn->prepare(
        'SELECT user_id, action_name, response_json
         FROM mattermost_idempotency_keys
         WHERE instance_id = ? AND idempotency_key = ?
           AND expires_at > UTC_TIMESTAMP(6)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the idempotency lookup.');
    }
    $stmt->bind_param('ss', $instanceId, $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    if ((int) $row['user_id'] !== $userId || !hash_equals((string) $row['action_name'], $action)) {
        throw new InvalidArgumentException('That idempotency key was used for another action.');
    }
    $decoded = json_decode((string) $row['response_json'], true);
    return is_array($decoded) ? $decoded : null;
}

function storeMattermostIdempotentResponse(
    mysqli $conn,
    string $instanceId,
    string $key,
    int $userId,
    string $action,
    array $response
): void {
    $cleanup = $conn->prepare(
        'DELETE FROM mattermost_idempotency_keys
         WHERE expires_at <= UTC_TIMESTAMP(6)
         LIMIT 100'
    );
    if ($cleanup) {
        $cleanup->execute();
        $cleanup->close();
    }
    $json = json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + 86400);
    $stmt = $conn->prepare(
        'INSERT INTO mattermost_idempotency_keys (
            instance_id, idempotency_key, user_id, action_name,
            response_json, expires_at
         ) VALUES (?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the idempotency record.');
    }
    $stmt->bind_param('ssisss', $instanceId, $key, $userId, $action, $json, $expiresAt);
    $stmt->execute();
    $stmt->close();
}
