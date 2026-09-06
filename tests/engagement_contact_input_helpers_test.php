<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/engagement_contact_input_helpers.php';

function expectEventContactInput(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException('Event contact input test failed: ' . $message); }
}
function expectEventContactInputRejected(mixed $rows, string $message): void
{
    try { normalizeEngagementNewContacts($rows); }
    catch (InvalidArgumentException) { return; }
    throw new RuntimeException('Event contact input test failed: ' . $message);
}
$row = ['first_name' => ' Andy ', 'last_name' => 'Woods', 'email' => 'andy@example.test',
    'role_title' => 'Chairman', 'roles' => ['travel', 'primary_host', 'travel']];
$normalized = normalizeEngagementNewContacts([$row, array_merge($row, ['first_name' => 'Sam'])]);
expectEventContactInput(count($normalized) === 2 && $normalized[0]['first_name'] === 'Andy'
    && $normalized[0]['role_other'] === 'Chairman'
    && $normalized[0]['roles'] === ['primary_host', 'travel'], 'Multiple contacts and roles should normalize in a stable order');
expectEventContactInput(normalizeEngagementAddedContactIds(['12', '3', '12']) === [12, 3], 'Added IDs should deduplicate');
foreach ([['nope'], [['id' => 12]], ['9223372036854775808'], ['0']] as $invalid) {
    $rejected = false;
    try { normalizeEngagementAddedContactIds($invalid); } catch (InvalidArgumentException) { $rejected = true; }
    expectEventContactInput($rejected, 'Malformed and overflowing IDs must be rejected');
}
expectEventContactInputRejected([array_merge($row, ['roles' => []])], 'New contacts require an event role');
expectEventContactInputRejected([array_merge($row, ['roles' => ['owner']])], 'Unsupported roles must fail');
expectEventContactInputRejected([array_merge($row, ['email' => 'invalid'])], 'Invalid email must fail');
expectEventContactInputRejected([array_merge($row, ['first_name' => ['malformed']])], 'Nested name input must fail');
expectEventContactInputRejected(array_fill(0, 21, $row), 'New contacts should have a bounded batch size');
$drafts = engagementNewContactFormRows([$row, array_merge($row, ['email' => 'invalid'])]);
expectEventContactInput(count($drafts) === 2 && $drafts[1]['email'] === 'invalid'
    && $drafts[0]['first_name'] === ' Andy ', 'All drafts must survive a different row failing validation');
echo "Event contact input helper tests passed.\n";
