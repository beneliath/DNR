<?php

function expectOptionalContactOrganization(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Optional contact organization feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$addContact = file_get_contents($root . '/src/add_contact.php');
$contactInput = file_get_contents($root . '/src/app/Domain/ContactInput.php');
$contacts = file_get_contents($root . '/src/contacts.php');
$viewContact = file_get_contents($root . '/src/view_contact.php');
$viewOrganization = file_get_contents($root . '/src/view_organization.php');
$migration = file_get_contents(
    $root . '/migrations/20260825_allow_contacts_without_organizations.sql'
);
$migrationOrder = file_get_contents($root . '/migrations/order.txt');

expectOptionalContactOrganization(
    str_contains($addContact, '<label for="organization_id">Organization</label>')
        && str_contains($addContact, '<select name="organization_id" id="organization_id">')
        && str_contains($addContact, '>No organization</option>')
        && str_contains($addContact, 'if ($organization_id !== null)')
        && !str_contains($contactInput, 'Organization is required.'),
    'the New Contact form and server validation should accept a blank organization.'
);

expectOptionalContactOrganization(
    str_contains($migration, 'MODIFY organization_id INT NULL')
        && str_contains($migrationOrder, '20260825_allow_contacts_without_organizations.sql'),
    'the database migration should make the contact organization foreign key nullable.'
);

expectOptionalContactOrganization(
    str_contains($contacts, 'LEFT JOIN organizations o ON c.organization_id = o.id')
        && str_contains($viewContact, 'LEFT JOIN organizations o ON o.id = c.organization_id')
        && str_contains($viewContact, 'Not specified'),
    'organization-less contacts should remain visible in contact lists and detail views.'
);

expectOptionalContactOrganization(
    str_contains($viewOrganization, 'add_contact.php?organization_id=<?php echo $org_id; ?>')
        && str_contains($viewOrganization, 'view_contact.php?id=<?php echo (int) $contact[\'id\']; ?>')
        && str_contains($addContact, "RequestInput::positiveInt(\$_GET, 'organization_id')")
        && str_contains($addContact, '$selected_organization_id')
        && str_contains($addContact, "'view_organization.php?id=' . \$requested_organization_id"),
    'organization details should link contacts and offer a context-aware contact creation flow.'
);

echo "Optional contact organization feature tests passed.\n";
