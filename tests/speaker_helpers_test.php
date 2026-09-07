<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/speaker_helpers.php';
require_once __DIR__ . '/../src/presentation_helpers.php';

function expectSpeaker(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Speaker test failed: ' . $message);
    }
}
$input = ['name' => ' Olivier Melnick ', 'email' => 'olivier@shalominmessiah.com', 'phone' => '+1 (949)400-2892'];
$speaker = normalizeSpeakerInput($input);
expectSpeaker($speaker === ['name' => 'Olivier Melnick', 'email' => 'olivier@shalominmessiah.com', 'phone' => '+19494002892', 'bio' => ''] + array_fill_keys(array_keys(SPEAKER_URL_FIELDS), ''), 'Names are trimmed, telephone numbers normalized, and links optional.');
foreach (SPEAKER_URL_FIELDS as $field => $label) {
    $linked = normalizeSpeakerInput($input + [$field => '  https://example.com/' . $field . '?from=profile&lang=en  ']);
    expectSpeaker($linked[$field] === 'https://example.com/' . $field . '?from=profile&lang=en', $label . ' preserves its path and query while trimming whitespace.');
    foreach (['javascript:alert(1)', 'ftp://example.com', '//example.com', 'example.com', ['https://example.com'], 'https://example.com/' . str_repeat('a', 2048)] as $invalidUrl) {
        try {
            normalizeSpeakerInput($input + [$field => $invalidUrl]);
            throw new RuntimeException('Invalid ' . $label . ' was accepted.');
        } catch (InvalidArgumentException $expected) {
        }
    }
}
$national = normalizeSpeakerInput(array_replace($input, ['phone_country_code' => '+1', 'phone' => '(949) 400-2892', 'bio' => " First line\nSecond line "]));
expectSpeaker($national['phone'] === '+19494002892' && $national['bio'] === "First line\nSecond line", 'The country picker accepts national numbers and bios retain line breaks.');
$international = normalizeSpeakerInput(array_replace($input, ['phone_country_code' => '+44', 'phone' => '020 7946 0018']));
expectSpeaker($international['phone'] === '+442079460018', 'International numbers use the selected country.');
foreach ([['name' => ''], ['name' => '   '], ['name' => str_repeat('é', 256)], ['name' => []], ['email' => ''], ['email' => '   '], ['email' => 'invalid'],
    ['email' => "a@example.com\r\nBcc: other@example.com"], ['phone' => '9494002892'],
    ['phone' => '+1 123'], ['phone' => '+1 949 400 2892 ext 123'], ['phone' => []],
    ['phone_country_code' => '+44'], ['phone_country_code' => []], ['bio' => str_repeat('é', 32768)]] as $invalid) {
    try {
        normalizeSpeakerInput(array_replace($input, $invalid));
        throw new RuntimeException('Invalid speaker input was accepted.');
    } catch (InvalidArgumentException $expected) {
    }
}
$options = [2 => ['id' => 2, 'name' => 'Another Speaker'], 1 => ['id' => 1, 'name' => 'Renamed Speaker']];
expectSpeaker(defaultSpeakerId($options) === 1, 'Renaming the original speaker does not change the fallback.');
expectSpeaker(defaultSpeakerId($options, 'Another Speaker') === 2, 'Configured defaults resolve to saved records.');
expectSpeaker(defaultSpeakerId($options, 'Missing Name') === 1, 'Unmatched configuration never creates a free-text speaker.');
foreach (['-1', '0', '1.5', '2147483648', 'Olivier', ['1']] as $invalidId) {
    try {
        normalizeEngagementPresentations([['topic_title' => 'Test', 'speaker_id' => $invalidId]], '', '', 1);
        throw new RuntimeException('Invalid speaker ID was accepted.');
    } catch (InvalidArgumentException $expected) {
    }
}
$rows = normalizeEngagementPresentations([['topic_title' => 'Test', 'speaker_id' => '2']], '', '', 1);
expectSpeaker($rows[0]['speaker_id'] === 2, 'Presentation speaker selections become integer IDs.');
expectSpeaker(!engagementPresentationMatches($rows[0], array_replace($rows[0], ['speaker_id' => 1])), 'Changing only the speaker must be saved.');
echo "Speaker helper tests passed.\n";
