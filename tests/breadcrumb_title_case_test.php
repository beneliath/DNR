<?php

function expectBreadcrumbTitleCase($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "Breadcrumb title-case test failed: {$message}\n");
        exit(1);
    }
}

function breadcrumbLabelIsTitleCase($label)
{
    $minor_words = ['a', 'an', 'and', 'as', 'at', 'by', 'for', 'from', 'in', 'of', 'on', 'or', 'the', 'to', 'with'];
    $words = preg_split('/\s+/', trim($label));
    $last_index = count($words) - 1;

    foreach ($words as $index => $word) {
        $word = trim($word, " \t\n\r\0\x0B?!:;,()[]{}");
        if ($word === '' || preg_match('/^[A-Z0-9]+$/', $word)) {
            continue;
        }
        if ($index > 0 && $index < $last_index && in_array(strtolower($word), $minor_words, true)) {
            continue;
        }
        foreach (explode('-', $word) as $word_part) {
            if ($word_part !== '' && !ctype_upper($word_part[0])) {
                return false;
            }
        }
    }

    return true;
}

$source_files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(__DIR__ . '/../src', FilesystemIterator::SKIP_DOTS)
);
$breadcrumb_count = 0;

foreach ($source_files as $source_file) {
    if (!$source_file->isFile() || $source_file->getExtension() !== 'php') {
        continue;
    }
    $source = file_get_contents($source_file->getPathname());
    $source = preg_replace('/<\?php.*?\?>/s', '', $source);
    preg_match_all(
        '/<nav\b(?=[^>]*\bclass="[^"]*\bbreadcrumb\b[^"]*")[^>]*>(.*?)<\/nav>/si',
        $source,
        $breadcrumb_matches
    );
    foreach ($breadcrumb_matches[1] as $breadcrumb_markup) {
        $breadcrumb_count++;
        preg_match_all('/<(?:a|span)\b[^>]*>(.*?)<\/(?:a|span)>/si', $breadcrumb_markup, $label_matches);
        foreach ($label_matches[1] as $label_markup) {
            if (str_contains($label_markup, '<?php')) {
                continue;
            }
            $label = trim(html_entity_decode(strip_tags($label_markup), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($label === '' || $label === '/') {
                continue;
            }
            expectBreadcrumbTitleCase(
                breadcrumbLabelIsTitleCase($label),
                $source_file->getFilename() . " breadcrumb is not title case: {$label}"
            );
        }
    }
}

expectBreadcrumbTitleCase($breadcrumb_count > 0, 'No breadcrumbs were found to validate.');
echo "Breadcrumb title-case tests passed.\n";
