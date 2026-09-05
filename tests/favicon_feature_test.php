<?php

declare(strict_types=1);

function expectFavicon(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Favicon feature test failed: {$message}\n");
        exit(1);
    }
}

require_once __DIR__ . '/../src/functions.php';

$favicon_path = __DIR__ . '/../src/assets/favicon.svg';
$favicon = file_get_contents($favicon_path);

expectFavicon(is_string($favicon), 'the favicon SVG should exist.');
expectFavicon(
    str_contains($favicon, 'viewBox="50 30 330 330"')
        && str_contains($favicon, 'fill="#081e3a"')
        && str_contains($favicon, 'fill="#b38e47"')
        && !str_contains($favicon, '<rect')
        && !str_contains($favicon, 'fill="#151a23"'),
    'the favicon should use the reverse-field MOED palette on a transparent canvas.'
);

ob_start();
renderPageHead('Dashboard - MOED', ['styles' => []]);
$head = (string) ob_get_clean();

expectFavicon(
    preg_match(
        '/<title>Dashboard - MOED<\/title>\s*<link rel="icon" type="image\/svg\+xml" href="assets\/favicon\.svg\?h=[0-9a-f]{12}">/',
        $head
    ) === 1,
    'the shared page head should publish the fingerprinted SVG favicon on every rendered page.'
);

echo "Favicon feature tests passed.\n";
