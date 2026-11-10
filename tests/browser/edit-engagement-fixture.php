<?php
// Exercise the edit form's real widgets and bundles with synthetic records.
require __DIR__ . '/../../src/functions.php';
require_once __DIR__ . '/../../src/presentation_helpers.php';
require_once __DIR__ . '/../../src/engagement_lifecycle_helpers.php';

$engagement_lifecycle_options = engagementLifecycleStatuses();
$engagement_confirmation_statuses = \Dnr\Domain\ReferenceData::engagementStatuses();
$selected_lifecycle_status = 'active';
$selected_confirmation_status = 'work_in_progress';
$selected_cancellation_reason = '';
$reschedule_candidates = [];
$default_speaker_id = 1;
$speaker_options = [['id' => 1, 'name' => 'Sample Speaker', 'email' => 'speaker@example.test']];
$withDraft = ($argv[1] ?? '') === 'draft';
$rejectedSave = isset($argv[1]) && !$withDraft;
$presentation_form_rows = [['topic_title' => $rejectedSave ? 'Submitted edit' : 'Opening session', 'presentation_time' => '18:00']];
?>
<!doctype html>
<html lang="en">
<?php renderPageHead('Edit Engagement', ['styles' => [
    'assets/css/style.min.css', 'assets/css/modern.min.css',
    'assets/css/pages/edit_engagement.min.css', 'assets/css/pages/engagement_lifecycle.min.css',
]]); ?>
<body class="edit-engagement-body">
<?php if ($withDraft): ?><aside hidden id="app-sidebar" data-nav-preference-user="draft-fixture"></aside><?php endif; ?>
<?php renderScript('assets/js/edit-engagement.min.js', false); ?>
<div class="account-identity-banner">Fixture Account</div>
<?php renderScript('assets/js/app-shell.min.js', false); ?>
<main class="container edit-engagement-page">
    <form class="engagement-form" id="engagement-edit-form"<?= $withDraft ? ' data-recoverable-draft="edit_engagement.php:1"' : '' ?>>
        <input type="hidden" name="edit_baseline" value="<?= htmlspecialchars($rejectedSave ? $argv[1] : '', ENT_QUOTES, 'UTF-8') ?>">
        <?php include __DIR__ . '/../../src/templates/engagement_lifecycle_form.php'; ?>
        <section class="form-section engagement-event-details-section">
            <h2>Event Details &amp; Schedule</h2>
            <div class="organization-container">
                <label for="organization_id">Organization</label>
                <input type="search" data-relationship-search-input>
                <select name="organization_id" id="organization_id"><option>Fixture organization</option></select>
                <p class="field-help" role="status" data-relationship-search-status></p>
            </div>
            <div class="event-schedule-row"><div class="event-caller-field">
                <label for="caller_user_id">Caller</label><select id="caller_user_id"><option>No Caller Selected</option></select>
            </div></div>
            <div class="event-row">
                <div class="event-group event-title-group"><label>Event Title<input type="text"></label></div>
                <div class="event-group"><label for="event_type">Event Type</label><select id="event_type"><option>Conference</option></select></div>
            </div>
            <label for="event_description">Event Description</label><textarea id="event_description"></textarea>
        </section>
        <?php include __DIR__ . '/../../src/templates/presentation_form.php'; ?>
    </form>
    <textarea name="new_chron_entry" form="engagement-edit-form"></textarea>
    <div class="engagement-page-actions">
        <button type="submit" class="save-button" form="engagement-edit-form" name="save_engagement"<?= $rejectedSave ? ' data-unsaved-changes' : '' ?>>Save Changes</button>
    </div>
</main>
<?php renderScript('assets/js/page-actions.min.js'); ?>
<?php renderScript('assets/js/presentation-form.min.js'); ?>
</body></html>
