<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/short_link_helpers.php';

function expectCustomLink(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$links = normalizeSpeakerCustomLinks([
    ['label' => ' Videos & Resources ', 'url' => ' https://example.com/watch?a=1&b=2 '],
    ['label' => 'Videos & Resources', 'url' => 'https://example.com/other'],
    ['label' => '', 'url' => ''],
]);
expectCustomLink(count($links) === 2 && $links[0]['key'] !== $links[1]['key'], 'Same-name links have independent identities; empty rows are ignored.');
expectCustomLink($links[0]['label'] === 'Videos & Resources' && $links[0]['url'] === 'https://example.com/watch?a=1&b=2', 'Names and URLs are trimmed without losing query parameters.');
expectCustomLink(normalizeSpeakerCustomLinks(array_reverse($links), $links) === array_reverse($links), 'Reordering preserves identities.');
expectCustomLink(normalizeSpeakerCustomLinks([], $links) === [], 'All profile links can be removed.');
expectCustomLink(speakerCustomLinks(['custom_links' => json_encode($links)]) === $links, 'Stored JSON decodes consistently.');
foreach ([
    'invalid', [['label' => [], 'url' => 'https://example.com']], [['label' => 'Test', 'url' => []]],
    [['label' => '', 'url' => 'https://example.com']], [['label' => 'Test', 'url' => '']],
    [['label' => str_repeat('é', 256), 'url' => 'https://example.com']],
    [['label' => "Test\nName", 'url' => 'https://example.com']],
    [['key' => 'ffffffffffffffff', 'label' => 'Test', 'url' => 'https://example.com']],
    [$links[0], $links[0]], array_fill(0, 51, ['label' => '', 'url' => '']),
] as $invalid) {
    try {
        normalizeSpeakerCustomLinks($invalid, $links);
        throw new RuntimeException('Invalid custom links accepted.');
    } catch (InvalidArgumentException $expected) {}
}
foreach (['javascript:alert(1)', 'ftp://example.com', '//example.com', 'https://user:password@example.com',
    'https://example.com/' . str_repeat('a', 2048), "https://example.com/\r\nLocation:bad"] as $invalidUrl) {
    try {
        normalizeSpeakerCustomLinks([['label' => 'Test', 'url' => $invalidUrl]]);
        throw new RuntimeException('Unsafe destination accepted.');
    } catch (InvalidArgumentException $expected) {}
}
expectCustomLink(shortLinkLabel(['link_type' => 'custom', 'custom_label' => 'Video Channel']) === 'Video Channel', 'Statistics use the custom name.');
expectCustomLink(shortLinkLabel(['link_type' => 'website']) === 'Website', 'Built-in names remain available.');
echo "Speaker custom link tests passed.\n";
