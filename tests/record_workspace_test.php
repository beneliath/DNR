<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/record_workspace_helpers.php';

function expectRecordWorkspace(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$map = 'map.php?lifecycle=active&location=needs_address&page=2';
expectRecordWorkspace(recordReturnLabel($map) === 'Map' && recordReturnLabel('view_organization.php?id=2') === 'Organization', 'Return labels identify the actual source screen');
expectRecordWorkspace(safeRecordReturnUrl($map, 'engagements.php') === $map, 'Map filters must survive a section edit');
$filtered = 'contacts.php?q=Church&sort_by=organization&cursor=abc&per_page=50';
$detail = recordUrlWithQuery('view_contact.php?id=42#chron-log', ['return_to' => $filtered]);
expectRecordWorkspace(safeRecordReturnUrl($detail, '') === $detail, 'Record activity may retain a nested list destination');
expectRecordWorkspace(str_ends_with($detail, '#chron-log'), 'Query changes must not destroy the record section');
foreach (['https://example.com', '//example.com', '/\\example.com', 'logout.php', 'view_contact.php\r\nLocation: https://example.com', "view_contact.php?x=\nfoo", '../contacts.php', ['contacts.php']] as $unsafe) {
    expectRecordWorkspace(safeRecordReturnUrl($unsafe, 'contacts.php') === 'contacts.php', 'Reject unsafe destinations');
}
$created = recordUrlWithQuery('edit_inquiry.php?id=12#request-details', ['created_organization_id' => 8]);
expectRecordWorkspace($created === 'edit_inquiry.php?id=12&created_organization_id=8#request-details', 'Creation resumes the inquiry with its new selected record');
$trail = ['', 'cursor-one', 'cursor-two'];
expectRecordWorkspace(recordCursorTrail(recordEncodedCursorTrail($trail)) === $trail, 'Previous retains cursor history including the first-page empty cursor');
foreach (['not base64', base64_encode('{"x":4}'), base64_encode('[1,2]')] as $invalid) {
    expectRecordWorkspace(recordCursorTrail($invalid) === [], 'Malformed cursor history must be discarded');
}
require_once __DIR__ . '/../src/functions.php';
$nameOnly = \Dnr\Domain\OrganizationInput::normalize(['organization_name' => ' New relationship ']);
expectRecordWorkspace($nameOnly['errors'] === [] && $nameOnly['data']['organization_name'] === 'New relationship', 'Name-only intake must be valid before any address is known');
$partial = \Dnr\Domain\OrganizationInput::normalize(['organization_name' => 'Partial address', 'physical_country' => 'US', 'physical_city' => 'Chicago']);
expectRecordWorkspace($partial['errors'] === [], 'A partial address must not block relationship intake');
$badRegion = \Dnr\Domain\OrganizationInput::normalize(['organization_name' => 'Bad region', 'physical_country' => 'US', 'physical_state' => 'Atlantis']);
expectRecordWorkspace($badRegion['errors'] !== [], 'Optional address fields must still reject invalid supplied values');
$contactInput = ['contact_first_name' => 'Avery', 'contact_last_name' => 'Morgan', 'contact_role' => 'admin', 'contact_email' => 'avery@example.org'];
expectRecordWorkspace(\Dnr\Domain\ContactInput::normalize($contactInput)['errors'] === [], 'A contact needs only one valid email entry');
expectRecordWorkspace(\Dnr\Domain\ContactInput::normalize(array_merge($contactInput, ['contact_email' => 'invalid']))['errors'] !== [], 'A single email entry must still be validated');
expectRecordWorkspace(\Dnr\Domain\ContactInput::normalize($contactInput + ['contact_email_confirm' => 'someone@example.org'])['errors'] !== [], 'Legacy callers submitting a confirmation must still match');
echo "Record workspace tests passed.\n";
