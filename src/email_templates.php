<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/email_template_helpers.php';
require_once __DIR__ . '/two_factor_helpers.php';
$conn = applicationDatabaseConnection();
startSecureSession();
requireLogin();
$canManage = hasRole(['admin', 'editor']);
$canDelete = hasRole(['admin']);
$status = \Dnr\Http\RequestInput::enum($_POST + $_GET, 'status', ['active', 'archived'], 'active');
$archived = $status === 'archived' ? 1 : 0;
$engagementId = \Dnr\Http\RequestInput::positiveInt($_POST + $_GET, 'engagement_id');
$search = \Dnr\Http\RequestInput::string($_GET, 'q', '', 255);
$listUrl = 'email_templates.php?' . http_build_query(['status' => $status, 'engagement_id' => $engagementId]);
$contextQuery = $engagementId !== null ? '&engagement_id=' . $engagementId : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = \Dnr\Http\RequestInput::string($_POST, 'action');
    if (!$canManage || ($action === 'delete' && !$canDelete)) {
        http_response_code(403);
        exit('Forbidden.');
    }
    if ($action === 'delete') requireRecentAdminElevation($listUrl);
    try {
        changeEmailMessageTemplateStatus(
            $conn,
            \Dnr\Http\RequestInput::positiveInt($_POST, 'template_id') ?? 0,
            \Dnr\Http\RequestInput::positiveInt($_POST, 'version') ?? 0,
            $action,
            (int) $_SESSION['user_id']
        );
        $_SESSION['email_template_message'] = match ($action) {
            'archive' => 'Email template archived. It is no longer offered for new messages.',
            'restore' => 'Email template restored. It is available for new messages.',
            default => 'Email template permanently deleted. Sent messages and their history remain available.',
        };
    } catch (InvalidArgumentException $exception) {
        $_SESSION['email_template_error'] = $exception->getMessage();
    } catch (Throwable $exception) {
        applicationLog('error', 'Unable to update email template', ['error' => $exception->getMessage()]);
        $_SESSION['email_template_error'] = 'Unable to update the email template. Please try again.';
    }
    header('Location: ' . $listUrl);
    exit();
}
$message = (string) ($_SESSION['email_template_message'] ?? '');
$error = (string) ($_SESSION['email_template_error'] ?? '');
unset($_SESSION['email_template_message'], $_SESSION['email_template_error']);
$pageSize = paginationPageSizePreference('email_templates', $_GET['per_page'] ?? null, 20, [20, 50, 100]);
$from = 'FROM email_message_templates WHERE is_archived = ? AND (LOCATE(?, name) > 0 OR LOCATE(?, subject_template) > 0)';
$pagination = queryPagination($conn, $from, 'iss', [$archived, $search, $search], $pageSize, $_GET['page'] ?? null);
$offset = $pagination['offset'];
$stmt = $conn->prepare("SELECT id, name, subject_template, suggested_roles_json, sort_order, version, updated_at {$from} ORDER BY sort_order, name, id LIMIT ? OFFSET ?");
if (!$stmt) abortApplication(503, 'Email templates are temporarily unavailable.');
$stmt->bind_param('issii', $archived, $search, $search, $pageSize, $offset);
$stmt->execute();
$templates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$countResult = $conn->query('SELECT SUM(is_archived = 0) AS active_count, SUM(is_archived = 1) AS archived_count FROM email_message_templates');
$counts = $countResult ? ($countResult->fetch_assoc() ?: []) : [];
$paginationUrl = $listUrl . '&' . http_build_query(['q' => $search, 'per_page' => $pageSize]);
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Email Templates'), ['styles' => ['assets/css/style.min.css', 'assets/css/modern.min.css', 'assets/css/pages/email_templates.min.css']]); ?>
<body class="email-templates-body">
<?php include 'templates/header.php'; ?>
<main class="container email-templates-page">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="engagements.php">Engagements</a><span aria-hidden="true">/</span><span>Email Templates</span></nav>
    <div class="page-heading">
        <div><h1><?php echo $archived ? 'Archived Email Templates' : 'Email Templates'; ?></h1><p class="page-intro">Manage reusable subjects and messages for engagement correspondence.</p></div>
        <div class="page-heading-actions">
            <?php if ($engagementId !== null): ?><a href="compose_engagement_email.php?id=<?php echo $engagementId; ?>" class="button-secondary">Return to Email</a><?php endif; ?>
            <?php if ($canManage): ?><a href="edit_email_template.php<?php echo $engagementId !== null ? '?engagement_id=' . $engagementId : ''; ?>" class="button-add">+ New Email Template</a><?php endif; ?>
        </div>
    </div>
    <?php if ($message !== ''): ?><p class="success" role="status"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <div class="list-controls">
        <form method="get" action="email_templates.php" class="list-search-form" role="search">
            <input type="hidden" name="status" value="<?php echo $status; ?>">
            <?php if ($engagementId !== null): ?><input type="hidden" name="engagement_id" value="<?php echo $engagementId; ?>"><?php endif; ?>
            <label class="visually-hidden" for="template-search">Search email templates</label><span class="search-icon" aria-hidden="true">⌕</span>
            <input type="search" id="template-search" name="q" maxlength="255" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search email templates">
            <?php if ($search !== ''): ?><a href="<?php echo htmlspecialchars($listUrl, ENT_QUOTES, 'UTF-8'); ?>" class="clear-search">Clear</a><?php endif; ?>
        </form>
        <div class="control-group" aria-label="Email template archive status">
            <a href="email_templates.php?status=active<?php echo $contextQuery; ?>" class="sort-button<?php echo !$archived ? ' active' : ''; ?>"<?php echo !$archived ? ' aria-current="page"' : ''; ?>>Active (<?php echo (int) ($counts['active_count'] ?? 0); ?>)</a>
            <a href="email_templates.php?status=archived<?php echo $contextQuery; ?>" class="sort-button<?php echo $archived ? ' active' : ''; ?>"<?php echo $archived ? ' aria-current="page"' : ''; ?>>Archived (<?php echo (int) ($counts['archived_count'] ?? 0); ?>)</a>
        </div>
    </div>
    <p class="result-context"><?php echo $archived ? 'Restore a template to make it available again. Administrators can permanently delete archived templates.' : 'Active templates appear in the engagement email composer. Changes apply to future messages.'; ?> Custom message is always available for writing from scratch.</p>
    <?php renderPagination($pagination['total'], $pagination['page'], $pageSize, $paginationUrl, 'email templates', 'Email template pages'); ?>
    <div class="email-template-table-wrapper">
        <table class="task-table data-table email-template-table">
            <thead><tr><th scope="col">Order</th><th scope="col">Template</th><th scope="col">Suggested contacts</th><th scope="col">Updated</th><th scope="col">Actions</th></tr></thead>
            <tbody>
                <?php foreach ($templates as $template): ?>
                    <?php $roles = json_decode((string) $template['suggested_roles_json'], true); $roles = is_array($roles) ? $roles : []; ?>
                    <tr>
                        <td><?php echo (int) $template['sort_order']; ?></td>
                        <td><a class="record-link" href="edit_email_template.php?id=<?php echo (int) $template['id']; ?><?php echo $contextQuery; ?>"><?php echo htmlspecialchars((string) $template['name'], ENT_QUOTES, 'UTF-8'); ?></a><small class="task-notes-preview"><?php echo htmlspecialchars((string) $template['subject_template'], ENT_QUOTES, 'UTF-8'); ?></small></td>
                        <td><?php echo htmlspecialchars($roles !== [] ? implode(' · ', array_map('engagementContactRoleLabel', $roles)) : 'Choose when composing', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(applicationTimestampLabel($template['updated_at'], 'M j, Y'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><div class="task-actions">
                            <a href="edit_email_template.php?id=<?php echo (int) $template['id']; ?><?php echo $contextQuery; ?>" class="action-button action-icon-button <?php echo $canManage && !$archived ? 'edit-button' : 'view-button'; ?>" aria-label="<?php echo $canManage && !$archived ? 'Edit' : 'View'; ?> email template" title="<?php echo $canManage && !$archived ? 'Edit' : 'View'; ?>" data-tooltip="<?php echo $canManage && !$archived ? 'Edit' : 'View'; ?>"><?php echo actionIconSvg($canManage && !$archived ? 'edit' : 'view'); ?></a>
                            <?php if ($canManage): ?>
                                <form method="post" action="email_templates.php">
                                    <?php echo csrfInput(); ?><input type="hidden" name="template_id" value="<?php echo (int) $template['id']; ?>"><input type="hidden" name="version" value="<?php echo (int) $template['version']; ?>"><input type="hidden" name="status" value="<?php echo $status; ?>"><input type="hidden" name="engagement_id" value="<?php echo $engagementId ?? ''; ?>"><input type="hidden" name="action" value="<?php echo $archived ? 'restore' : 'archive'; ?>">
                                    <button type="submit" class="action-button action-icon-button <?php echo $archived ? 'restore-button' : 'archive-button'; ?>" aria-label="<?php echo $archived ? 'Restore' : 'Archive'; ?> email template" title="<?php echo $archived ? 'Restore' : 'Archive'; ?>" data-tooltip="<?php echo $archived ? 'Restore' : 'Archive'; ?>"><?php echo actionIconSvg($archived ? 'restore' : 'archive'); ?></button>
                                </form>
                            <?php endif; ?>
                            <?php if ($archived && $canDelete): ?>
                                <form method="post" action="email_templates.php" data-delete-confirmation="Permanently delete this email template? Sent messages and their history will remain available." data-archive-button-label="Keep archived">
                                    <?php echo csrfInput(); ?><input type="hidden" name="template_id" value="<?php echo (int) $template['id']; ?>"><input type="hidden" name="version" value="<?php echo (int) $template['version']; ?>"><input type="hidden" name="status" value="archived"><input type="hidden" name="engagement_id" value="<?php echo $engagementId ?? ''; ?>"><input type="hidden" name="action" value="delete">
                                    <button type="submit" class="action-button action-icon-button delete-button" aria-label="Delete email template" title="Delete" data-tooltip="Delete"><?php echo actionIconSvg('delete'); ?></button>
                                </form>
                            <?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($templates === []): ?><tr><td colspan="5" class="empty-state">No <?php echo $archived ? 'archived' : 'active'; ?> email templates<?php echo $search !== '' ? ' match your search' : ''; ?>.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php renderPagination($pagination['total'], $pagination['page'], $pageSize, $paginationUrl, 'email templates', 'Email template pages'); ?>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
