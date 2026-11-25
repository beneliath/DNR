<?php
require_once __DIR__ . '/../../src/functions.php';
require_once __DIR__ . '/../../src/engagement_lifecycle_helpers.php';
require_once __DIR__ . '/../../src/bulk_delete_helpers.php';

$page = $argv[1];
$mode = $argv[2];
$templates = __DIR__ . '/../../src/templates/';
$_SESSION = ['user_id' => 11, 'role' => 'admin'];

$engagement_form_editing = $page === 'edit_engagement';
$engagement_form_values = [];
$engagement_lifecycle_options = engagementLifecycleStatuses();
$engagement_confirmation_statuses = \Dnr\Domain\ReferenceData::engagementStatuses();
$selected_lifecycle_status = 'active';
$selected_confirmation_status = 'work_in_progress';
$selected_cancellation_reason = '';
$reschedule_candidates = [];
$record_edit_return = 'engagements.php';
$styles = [
    'index' => ['index', 'engagement_lifecycle', 'engagement_form'],
    'edit_engagement' => ['edit_engagement', 'engagement_lifecycle', 'engagement_form'],
    'reimbursements' => ['reimbursements'],
    'contacts' => ['contacts'],
    'organizations' => ['organizations'],
];
?>
<!doctype html>
<html lang="en">
<?php renderPageHead('Sticky Banners', ['styles' => [
    'assets/css/style.min.css', 'assets/css/modern.min.css',
    ...array_map(static fn($style) => 'assets/css/pages/' . $style . '.min.css', $styles[$page]),
]]); ?>
<body>
<header class="app-shell-header">
    <div class="mobile-app-bar">Mobile navigation</div>
    <?php include $templates . 'deployment-notice.php'; ?>
    <?php if ($mode !== 'plain'): ?>
        <section class="account-identity-banner">
            <span class="account-identity-label"><strong>Example Account with a Name Long Enough to Wrap on a Phone</strong></span>
            <a href="accounts.php">Switch Account</a>
        </section>
    <?php endif; ?>
    <?php if ($mode === 'preview'): ?>
        <section class="role-preview-banner" data-role-preview-banner>
            <div class="role-preview-banner-copy"><strong>Viewing as Editor</strong><span>Navigation and permissions reflect this role.</span></div>
            <div class="role-preview-return-form"><button type="button">Return to SuperAdmin</button></div>
        </section>
    <?php endif; ?>
    <section class="admin-unlock-banner" hidden><strong>Administrator actions unlocked</strong></section>
</header>
<?php renderScript('assets/js/app-shell.min.js', false); ?>
<main class="container<?= $page === 'reimbursements' ? ' reimbursement-page' : '' ?>">
    <h1>Sticky Banners</h1>
    <?php if ($page === 'index' || $page === 'edit_engagement'): ?>
        <form class="engagement-form" id="<?= $engagement_form_editing ? 'engagement-edit-form' : 'new-engagement-form' ?>">
            <?php include $templates . 'engagement_lifecycle_form.php'; ?>
            <div style="height: 1600px"></div>
        </form>
    <?php elseif ($page === 'reimbursements'): ?>
        <?php include $templates . 'reimbursement_progress.php'; ?>
        <div style="height: 1600px"></div>
    <?php else: ?>
        <div class="record-table-pane">
            <?php renderBulkDeleteToolbar($page === 'contacts' ? 'contact' : 'organization', $page . '.php', true); ?>
            <div class="data-table-scroll" style="height: 1600px"><?php renderBulkDeleteCheckbox(12, 'Example Record'); ?></div>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
