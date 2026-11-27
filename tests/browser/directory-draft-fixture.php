<?php

// Render the production forms without creating or changing directory records.
$src = dirname(__DIR__, 2) . '/src';
foreach (['functions.php', 'app/Domain/ReferenceData.php', 'record_creation_helpers.php',
    'duplicate_warning_helpers.php', 'contact_organization_helpers.php', 'speaker_helpers.php',
    'chron_log_helpers.php', 'pagination_helpers.php'] as $file) {
    require_once $src . '/' . $file;
}

$route = $argv[1];
$version = $argv[2] ?? '1';
$id = (int) ($argv[3] ?? 0);
$source = file_get_contents($src . '/' . $route);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION = ['user_id' => 42, '_csrf_token' => 'draft-fixture'];

$draftKey = $route . ':' . ($id ?: 'new');
$creation_operation_token = str_repeat('a', 32);
$creation_return = $record_edit_return = $return_to = $cancel_url = '';
$add_contact_action = 'add_contact.php';
$existing_contact_rows = organizationExistingContactFormRows(null);
$existing_contact_option_ids = $existing_contact_options = $additional_organization_rows = [];
$contact_organization_options = [['id' => 8, 'organization_name' => 'Current organization']];
$selected_organization_id = null;
$org_id = $contact_id = $id;
$organization = ['organization_name' => 'Current organization', 'physical_country' => 'US',
    'mailing_country' => 'US', 'updated_at' => $version];
$same_address = true;
$contact = ['contact_first_name' => 'Current', 'contact_last_name' => 'Contact',
    'contact_email' => 'contact@example.test', 'organization_id' => 8,
    'contact_role' => 'other', 'updated_at' => $version];
$contact_photo_placeholder = $contact_photo_version = $contact_birthday_input = '';
$phone_country_code = $phone_country_code_value = $fax_country_code_value = $contact_phone_country_code_value = '+1';
$phone_local_value = $fax_local_value = $contact_phone_local_value = '';
$speaker_id = $id ?: null;
$page_title = $id ? 'Edit Speaker' : 'Add Speaker';
$photo_url = '';
$speaker = $id ? ['version' => $version] : null;
$form_values = ['name' => $id ? 'Current speaker' : '', 'version' => $id ? $version : '',
    'sort_name' => '', 'phone' => '', 'email' => '', 'bio' => ''];
$custom_links = $id ? [
    ['key' => 'lost-key', 'label' => 'First link', 'url' => 'https://example.test/first'],
    ['key' => 'kept-key', 'label' => 'Second link', 'url' => 'https://example.test/second'],
] : [[]];

preg_match('/renderPageHead\(.*?\);/s', $source, $head);
preg_match('/^\h*<form[^\n]*data-recoverable-draft=.*?^\h*<\/form>/ms', $source, $form);
$body = str_replace("__DIR__ . '/templates/", "'" . $src . "/templates/", $form[0]);
?>
<!doctype html>
<html lang="en">
<?php eval($head[0]); ?>
<body>
<aside hidden id="app-sidebar" data-nav-preference-user="directory-draft-fixture"></aside>
<main class="container">
<?php
eval('?>' . $body);
if ($route === 'edit_organization.php' || $route === 'edit_contact.php') {
    $chron_entity_label = $route === 'edit_contact.php' ? 'contact' : 'organization';
    $chron_edit_form_id = $chron_entity_label . '-edit-form';
    $archived_chron_count = $chron_entry_count = 0;
    $chron_page = 1;
    $chron_page_size = 20;
    $chron_entries = [];
    $chron_edit_url = $route . '?id=' . $id;
    include $src . '/templates/entity_chron_log_edit_section.php';
}
?>
</main>
<script type="application/json" id="address-region-data"><?= json_encode(addressRegionClientData(), JSON_HEX_TAG) ?></script>
<?php
foreach (['phone-input', 'page-actions', 'record-workspace'] as $name) {
    renderScript('assets/js/' . $name . '.min.js');
}
?>
</body>
</html>
