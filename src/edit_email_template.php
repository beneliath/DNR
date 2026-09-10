<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/email_template_helpers.php';
$conn = applicationDatabaseConnection();
startSecureSession();
requireLogin();
$canManage = hasRole(['admin', 'editor']);
$id = \Dnr\Http\RequestInput::positiveInt($_GET, 'id');
$engagementId = \Dnr\Http\RequestInput::positiveInt($_POST + $_GET, 'engagement_id');
$template = $id !== null ? fetchEmailMessageTemplate($conn, $id) : null;
if (isset($_GET['id']) && !$template) {
    http_response_code(404);
    exit('Email template not found.');
}
if ($id === null && !$canManage) {
    http_response_code(403);
    exit('Forbidden.');
}
$isArchived = !empty($template['is_archived']);
$readOnly = !$canManage || $isArchived;
$backUrl = 'email_templates.php?' . http_build_query(['status' => $isArchived ? 'archived' : 'active', 'engagement_id' => $engagementId]);
$form = $template ?? ['name' => '', 'subject_template' => '', 'body_template' => '', 'sort_order' => 60, 'version' => ''];
$selectedRoles = $template ? json_decode((string) $template['suggested_roles_json'], true) : [];
$selectedRoles = is_array($selectedRoles) ? $selectedRoles : [];
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    if (!$canManage) {
        http_response_code(403);
        exit('Forbidden.');
    }
    foreach (['name', 'subject_template', 'body_template', 'sort_order', 'version'] as $field) {
        $form[$field] = \Dnr\Http\RequestInput::string($_POST, $field);
    }
    $selectedRoles = is_array($_POST['suggested_roles'] ?? null) ? $_POST['suggested_roles'] : [];
    try {
        if ($isArchived) throw new InvalidArgumentException('Restore this email template before editing it.');
        saveEmailMessageTemplate($conn, $_POST, (int) $_SESSION['user_id'], $id, \Dnr\Http\RequestInput::positiveInt($_POST, 'version'));
        $_SESSION['email_template_message'] = $id === null ? 'Email template added.' : 'Email template updated. Changes apply to future messages.';
        header('Location: ' . $backUrl);
        exit();
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        applicationLog('error', 'Unable to save email template', ['error' => $exception->getMessage()]);
        $error = 'Unable to save the email template. Please try again.';
    }
}
$pageTitle = $id === null ? 'New Email Template' : ($readOnly ? 'View Email Template' : 'Edit Email Template');
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle($pageTitle), ['styles' => ['assets/css/style.min.css', 'assets/css/modern.min.css', 'assets/css/pages/email_templates.min.css'], 'scripts' => ['assets/js/email-template-editor.min.js']]); ?>
<body>
<?php include 'templates/header.php'; ?>
<main class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?php echo htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8'); ?>">Email Templates</a><span aria-hidden="true">/</span><span><?php echo $pageTitle; ?></span></nav>
    <div class="page-heading form-page-heading"><div><h1><?php echo $pageTitle; ?></h1><p class="page-intro">Set the starting subject, message, and suggested contacts for engagement emails.</p></div></div>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($isArchived): ?><p class="warning">This template is archived. Restore it from the template library before editing or using it.</p><?php endif; ?>
    <?php if (!$readOnly): ?><p class="required-fields-note"><span aria-hidden="true">*</span> Required fields</p><?php endif; ?>
    <form method="post" action="edit_email_template.php<?php echo $id !== null ? '?id=' . $id : ''; ?>" data-email-template-editor>
        <?php echo csrfInput(); ?>
        <input type="hidden" name="version" value="<?php echo htmlspecialchars((string) $form['version'], ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="engagement_id" value="<?php echo $engagementId ?? ''; ?>">
        <section class="form-section">
            <h2>Template Details</h2>
            <div class="form-group"><label for="template-name" class="required">Template name</label><input type="text" id="template-name" name="name" maxlength="100" required value="<?php echo htmlspecialchars((string) $form['name'], ENT_QUOTES, 'UTF-8'); ?>"<?php echo $readOnly ? ' readonly' : ''; ?>></div>
            <div class="form-group"><label for="template-subject" class="required">Subject</label><input type="text" id="template-subject" name="subject_template" maxlength="255" required value="<?php echo htmlspecialchars((string) $form['subject_template'], ENT_QUOTES, 'UTF-8'); ?>" data-template-subject<?php echo $readOnly ? ' readonly' : ''; ?>><p class="field-help">The engagement routing marker is added automatically when composing the email.</p></div>
            <div class="form-group"><label for="template-body" class="required">Plain-text message</label><textarea id="template-body" name="body_template" rows="14" maxlength="100000" required data-template-body<?php echo $readOnly ? ' readonly' : ''; ?>><?php echo htmlspecialchars((string) $form['body_template'], ENT_QUOTES, 'UTF-8'); ?></textarea></div>
            <details class="email-template-field-help">
                <summary>Personalize with event fields</summary>
                <p>These fields are filled from the engagement when you choose the template. Click in the subject or message, then choose a field to insert it.</p>
                <div class="email-template-fields">
                    <?php foreach (emailMessageTemplatePlaceholders() as $key => $label): ?>
                        <button type="button" class="button-secondary email-template-field" data-insert-email-field="<?php echo $key; ?>"<?php echo $readOnly ? ' disabled' : ''; ?>><span><?php echo $label; ?></span><small>{{<?php echo $key; ?>}}</small></button>
                    <?php endforeach; ?>
                </div>
                <p class="field-help" data-email-field-status role="status" aria-live="polite"></p>
            </details>
        </section>
        <section class="form-section">
            <h2>Recipient Suggestions</h2>
            <p class="field-help">Suggest event contacts when this template is selected. The sender can change recipients and include speakers before sending.</p>
            <fieldset class="email-template-roles"<?php echo $readOnly ? ' disabled' : ''; ?>><legend class="visually-hidden">Suggested event contacts</legend>
                <?php foreach (engagementContactRoles() as $role => $label): ?><label><input type="checkbox" name="suggested_roles[]" value="<?php echo $role; ?>"<?php echo in_array($role, $selectedRoles, true) ? ' checked' : ''; ?>><span><?php echo $label; ?></span></label><?php endforeach; ?>
            </fieldset>
            <div class="form-group"><label for="template-order">Display order</label><input type="number" id="template-order" name="sort_order" min="0" max="65535" step="1" required value="<?php echo htmlspecialchars((string) $form['sort_order'], ENT_QUOTES, 'UTF-8'); ?>"<?php echo $readOnly ? ' readonly' : ''; ?>><p class="field-help">Lower numbers appear first in the template library and email composer.</p></div>
        </section>
        <div class="engagement-page-actions">
            <a href="<?php echo htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8'); ?>" class="cancel-button"><?php echo $readOnly ? 'Back to Templates' : 'Cancel'; ?></a>
            <?php if (!$readOnly): ?><button type="submit" class="save-button"><?php echo $id === null ? 'Create Template' : 'Save Changes'; ?></button><?php endif; ?>
        </div>
    </form>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
