<?php
// Exercise the new form's shared draft, lifecycle, and contact controls without a database.
require __DIR__ . '/../../src/functions.php';
require __DIR__ . '/../../src/engagement_lifecycle_helpers.php';
require __DIR__ . '/../../src/engagement_contact_helpers.php';
$engagement_form_editing = false;
$engagement_form_values = ['event_start_date' => '', 'event_end_date' => '', 'event_type' => 'conference'];
$engagement_lifecycle_options = engagementLifecycleStatuses();
$engagement_confirmation_statuses = \Dnr\Domain\ReferenceData::engagementStatuses();
$selected_lifecycle_status = 'active';
$selected_confirmation_status = 'work_in_progress';
$selected_cancellation_reason = '';
$selected_rescheduled_to_engagement_id = null;
$reschedule_candidates = [];
$selected_engagement_organization_id = 1;
$organization_contacts = $engagement_added_contacts = $engagement_new_contact_rows = [];
$engagement_contact_role_options = engagementContactRoles();
?>
<!doctype html>
<html lang="en">
<?php renderPageHead('New Engagement', ['styles' => [
    'assets/css/style.min.css', 'assets/css/modern.min.css', 'assets/css/pages/index.min.css',
    'assets/css/pages/engagement_form.min.css', 'assets/css/pages/engagement_contacts.min.css',
    'assets/css/pages/engagement_lifecycle.min.css',
]]); ?>
<body>
<aside hidden id="app-sidebar" data-nav-preference-user="draft-fixture"></aside>
<main class="container">
    <form id="new-engagement-form" class="engagement-form" data-recoverable-draft="index.php:new">
        <?php renderFormDraftPanel(true); ?>
        <?php include __DIR__ . '/../../src/templates/engagement_lifecycle_form.php'; ?>
        <label for="organization_id">Organization</label>
        <select name="organization_id" id="organization_id" data-organization-search>
            <option value="">Select an Organization</option>
            <option value="1" selected>Original organization</option>
            <option value="2">Draft organization</option>
        </select>
        <?php include __DIR__ . '/../../src/templates/engagement_contact_form.php'; ?>
    </form>
</main>
<?php renderScript('assets/js/engagement-contacts.min.js', false); ?>
<?php renderScript('assets/js/engagement-lifecycle.min.js', false); ?>
<?php renderScript('assets/js/relationship-search.min.js', false); ?>
</body>
</html>
