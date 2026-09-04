<?php

declare(strict_types=1);

function expectLabelPunctuation(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Label punctuation test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$violations = [];
$patterns = [
    ['pattern' => '~<label\b[^>]*>(.*?)</label>~si', 'body' => 1],
    [
        'pattern' => '~<([a-z][a-z0-9]*)\b(?=[^>]*class="[^"]*\blabel\b[^"]*")[^>]*>(.*?)</\1>~si',
        'body' => 2,
    ],
    ['pattern' => '~\baria-label="([^"]*)"~si', 'body' => 1],
];

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));
foreach ($files as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $source = (string) file_get_contents($file->getPathname());
    foreach ($patterns as $configuration) {
        if (!preg_match_all($configuration['pattern'], $source, $matches, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        foreach ($matches[$configuration['body']] as [$body, $offset]) {
            $body_without_php = preg_replace('~<\?(?:php|=).*?\?>~s', 'value', $body) ?? $body;
            if (!preg_match_all('~(?:^|>)([^<]+)(?=<|$)~s', $body_without_php, $text_matches)) {
                continue;
            }

            foreach ($text_matches[1] as $text) {
                $text = html_entity_decode(
                    trim(preg_replace('~\s+~u', ' ', $text) ?? $text),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                );
                if ($text !== '' && preg_match('~[.!;…]\z~u', $text) === 1) {
                    $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                    $violations[] = str_replace($root . '/', '', $file->getPathname())
                        . ':' . $line . ' (`' . $text . '`)';
                }
            }
        }
    }
}

expectLabelPunctuation(
    $violations === [],
    'control labels must not end in disallowed punctuation: ' . implode(', ', array_unique($violations))
);

$header = (string) file_get_contents($root . '/src/templates/header.php');
expectLabelPunctuation(
    str_contains($header, '<small id="role-preview-help">menus/access as another role</small>'),
    'the Preview Access helper must use the compact one-line label.'
);

$booking_inquiry_form = (string) file_get_contents($root . '/src/templates/booking_inquiry_form.php');
expectLabelPunctuation(
    str_contains($booking_inquiry_form, '<label for="inquiry-summary">What Is Being Requested?</label>'),
    'a genuine question label may retain its final question mark.'
);
expectLabelPunctuation(
    str_contains((string) file_get_contents($root . '/src/contacts.php'), '<span class="control-label">Sort:</span>'),
    'a label may retain an intentional final colon.'
);

echo "Label punctuation tests passed.\n";
