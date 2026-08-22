<?php

require_once __DIR__ . '/../src/functions.php';

use Dnr\Domain\ContactInput;
use Dnr\Domain\EngagementInput;
use Dnr\Domain\OrganizationInput;

function expectDomainInput(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Domain input test failed: {$message}\n");
        exit(1);
    }
}

/** @param callable(): mixed $callback */
function expectDomainInputFailure(callable $callback, string $expected_message, string $message): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        expectDomainInput(
            str_contains($exception->getMessage(), $expected_message),
            $message . ' (unexpected error: ' . $exception->getMessage() . ')'
        );
        return;
    }

    expectDomainInput(false, $message . ' (no validation error was raised)');
}

$organization = OrganizationInput::normalize([
    'organization_name' => ' Test Organization ',
    'website_url' => 'https://example.org',
    'phone_country_code' => '+1',
    'phone' => '3125550100',
    'same_address' => 'yes',
    'physical_address_line_1' => '1 Main Street',
    'physical_city' => 'Chicago',
    'physical_state' => 'IL',
    'physical_zipcode' => '60601',
    'physical_country' => 'USA',
]);
expectDomainInput($organization['errors'] === [], 'a valid organization should normalize without errors.');
expectDomainInput(
    $organization['data']['website_url'] === 'https://example.org'
        && $organization['data']['mailing_city'] === 'Chicago'
        && $organization['data']['phone'] === '+13125550100',
    'organization URLs, same-address fields, and telephone numbers should be canonicalized.'
);

$embeddedContact = ContactInput::normalizeEmbedded([
    'first_name' => 'Avery',
    'last_name' => 'Morgan',
    'role' => 'admin',
    'email' => 'avery@example.org',
    'email_confirm' => 'avery@example.org',
]);
expectDomainInput(
    $embeddedContact['errors'] === [] && $embeddedContact['data']['role'] === 'admin',
    'organization contact rows should use the same contact validation as standalone forms.'
);

$contact = ContactInput::normalize([
    'organization_id' => 10,
    'contact_first_name' => ' Avery ',
    'contact_last_name' => ' Morgan ',
    'contact_role' => 'OTHER',
    'contact_role_other' => 'Coordinator',
    'contact_email' => 'avery@example.org',
    'contact_email_confirm' => 'avery@example.org',
    'contact_phone_country_code' => '+1',
    'contact_phone' => '7735550199',
]);
expectDomainInput($contact['errors'] === [], 'a valid contact should normalize without errors.');
expectDomainInput(
    $contact['data']['contact_role'] === 'other'
        && $contact['data']['contact_phone'] === '+17735550199',
    'contact role and telephone normalization should be shared across create and edit flows.'
);

$engagement = EngagementInput::normalize([
    'organization_id' => 10,
    'event_title' => 'Conference',
    'event_start_date' => '2026-09-10',
    'event_end_date' => '2026-09-12',
    'event_type' => 'other',
    'event_type_other' => 'Retreat',
    'confirmation_status' => 'not-valid',
    'travel_amount' => '125.50',
    'event_address_line_1' => '20 W Kinzie Street',
    'event_city' => 'Chicago',
    'event_state' => 'IL',
    'event_zipcode' => '60654',
    'event_country' => 'USA',
]);
expectDomainInput(
    $engagement['event_type_other'] === 'Retreat'
        && $engagement['confirmation_status'] === 'work_in_progress'
        && $engagement['travel_amount'] === 125.5
        && $engagement['event_city'] === 'Chicago'
        && $engagement['event_country'] === 'USA',
    'engagement reference choices, amounts, and address fields should be normalized consistently.'
);

$maximumTitle = str_repeat('é', 255);
$maximumLengthEngagement = EngagementInput::normalize([
    'organization_id' => 10,
    'event_title' => $maximumTitle,
    'event_start_date' => '2026-09-10',
    'event_end_date' => '2026-09-10',
    'event_type' => 'conference',
]);
expectDomainInput(
    $maximumLengthEngagement['event_title'] === $maximumTitle,
    'field limits should count characters and accept the documented maximum.'
);
expectDomainInputFailure(
    static fn () => EngagementInput::normalize([
        'organization_id' => 10,
        'event_title' => str_repeat('é', 256),
        'event_start_date' => '2026-09-10',
        'event_end_date' => '2026-09-10',
        'event_type' => 'conference',
    ]),
    '255 characters or fewer',
    'overlong engagement titles should be rejected before reaching the database.'
);

$overlongOrganization = OrganizationInput::normalize([
    'organization_name' => str_repeat('A', 256),
]);
expectDomainInput(
    in_array('Organization name must be 255 characters or fewer.', $overlongOrganization['errors'], true),
    'overlong organization names should produce a validation error.'
);

$overlongContact = ContactInput::normalize([
    'organization_id' => 10,
    'contact_first_name' => str_repeat('A', 256),
    'contact_last_name' => 'Morgan',
    'contact_role' => 'admin',
]);
expectDomainInput(
    in_array('First name must be 255 characters or fewer.', $overlongContact['errors'], true),
    'overlong contact fields should produce a validation error.'
);

$oversizedContactNotes = ContactInput::normalize([
    'organization_id' => 10,
    'contact_first_name' => 'Avery',
    'contact_last_name' => 'Morgan',
    'contact_role' => 'admin',
    'contact_email' => 'avery@example.org',
    'contact_email_confirm' => 'avery@example.org',
    'contact_notes' => str_repeat('🚀', 16384),
]);
expectDomainInput(
    in_array('Contact notes is too long; shorten it and try again.', $oversizedContactNotes['errors'], true),
    'TEXT-backed fields should be limited by their UTF-8 storage size.'
);

echo "Domain input tests passed.\n";
