<?php

require_once __DIR__ . '/../src/text_rendering_helpers.php';

function expectNoteUrlLinkFeature($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "Note URL link feature test failed: {$message}\n");
        exit(1);
    }
}

$linked_text = renderTextWithLinks(
    "Source: https://example.test/posts/42?view=full&from=notes.\n<script>alert('unsafe')</script>"
);
expectNoteUrlLinkFeature(
    str_contains(
        $linked_text,
        '<a href="https://example.test/posts/42?view=full&amp;from=notes" target="_blank" rel="noopener noreferrer">https://example.test/posts/42?view=full&amp;from=notes</a>.'
    ),
    'absolute HTTP(S) URLs should become safe external links without absorbing sentence punctuation.'
);
expectNoteUrlLinkFeature(
    str_contains($linked_text, "<br />\n&lt;script&gt;alert(&#039;unsafe&#039;)&lt;/script&gt;")
        && !str_contains($linked_text, '<script>'),
    'line breaks should remain visible and non-link HTML should be escaped.'
);

$multiple_links = renderTextWithLinks(
    'See (https://example.test/wiki/Function_(mathematics)) and http://example.test/plain. javascript:alert(1)'
);
expectNoteUrlLinkFeature(
    str_contains(
        $multiple_links,
        '<a href="https://example.test/wiki/Function_(mathematics)" target="_blank" rel="noopener noreferrer">https://example.test/wiki/Function_(mathematics)</a>'
    )
        && str_contains(
            $multiple_links,
            '<a href="http://example.test/plain" target="_blank" rel="noopener noreferrer">http://example.test/plain</a>.'
        )
        && substr_count($multiple_links, '<a href=') === 2
        && !str_contains($multiple_links, 'href="javascript:'),
    'multiple links should render while balanced parentheses remain part of a URL and unsupported schemes stay plain.'
);

$root = dirname(__DIR__);
$read = static fn($path) => file_get_contents($root . '/' . $path);
$note_views = [
    'contact notes' => $read('src/view_contact.php'),
    'organization notes' => $read('src/view_organization.php'),
    'standard task notes' => $read('src/view_standard_task.php'),
    'engagement closeout notes' => $read('src/view_engagement.php'),
];
foreach ($note_views as $label => $source) {
    expectNoteUrlLinkFeature(
        is_string($source) && str_contains($source, 'renderTextWithLinks('),
        "{$label} should render URLs with the shared link helper."
    );
}

echo "Note URL link feature tests passed.\n";
