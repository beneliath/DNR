<?php

function expectContactBirthdayFeature(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Contact birthday feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$read = static fn(string $path): string => file_get_contents($root . '/' . $path);
$migration = $read('migrations/20260902_add_contact_birthdays.sql');
$month_day_migration = $read('migrations/20260902_store_contact_birthday_month_day.sql');
$migration_order = $read('migrations/order.txt');
$add_contact = $read('src/add_contact.php');
$edit_contact = $read('src/edit_contact.php');
$add_organization = $read('src/add_organization.php');
$view_contact = $read('src/view_contact.php');
$calendar = $read('src/calendar.php');
$calendar_page = $read('src/view_calendar.php');
$calendar_helpers = $read('src/calendar_helpers.php');

expectContactBirthdayFeature(
    str_contains($migration, 'ADD COLUMN contact_birthday DATE NULL')
        && str_contains($migration, 'calendar_contacts_after_update')
        && str_contains($month_day_migration, 'MODIFY COLUMN contact_birthday CHAR(5) NULL')
        && str_contains($month_day_migration, "'%m/%d'")
        && str_contains($migration_order, '20260902_add_contact_birthdays.sql')
        && str_contains($migration_order, '20260902_store_contact_birthday_month_day.sql'),
    'the migrations should store month/day birthdays and invalidate cached calendar feeds.'
);

expectContactBirthdayFeature(
    str_contains($add_contact, 'name="contact_birthday"')
        && str_contains($edit_contact, 'name="contact_birthday"')
        && str_contains($add_organization, 'name="contact_birthday"')
        && str_contains($add_organization, '[__CONTACT_INDEX__][birthday]')
        && str_contains($view_contact, '<strong>Birthday</strong>')
        && str_contains($add_contact, 'placeholder="MM/DD"')
        && str_contains($add_contact, 'class="contact-phone-birthday-row"')
        && str_contains($edit_contact, 'class="form-group contact-birthday-field"'),
    'contact forms should use a compact MM/DD birthday field beside the phone number.'
);

expectContactBirthdayFeature(
    str_contains($calendar, 'contact_birthday IS NOT NULL')
        && str_contains($calendar, '$birthdays')
        && str_contains($calendar_page, 'fetchCalendarViewerBirthdays($conn)')
        && str_contains($calendar_helpers, 'function calendarBirthdayEventLines(')
        && str_contains($calendar_helpers, "'RRULE:FREQ=YEARLY'")
        && str_contains($calendar_helpers, 'function calendarBirthdayOccurrences(')
        && str_contains($calendar_helpers, "'calendar_item_type' => 'birthday'")
        && str_contains($calendar_helpers, "'view_contact.php'"),
    'birthdays should appear in both subscribed and in-app calendars as annual contact reminders.'
);

echo "Contact birthday feature tests passed.\n";
