<?php

function expectMinifiedAsset($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "Minified asset test failed: {$message}\n");
        exit(1);
    }
}

$asset_pairs = [
    'assets/css/style.css' => 'assets/css/style.min.css',
    'assets/css/modern.css' => 'assets/css/modern.min.css',
    'assets/js/main.js' => 'assets/js/main.min.js',
    'assets/js/theme.js' => 'assets/js/theme.min.js',
    'assets/js/app-shell.js' => 'assets/js/app-shell.min.js',
];

foreach ($asset_pairs as $source_asset => $minified_asset) {
    $source_path = __DIR__ . '/../src/' . $source_asset;
    $minified_path = __DIR__ . '/../src/' . $minified_asset;
    expectMinifiedAsset(is_file($minified_path), $minified_asset . ' should exist.');
    expectMinifiedAsset(
        filesize($minified_path) < filesize($source_path),
        $minified_asset . ' should be smaller than its readable source.'
    );
}

$runtime_references = array_fill_keys(array_values($asset_pairs), false);
$source_directory = new RecursiveDirectoryIterator(
    __DIR__ . '/../src',
    FilesystemIterator::SKIP_DOTS
);
$source_files = new RecursiveIteratorIterator($source_directory);

foreach ($source_files as $source_file) {
    if (!$source_file->isFile() || $source_file->getExtension() !== 'php') {
        continue;
    }

    $page_path = $source_file->getPathname();
    $page_source = file_get_contents($page_path);
    foreach ($asset_pairs as $source_asset => $minified_asset) {
        expectMinifiedAsset(
            !str_contains($page_source, $source_asset),
            basename($page_path) . ' should not load the readable ' . $source_asset . ' asset.'
        );
        if (str_contains($page_source, $minified_asset)) {
            $runtime_references[$minified_asset] = true;
        }
    }
}

foreach ($runtime_references as $minified_asset => $is_referenced) {
    expectMinifiedAsset($is_referenced, $minified_asset . ' should be loaded by the application.');
}

echo "Minified asset tests passed.\n";
