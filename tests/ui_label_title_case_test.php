<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/app/Domain/ReferenceData.php';

function expectUiLabelTitleCase(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('UI label Title Case test failed: ' . $message);
    }
}

foreach ([
    'work_in_progress' => 'Work in Progress',
    'study or teaching' => 'Study or Teaching',
    'Honorarium and Offering' => 'Honorarium and Offering',
    'non-subscribed' => 'Non-Subscribed',
    'No PDF Selected' => 'No PDF Selected',
    'PPT Slidedeck and QR Codes' => 'PPT Slidedeck and QR Codes',
] as $value => $expected) {
    expectUiLabelTitleCase(\Dnr\Domain\ReferenceData::label($value) === $expected, $value);
}

$minorWords = ['a', 'an', 'and', 'as', 'at', 'by', 'for', 'from', 'in', 'into', 'of', 'on', 'or', 'the', 'to', 'with'];
$notation = ['px', 'pt', 'em', 'rem', 'kg', 'mm', 'cm', 'dpi', 'beneliath', 'iOS', 'macOS', 'iPhone', 'iPad', 'vCard'];
$sourceDirectory = realpath(__DIR__ . '/../src');
$violations = [];
$checked = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $source = (string) file_get_contents($file->getPathname());
    $markup = preg_replace('~<\?(?:php|=).*?\?>~s', ' VALUE ', $source) ?? $source;
    $markup = preg_replace('~<(small|code|script|style|p)\b[^>]*>.*?</\1>~si', '', $markup) ?? $markup;
    $markup = preg_replace('~<span\b[^>]*class="[^"]*(?:audience-help|field-help|helper-text)[^"]*"[^>]*>.*?</span>~si', '', $markup) ?? $markup;
    preg_match_all('~<(label|button|option|th|dt|legend|a|summary)\b[^>]*>(.*?)</\1>|\baria-label="([^"]*)"~si', $markup, $matches, PREG_SET_ORDER);
    preg_match_all('~<(span|small|strong)\b[^>]*class="[^"]*(?:pill|badge|chip)[^"]*"[^>]*>(.*?)</\1>~si', $markup, $pillMatches, PREG_SET_ORDER);
    $matches = array_merge($matches, $pillMatches);
    foreach ($matches as $match) {
        $body = $match[3] ?? $match[2];
        if (($match[1] ?? '') === 'label') {
            $body = preg_replace('~<select\b[^>]*>.*?</select>~si', '', $body) ?? $body;
        }
        $text = trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($text === '' || str_contains($text, '$')) {
            continue;
        }
        $words = preg_split('/\s+/u', $text) ?: [];
        $last = count($words) - 1;
        $checked++;
        foreach ($words as $index => $word) {
            $word = trim($word, " \t\n\r\0\x0B?!:;,()[]{}\"'·");
            if (preg_match('~^(?:and|or)[\"\']?/(?:and|or)$~i', $word)) {
                continue;
            }
            if ($word === '' || in_array($word, $notation, true) || preg_match('~^(?:\.[a-z0-9]+|https?://)~', $word)) {
                continue;
            }
            if ($index > 0 && $index < $last && in_array(strtolower($word), $minorWords, true)) {
                continue;
            }
            if (preg_match('/^\p{Ll}/u', $word) === 1) {
                $violations[] = str_replace($sourceDirectory . '/', '', $file->getPathname()) . ' — ' . $text;
                break;
            }
        }
    }
}
expectUiLabelTitleCase($checked > 1000, 'The label audit must cover the whole application, including MessageMonkey.');
expectUiLabelTitleCase($violations === [], implode("\n", array_slice(array_unique($violations), 0, 25)));

// These action labels name exact confirmation tokens; title casing must not
// change the token that the form requires the user to enter.
foreach (['dialogs.php', 'campaigns.php', 'person.php', 'collection-lifecycle-dialogs.php', 'flow-lifecycle-dialogs.php'] as $template) {
    $source = (string) file_get_contents($sourceDirectory . '/templates/audience/' . $template);
    expectUiLabelTitleCase(str_contains($source, 'Type DELETE to'), $template . ' must preserve the DELETE confirmation token.');
    expectUiLabelTitleCase(!str_contains($source, 'Type Delete to'), $template . ' must not recase a confirmation token.');
}

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDirectory . '/assets/css')) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'css' || str_ends_with($file->getFilename(), '.min.css')) {
        continue;
    }
    preg_match_all('/([^{}]+)\{([^{}]*)\}/s', (string) file_get_contents($file->getPathname()), $rules, PREG_SET_ORDER);
    foreach ($rules as $rule) {
        if (preg_match('/text-transform:\s*uppercase/', $rule[2])) {
            expectUiLabelTitleCase(str_contains($rule[1], '#delivery-code'), $file->getFilename() . ' forces a label into all caps.');
        }
    }
}

echo "UI labels, reference labels, and label styling Title Case tests passed ({$checked} labels).\n";
