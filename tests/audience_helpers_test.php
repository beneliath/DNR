<?php
declare(strict_types=1);
require_once (getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src') . '/audience_helpers.php';
function audienceExpect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function audienceReject(callable $call): void {
    try { $call(); } catch (InvalidArgumentException $expected) { return; }
    throw new RuntimeException('Invalid audience operation was accepted.');
}
$input = ['email' => ' CONNECTION@example.com ', 'email_status' => 'non-subscribed', 'tags' => 'Donor, donor, Volunteer'];
$person = audienceContactInput($input);
audienceExpect($person['email'] === 'connection@example.com' && $person['tags'] === ['donor', 'Volunteer'], 'Email normalization and tag deduplication failed.');
foreach ([['email' => []], ['email' => "a@example.com\r\nBcc: b@example.com"], ['email_status' => 'invalid'], ['first_name' => str_repeat('é', 101)], ['notes' => "test\0value"], ['tags' => str_repeat('x', 101)]] as $invalid) {
    audienceReject(fn() => audienceContactInput(array_replace($input, $invalid)));
}
$subscribed = array_replace($person, ['email_status' => 'subscribed']);
audienceReject(fn() => audienceRequireConsent($subscribed, null));
audienceRequireConsent(array_replace($subscribed, ['consent_note' => 'Signup form, 2026-10-16']), null);
audienceRequireConsent($subscribed, $subscribed);
audienceReject(fn() => audienceRequireConsent(array_replace($subscribed, ['email' => 'new@example.com']), $subscribed));
audienceReject(fn() => audienceRequireConsent($subscribed, array_replace($person, ['email_status' => 'unsubscribed'])));
foreach (['subscribed', 'non-subscribed', 'pending', 'unsubscribed'] as $status) {
    audienceReject(fn() => audienceRequireConsent(array_replace($person, ['email_status' => $status, 'consent_note' => 'New permission']), array_replace($person, ['email_status' => 'cleaned'])));
}
$attack = "' OR 1=1 --";
[$where, $params] = audienceWhere(audienceFilters(['q' => $attack, 'tag' => $attack, 'status' => 'subscribed']));
audienceExpect(!str_contains($where, $attack) && in_array($attack, $params, true), 'Filter values must be bound, not interpolated.');
audienceReject(fn() => audienceFilters(['tag' => ['bad']]));
audienceReject(fn() => audienceFilters(['status' => 'unknown']));
foreach (['=1+1', "\t=HYPERLINK(\"bad\")", '+1', '-1', '@SUM(1)'] as $cell) audienceExpect(str_starts_with(audienceCsvCell($cell), "'"), 'CSV formulas must be neutralized.');
audienceExpect(audienceCsvCell('connection@example.com') === 'connection@example.com', 'Normal export values should be unchanged.');
echo "Audience validation, consent, suppression, filters, and CSV tests passed.\n";

audienceExpect(str_contains(audienceConnectionOrder('custom:12','asc',[['id'=>12,'field_type'=>'date']]),'AS DATE') && str_contains(audienceConnectionOrder('custom:12','desc',[['id'=>12,'field_type'=>'date']]),'IS NULL) ASC'), 'Custom dates sort chronologically with empty dates last.');
audienceExpect(audienceConnectionOrder('custom:99','desc',[['id'=>12,'field_type'=>'date']]) === 'c.created_at DESC,c.id DESC', 'Unknown custom sort fields do not become SQL.');
