<?php

function expectHeadingTitleCase($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "Heading title-case test failed: {$message}\n");
        exit(1);
    }
}

function staticHeadingIsTitleCase($heading)
{
    $minor_words = ['a', 'an', 'and', 'as', 'at', 'by', 'for', 'from', 'in', 'of', 'on', 'or', 'the', 'to', 'with'];
    $words = preg_split('/\s+/', trim($heading));
    $last_index = count($words) - 1;

    foreach ($words as $index => $word) {
        $word = trim($word, " \t\n\r\0\x0B?!:;,()[]{}");
        if ($word === '' || $word === '&' || preg_match('/^[A-Z0-9]+$/', $word)) {
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

foreach ($source_files as $source_file) {
    if (!$source_file->isFile() || $source_file->getExtension() !== 'php') {
        continue;
    }
    $source = file_get_contents($source_file->getPathname());
    preg_match_all('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/si', $source, $heading_matches);
    foreach ($heading_matches[1] as $heading_markup) {
        if (str_contains($heading_markup, '<?php')) {
            continue;
        }
        $heading = html_entity_decode(strip_tags($heading_markup), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        expectHeadingTitleCase(
            staticHeadingIsTitleCase($heading),
            $source_file->getFilename() . " heading is not title case: {$heading}"
        );
    }
}

echo "Heading title-case tests passed.\n";
