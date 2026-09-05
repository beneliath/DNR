<?php
require_once __DIR__ . '/../src/functions.php';
$first = assetUrl('assets/css/modern.min.css');
define('APP_VERSION', '999.888.777');
if (assetUrl('assets/css/modern.min.css') !== $first || str_contains($first, 'v=')) {
    throw new RuntimeException('Version-only changes must preserve content-addressed URLs');
}
$manifest = require __DIR__ . '/../src/assets/asset-manifest.php';
if (!str_ends_with($first, 'h=' . $manifest['assets/css/modern.min.css'])) throw new RuntimeException('Manifest identity missing');
if (assetUrl('unbuilt.php?x=1#section') !== 'unbuilt.php?x=1&v=999.888.777#section') throw new RuntimeException('Version fallback or fragment placement changed');
if (!str_ends_with(assetUrl('assets/css/modern.min.css?x=1#sample'), '?x=1&h=' . $manifest['assets/css/modern.min.css'] . '#sample')) throw new RuntimeException('Asset query handling changed');
echo "Asset cache identity tests passed.\n";
