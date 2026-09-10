<?php

declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Email template HTTP tests skipped (requires a disposable database and HTTP server).\n";
    exit(0);
}
$sourceDirectory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $sourceDirectory . '/bootstrap.php';
require_once $sourceDirectory . '/email_template_helpers.php';
$baseUrl = rtrim((string) (getenv('DNR_TEST_BASE_URL') ?: 'http://127.0.0.1:8080'), '/');
if (!in_array(parse_url($baseUrl, PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) throw new RuntimeException('Use a loopback test server.');
function expectTemplateHttp(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('Email template HTTP test failed: ' . $message);
}
$request = static function (string $path, ?array $post = null, string $cookie = '') use ($baseUrl): array {
    $curl = curl_init($baseUrl . '/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIE => $cookie]);
    if ($post !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    $response = curl_exec($curl);
    if (!is_string($response)) throw new RuntimeException(curl_error($curl));
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($response, 0, $headerSize), 'body' => substr($response, $headerSize)];
};
$users = [];
$sessions = [];
$templateId = 0;
$engagementId = 0;
$organizationId = 0;
try {
    expectTemplateHttp($request('email_templates.php')['status'] === 302, 'anonymous visitors must sign in');
    foreach (['editor', 'reviewer', 'admin'] as $role) {
        $name = 'template-http-' . bin2hex(random_bytes(5));
        $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $stmt = $conn->prepare('INSERT INTO users (username, password, role) VALUES (?, ?, ?)');
        $stmt->bind_param('sss', $name, $hash, $role);
        $stmt->execute();
        $users[$role] = (int) $conn->insert_id;
        session_id('');
        startSecureSession();
        $_SESSION = ['user_id' => $users[$role], 'username' => $name, 'role' => $role, 'authenticated_role' => $role,
            'auth_version' => 1, 'auth_complete' => true, '_csrf_token' => bin2hex(random_bytes(32))];
        $sessions[$role] = ['cookie' => session_name() . '=' . session_id(), 'id' => session_id(), 'csrf' => $_SESSION['_csrf_token']];
        session_write_close();
    }
    $editor = $sessions['editor'];
    $input = ['csrf_token' => $editor['csrf'], 'name' => 'HTTP <Template>', 'subject_template' => 'Welcome {{event_name}}',
        'body_template' => 'Thank you, {{organization_name}}', 'suggested_roles' => ['primary_host'], 'sort_order' => '1'];
    expectTemplateHttp($request('edit_email_template.php', array_replace($input, ['csrf_token' => 'invalid']), $editor['cookie'])['status'] === 400, 'creation must validate CSRF');
    $invalid = $request('edit_email_template.php', array_replace($input, ['body_template' => '{{unknown}}']), $editor['cookie']);
    expectTemplateHttp($invalid['status'] === 200 && str_contains($invalid['body'], 'Unknown field') && str_contains($invalid['body'], 'HTTP &lt;Template&gt;'), 'invalid fields must preserve the safely escaped draft');
    expectTemplateHttp($request('edit_email_template.php', $input, $editor['cookie'])['status'] === 302, 'editors can add templates');
    $templateId = (int) $conn->query('SELECT id FROM email_message_templates WHERE created_by = ' . $users['editor'])->fetch_assoc()['id'];
    $templateKey = (string) fetchEmailMessageTemplate($conn, $templateId)['template_key'];
    $conn->query("INSERT INTO organizations (organization_name) VALUES ('Template HTTP Host')");
    $organizationId = (int) $conn->insert_id;
    $conn->query("INSERT INTO engagements (organization_id, event_title, event_type, confirmation_status, lifecycle_status, event_start_date, event_end_date)
        VALUES ({$organizationId}, 'Template HTTP Event', 'conference', 'confirmed', 'active', '2026-10-10', '2026-10-10')");
    $engagementId = (int) $conn->insert_id;
    $composePath = 'compose_engagement_email.php?id=' . $engagementId . '&template=' . $templateKey;
    $compose = $request($composePath, null, $editor['cookie']);
    expectTemplateHttp($compose['status'] === 200 && str_contains($compose['body'], 'Welcome Template HTTP Event')
        && str_contains($compose['body'], 'Thank you, Template HTTP Host')
        && str_contains($compose['body'], 'Manage Email Templates'), 'the web composer must offer and render stored templates');
    $path = 'edit_email_template.php?id=' . $templateId;
    $form = $request($path, null, $editor['cookie']);
    expectTemplateHttp($form['status'] === 200 && str_contains($form['body'], 'HTTP &lt;Template&gt;'), 'saved names should remain escaped');
    $edit = array_replace($input, ['version' => '1', 'name' => 'Updated template']);
    expectTemplateHttp($request($path, $edit, $editor['cookie'])['status'] === 302, 'editors can update active templates');
    $stale = $request($path, array_replace($edit, ['name' => 'Stale draft']), $editor['cookie']);
    expectTemplateHttp($stale['status'] === 200 && str_contains($stale['body'], 'another session')
        && str_contains($stale['body'], 'value="1"') && fetchEmailMessageTemplate($conn, $templateId)['name'] === 'Updated template', 'stale updates must retain the old version and not overwrite newer data');
    $action = ['csrf_token' => $editor['csrf'], 'template_id' => $templateId, 'version' => '2', 'status' => 'active', 'action' => 'archive'];
    $reviewer = $sessions['reviewer'];
    expectTemplateHttp($request('edit_email_template.php', array_replace($input, ['csrf_token' => $reviewer['csrf']]), $reviewer['cookie'])['status'] === 403, 'reviewers cannot create');
    expectTemplateHttp($request($path, array_replace($edit, ['csrf_token' => $reviewer['csrf']]), $reviewer['cookie'])['status'] === 403, 'reviewers cannot edit');
    expectTemplateHttp($request('email_templates.php', array_replace($action, ['csrf_token' => $reviewer['csrf']]), $reviewer['cookie'])['status'] === 403, 'reviewers cannot archive');
    expectTemplateHttp($request('email_templates.php', array_replace($action, ['csrf_token' => 'invalid']), $editor['cookie'])['status'] === 400, 'archiving must validate CSRF');
    $request('email_templates.php', $action, $editor['cookie']);
    expectTemplateHttp((int) fetchEmailMessageTemplate($conn, $templateId)['is_archived'] === 1, 'archive must persist');
    $compose = $request($composePath, null, $editor['cookie']);
    expectTemplateHttp($compose['status'] === 200 && !str_contains($compose['body'], 'value="' . $templateKey . '"'), 'archived templates should not be offered for composing');
    $staleCompose = $request('compose_engagement_email.php', ['csrf_token' => $editor['csrf'], 'id' => $engagementId,
        'template_key' => $templateKey, 'subject' => 'My reviewed subject', 'body' => 'My reviewed draft'], $editor['cookie']);
    expectTemplateHttp($staleCompose['status'] === 200 && str_contains($staleCompose['body'], 'Your draft is preserved')
        && str_contains($staleCompose['body'], 'My reviewed draft') && str_contains($staleCompose['body'], 'value="custom" selected'), 'a stale composer must preserve the draft and require review before sending');
    $archived = $request($path, null, $editor['cookie']);
    expectTemplateHttp(str_contains($archived['body'], 'This template is archived') && !str_contains($archived['body'], '>Save Changes</button>'), 'archived templates should be view-only');
    $delete = array_replace($action, ['version' => '3', 'status' => 'archived', 'action' => 'delete']);
    expectTemplateHttp($request('email_templates.php', $delete, $editor['cookie'])['status'] === 403, 'editors cannot permanently delete');
    $request('email_templates.php', array_replace($delete, ['action' => 'restore']), $editor['cookie']);
    expectTemplateHttp((int) fetchEmailMessageTemplate($conn, $templateId)['is_archived'] === 0, 'editors can restore');
    $request('email_templates.php', array_replace($action, ['version' => '4']), $editor['cookie']);
    $admin = $sessions['admin'];
    $delete = array_replace($delete, ['version' => '5', 'csrf_token' => $admin['csrf']]);
    $gate = $request('email_templates.php', $delete, $admin['cookie']);
    expectTemplateHttp($gate['status'] === 302 && str_contains($gate['headers'], 'admin_elevation.php') && fetchEmailMessageTemplate($conn, $templateId) !== null, 'deletion must require recent admin elevation');
    session_id($admin['id']);
    startSecureSession();
    $_SESSION['_admin_elevated_at'] = time();
    session_write_close();
    expectTemplateHttp($request('email_templates.php', $delete, $admin['cookie'])['status'] === 302 && fetchEmailMessageTemplate($conn, $templateId) === null, 'elevated administrators can permanently delete an archived template');
} finally {
    setDatabaseAuditContext($conn);
    if ($engagementId) $conn->query("DELETE FROM engagements WHERE id = {$engagementId}");
    if ($organizationId) $conn->query("DELETE FROM organizations WHERE id = {$organizationId}");
    if ($templateId) $conn->query("DELETE FROM email_message_templates WHERE id = {$templateId}");
    foreach ($users as $userId) $conn->query("DELETE FROM users WHERE id = {$userId}");
    foreach ($sessions as $session) {
        session_id($session['id']);
        startSecureSession();
        session_destroy();
    }
}
echo "Email template HTTP integration tests passed.\n";
