<?php

declare(strict_types=1);

function expectEngagementContactsFeature(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Engagement contacts feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$migration = $read('migrations/20260823_add_engagement_contacts.sql');
$order = $read('migrations/order.txt');
$create = $read('src/index.php');
$edit = $read('src/edit_engagement.php');
$view = $read('src/view_engagement.php');
$viewStyles = $read('src/assets/css/pages/view_engagement.css');
$pdf = $read('src/download_engagement_pdf.php');
$editContact = $read('src/edit_contact.php');
$template = $read('src/templates/engagement_contact_form.php');
$javascript = $read('src/assets/js/engagement-contacts.js');
$privileges = $read('scripts/configure_database_privileges.sh');

expectEngagementContactsFeature(
    str_contains($migration, 'CREATE TABLE engagement_contacts')
        && str_contains($migration, 'PRIMARY KEY (engagement_id, contact_id, contact_role)')
        && str_contains($migration, "'primary_host'")
        && str_contains($migration, "'on_site_contact'")
        && str_contains($migration, 'FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE CASCADE')
        && str_contains($migration, 'FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE')
        && str_contains($migration, 'audit_engagement_contacts_after_insert')
        && str_contains($order, '20260823_add_engagement_contacts.sql'),
    'the ordered migration should constrain and audit event contact relationships.'
);
expectEngagementContactsFeature(
    str_contains($editContact, 'DELETE FROM engagement_contacts WHERE contact_id = ?')
        && str_contains($editContact, 'SET engagement.updated_at = CURRENT_TIMESTAMP(6)'),
    'moving a contact to another organization should clear now-invalid event assignments.'
);
expectEngagementContactsFeature(
    str_contains($create, 'normalizeEngagementContactAssignments')
        && str_contains($create, 'syncEngagementContacts')
        && str_contains($edit, 'normalizeEngagementContactAssignments')
        && str_contains($edit, 'syncEngagementContacts')
        && substr_count($template, 'engagement_contacts[') >= 1
        && str_contains($javascript, 'organization_contacts.php') === false
        && str_contains($javascript, 'dataset.contactOptionsUrl'),
    'create and edit forms should persist roles and reload contacts when the organization changes.'
);
expectEngagementContactsFeature(
    str_contains($view, 'fetchEngagementContacts')
        && str_contains($view, 'engagement_contact_roles')
        && preg_match('/\.contacts-list\s*\{[^}]*grid-template-columns:\s*repeat\(2,\s*minmax\(0,\s*1fr\)\);/s', $viewStyles) === 1
        && preg_match('/@media \(max-width:\s*720px\)\s*\{\s*\.contacts-list,/s', $viewStyles) === 1
        && str_contains($pdf, 'fetchEngagementContacts')
        && !str_contains($pdf, 'WHERE organization_id = ? AND is_deleted = 0'),
    'detail and export routes should use only explicitly assigned event contacts, with detail cards arranged responsively side by side.'
);
expectEngagementContactsFeature(
    str_contains($privileges, '.engagement_contacts')
        && str_contains($privileges, 'GRANT SELECT, INSERT, UPDATE, DELETE'),
    'the application database user should be able to manage event contact assignments.'
);

echo "Engagement contacts feature tests passed.\n";
