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
        && $organization['data']['phone'] === '+1 (312) 555-0100',
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
        && $contact['data']['contact_phone'] === '+1 (773) 555-0199',
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
]);
expectDomainInput(
    $engagement['event_type_other'] === 'Retreat'
        && $engagement['confirmation_status'] === 'work_in_progress'
        && $engagement['travel_amount'] === 125.5,
    'engagement reference choices and amounts should be normalized consistently.'
);

echo "Domain input tests passed.\n";
