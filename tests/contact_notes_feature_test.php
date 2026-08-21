<?php

function expectContactNotesFeature($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "Contact notes feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$read = static fn($path) => file_get_contents($root . '/' . $path);

$init = $read('init.sql');
$migration = $read('migrations/20260818_add_contact_notes.sql');
expectContactNotesFeature(
    str_contains($init, 'contact_notes TEXT NULL')
        && str_contains($init, "'20260818_add_contact_notes.sql'")
        && str_contains($migration, 'ADD COLUMN contact_notes TEXT NULL'),
    'fresh and upgraded databases should include optional contact notes.'
);

$add_contact = $read('src/add_contact.php');
$edit_contact = $read('src/edit_contact.php');
$add_organization = $read('src/add_organization.php');
expectContactNotesFeature(
    str_contains($add_contact, '<textarea name="contact_notes"')
        && str_contains($add_contact, 'contact_phone, contact_notes')
        && str_contains($edit_contact, '<textarea name="contact_notes"')
        && str_contains($edit_contact, 'contact_notes = ?')
        && str_contains($add_organization, "'notes' => \$contact_notes")
        && str_contains($add_organization, 'contact_phone, contact_notes')
        && str_contains($add_organization, '<textarea name="contact_notes"')
        && str_contains($add_organization, '<textarea name="contacts[${contactCount-1}][notes]"'),
    'all contact creation and editing paths should use multiline fields and save optional notes.'
);

$view_contact = $read('src/view_contact.php');
expectContactNotesFeature(
    str_contains($view_contact, '<strong>Notes</strong>')
        && str_contains($view_contact, "nl2br(htmlspecialchars(\$contact_notes"),
    'contact details should safely display multiline notes.'
);

$contacts = $read('src/contacts.php');
expectContactNotesFeature(
    str_contains($contacts, 'c.contact_phone, c.contact_role_other, c.contact_notes')
        && str_contains($contacts, 'AGAINST (? IN BOOLEAN MODE)'),
    'contact search should include notes.'
);

echo "Contact notes feature tests passed.\n";
