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
$editOrganization = file_get_contents($root . '/src/edit_organization.php');
$viewContact = file_get_contents($root . '/src/view_contact.php');
$viewOrganization = file_get_contents($root . '/src/view_organization.php');
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
        && str_contains($editContact, "insertEntityChronLogEntry(\n                    \$conn,\n                    'contact'")
        && str_contains($editContact, "updateEntityChronLogEntries(\n                \$conn,\n                'contact'")
        && str_contains($editContact, 'name="save_contact"')
        && str_contains($editContact, "include 'templates/entity_chron_log_edit_section.php'"),
    'Edit Contact should add and update contact-owned Chron entries.'
);
expectEntityChronFeature(
    is_string($editOrganization)
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
        && str_contains($editTemplate, 'name="chron_action" value="archive"')
        && str_contains($editTemplate, "\$user_role === 'admin'"),
    'the shared editor should support add, edit, archive, and admin-only deletion.'
);

expectEntityChronFeature(
    is_string($viewContact)
        && str_contains($viewContact, "fetchEntityChronLogEntries(\n        \$conn,\n        'contact'")
        && is_string($viewOrganization)
        && str_contains($viewOrganization, "fetchEntityChronLogEntries(\n        \$conn,\n        'organization'")
        && is_string($viewTemplate)
        && str_contains($viewTemplate, 'id="chron-log"'),
    'contact and organization detail pages should display their independently loaded histories.'
);

expectEntityChronFeature(
    is_string($restore)
        && str_contains($restore, "in_array(\$entity_type, ['contact', 'organization'], true)")
        && str_contains($restore, 'restoreEntityChronLogEntries(')
        && str_contains($restore, 'name="chron_entry_ids[]"')
        && str_contains($restore, 'canArchiveEntries($user_role)'),
    'the restore route should accept only contact and organization owners and remain editor-protected.'
);

expectEntityChronFeature(
    str_contains($migrationRunner, 'DNR_PRIVILEGE_SCRIPT')
        && str_contains($privilegeScript, 'contact_chron_entries')
        && str_contains($privilegeScript, 'organization_chron_entries'),
    'the single deployment privilege manifest should grant access to both new Chron tables.'
);

echo "Contact and organization Chron log feature tests passed.\n";
