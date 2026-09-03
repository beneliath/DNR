<?php

declare(strict_types=1);

function expectTextareaRowDefault(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Textarea row defaults feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$newEngagement = $read('src/index.php');
$editEngagement = $read('src/edit_engagement.php');
$entityChronEditor = $read('src/templates/entity_chron_log_edit_section.php');

expectTextareaRowDefault(
    str_contains($newEngagement, 'name="event_description" id="event_description" rows="10"')
        && str_contains($editEngagement, 'name="event_description" id="event_description" rows="10"'),
    'event descriptions should default to 10 rows on create and edit forms.'
);

expectTextareaRowDefault(
    str_contains($newEngagement, 'name="chron_entry" id="chron_entry" rows="6"')
        && str_contains($editEngagement, 'name="new_chron_entry" id="new-chron-entry" rows="6"')
        && str_contains($editEngagement, 'name="chron_entries[<?php echo (int) $chron_entry[\'id\']; ?>]" id="chron-entry-<?php echo (int) $chron_entry[\'id\']; ?>" rows="6"')
        && str_contains($entityChronEditor, 'name="new_chron_entry" id="new-chron-entry" rows="6"')
        && str_contains($entityChronEditor, 'name="chron_entries[<?php echo (int) $chron_entry[\'id\']; ?>]" id="chron-entry-<?php echo (int) $chron_entry[\'id\']; ?>" rows="6"'),
    'all engagement, contact, and organization Chron create/edit fields should default to 6 rows.'
);

$addContact = $read('src/add_contact.php');
$editContact = $read('src/edit_contact.php');
$addOrganization = $read('src/add_organization.php');
$editOrganization = $read('src/edit_organization.php');
$closeEngagement = $read('src/close_engagement.php');
$followUpTaskForm = $read('src/templates/follow_up_task_form.php');
$standardEventTaskForm = $read('src/templates/standard_event_task_form.php');

expectTextareaRowDefault(
    str_contains($addContact, 'name="contact_notes" id="contact_notes" rows="6"')
        && str_contains($editContact, 'name="contact_notes" id="contact_notes" rows="6"')
        && str_contains($addOrganization, 'name="notes" rows="6"')
        && str_contains($addOrganization, 'name="contact_notes" id="contact_notes" rows="6"')
        && str_contains($addOrganization, 'name="contacts[__CONTACT_INDEX__][notes]" rows="6"')
        && str_contains($editOrganization, 'name="notes" rows="6"')
        && str_contains($closeEngagement, 'id="notes" name="notes" rows="6"')
        && str_contains($followUpTaskForm, 'id="task-details" name="details" rows="6"')
        && str_contains($standardEventTaskForm, 'id="standard-task-details" name="details" rows="6"'),
    'all organization, contact, closeout, and task notes fields should default to 6 rows.'
);

$modernStyles = $read('src/assets/css/modern.css');
expectTextareaRowDefault(
    preg_match('/textarea\s*\{[^}]*resize:\s*vertical;/s', $modernStyles) === 1,
    'textareas should retain vertical drag resizing.'
);

echo "Textarea row defaults feature tests passed.\n";
