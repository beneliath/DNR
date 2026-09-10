<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Email template integration tests skipped (requires a disposable database).\n";
    exit(0);
}
$sourceDirectory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
putenv('DNR_INBOUND_ROUTING_KEY=' . base64_encode(str_repeat('R', 32)));
require_once $sourceDirectory . '/bootstrap.php';
require_once $sourceDirectory . '/engagement_email_helpers.php';
function expectTemplateIntegration(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('Email template integration failed: ' . $message);
}
$userId = 0;
$templateId = 0;
try {
    $name = 'template-test-' . bin2hex(random_bytes(5));
    $password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'editor')");
    $stmt->bind_param('ss', $name, $password);
    $stmt->execute();
    $userId = (int) $conn->insert_id;
    setDatabaseAuditContext($conn, $userId, $name);
    $input = ['name' => 'Test template ' . $name, 'subject_template' => 'Welcome to {{event_name}}',
        'body_template' => "{{organization_name}}\n{{speaker_names}}\n{{presentation_schedule}}", 'suggested_roles' => ['travel'], 'sort_order' => '2'];
    $templateId = saveEmailMessageTemplate($conn, $input, $userId);
    $template = fetchEmailMessageTemplate($conn, $templateId);
    expectTemplateIntegration($template !== null && (int) $template['version'] === 1, 'new templates should be persisted');
    $key = $template['template_key'];
    $event = ['id' => 1, 'event_title' => 'Example Event', 'organization_name' => 'Example Host'];
    $presentations = [['topic_title' => 'Opening Session', 'speaker_name' => 'Casey'], ['topic_title' => 'Closing Session', 'speaker_name' => 'Casey']];
    $rendered = renderEngagementEmailTemplates($event, $presentations, fetchEmailMessageTemplates($conn));
    expectTemplateIntegration(isset($rendered[$key]) && str_contains($rendered[$key]['subject'], 'Example Event')
        && str_contains($rendered[$key]['body'], 'Opening Session') && substr_count($rendered[$key]['body'], 'Casey') === 1
        && $rendered[$key]['suggested_roles'] === ['travel'], 'saved templates should render event fields and suggested contacts');
    $input['name'] = 'Edited ' . $name;
    saveEmailMessageTemplate($conn, $input, $userId, $templateId, 1);
    expectTemplateIntegration(fetchEmailMessageTemplate($conn, $templateId)['name'] === $input['name'], 'edits should persist');
    try {
        saveEmailMessageTemplate($conn, array_replace($input, ['name' => 'Stale overwrite']), $userId, $templateId, 1);
        expectTemplateIntegration(false, 'stale edits must be rejected');
    } catch (InvalidArgumentException) {}
    changeEmailMessageTemplateStatus($conn, $templateId, 2, 'archive', $userId);
    expectTemplateIntegration(!isset(renderEngagementEmailTemplates($event, [], fetchEmailMessageTemplates($conn))[$key]), 'archived templates should disappear from the composer');
    try {
        $conn->begin_transaction();
        emailMessageTemplateLabelForSend($conn, $key);
        expectTemplateIntegration(false, 'an archived template must not be accepted for new mail');
    } catch (InvalidArgumentException) {
        $conn->rollback();
    }
    try {
        saveEmailMessageTemplate($conn, $input, $userId, $templateId, 3);
        expectTemplateIntegration(false, 'archived templates must be restored before editing');
    } catch (InvalidArgumentException) {}
    changeEmailMessageTemplateStatus($conn, $templateId, 3, 'restore', $userId);
    expectTemplateIntegration(isset(renderEngagementEmailTemplates($event, [], fetchEmailMessageTemplates($conn))[$key]), 'restoring should return the template to the composer');
    try {
        changeEmailMessageTemplateStatus($conn, $templateId, 4, 'delete', $userId);
        expectTemplateIntegration(false, 'active templates must not be permanently deleted');
    } catch (InvalidArgumentException) {}
    changeEmailMessageTemplateStatus($conn, $templateId, 4, 'archive', $userId);
    changeEmailMessageTemplateStatus($conn, $templateId, 5, 'delete', $userId);
    expectTemplateIntegration(fetchEmailMessageTemplate($conn, $templateId) === null, 'archived templates should be deleted');
    $audit = $conn->query("SELECT event_type FROM security_audit_log WHERE entity_type = 'email_message_templates' AND entity_id = {$templateId}")->fetch_all(MYSQLI_ASSOC);
    expectTemplateIntegration(count($audit) === 6, 'create, edit, archive, restore, archive, and delete should all be audited');
    expectTemplateIntegration(array_keys(renderEngagementEmailTemplates($event, [], [])) === ['custom'], 'writing from scratch must remain available when every template is archived');
} finally {
    setDatabaseAuditContext($conn);
    if ($templateId) $conn->query("DELETE FROM email_message_templates WHERE id = {$templateId}");
    if ($userId) $conn->query("DELETE FROM users WHERE id = {$userId}");
}
echo "Email template integration tests passed.\n";
