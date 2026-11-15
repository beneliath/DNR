<?php
require __DIR__ . '/../../src/functions.php';
$list_current_url = 'engagements.php?lifecycle=all';
?>
<!doctype html><html lang="en">
<?php renderPageHead('Cancel Engagement', ['styles' => ['assets/css/style.min.css', 'assets/css/modern.min.css', 'assets/css/pages/engagements.min.css'], 'scripts' => ['assets/js/engagement-cancel.min.js']]); ?>
<body class="engagements-body"><main class="container engagements-page">
<table class="engagement-table"><thead><tr><th>Engagement</th><th>Actions</th></tr></thead><tbody>
<?php foreach ([11 => true, 12 => false, 13 => true] as $id => $canCancel): ?>
<tr><td>Example engagement <?= $id ?></td><td><div class="action-buttons">
    <a href="#view" class="action-button action-icon-button view-button" aria-label="View Event"><?= actionIconSvg('view') ?></a>
    <a href="#edit" class="action-button action-icon-button edit-button" aria-label="Edit Event"><?= actionIconSvg('edit') ?></a>
    <?php if ($canCancel): ?><button type="button" class="action-button action-icon-button cancel-engagement-button" data-cancel-engagement="<?= $id ?>" data-engagement-version="2026-11-15 13:00:00.123456" data-engagement-label="Example engagement <?= $id ?>" aria-label="Cancel Engagement" data-tooltip="Cancel Engagement"><?= actionIconSvg('cancel') ?></button><?php endif; ?>
    <form><button type="button" class="action-button action-icon-button archive-button" aria-label="Archive Event"><?= actionIconSvg('archive') ?></button></form>
    <form><button type="button" class="action-button action-icon-button delete-button" aria-label="Delete Event"><?= actionIconSvg('delete') ?></button></form>
</div></td></tr>
<?php endforeach; ?>
</tbody></table></main>
<?php include __DIR__ . '/../../src/templates/engagement_cancel_dialog.php'; ?>
</body></html>
