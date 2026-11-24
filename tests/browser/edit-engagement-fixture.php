<?php
// Exercise the edit form's real widgets and bundles with synthetic records.
require __DIR__ . '/../../src/functions.php';
require_once __DIR__ . '/../../src/presentation_helpers.php';
require_once __DIR__ . '/../../src/engagement_lifecycle_helpers.php';
require_once __DIR__ . '/../../src/engagement_contact_helpers.php';
require_once __DIR__ . '/../../src/chron_log_helpers.php';
require_once __DIR__ . '/../../src/pagination_helpers.php';
require_once __DIR__ . '/../../src/record_workspace_helpers.php';

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

$source_dir = __DIR__ . '/../../src';
set_include_path($source_dir . PATH_SEPARATOR . get_include_path());
$source = file_get_contents($source_dir . '/edit_engagement.php');
$layout_start = strpos($source, '<div class="engagement-form-meta">');
$layout_end = strpos($source, "<?php\n        \$presentation_not_required", $layout_start);
$chron_start = strpos($source, '<section class="form-section chron-log-section" id="chron-log">');
$chron_end = strpos($source, "\n</div>\n\n<script nonce=", $chron_start);
$footer_start = strpos($source, '<script nonce=', $chron_end);
$footer_end = strpos($source, "<?php renderScript('assets/js/presentation-form.min.js'", $footer_start);

$engagement_id = 1;
$record_edit_return = 'engagements.php';
$user_role = 'admin';
$error_message = $rejectedSave ? 'Fixture validation error' : '';
$engagement = [
    'event_title' => 'Fixture engagement',
    'event_description' => 'Saved event details',
    'event_start_date' => '2026-11-18',
    'event_end_date' => '2026-11-19',
    'event_type' => 'study or teaching',
    'caller_user_id' => 7,
    'book_table' => true,
    'brochures' => false,
    'travel_covered' => 'yes',
    'compensation_type' => 'Unknown',
    'housing_type' => 'Unknown',
    'event_city' => 'Washington',
    'event_state' => 'DC',
    'event_country' => 'US',
];
$selected_engagement_organization_id = 1;
$engagement_organization_options = [
    ['id' => 1, 'organization_name' => 'Fixture organization'],
    ['id' => 2, 'organization_name' => 'Canadian organization'],
];
$organization_contacts = $engagement_added_contacts = $engagement_new_contact_rows = [];
$engagement_contact_role_options = engagementContactRoles();
$conn = new class {
    public function query(string $sql): object
    {
        $rows = [['id' => 3, 'username' => 'Active caller']];
        if (str_contains($sql, 'OR id = 7')) {
            $rows[] = ['id' => 7, 'username' => 'Saved inactive caller'];
        }
        return new class ($rows) {
            public function __construct(private array $rows) {}
            public function fetch_assoc(): ?array { return array_shift($this->rows); }
        };
    }
};
$chron_entries = [[
    'id' => 8,
    'created_at' => '2026-11-18 12:00:00',
    'updated_at' => '2026-11-18 12:00:00',
    'created_by_username' => 'Fixture editor',
    'entry_text' => 'Saved Chron entry',
], [
    'id' => 7,
    'created_at' => '2026-11-17 12:00:00',
    'updated_at' => '2026-11-17 12:00:00',
    'created_by_username' => 'Fixture editor',
    'entry_text' => 'Earlier Chron entry',
]];
$chron_entry_count = count($chron_entries);
$chron_page = 1;
$chron_page_size = 20;
$archived_chron_count = 1;
?>
<!doctype html>
<html lang="en">
<?php renderPageHead('Edit Engagement', ['styles' => [
    'assets/css/style.min.css', 'assets/css/modern.min.css',
    'assets/css/pages/edit_engagement.min.css', 'assets/css/pages/engagement_contacts.min.css',
    'assets/css/pages/engagement_lifecycle.min.css', 'assets/css/pages/engagement_form.min.css',
], 'scripts' => ['assets/js/relationship-search.min.js']]); ?>
<body class="edit-engagement-body">
<?php if ($withDraft): ?><aside hidden id="app-sidebar" data-nav-preference-user="draft-fixture"></aside><?php endif; ?>
<?php renderScript('assets/js/edit-engagement.min.js', false); ?>
<div class="account-identity-banner">Fixture Account</div>
<?php renderScript('assets/js/app-shell.min.js', false); ?>
<main class="container edit-engagement-page">
    <form class="engagement-form" id="engagement-edit-form"<?= $withDraft ? ' data-recoverable-draft="edit_engagement.php:1"' : '' ?>>
        <input type="hidden" name="edit_baseline" value="<?= htmlspecialchars($rejectedSave ? $argv[1] : '', ENT_QUOTES, 'UTF-8') ?>">
        <?php eval('?>' . substr($source, $layout_start, $layout_end - $layout_start)); ?>
        <?php include __DIR__ . '/../../src/templates/presentation_form.php'; ?>
    </form>
    <?php eval('?>' . substr($source, $chron_start, $chron_end - $chron_start)); ?>
</main>
<?php eval('?>' . substr($source, $footer_start, $footer_end - $footer_start)); ?>
<?php renderScript('assets/js/presentation-form.min.js'); ?>
<?php renderScript('assets/js/engagement-contacts.min.js', false); ?>
<?php renderScript('assets/js/engagement-lifecycle.min.js', false); ?>
</body></html>
