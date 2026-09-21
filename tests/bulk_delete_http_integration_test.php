<?php

declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
    || getenv('DNR_TEST_BASE_URL') === false) {
    echo "Bulk deletion HTTP integration tests skipped (requires disposable database and HTTP server).\n";
    exit(0);
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/bootstrap.php';
require_once __DIR__ . '/integration_auth_helpers.php';
function expectBulkHttp(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function bulkHttp(string $path, ?array $session = null, ?array $post = null): array {
    $curl = curl_init(rtrim((string) getenv('DNR_TEST_BASE_URL'), '/') . '/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_COOKIE => 'PHPSESSID=' . ($session[0] ?? '')]);
    if ($post !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    $response = curl_exec($curl);
    if (!is_string($response)) throw new RuntimeException(curl_error($curl));
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $size);
    preg_match('/^Location: (.+)$/mi', $headers, $location);
    $result = ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'location' => trim($location[1] ?? ''), 'body' => substr($response, $size)];
    return $result;
}
function bulkSession(int $id, string $role): array {
    session_start();
    $csrf = bin2hex(random_bytes(32));
    $_SESSION = ['user_id' => $id, 'role' => $role, 'username' => 'bulk-' . $id,
        'auth_version' => 1, 'auth_complete' => true, '_csrf_token' => $csrf];
    completeIntegrationTestMfaSession();
    $session = [session_id(), $csrf];
    session_write_close(); session_id('');
    return $session;
}
function bulkElevation(array $session, ?int $timestamp): void {
    session_id($session[0]); session_start();
    if ($timestamp === null) unset($_SESSION['_admin_elevated_at']);
    else $_SESSION['_admin_elevated_at'] = $timestamp;
    session_write_close(); session_id('');
}
function bulkSelection(string $entity, array $ids, array $session, string $return): array {
    $review = bulkHttp('bulk_delete.php', $session, ['csrf_token' => $session[1], 'action' => 'review',
        'entity' => $entity, 'selected_ids' => $ids, 'return_to' => $return]);
    expectBulkHttp($review['status'] === 303, 'A selection must open the review: ' . $review['body']);
    parse_str((string) parse_url($review['location'], PHP_URL_QUERY), $query);
    return [$review['location'], $query['selection']];
}
$suffix = bin2hex(random_bytes(5));
$fixtures = [];
$sessions = [];
$insert = static function (string $table, string $sql, array $params = []) use ($conn, &$fixtures): int {
    $conn->execute_query($sql, $params);
    $id = (int) $conn->insert_id;
    $fixtures[$table][] = $id;
    return $id;
};
$exists = static fn(string $table, int $id): bool => $conn->execute_query("SELECT id FROM {$table} WHERE id = ?", [$id])->num_rows === 1;
try {
    foreach (['admin', 'editor', 'reviewer'] as $role) {
        $id = $insert('users', 'INSERT INTO users (username, password, role) VALUES (?, ?, ?)',
            ['bulk-' . $role . '-' . $suffix, password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT), $role]);
        $sessions[$role] = bulkSession($id, $role);
        if ($role === 'admin') $adminId = $id;
    }
    $admin = $sessions['admin'];
    expectBulkHttp(bulkHttp('bulk_delete.php')['status'] === 302, 'Login is required.');
    foreach (['editor', 'reviewer'] as $role) {
        $session = $sessions[$role];
        expectBulkHttp(bulkHttp('bulk_delete.php', $session, ['csrf_token' => $session[1], 'action' => 'review', 'entity' => 'task', 'selected_ids' => [1]])['status'] === 403, 'Non-admin roles cannot bulk delete.');
        foreach (['organizations.php' => 'organization_id', 'contacts.php' => 'contact_id', 'engagements.php' => 'engagement_id', 'tasks.php' => 'task_id'] as $path => $idField) {
            $page = bulkHttp($path, $session);
            expectBulkHttp(!str_contains($page['body'], 'data-bulk-delete') && !str_contains($page['body'], 'aria-label="Delete '), 'Non-admin lists hide both deletion options.');
            expectBulkHttp(bulkHttp($path, $session, ['csrf_token' => $session[1], 'action' => 'delete', $idField => 1])['status'] === 403, 'Forged individual deletes require admin: ' . $path);
        }
        expectBulkHttp(bulkHttp('delete_user.php', $session, ['csrf_token' => $session[1], 'id' => 1, 'delete_confirmation' => 'DELETE USER'])['status'] === 403, 'Individual user deletion requires admin.');
        expectBulkHttp(!str_contains(bulkHttp('speakers.php', $session)['body'], 'data-bulk-delete'), 'Editors/reviewers must not see selection controls.');
    }
    expectBulkHttp(bulkHttp('bulk_delete.php', $admin, ['csrf_token' => 'bad'])['status'] === 400, 'CSRF is required.');
    foreach ([[], ['1x'], ['a' => 1], range(1, 101)] as $ids) {
        expectBulkHttp(bulkHttp('bulk_delete.php', $admin, ['csrf_token' => $admin[1], 'action' => 'review', 'entity' => 'task', 'selected_ids' => $ids])['status'] === 400, 'Malformed/empty/oversized selections are rejected.');
    }
    $org = $insert('organizations', 'INSERT INTO organizations (organization_name) VALUES (?)', ['Bulk parent ' . $suffix]);
    foreach (['organization', 'contact', 'speaker', 'engagement', 'task'] as $entity) {
        $table = ['organization' => 'organizations', 'contact' => 'contacts', 'speaker' => 'speakers', 'engagement' => 'engagements', 'task' => 'follow_up_tasks'][$entity];
        $ids = [];
        for ($n = 0; $n < 3; $n++) {
            $name = 'Bulk ' . $entity . ' ' . $n . ' ' . $suffix;
            $ids[] = match ($entity) {
                'organization' => $insert($table, 'INSERT INTO organizations (organization_name) VALUES (?)', [$name]),
                'contact' => $insert($table, "INSERT INTO contacts (organization_id, contact_first_name, contact_last_name, contact_role, contact_email) VALUES (?, ?, 'Test', 'admin', 'bulk@example.test')", [$org, $name]),
                'speaker' => $insert($table, "INSERT INTO speakers (name, email, phone) VALUES (?, 'bulk@example.test', '+19494002892')", [$name]),
                'engagement' => $insert($table, "INSERT INTO engagements (organization_id, event_title, event_start_date, event_end_date, event_type, confirmation_status) VALUES (?, ?, '2026-10-01', '2026-10-02', 'conference', 'under_review')", [$org, $name]),
                'task' => $insert($table, "INSERT INTO follow_up_tasks (title, subject_type) VALUES (?, 'general')", [$name]),
            };
        }
        $page = $entity === 'organization' ? 'organizations.php' : $entity . 's.php';
        $list = bulkHttp($page . ($entity === 'task' ? '?scope=everyone' : ''), $admin);
        expectBulkHttp($list['status'] === 200 && str_contains($list['body'], 'data-bulk-item'), 'Admin list has checkboxes: ' . $page);
        expectBulkHttp(str_contains($list['body'], 'aria-label="Delete '), 'Individual deletion remains available: ' . $page);
        $return = $page . '?page=2&per_page=20';
        bulkElevation($admin, null);
        [$url, $selection] = bulkSelection($entity, [$ids[0], $ids[1], $ids[0]], $admin, $return);
        expectBulkHttp(str_starts_with(bulkHttp($url, $admin)['location'], 'admin_elevation.php?'), 'Locked review prompts for unlock.');
        $delete = ['csrf_token' => $admin[1], 'action' => 'delete', 'selection' => $selection];
        expectBulkHttp(str_starts_with(bulkHttp('bulk_delete.php', $admin, $delete)['location'], 'admin_elevation.php?') && $exists($table, $ids[0]), 'Forged direct deletion is blocked while locked.');
        bulkElevation($admin, time() - 30);
        $review = bulkHttp($url, $admin);
        expectBulkHttp($review['status'] === 200 && str_contains($review['body'], '2 ' . $entity) && $exists($table, $ids[0]), 'Review preserves the deduplicated selection through unlock without deleting.');
        expectBulkHttp(bulkHttp('bulk_delete.php', $admin, array_replace($delete, ['csrf_token' => 'bad']))['status'] === 400, 'Confirmation needs CSRF.');
        bulkElevation($admin, time() - 301);
        expectBulkHttp(str_starts_with(bulkHttp('bulk_delete.php', $admin, $delete)['location'], 'admin_elevation.php?') && $exists($table, $ids[0]), 'Expiry between review and confirmation blocks deletion.');
        bulkElevation($admin, time() - 30);
        $done = bulkHttp('bulk_delete.php', $admin, $delete);
        expectBulkHttp($done['status'] === 303 && $done['location'] === $return, 'Deletion preserves pagination/filter context.');
        expectBulkHttp(!$exists($table, $ids[0]) && !$exists($table, $ids[1]) && $exists($table, $ids[2]), 'Only selected records are deleted: ' . $entity);
        expectBulkHttp(bulkHttp('bulk_delete.php', $admin, $delete)['status'] === 400, 'A consumed selection cannot be replayed.');
        [$cancelUrl, $cancelId] = bulkSelection($entity, [$ids[2]], $admin, $return);
        bulkHttp('bulk_delete.php', $admin, ['csrf_token' => $admin[1], 'action' => 'cancel', 'selection' => $cancelId]);
        expectBulkHttp($exists($table, $ids[2]) && bulkHttp($cancelUrl, $admin)['status'] === 400, 'Cancel keeps records and invalidates the selection.');
    }
    // Retained speaker attribution blocks deletion, even alongside an unused speaker.
    $linked = $insert('speakers', "INSERT INTO speakers (name, email, phone) VALUES ('Linked speaker', 'linked@example.test', '+19494002892')");
    $unused = $insert('speakers', "INSERT INTO speakers (name, email, phone) VALUES ('Unused speaker', 'unused@example.test', '+19494002892')");
    $event = end($fixtures['engagements']);
    $presentation = $insert('presentations', 'INSERT INTO presentations (engagement_id, topic_title, speaker_id) VALUES (?, ?, ?)', [$event, 'Protected talk', $linked]);
    [$url, $selection] = bulkSelection('speaker', [$linked, $unused], $admin, 'speakers.php');
    expectBulkHttp(str_contains(bulkHttp($url, $admin)['body'], 'Kept:'), 'Review identifies protected speakers.');
    bulkHttp('bulk_delete.php', $admin, ['csrf_token' => $admin[1], 'action' => 'delete', 'selection' => $selection]);
    expectBulkHttp($exists('speakers', $linked) && !$exists('speakers', $unused) && $exists('presentations', $presentation), 'Linked history remains; unused speakers can be deleted.');
    $feedback = bulkHttp('speakers.php', $admin)['body'];
    expectBulkHttp(str_contains($feedback, '1 of 2 selected speakers permanently deleted.') && str_contains($feedback, 'could not be deleted'), 'Partial failures have accurate feedback.');
    expectBulkHttp($conn->query("SELECT id FROM security_audit_log WHERE entity_type = 'speakers' AND entity_id = {$unused} AND event_type = 'database_delete'")->num_rows === 1, 'Speaker deletion is audited.');

    $inactive = [];
    for ($i = 0; $i < 3; $i++) $inactive[] = $insert('users', "INSERT INTO users (username, password, role) VALUES (?, 'unused-hash', 'reviewer')", ['bulk-inactive-' . $suffix . '-' . $i]);
    $deadlineStart = time() - 90;
    bulkElevation($admin, $deadlineStart);
    foreach ($inactive as $id) {
        expectBulkHttp(bulkHttp('user_lifecycle.php', $admin, ['csrf_token' => $admin[1], 'action' => 'deactivate', 'id' => $id])['status'] === 302, 'Accounts can be deactivated sequentially.');
        $status = json_decode(bulkHttp('admin_unlock_status.php', $admin)['body'], true);
        expectBulkHttp($status['unlocked'] === true && (int) $status['expires_at'] === $deadlineStart + 300, 'Deactivation preserves the exact original unlock deadline.');
    }
    bulkElevation($admin, null);
    $usersPage = bulkHttp('users.php?per_page=100', $admin)['body'];
    expectBulkHttp(str_contains($usersPage, '>Delete user</button>') && str_contains($usersPage, 'data-bulk-item'), 'Locked admins can discover individual and bulk user deletion.');
    $individual = ['csrf_token' => $admin[1], 'id' => $inactive[0], 'delete_confirmation' => 'DELETE USER'];
    expectBulkHttp(str_starts_with(bulkHttp('delete_user.php', $admin, $individual)['location'], 'admin_elevation.php?'), 'Individual user deletion requires unlock.');
    bulkElevation($admin, $deadlineStart);
    expectBulkHttp(bulkHttp('delete_user.php', $admin, array_replace($individual, ['delete_confirmation' => 'wrong']))['status'] === 400, 'Individual user deletion requires its phrase.');
    bulkHttp('delete_user.php', $admin, $individual);
    expectBulkHttp(!$exists('users', $inactive[0]), 'Individual inactive user deletion works.');
    [$url, $selection] = bulkSelection('user', [$inactive[1], $inactive[2], $adminId], $admin, 'users.php?per_page=100');
    $review = bulkHttp($url, $admin);
    $dom = new DOMDocument(); @$dom->loadHTML($review['body']); $xpath = new DOMXPath($dom);
    $confirm = $xpath->query('//form[@id="bulk-delete-confirm-form"]')->item(0);
    expectBulkHttp($confirm instanceof DOMElement, 'User deletion has a dedicated confirmation form.');
    $submitters = $xpath->query('.//button[@type="submit" and not(@form)]', $confirm);
    expectBulkHttp($submitters->length === 1 && $submitters->item(0)->hasAttribute('data-confirm'),
        'Enter in the phrase field must default to the confirmed Delete action, never Cancel.');
    expectBulkHttp($xpath->query('.//input[@name="action" and @value="delete"]', $confirm)->length === 1
        && $xpath->query('//button[@form="bulk-delete-cancel-form"]')->length === 1,
        'Deletion action is submitted reliably; cancellation belongs to its own form.');
    $delete = ['csrf_token' => $admin[1], 'action' => 'delete', 'selection' => $selection];
    expectBulkHttp(bulkHttp('bulk_delete.php', $admin, $delete)['status'] === 400 && $exists('users', $inactive[1]), 'Bulk user deletion requires its typed phrase.');
    bulkHttp('bulk_delete.php', $admin, $delete + ['delete_confirmation' => 'DELETE USERS']);
    expectBulkHttp(!$exists('users', $inactive[1]) && !$exists('users', $inactive[2]) && $exists('users', $adminId), 'Bulk deletion removes inactive users and protects the current active admin.');
    $status = json_decode(bulkHttp('admin_unlock_status.php', $admin)['body'], true);
    expectBulkHttp($status['unlocked'] === true && (int) $status['expires_at'] === $deadlineStart + 300, 'Individual and bulk user deletion preserve the countdown.');
} finally {
    foreach (['presentations', 'follow_up_tasks', 'engagements', 'contacts', 'organizations', 'speakers', 'users'] as $table) {
        foreach ($fixtures[$table] ?? [] as $id) $conn->execute_query("DELETE FROM {$table} WHERE id = ?", [$id]);
    }
    foreach ($sessions as $session) { session_id($session[0]); session_start(); $_SESSION = []; session_destroy(); session_id(''); }
}
echo "Bulk deletion HTTP integration tests passed.\n";
