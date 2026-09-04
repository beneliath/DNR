<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/chron_log_helpers.php';

function expectEntityChronFeature(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Contact/organization Chron log feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$migration = file_get_contents(
    $root . '/migrations/20260823_add_contact_organization_chron_entries.sql'
);
$editContact = file_get_contents($root . '/src/edit_contact.php');
$editContactStyles = file_get_contents($root . '/src/assets/css/pages/edit_contact.css');
$editOrganization = file_get_contents($root . '/src/edit_organization.php');
$viewContact = file_get_contents($root . '/src/view_contact.php');
$viewContactStyles = file_get_contents($root . '/src/assets/css/pages/view_contact.css');
$viewOrganization = file_get_contents($root . '/src/view_organization.php');
$viewOrganizationStyles = file_get_contents($root . '/src/assets/css/pages/view_organization.css');
$restore = file_get_contents($root . '/src/restore_entity_chron_entries.php');
$editTemplate = file_get_contents($root . '/src/templates/entity_chron_log_edit_section.php');
$viewTemplate = file_get_contents($root . '/src/templates/entity_chron_log_view_section.php');
$migrationRunner = file_get_contents($root . '/scripts/migrate.sh');
$privilegeScript = file_get_contents($root . '/scripts/configure_database_privileges.sh');

expectEntityChronFeature(
    is_string($migration)
        && str_contains($migration, 'CREATE TABLE contact_chron_entries')
        && str_contains($migration, 'FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE')
        && str_contains($migration, 'CREATE TABLE organization_chron_entries')
        && str_contains($migration, 'FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE'),
    'contacts and organizations should have separate parent-scoped tables.'
);
$contactConfiguration = chronLogEntityConfiguration('contact');
$organizationConfiguration = chronLogEntityConfiguration('organization');
expectEntityChronFeature(
    $contactConfiguration['table'] === 'contact_chron_entries'
        && $contactConfiguration['parent_column'] === 'contact_id'
        && $organizationConfiguration['table'] === 'organization_chron_entries'
        && $organizationConfiguration['parent_column'] === 'organization_id',
    'the query allowlist should map each owner type only to its own table and parent key.'
);

try {
    chronLogEntityConfiguration('invalid');
    $invalidTypeRejected = false;
} catch (InvalidArgumentException $exception) {
    $invalidTypeRejected = true;
}
expectEntityChronFeature(
    $invalidTypeRejected,
    'unknown entity types should be rejected before an identifier reaches SQL.'
);

expectEntityChronFeature(
    is_string($editContact)
        && str_contains($editContact, "require_once __DIR__ . '/two_factor_helpers.php';")
        && str_contains($editContact, 'requireRecentAdminElevation(')
        && str_contains($editContact, "insertEntityChronLogEntry(\n                    \$conn,\n                    'contact'")
        && str_contains($editContact, "updateEntityChronLogEntries(\n                \$conn,\n                'contact'")
        && str_contains($editContact, 'name="save_contact"')
        && str_contains($editContact, "include 'templates/entity_chron_log_edit_section.php'"),
    'Edit Contact should add and update contact-owned Chron entries.'
);
expectEntityChronFeature(
    str_contains($editContact, '<body class="edit-contact-body">')
        && str_contains($editContact, '<div class="container edit-contact-page" role="main">')
        && str_contains($editContact, 'form-page-heading edit-contact-heading')
        && preg_match('/\.edit-contact-page\s*\{[^}]*width:\s*min\(100%,\s*var\(--app-content-max\)\);[^}]*max-width:\s*var\(--app-content-max\);/s', $editContactStyles) === 1
        && preg_match('/\.edit-contact-heading h1\s*\{[^}]*font-size:\s*clamp\(1\.8rem,\s*3vw,\s*2\.3rem\);/s', $editContactStyles) === 1
        && preg_match('/\.edit-contact-body \.app-footer\s*\{[^}]*max-width:\s*var\(--app-content-max\);/s', $editContactStyles) === 1,
    'Edit Contact should use the Dashboard canvas width, heading scale, and footer alignment.'
);
expectEntityChronFeature(
    is_string($editOrganization)
        && str_contains($editOrganization, "require_once __DIR__ . '/two_factor_helpers.php';")
        && str_contains($editOrganization, 'requireRecentAdminElevation(')
        && str_contains($editOrganization, "insertEntityChronLogEntry(\n                    \$conn,\n                    'organization'")
        && str_contains($editOrganization, "updateEntityChronLogEntries(\n                \$conn,\n                'organization'")
        && str_contains($editOrganization, 'name="save_organization"')
        && str_contains($editOrganization, "include 'templates/entity_chron_log_edit_section.php'"),
    'Edit Organization should add and update organization-owned Chron entries.'
);
expectEntityChronFeature(
    is_string($editTemplate)
        && str_contains($editTemplate, 'name="save_and_add_chron"')
        && str_contains($editTemplate, 'name="chron_entries[')
        && str_contains($editTemplate, 'name="chron_entry_versions[')
        && str_contains($editTemplate, 'name="chron_action" value="archive"')
        && str_contains($editTemplate, 'name="chron_action" value="delete"')
        && substr_count($editTemplate, 'class="chron-entry-management"') === 2
        && str_contains($editTemplate, "\$user_role === 'admin'"),
    'the shared editor should use separate archive and admin-only delete submissions.'
);

expectEntityChronFeature(
    is_string($viewContact)
        && str_contains($viewContact, "fetchEntityChronLogEntries(\n        \$conn,\n        'contact'")
        && str_contains(
            $viewContact,
            "Communication history for this contact only. Entries are shown newest first. Select 'Edit Contact' to add/edit Chron Log entry."
        )
        && is_string($viewOrganization)
        && str_contains($viewOrganization, "fetchEntityChronLogEntries(\n        \$conn,\n        'organization'")
        && is_string($viewTemplate)
        && str_contains($viewTemplate, 'id="chron-log"'),
    'contact and organization detail pages should display their independently loaded histories.'
);
expectEntityChronFeature(
    str_contains($viewContact, '<body class="view-contact-body">')
        && str_contains($viewContact, '<div class="container view-contact-page" role="main">')
        && str_contains($viewContact, 'record-page-heading view-contact-heading')
        && str_contains($viewContact, 'class="contact-overview-grid"')
        && str_contains($viewContact, 'class="contact-details contact-notes-panel"')
        && preg_match('/\.view-contact-page\s*\{[^}]*width:\s*min\(100%,\s*var\(--app-content-max\)\);[^}]*max-width:\s*var\(--app-content-max\);/s', $viewContactStyles) === 1
        && preg_match('/\.view-contact-heading h1\s*\{[^}]*font-size:\s*clamp\(1\.8rem,\s*3vw,\s*2\.3rem\);/s', $viewContactStyles) === 1
        && preg_match('/\.contact-overview-grid\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*2fr\)\s+minmax\(0,\s*3fr\);[^}]*align-items:\s*stretch;/s', $viewContactStyles) === 1
        && preg_match('/\.contact-overview-grid\s*>\s*\.contact-details\s*\{[^}]*height:\s*100%;/s', $viewContactStyles) === 1
        && preg_match('/@media\s*\(max-width:\s*900px\)\s*\{.*?\.contact-overview-grid\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\);/s', $viewContactStyles) === 1
        && preg_match('/\.view-contact-body \.app-footer\s*\{[^}]*max-width:\s*var\(--app-content-max\);/s', $viewContactStyles) === 1,
    'View Contact should use the Dashboard canvas width, heading scale, and footer alignment.'
);
expectEntityChronFeature(
    str_contains($viewOrganization, '<body class="view-organization-body">')
        && str_contains($viewOrganization, '<div class="container view-organization-page" role="main">')
        && str_contains($viewOrganization, 'record-page-heading view-organization-heading')
        && str_contains($viewOrganization, 'class="organization-overview-grid"')
        && str_contains($viewOrganization, 'class="organization-details organization-notes-panel"')
        && preg_match('/\.view-organization-page\s*\{[^}]*width:\s*min\(100%,\s*var\(--app-content-max\)\);[^}]*max-width:\s*var\(--app-content-max\);/s', $viewOrganizationStyles) === 1
        && preg_match('/\.view-organization-heading h1\s*\{[^}]*font-size:\s*clamp\(1\.8rem,\s*3vw,\s*2\.3rem\);/s', $viewOrganizationStyles) === 1
        && preg_match('/\.organization-overview-grid\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*2fr\)\s+minmax\(0,\s*3fr\);[^}]*align-items:\s*stretch;/s', $viewOrganizationStyles) === 1
        && preg_match('/\.organization-overview-grid\s*>\s*\.organization-details\s*\{[^}]*height:\s*100%;/s', $viewOrganizationStyles) === 1
        && preg_match('/@media\s*\(max-width:\s*900px\)\s*\{.*?\.organization-overview-grid\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\);/s', $viewOrganizationStyles) === 1
        && str_contains($viewOrganization, '<div class="organization-contact-grid">')
        && preg_match('/\.organization-contact-grid\s*\{[^}]*grid-template-columns:\s*repeat\(2,\s*minmax\(0,\s*1fr\)\);[^}]*align-items:\s*stretch;/s', $viewOrganizationStyles) === 1
        && preg_match('/\.contact-card\s*\{[^}]*height:\s*100%;/s', $viewOrganizationStyles) === 1
        && preg_match('/@media \(max-width:\s*760px\)\s*\{\s*\.organization-contact-grid\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\);/s', $viewOrganizationStyles) === 1
        && preg_match('/\.view-organization-body \.app-footer\s*\{[^}]*max-width:\s*var\(--app-content-max\);/s', $viewOrganizationStyles) === 1,
    'View Organization should use the Dashboard canvas and arrange contact cards side by side with equal heights.'
);

expectEntityChronFeature(
    is_string($restore)
        && str_contains($restore, "['contact', 'organization', 'inquiry']")
        && str_contains($restore, 'restoreEntityChronLogEntries(')
        && str_contains($restore, 'name="chron_entry_ids[]"')
        && str_contains($restore, 'canArchiveEntries($user_role)'),
    'the shared restore route should retain contact and organization support and remain editor-protected.'
);

expectEntityChronFeature(
    str_contains($migrationRunner, 'DNR_PRIVILEGE_SCRIPT')
        && str_contains($privilegeScript, 'contact_chron_entries')
        && str_contains($privilegeScript, 'organization_chron_entries'),
    'the single deployment privilege manifest should grant access to both new Chron tables.'
);
expectEntityChronFeature(
    is_string($viewTemplate)
        && str_contains($viewTemplate, 'renderChronLogEntryHtml($chron_entry[\'entry_text\'])')
        && is_string($restore)
        && str_contains($restore, 'renderChronLogEntryHtml($chron_entry[\'entry_text\'])'),
    'active and archived contact and organization Chron entries should render URLs as safe links.'
);

echo "Contact and organization Chron log feature tests passed.\n";
